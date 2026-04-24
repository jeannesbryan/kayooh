<?php
// gpx_import.php - Mesin Ekstraktor GPX Luring (Offline Importer v4.0 + Sensor Peleton)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$pesan = '';
$status = 'standby';

// ==========================================
// FUNGSI 1: Polyline Encoder
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
// FUNGSI 2: Kalkulator Jarak Haversine (Akurasi Tinggi)
// ==========================================
function hitungJarakMeters($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // Radius Bumi dalam meter
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// ==========================================
// MESIN UTAMA: PROSES UPLOAD & PARSING GPX
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gpx_file'])) {
    $file = $_FILES['gpx_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'gpx') {
            $pesan = "Maaf wak, format file harus .gpx!";
            $status = 'error';
        } else {
            // Muat file XML (GPX)
            $xml = simplexml_load_file($file['tmp_name']);
            if ($xml === false) {
                $pesan = "Gagal membaca struktur file GPX. Pastikan file tidak korup.";
                $status = 'error';
            } else {
                $trkpts = $xml->xpath('//*[local-name()="trkpt"]');
                
                if (count($trkpts) < 2) {
                    $pesan = "File GPX tidak memiliki data titik koordinat (trkpt) yang valid.";
                    $status = 'error';
                } else {
                    $points = [];
                    $total_distance_m = 0;
                    $total_elevation_gain = 0;
                    $max_speed_kmh = 0;
                    
                    $start_time = 0;
                    $end_time = 0;
                    
                    $prev_lat = null;
                    $prev_lon = null;
                    $prev_ele = null;
                    $prev_time = null;

                    // --- 1. AMBIL NAMA RUTE ---
                    $ride_name = "Gowes Impor " . date('d/m/Y');
                    $name_node = $xml->xpath('//*[local-name()="trk"]/*[local-name()="name"]');
                    if (!empty($name_node)) {
                        $ride_name = (string) $name_node[0];
                    }

                    // --- 2. SENSOR METADATA PELETON (FITUR BARU) ---
                    $source_db = 'GPX_IMPORT'; // Default
                    $desc_node = $xml->xpath('//*[local-name()="trk"]/*[local-name()="desc"]');
                    
                    if (!empty($desc_node)) {
                        $desc_text = (string) $desc_node[0];
                        
                        // Deteksi jika ini adalah file GPX hasil gowes mabar
                        if (strpos($desc_text, 'Peleton Mode') !== false) {
                            $source_db = 'PELETON_IMPORT';
                            
                            // Ekstrak info peserta ("Kapten [X], Peserta: [Y, Z]")
                            $info_peleton = str_replace("Peleton Mode: ", "", $desc_text);
                            
                            // Suntikkan lencana dan info peserta ke dalam nama rute
                            $ride_name = "👥 " . $ride_name . " | " . $info_peleton;
                        }
                    }
                    // -----------------------------------------------

                    // Looping Pembedahan Titik Koordinat
                    foreach ($trkpts as $pt) {
                        $lat = (float) $pt['lat'];
                        $lon = (float) $pt['lon'];
                        $points[] = [$lat, $lon];

                        // Ekstrak Elevasi
                        $ele_node = $pt->xpath('*[local-name()="ele"]');
                        $ele = !empty($ele_node) ? (float) $ele_node[0] : 0;

                        // Ekstrak Waktu
                        $time_node = $pt->xpath('*[local-name()="time"]');
                        $time = !empty($time_node) ? strtotime((string) $time_node[0]) : 0;

                        if ($start_time === 0 && $time > 0) $start_time = $time;
                        if ($time > 0) $end_time = $time;

                        // Hitung Delta (Jarak, Kecepatan, Elevasi Naik)
                        if ($prev_lat !== null && $prev_lon !== null) {
                            $jarak_titik = hitungJarakMeters($prev_lat, $prev_lon, $lat, $lon);
                            $total_distance_m += $jarak_titik;

                            if ($prev_ele !== null && $ele > $prev_ele) {
                                $total_elevation_gain += ($ele - $prev_ele);
                            }

                            if ($prev_time !== null && $time > $prev_time) {
                                $time_diff = $time - $prev_time;
                                $speed_ms = $jarak_titik / $time_diff;
                                $speed_kmh = $speed_ms * 3.6;
                                if ($speed_kmh > $max_speed_kmh && $speed_kmh < 120) {
                                    $max_speed_kmh = $speed_kmh;
                                }
                            }
                        }

                        $prev_lat = $lat;
                        $prev_lon = $lon;
                        $prev_ele = $ele;
                        $prev_time = $time;
                    }

                    // Finalisasi Data
                    $distance_km = $total_distance_m / 1000;
                    $moving_time = ($end_time > $start_time) ? ($end_time - $start_time) : 0;
                    $avg_speed = ($moving_time > 0) ? ($distance_km / ($moving_time / 3600)) : 0;
                    $encoded_polyline = encodePolyline($points);
                    
                    $db_start_date = ($start_time > 0) ? date('Y-m-d H:i:s', $start_time) : date('Y-m-d H:i:s');

                    // --- INSERT KE SQLITE ---
                    try {
                        $pdo = new PDO("sqlite:" . $db_file);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        
                        // Perhatikan: parameter source (?) terakhir sekarang menggunakan $source_db dinamis
                        $stmt = $pdo->prepare("INSERT INTO rides (name, distance, moving_time, average_speed, max_speed, total_elevation_gain, start_date, polyline, source) 
                                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $ride_name,
                            $distance_km,
                            $moving_time,
                            $avg_speed,
                            $max_speed_kmh,
                            round($total_elevation_gain),
                            $db_start_date,
                            $encoded_polyline,
                            $source_db
                        ]);

                        $pesan = "Sukses wak! Rute berhasil diekstrak dan masuk ke database.";
                        $status = 'success';
                    } catch (PDOException $e) {
                        $pesan = "Database Error: " . $e->getMessage();
                        $status = 'error';
                    }
                }
            }
        }
    } else {
        $pesan = "Terjadi kesalahan saat mengunggah file. Kode error: " . $file['error'];
        $status = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import GPX - Kayooh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <style>
        .upload-area {
            border: 2px dashed var(--primary-color); border-radius: 12px;
            padding: 30px 20px; text-align: center; background: rgba(52, 152, 219, 0.05);
            margin-top: 20px; margin-bottom: 20px; cursor: pointer; transition: all 0.3s ease;
        }
        .upload-area:hover { background: rgba(52, 152, 219, 0.1); }
        .upload-area input[type="file"] { display: none; }
        .file-name-display { font-family: monospace; color: #e67e22; font-weight: bold; margin-top: 10px; word-break: break-all; }
    </style>
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

    <div style="position: absolute; top: 20px; right: 20px; z-index: 100;">
        <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
    </div>

<div class="strava-container">
    <div class="box" style="margin:0 auto; position: relative;">
        <img src="assets/kayooh.png" alt="Kayooh" style="height: 40px; margin-bottom: 10px;">
        <h2 style="margin-bottom: 5px;">Import <span style="color: #27ae60;">GPX</span> Luring</h2>
        <p style="font-size: 12px; color: var(--text-color); opacity: 0.7; margin-top: 0;">
            Ekstrak data mandiri atau File Mabar Peleton.
        </p>
        
        <?php if ($pesan): ?>
            <div class="<?= $status === 'success' ? 'alert alert-success' : 'alert alert-danger' ?>" style="margin-top: 15px;">
                <?= htmlspecialchars($pesan) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <label class="upload-area" for="gpx_file">
                <div style="font-size: 40px; margin-bottom: 10px;">📥</div>
                <div style="font-weight: bold; color: var(--text-color);">Tap untuk Memilih File .GPX</div>
                <div style="font-size: 11px; color: #7f8c8d; margin-top: 5px;">Maksimal 10MB</div>
                <div id="file-name" class="file-name-display">Belum ada file dipilih</div>
                
                <input type="file" name="gpx_file" id="gpx_file" accept=".gpx" required onchange="updateFileName()">
            </label>

            <button type="submit" id="btn-submit" class="btn-primary" style="background-color: #27ae60; width: 100%; display: none;">
                ⚙️ PROSES & SIMPAN RUTE
            </button>
        </form>

        <div style="margin-top: 25px; text-align: center;">
            <a href="dashboard.php" style="color: var(--text-color); text-decoration: none; font-weight: bold; font-size: 13px; opacity: 0.7;">&larr; KEMBALI KE DASHBOARD</a>
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
        btn.textContent = "⏳ MENGEKSTRAK KOORDINAT...";
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.7';
    });

    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';
    }
    
    window.addEventListener('DOMContentLoaded', () => { 
        if(localStorage.getItem('theme') === 'dark') {
            document.getElementById('theme-icon').textContent = '☀️';
        }
    });
</script>

</body>
</html>