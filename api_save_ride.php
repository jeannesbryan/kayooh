<?php
// api_save_ride.php - Kayooh v5.0 (Chunk Receiver & Assembler)
session_start();
header('Content-Type: application/json');

// Proteksi agar hanya Kapten yang bisa nge-post data
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']);
    exit;
}

$db_file = __DIR__ . '/kayooh.sqlite';
$temp_dir = __DIR__ . '/temp';

// Buat folder penampungan sementara kalau belum ada
if (!is_dir($temp_dir)) {
    mkdir($temp_dir, 0755, true);
}

// Tangkap data kiriman dari Javascript
$uuid = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['uuid'] ?? '');
$chunk_index = (int)($_POST['chunk_index'] ?? 0);
$total_chunks = (int)($_POST['total_chunks'] ?? 1);
$points_raw = $_POST['points'] ?? '[]';

if (empty($uuid)) {
    echo json_encode(['status' => 'error', 'message' => 'UUID tidak valid!']);
    exit;
}

// 1. SIMPAN CHUNK SEMENTARA
// Format file: ID_Gowes_chunk_0.json, ID_Gowes_chunk_1.json, dst.
$chunk_file = $temp_dir . "/{$uuid}_chunk_{$chunk_index}.json";
file_put_contents($chunk_file, $points_raw);

// 2. CEK APAKAH INI CHUNK TERAKHIR?
if ($chunk_index === $total_chunks - 1) {
    
    // TAHAP RAKIT DATA (ASSEMBLER)
    $all_points = [];
    for ($i = 0; $i < $total_chunks; $i++) {
        $c_file = $temp_dir . "/{$uuid}_chunk_{$i}.json";
        if (file_exists($c_file)) {
            $c_data = json_decode(file_get_contents($c_file), true);
            if (is_array($c_data)) {
                // Konversi format {lat: x, lng: y} dari IndexedDB jadi array murni [lat, lon]
                // Ini bikin ukuran file JSON final turun drastis!
                foreach ($c_data as $p) {
                    if (isset($p['lat']) && isset($p['lng'])) {
                        $all_points[] = [$p['lat'], $p['lng']];
                    }
                }
            }
            unlink($c_file); // Hapus file pecahan (chunk) biar server bersih
        }
    }

    // TAHAP SIMPAN JSON FINAL
    $final_json_path = $temp_dir . "/ride_{$uuid}.json";
    file_put_contents($final_json_path, json_encode($all_points));

    // TAHAP TANGKAP METADATA
    $ride_name = $_POST['ride_name'] ?? 'Gowes v5.0';
    $distance = (float)($_POST['distance'] ?? 0);
    $moving_time = (int)($_POST['moving_time'] ?? 0);
    $avg_speed = (float)($_POST['avg_speed'] ?? 0);
    $max_speed = (float)($_POST['max_speed'] ?? 0);
    $start_date = date('Y-m-d H:i:s'); // Jam selesainya gowes

    // TAHAP KALKULASI ELEVASI RINGAN (Anti-500)
    $elevation_gain = 0;
    $sampled_points = [];
    for ($i = 0; $i < count($all_points); $i += 50) { 
        $sampled_points[] = $all_points[$i]; 
    }
    if (count($sampled_points) > 0) {
        $lats = array_column($sampled_points, 0);
        $lngs = array_column($sampled_points, 1);
        $url = "https://api.open-meteo.com/v1/elevation?latitude=" . implode(',', $lats) . "&longitude=" . implode(',', $lngs);
        
        $ctx = stream_context_create(['http' => ['timeout' => 3]]); // Timeout 3 detik biar gak hang
        $res = @file_get_contents($url, false, $ctx);
        if ($res) {
            $elev_data = json_decode($res, true);
            if (isset($elev_data['elevation'])) {
                for ($j = 1; $j < count($elev_data['elevation']); $j++) {
                    $diff = $elev_data['elevation'][$j] - $elev_data['elevation'][$j-1];
                    if ($diff > 0) $elevation_gain += $diff;
                }
            }
        }
    }

    // TAHAP DATABASE
    try {
        $pdo = new PDO("sqlite:" . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // PERHATIAN: Kolom 'polyline' untuk sementara kita isi dengan LOKASI FILE LOKAL.
        // Nanti setelah di-upload ke R2, tulisan ini bakal diganti jadi URL Cloudflare.
        $stmt = $pdo->prepare("INSERT INTO rides (
            name, distance, moving_time, average_speed, max_speed, 
            total_elevation_gain, start_date, polyline, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'V5_CHUNKING')");

        $stmt->execute([
            $ride_name, $distance, $moving_time, $avg_speed, $max_speed, 
            round($elevation_gain), $start_date, $final_json_path
        ]);

        $ride_id = $pdo->lastInsertId();

        echo json_encode([
            'status' => 'success', 
            'message' => 'Data dirakit & disimpan!', 
            'ride_id' => $ride_id,
            'file_path' => $final_json_path
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }

} else {
    // Kalau belum paket terakhir, balas sukses aja biar HP lanjut kirim paket berikutnya
    echo json_encode([
        'status' => 'success', 
        'message' => "Chunk {$chunk_index} dari " . ($total_chunks - 1) . " diterima."
    ]);
}
?>