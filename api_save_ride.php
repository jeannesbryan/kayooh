<?php
// api_save_ride.php - Kayooh v5.0 (Chunk Receiver, Assembler & Cloudflare R2 Engine)
session_start();
header('Content-Type: application/json');

// Proteksi agar hanya Kapten yang bisa nge-post data
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']);
    exit;
}

$db_file = __DIR__ . '/kayooh.sqlite';
$temp_dir = __DIR__ . '/temp';

// Buat folder penampungan sementara
if (!is_dir($temp_dir)) { mkdir($temp_dir, 0755, true); }

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
$chunk_file = $temp_dir . "/{$uuid}_chunk_{$chunk_index}.json";
file_put_contents($chunk_file, $points_raw);

// 2. CEK APAKAH INI CHUNK TERAKHIR? (TOMBOL FINISH DITEKAN)
if ($chunk_index === $total_chunks - 1) {
    
    // TAHAP RAKIT DATA (ASSEMBLER)
    $all_points = [];
    for ($i = 0; $i < $total_chunks; $i++) {
        $c_file = $temp_dir . "/{$uuid}_chunk_{$i}.json";
        if (file_exists($c_file)) {
            $c_data = json_decode(file_get_contents($c_file), true);
            if (is_array($c_data)) {
                // Ekstrak jadi array murni [lat, lon] agar ukuran super kecil
                foreach ($c_data as $p) {
                    if (isset($p['lat']) && isset($p['lng'])) {
                        $all_points[] = [$p['lat'], $p['lng']];
                    }
                }
            }
            unlink($c_file); // Hapus file pecahan
        }
    }

    // TAHAP TANGKAP METADATA
    $ride_name = $_POST['ride_name'] ?? 'Gowes v5.0';
    $distance = (float)($_POST['distance'] ?? 0);
    $moving_time = (int)($_POST['moving_time'] ?? 0);
    $avg_speed = (float)($_POST['avg_speed'] ?? 0);
    $max_speed = (float)($_POST['max_speed'] ?? 0);
    $avg_temp = (float)($_POST['avg_temp'] ?? 0); // BUG SUHU FIXED!
    $participants = $_POST['participants'] ?? '[]'; 
    $start_date = date('Y-m-d H:i:s');

    // TAHAP KALKULASI ELEVASI (BUG ELEVASI 0 FIXED!)
    $elevation_gain = 0;
    $total_pts = count($all_points);
    if ($total_pts > 1) {
        $step = max(1, floor($total_pts / 50)); // Adaptif: Kalau jarak pendek, step mengecil
        $sampled_points = [];
        for ($i = 0; $i < $total_pts; $i += $step) { 
            $sampled_points[] = $all_points[$i]; 
        }
        if (end($sampled_points) !== end($all_points)) {
            $sampled_points[] = end($all_points); // Kunci titik finish
        }
        
        $lats = array_column($sampled_points, 0);
        $lngs = array_column($sampled_points, 1);
        $url = "https://api.open-meteo.com/v1/elevation?latitude=" . implode(',', $lats) . "&longitude=" . implode(',', $lngs);
        
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
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

    // TAHAP DATABASE INITIALIZATION
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // INJEKSI KOLOM BARU SECARA OTOMATIS (Mencegah Error di Database Lama)
    try { $pdo->exec("ALTER TABLE rides ADD COLUMN avg_temp REAL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rides ADD COLUMN participants TEXT"); } catch (Exception $e) {}

    // AMBIL SETTING CLOUDFLARE R2
    $r2 = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('r2_account_id', 'r2_access_key', 'r2_secret_key', 'r2_bucket', 'r2_public_url')");
        $r2 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}

    $final_polyline = '';
    $json_data = json_encode($all_points);

    // --- MESIN UPLOAD CLOUDFLARE R2 (AWS SIG V4) ---
    if (!empty($r2['r2_account_id']) && !empty($r2['r2_access_key']) && !empty($r2['r2_secret_key']) && !empty($r2['r2_bucket']) && !empty($r2['r2_public_url'])) {
        $object_name = "ride_{$uuid}.json";
        $host = "{$r2['r2_account_id']}.r2.cloudflarestorage.com";
        $endpoint = "https://{$host}/{$r2['r2_bucket']}/{$object_name}";
        $region = 'auto';
        $service = 's3';
        $date = gmdate('Ymd');
        $timestamp = gmdate('Ymd\THis\Z');
        $payload_hash = hash('sha256', $json_data);

        $canonical_uri = "/{$r2['r2_bucket']}/{$object_name}";
        $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$timestamp}\n";
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = "PUT\n{$canonical_uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
        $credential_scope = "{$date}/{$region}/{$service}/aws4_request";
        $string_to_sign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

        // Enkripsi Kunci
        $kSecret = 'AWS4' . $r2['r2_secret_key'];
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        $auth_header = "AWS4-HMAC-SHA256 Credential={$r2['r2_access_key']}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_header}",
            "x-amz-content-sha256: {$payload_hash}",
            "x-amz-date: {$timestamp}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $base_url = rtrim($r2['r2_public_url'], '/');
            $final_polyline = "{$base_url}/{$object_name}";
        }
    }

    // FALLBACK: JIKA R2 GAGAL/BELUM DISETTING (Simpan JSON mentah agar Peta tetap muncul)
    if (empty($final_polyline)) {
        $final_polyline = $json_data; 
    }

    // TAHAP SIMPAN KE DATABASE
    try {
        // DETEKSI OTOMATIS BADGE "KAYOOH" / "KAYOOH_PELETON"
        $source_tag = (strpos($ride_name, 'Peleton') !== false) ? 'KAYOOH_PELETON' : 'KAYOOH';

        $stmt = $pdo->prepare("INSERT INTO rides (
            name, distance, moving_time, average_speed, max_speed, 
            total_elevation_gain, avg_temp, participants, start_date, polyline, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $ride_name, $distance, $moving_time, $avg_speed, $max_speed, 
            round($elevation_gain), $avg_temp, $participants, $start_date, $final_polyline, $source_tag
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Data dirakit & disimpan!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }

} else {
    // Kalau belum paket terakhir, balas sukses aja
    echo json_encode(['status' => 'success', 'message' => "Chunk {$chunk_index} dari " . ($total_chunks - 1) . " diterima."]);
}
?>