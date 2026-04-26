<?php
// gpx_import.php - Mesin Ekstraktor GPX v5.0 (Smart Creator Detection & Peleton Sensor)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$pesan = '';
$status = 'standby';

// ==========================================
// FUNGSI 1: Polyline Encoder (Untuk Kompresi Database)
// ==========================================
function encodePolyline($points) {
    $encodedString = '';
    $prevLat = 0; $prevLng = 0;
    foreach ($points as $point) {
        $lat = round($point[0] * 1e5);
        $lng = round($point[1] * 1e5);
        $dLat = $lat - $prevLat; $dLng = $lng - $prevLng;
        $prevLat = $lat; $prevLng = $lng;
        foreach ([$dLat, $dLng] as $val) {
            $val = $val < 0 ? ~($val << 1) : ($val << 1);
            while ($val >= 0x20) {
                $encodedString .= chr((0x20 | ($val & 0x1f)) + 63);
                $val >>= 5;
            }
            $encodedString .= chr($val + 63);
        }
    }
    return $encodedString;
}

// ==========================================
// PROSES IMPORT GPX
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gpx_file'])) {
    $status = 'processing';
    $file = $_FILES['gpx_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $xml_content = file_get_contents($file['tmp_name']);
        
        // GUNAKAN SIMPLEXML UNTUK BEDAH KTP FILE
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);
        
        if ($xml === false) {
            $pesan = "<div class='alert error'>❌ Wah, file GPX-nya rusak atau formatnya gak standar wak!</div>";
        } else {
            // --- MESIN SATPAM KTP (CREATOR DETECTION) ---
            $creator = "IMPORT"; // Default jika tidak ketemu
            if (isset($xml['creator'])) {
                $creator = strtoupper(trim((string)$xml['creator']));
                // Pembersihan nama creator (hapus versi, spasi, dll jika perlu)
                $creator = explode(' ', $creator)[0]; 
                $creator = preg_replace('/[^A-Z0-9]/', '', $creator);
            }
            
            $source_tag = "GPX_" . $creator;

            $points = [];
            $total_dist = 0;
            $elevation_gain = 0;
            $prev_lat = $prev_lon = $prev_ele = null;
            $start_time = null;
            $end_time = null;

            // Ekstraksi Titik Koordinat & Metrik
            foreach ($xml->trk->trkseg->trkpt as $pt) {
                $lat = (float)$pt['lat'];
                $lon = (float)$pt['lon'];
                $ele = isset($pt->ele) ? (float)$pt->ele : null;
                $time = isset($pt->time) ? (string)$pt->time : null;

                if ($start_time === null && $time) $start_time = $time;
                if ($time) $end_time = $time;

                if ($prev_lat !== null) {
                    // Haversine Formula untuk Jarak
                    $theta = $prev_lon - $lon;
                    $dist = sin(deg2rad($prev_lat)) * sin(deg2rad($lat)) +  cos(deg2rad($prev_lat)) * cos(deg2rad($lat)) * cos(deg2rad($theta));
                    $dist = acos($dist);
                    $dist = rad2deg($dist);
                    $miles = $dist * 60 * 1.1515;
                    $total_dist += ($miles * 1.609344);

                    // Kalkulasi Tanjakan
                    if ($ele !== null && $prev_ele !== null) {
                        $diff = $ele - $prev_ele;
                        if ($diff > 0) $elevation_gain += $diff;
                    }
                }

                $points[] = [$lat, $lon];
                $prev_lat = $lat; $prev_lon = $lon; $prev_ele = $ele;
            }

            if (count($points) > 0) {
                // Hitung Moving Time & Speed
                $moving_time = 0;
                if ($start_time && $end_time) {
                    $moving_time = strtotime($end_time) - strtotime($start_time);
                }
                $avg_speed = ($moving_time > 0) ? ($total_dist / ($moving_time / 3600)) : 0;
                
                // Cek Sensor Peleton (Apakah ada lencana Peleton khas Kayooh?)
                $ride_name = (string)$xml->trk->name;
                if (empty($ride_name)) $ride_name = "Imported Ride " . date('d/m/Y');
                
                if (strpos($ride_name, 'Peleton') !== false) {
                    $source_tag = "PELETON_IMPORT";
                }

                $polyline = encodePolyline($points);
                $final_date = $start_time ? date('Y-m-d H:i:s', strtotime($start_time)) : date('Y-m-d H:i:s');

                try {
                    $pdo = new PDO("sqlite:" . $db_file);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    $stmt = $pdo->prepare("INSERT INTO rides (name, distance, moving_time, average_speed, total_elevation_gain, start_date, polyline, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $ride_name, round($total_dist, 2), $moving_time, round($avg_speed, 1), 
                        round($elevation_gain), $final_date, $polyline, $source_tag
                    ]);

                    $pesan = "<div class='alert success'>✅ Mantap wak! Data <b>{$creator}</b> berhasil diserap sempurna!</div>";
                    $status = 'success';
                } catch (Exception $e) {
                    $pesan = "<div class='alert error'>❌ Gagal masuk database: " . $e->getMessage() . "</div>";
                }
            } else {
                $pesan = "<div class='alert error'>❌ Filenya kosong wak, gak ada titik kordinatnya!</div>";
            }
        }
    } else {
        $pesan = "<div class='alert error'>❌ Gagal upload file, coba lagi wak!</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayooh - Import GPX</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        :root { --primary: #27ae60; --dark: #1a1a1a; }
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f7f6; color: #2c3e50; }
        .container { max-width: 500px; margin: 50px auto; padding: 20px; }
        .import-card { background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; border: 2px dashed #ddd; transition: 0.3s; }
        .import-card:hover { border-color: var(--primary); }
        .icon { font-size: 50px; margin-bottom: 20px; display: block; }
        input[type="file"] { display: none; }
        .custom-file-upload { background: var(--primary); color: white; padding: 12px 25px; border-radius: 10px; cursor: pointer; font-weight: bold; display: inline-block; margin-top: 15px; }
        .btn-submit { background: #2c3e50; color: white; border: none; padding: 12px 30px; border-radius: 10px; font-weight: bold; margin-top: 20px; cursor: pointer; width: 100%; display: none; }
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: bold; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        #file-name { display: block; margin-top: 10px; font-size: 12px; color: #7f8c8d; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="import-card">
        <span class="icon">📥</span>
        <h2 style="margin-top:0;">Import Aktivitas</h2>
        <p style="font-size: 13px; color: #7f8c8d; margin-bottom: 30px;">Silakan pilih file .gpx hasil rekaman alat gowes sampeyan (iGPSPORT, Garmin, dll).</p>
        
        <?= $pesan ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <label for="gpx_file" class="custom-file-upload">📁 PILIH FILE GPX</label>
            <input type="file" name="gpx_file" id="gpx_file" accept=".gpx" onchange="updateFileName()">
            <span id="file-name">Belum ada file dipilih</span>
            <button type="submit" id="btn-submit" class="btn-submit">🚀 SINKRONKAN SEKARANG</button>
        </form>

        <div style="margin-top: 30px;">
            <a href="dashboard.php" style="color: #7f8c8d; text-decoration: none; font-weight: bold; font-size: 13px;">&larr; KEMBALI KE DASHBOARD</a>
        </div>
    </div>
</div>

<script>
    function updateFileName() {
        const input = document.getElementById('gpx_file');
        const display = document.getElementById('file-name');
        const btnSubmit = document.getElementById('btn-submit');
        
        if (input.files && input.files.length > 0) {
            display.textContent = input.files[0].name;
            btnSubmit.style.display = 'block';
        } else {
            display.textContent = 'Belum ada file dipilih';
            btnSubmit.style.display = 'none';
        }
    }

    document.getElementById('uploadForm').addEventListener('submit', function() {
        const btn = document.getElementById('btn-submit');
        btn.textContent = "⏳ SEDANG MENGINTEROGASI FILE...";
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.7';
    });
</script>

</body>
</html>