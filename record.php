<?php
// record.php - GPS Tracker Kayooh (Full Route, Elevation API, Auto-Pause, Security, Dark Mode & Wake Lock)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ==========================================
// FUNGSI 1: Polyline Encoder (Strava Format)
// ==========================================
function encodePolyline($points) {
    $encodedString = '';
    $prevLat = 0; $prevLng = 0;
    foreach ($points as $point) {
        $lat = round($point[0] * 1e5);
        $lng = round($point[1] * 1e5);
        $dLat = $lat - $prevLat;
        $dLng = $lng - $prevLng;
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
// FUNGSI 2: Hitung Elevasi via API Open-Meteo
// ==========================================
function calculateElevationGain($points) {
    if (empty($points)) return 0;

    // Downsampling: Ambil maksimal 50 titik sampel agar URL API tidak terlalu panjang
    $step = max(1, floor(count($points) / 50));
    $sampledLats = []; $sampledLngs = [];

    for ($i = 0; $i < count($points); $i += $step) {
        $sampledLats[] = round($points[$i][0], 5);
        $sampledLngs[] = round($points[$i][1], 5);
    }
    // Pastikan titik terakhir selalu ikut
    if (end($sampledLats) != round(end($points)[0], 5)) {
        $sampledLats[] = round(end($points)[0], 5);
        $sampledLngs[] = round(end($points)[1], 5);
    }

    $url = "https://api.open-meteo.com/v1/elevation?latitude=" . implode(',', $sampledLats) . "&longitude=" . implode(',', $sampledLngs);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $gain = 0;
    if ($http_code == 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['elevation']) && is_array($data['elevation'])) {
            $elevations = $data['elevation'];
            for ($i = 1; $i < count($elevations); $i++) {
                $diff = $elevations[$i] - $elevations[$i - 1];
                if ($diff > 0) { // Hanya hitung tanjakan (positif)
                    $gain += $diff;
                }
            }
        }
    }
    return round($gain);
}

// Proses Simpan Data via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distance'])) {
    try {
        $pdo = new PDO("sqlite:" . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // FITUR BENTENG KEAMANAN: Type Casting ketat dan sanitasi array
        $raw_points = json_decode($_POST['polyline'], true);
        if (!is_array($raw_points)) $raw_points = [];
        
        $distance = (float)$_POST['distance'];
        $moving_time = (int)$_POST['moving_time'];
        $avg_speed = (float)$_POST['avg_speed'];
        $max_speed = (float)$_POST['max_speed'];

        // Eksekusi Poin 7 & 8
        $encoded_polyline = encodePolyline($raw_points);
        $elevation_gain = calculateElevationGain($raw_points);

        $stmt = $pdo->prepare("INSERT INTO rides (
            name, distance, moving_time, average_speed, max_speed, 
            total_elevation_gain, start_date, polyline, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'KAYOOH')");

        $name = "Gowes " . date('d/m/Y H:i'); // Penamaan rute otomatis & aman
        $stmt->execute([
            $name,
            $distance,
            $moving_time,
            $avg_speed,
            $max_speed,
            $elevation_gain,
            date('Y-m-d H:i:s'),
            $encoded_polyline
        ]);

        echo json_encode(['status' => 'success']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Record Ride - Kayooh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
</head>
<body style="background-color: var(--background-color);">
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

<div class="record-screen">
    <div class="header" style="margin-bottom: 10px;">
        <img src="assets/kayooh.png" alt="Kayooh" class="nav-logo">
        <div class="header-actions">
            <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
            <a href="dashboard.php" class="logout-link" onclick="return confirm('Batalkan rekaman ini?')">BATAL</a>
        </div>
    </div>

    <div id="gps-info" class="gps-status">Mencari sinyal GPS...</div>
    <div class="timer-display" id="display-time">00:00:00</div>

    <div class="big-stats">
        <div class="stat-box">
            <label>JARAK (KM)</label>
            <div id="display-distance">0.00</div>
        </div>
        <div class="stat-box">
            <label>SPEED (KM/H)</label>
            <div id="display-speed">0.0</div>
        </div>
    </div>

    <div class="controls">
        <button id="btn-main" class="btn-record-main btn-start">🔴 START RIDE</button>
        <button id="btn-save" class="btn-record-main btn-save" style="display: none;">💾 SIMPAN AKTIVITAS</button>
    </div>
</div>

<script>
let watchId = null;
let timerInterval = null;
let totalDistance = 0; // dalam km
let routePath = []; // Array untuk menyimpan titik koordinat
let lastCoord = null;
let maxSpeed = 0;
let isRecording = false;
let secondsElapsed = 0;
let wakeLock = null; // Variabel penahan layar
let isAutoPaused = false; // FITUR AUTO-PAUSE

const btnMain = document.getElementById('btn-main');
const btnSave = document.getElementById('btn-save');
const gpsInfo = document.getElementById('gps-info');

// ---------------------------------------------------------
// FUNGSI TOGGLE TEMA
// ---------------------------------------------------------
function toggleTheme() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';
}
window.onload = () => { 
    if(localStorage.getItem('theme') === 'dark') {
        document.getElementById('theme-icon').textContent = '☀️';
    }
}

// ---------------------------------------------------------
// FUNGSI WAKE LOCK (MENCEGAH LAYAR MATI)
// ---------------------------------------------------------
async function requestWakeLock() {
    if ('wakeLock' in navigator) {
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            console.log('Wake Lock aktif! Layar tidak akan mati.');
            wakeLock.addEventListener('release', () => {
                console.log('Wake Lock dilepas oleh sistem.');
            });
        } catch (err) {
            console.error('Gagal menahan layar:', err.message);
        }
    } else {
        console.warn('Browser ini tidak mendukung Wake Lock API.');
    }
}

function releaseWakeLock() {
    if (wakeLock !== null) {
        wakeLock.release();
        wakeLock = null;
    }
}

// Tangkap event jika browser di-minimize lalu dibuka lagi
document.addEventListener('visibilitychange', async () => {
    if (isRecording && wakeLock !== null && document.visibilityState === 'visible') {
        await requestWakeLock();
    }
});

// ---------------------------------------------------------
// FUNGSI UTAMA TRACKING (DENGAN SMART AUTO-PAUSE)
// ---------------------------------------------------------
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; 
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

function updateTimer() {
    // FITUR AUTO-PAUSE: Timer hanya dihitung jika sepeda sedang berjalan
    if (!isAutoPaused && isRecording) {
        secondsElapsed++;
        const h = Math.floor(secondsElapsed / 3600).toString().padStart(2, '0');
        const m = Math.floor((secondsElapsed % 3600) / 60).toString().padStart(2, '0');
        const s = (secondsElapsed % 60).toString().padStart(2, '0');
        document.getElementById('display-time').textContent = `${h}:${m}:${s}`;
    }
}

function startTracking() {
    if (!navigator.geolocation) {
        alert("GPS tidak didukung wak!");
        return;
    }

    isRecording = true;
    isAutoPaused = false;
    
    // Aktifkan penahan layar!
    requestWakeLock();

    btnMain.textContent = "⬜ STOP RIDE";
    btnMain.classList.replace('btn-start', 'btn-stop');
    gpsInfo.innerHTML = '<span class="gps-active">● GPS AKTIF</span>';
    
    timerInterval = setInterval(updateTimer, 1000);

    watchId = navigator.geolocation.watchPosition((pos) => {
        const { latitude, longitude, speed, accuracy } = pos.coords;
        
        // Hanya simpan titik jika akurasinya cukup bagus (< 30 meter)
        if (accuracy < 30) {
            // Update Kecepatan Real-time (m/s ke km/h)
            const currentSpeed = speed ? (speed * 3.6) : 0;
            document.getElementById('display-speed').textContent = currentSpeed.toFixed(1);
            if (currentSpeed > maxSpeed) maxSpeed = currentSpeed;

            // LOGIKA SMART AUTO-PAUSE
            if (currentSpeed < 1.5) { 
                // Jika kecepatan sangat rendah (< 1.5 km/h), anggap sedang berhenti / lampu merah
                if (!isAutoPaused && isRecording) {
                    isAutoPaused = true;
                    gpsInfo.innerHTML = '<span style="color: #e67e22; font-weight: bold;">⏸️ AUTO-PAUSED</span>';
                }
            } else {
                // Jika mulai bergerak kembali
                if (isAutoPaused && isRecording) {
                    isAutoPaused = false;
                    gpsInfo.innerHTML = '<span class="gps-active">● RESUMED</span>';
                }

                // Update Jarak dan Simpan Titik Koordinat (hanya jika bergerak)
                if (lastCoord) {
                    const dist = calculateDistance(lastCoord.lat, lastCoord.lon, latitude, longitude);
                    // Filter noise: Hanya rekam jika bergerak lebih dari 5 meter
                    if (dist > 0.005) {
                        totalDistance += dist;
                        document.getElementById('display-distance').textContent = totalDistance.toFixed(2);
                        routePath.push([latitude, longitude]); // Simpan jejak
                    }
                } else {
                    // Titik awal
                    routePath.push([latitude, longitude]);
                }
                lastCoord = { lat: latitude, lon: longitude };
            }
        }
    }, (err) => {
        console.error(err);
    }, {
        enableHighAccuracy: true,
        timeout: 5000,
        maximumAge: 0
    });
}

function stopTracking() {
    isRecording = false;
    clearInterval(timerInterval);
    navigator.geolocation.clearWatch(watchId);
    
    // Lepaskan penahan layar
    releaseWakeLock();
    
    btnMain.style.display = 'none';
    btnSave.style.display = 'flex';
    gpsInfo.textContent = "Rekaman Berhenti. Silakan Simpan.";
}

btnMain.addEventListener('click', () => {
    if (!isRecording) startTracking();
    else stopTracking();
});

btnSave.addEventListener('click', () => {
    btnSave.textContent = "⏳ MENGHITUNG ELEVASI...";
    btnSave.style.opacity = "0.7";
    btnSave.style.pointerEvents = "none";

    const avgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)) : 0;
    
    const formData = new FormData();
    formData.append('distance', totalDistance);
    formData.append('moving_time', secondsElapsed);
    formData.append('avg_speed', avgSpeed);
    formData.append('max_speed', maxSpeed);
    formData.append('polyline', JSON.stringify(routePath));

    fetch('record.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            window.location.href = 'dashboard.php';
        } else {
            alert("Gagal simpan: " + data.message);
            btnSave.textContent = "💾 SIMPAN AKTIVITAS";
            btnSave.style.opacity = "1";
            btnSave.style.pointerEvents = "auto";
        }
    });
});
</script>

</body>
</html>