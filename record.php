<?php
// record.php - GPS Tracker Kayooh (Full Route, Elevation, Map Matching OSRM, Auto-Pause, Security, Dark Mode, Wake Lock, Black Box & Temp)
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

    $step = max(1, floor(count($points) / 50));
    $sampledLats = []; $sampledLngs = [];

    for ($i = 0; $i < count($points); $i += $step) {
        $sampledLats[] = round($points[$i][0], 5);
        $sampledLngs[] = round($points[$i][1], 5);
    }
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
                if ($diff > 0) { 
                    $gain += $diff;
                }
            }
        }
    }
    return round($gain);
}

// ==========================================
// FUNGSI 3: Map Matching via OSRM API
// ==========================================
function matchRouteOSRM($points) {
    if (count($points) < 2) return $points;

    // Batasi titik agar tidak ditolak oleh API OSRM (Max ~100 koordinat)
    $step = max(1, ceil(count($points) / 90));
    $sampled = [];
    for ($i = 0; $i < count($points); $i += $step) {
        // Format OSRM: lon,lat
        $sampled[] = $points[$i][1] . ',' . $points[$i][0];
    }
    if (end($sampled) !== end($points)[1] . ',' . end($points)[0]) {
        $sampled[] = end($points)[1] . ',' . end($points)[0];
    }

    $coords_string = implode(';', $sampled);
    $url = "https://router.project-osrm.org/match/v1/cycling/" . $coords_string . "?geometries=geojson&overview=full";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'KayoohTracker/2.0'); // Identitas aplikasi (Wajib untuk OSRM)
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['matchings']) && count($data['matchings']) > 0) {
            $matched_points = [];
            foreach ($data['matchings'] as $matching) {
                if (isset($matching['geometry']['coordinates'])) {
                    foreach ($matching['geometry']['coordinates'] as $coord) {
                        $matched_points[] = [$coord[1], $coord[0]]; // Kembalikan ke format lat,lon
                    }
                }
            }
            if (count($matched_points) > 0) return $matched_points;
        }
    }
    return $points; // Fallback: Jika API down atau sinyal jelek, gunakan data mentah
}

// Proses Simpan Data via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distance'])) {
    try {
        $pdo = new PDO("sqlite:" . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $raw_points = json_decode($_POST['polyline'], true);
        if (!is_array($raw_points)) $raw_points = [];
        
        $distance = (float)$_POST['distance'];
        $moving_time = (int)$_POST['moving_time'];
        $avg_speed = (float)$_POST['avg_speed'];
        $max_speed = (float)$_POST['max_speed'];
        $avg_temp = isset($_POST['avg_temp']) ? (float)$_POST['avg_temp'] : 0; 

        // KOREKSI RUTE: Tempelkan ke jalan raya (Snap to Road)
        $final_points = matchRouteOSRM($raw_points);

        $encoded_polyline = encodePolyline($final_points);
        // Elevasi dihitung berdasarkan titik yang sudah dirapikan
        $elevation_gain = calculateElevationGain($final_points); 

        $stmt = $pdo->prepare("INSERT INTO rides (
            name, distance, moving_time, average_speed, max_speed, 
            total_elevation_gain, avg_temp, start_date, polyline, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'KAYOOH')");

        $name = "Gowes " . date('d/m/Y H:i');
        $stmt->execute([
            $name,
            $distance,
            $moving_time,
            $avg_speed,
            $max_speed,
            $elevation_gain,
            $avg_temp,
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
            <a href="dashboard.php" class="logout-link" onclick="if(confirm('Batalkan rekaman ini? Data akan hilang selamanya.')) { localStorage.removeItem('kayooh_backup'); return true; } return false;">BATAL</a>
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
let totalDistance = 0;
let routePath = []; 
let lastCoord = null;
let maxSpeed = 0;
let isRecording = false;
let wakeLock = null; 
let isAutoPaused = false;

// Variabel Waktu Presisi (Timestamp)
let secondsElapsed = 0; 
let accumulatedTimeMs = 0; 
let currentStartTimeMs = 0; 

// Variabel Suhu
let startTemp = null; 
let endTemp = null;

const btnMain = document.getElementById('btn-main');
const btnSave = document.getElementById('btn-save');
const gpsInfo = document.getElementById('gps-info');

// ---------------------------------------------------------
// FUNGSI FETCH SUHU (API OPEN-METEO)
// ---------------------------------------------------------
async function fetchTemperature(lat, lon) {
    try {
        const res = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current_weather=true`);
        const data = await res.json();
        return data.current_weather ? data.current_weather.temperature : null;
    } catch (e) {
        console.error("Gagal ambil suhu:", e);
        return null;
    }
}

// ---------------------------------------------------------
// FUNGSI BLACK BOX (AUTO-SAVE)
// ---------------------------------------------------------
function saveBlackBox() {
    if (routePath.length > 0) {
        localStorage.setItem('kayooh_backup', JSON.stringify({
            distance: totalDistance,
            route: routePath,
            time: secondsElapsed,
            maxSpeed: maxSpeed,
            lastCoord: lastCoord,
            startTemp: startTemp 
        }));
    }
}

function restoreBlackBox() {
    const backup = localStorage.getItem('kayooh_backup');
    if (backup) {
        try {
            const data = JSON.parse(backup);
            if (data.route && data.route.length > 0) {
                totalDistance = data.distance || 0;
                routePath = data.route || [];
                secondsElapsed = data.time || 0;
                maxSpeed = data.maxSpeed || 0;
                lastCoord = data.lastCoord || null;
                startTemp = data.startTemp !== undefined ? data.startTemp : null; 

                accumulatedTimeMs = secondsElapsed * 1000;
                currentStartTimeMs = 0; 

                document.getElementById('display-distance').textContent = totalDistance.toFixed(2);
                const h = Math.floor(secondsElapsed / 3600).toString().padStart(2, '0');
                const m = Math.floor((secondsElapsed % 3600) / 60).toString().padStart(2, '0');
                const s = (secondsElapsed % 60).toString().padStart(2, '0');
                document.getElementById('display-time').textContent = `${h}:${m}:${s}`;
                document.getElementById('display-speed').textContent = "0.0";

                gpsInfo.innerHTML = '<span style="color: #e67e22; font-weight: bold;">⚠️ SESI DIPULIHKAN (BLACK BOX)</span>';
                btnMain.textContent = "▶️ LANJUTKAN RIDE";
                btnMain.classList.replace('btn-stop', 'btn-start');
                btnSave.style.display = 'flex';
            }
        } catch(e) {
            console.error("Gagal memulihkan Black Box", e);
        }
    }
}

// ---------------------------------------------------------
// FUNGSI TOGGLE TEMA & INISIALISASI
// ---------------------------------------------------------
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
    restoreBlackBox(); 
});

// ---------------------------------------------------------
// FUNGSI WAKE LOCK (MENCEGAH LAYAR MATI)
// ---------------------------------------------------------
async function requestWakeLock() {
    if ('wakeLock' in navigator) {
        try {
            wakeLock = await navigator.wakeLock.request('screen');
        } catch (err) {
            console.error('Gagal menahan layar:', err.message);
        }
    }
}

function releaseWakeLock() {
    if (wakeLock !== null) {
        wakeLock.release();
        wakeLock = null;
    }
}

document.addEventListener('visibilitychange', async () => {
    if (isRecording && wakeLock !== null && document.visibilityState === 'visible') {
        await requestWakeLock();
    }
});

// ---------------------------------------------------------
// FUNGSI UTAMA TRACKING (DENGAN SMART AUTO-PAUSE & TIMESTAMP TIMER)
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
    if (isRecording) {
        let totalMs = accumulatedTimeMs;
        if (!isAutoPaused && currentStartTimeMs > 0) {
            totalMs += (Date.now() - currentStartTimeMs);
        }
        
        secondsElapsed = Math.floor(totalMs / 1000);
        
        const h = Math.floor(secondsElapsed / 3600).toString().padStart(2, '0');
        const m = Math.floor((secondsElapsed % 3600) / 60).toString().padStart(2, '0');
        const s = (secondsElapsed % 60).toString().padStart(2, '0');
        document.getElementById('display-time').textContent = `${h}:${m}:${s}`;
        
        if (!isAutoPaused) {
            saveBlackBox(); 
        }
    }
}

function startTracking() {
    if (!navigator.geolocation) {
        alert("GPS tidak didukung wak!");
        return;
    }

    isRecording = true;
    isAutoPaused = false;
    currentStartTimeMs = Date.now(); 
    
    requestWakeLock();

    btnMain.textContent = "⬜ STOP RIDE";
    btnMain.classList.replace('btn-start', 'btn-stop');
    gpsInfo.innerHTML = '<span class="gps-active">● GPS AKTIF</span>';
    btnSave.style.display = 'none';
    
    timerInterval = setInterval(updateTimer, 100);

    watchId = navigator.geolocation.watchPosition((pos) => {
        const { latitude, longitude, speed, accuracy } = pos.coords;
        
        if (accuracy < 30) {
            const currentSpeed = speed ? (speed * 3.6) : 0;
            document.getElementById('display-speed').textContent = currentSpeed.toFixed(1);
            if (currentSpeed > maxSpeed) maxSpeed = currentSpeed;

            if (startTemp === null) {
                startTemp = 'fetching'; 
                fetchTemperature(latitude, longitude).then(temp => {
                    startTemp = temp;
                    saveBlackBox();
                });
            }

            if (currentSpeed < 1.5) { 
                if (!isAutoPaused && isRecording) {
                    isAutoPaused = true;
                    if (currentStartTimeMs > 0) {
                        accumulatedTimeMs += (Date.now() - currentStartTimeMs);
                        currentStartTimeMs = 0;
                    }
                    gpsInfo.innerHTML = '<span style="color: #e67e22; font-weight: bold;">⏸️ AUTO-PAUSED</span>';
                }
            } else {
                if (isAutoPaused && isRecording) {
                    isAutoPaused = false;
                    currentStartTimeMs = Date.now();
                    gpsInfo.innerHTML = '<span class="gps-active">● RESUMED</span>';
                }

                if (lastCoord) {
                    const dist = calculateDistance(lastCoord.lat, lastCoord.lon, latitude, longitude);
                    if (dist > 0.002) {
                        totalDistance += dist;
                        document.getElementById('display-distance').textContent = totalDistance.toFixed(2);
                        routePath.push([latitude, longitude]); 
                        lastCoord = { lat: latitude, lon: longitude }; 
                        saveBlackBox(); 
                    }
                } else {
                    routePath.push([latitude, longitude]);
                    lastCoord = { lat: latitude, lon: longitude };
                    saveBlackBox(); 
                }
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
    
    releaseWakeLock();
    
    if (!isAutoPaused && currentStartTimeMs > 0) {
        accumulatedTimeMs += (Date.now() - currentStartTimeMs);
        currentStartTimeMs = 0;
    }
    secondsElapsed = Math.floor(accumulatedTimeMs / 1000); 
    
    btnMain.style.display = 'none';
    btnSave.style.display = 'flex';
    gpsInfo.textContent = "Rekaman Berhenti. Silakan Simpan.";
}

btnMain.addEventListener('click', () => {
    if (!isRecording) startTracking();
    else stopTracking();
});

btnSave.addEventListener('click', async () => {
    btnSave.textContent = "⏳ MENGOREKSI RUTE & SUHU...";
    btnSave.style.opacity = "0.7";
    btnSave.style.pointerEvents = "none";

    // AMBIL SUHU AKHIR
    if (lastCoord) {
        endTemp = await fetchTemperature(lastCoord.lat, lastCoord.lon);
    }

    // HITUNG SUHU RATA-RATA
    let finalAvgTemp = 0;
    if (typeof startTemp === 'number' && typeof endTemp === 'number') {
        finalAvgTemp = (startTemp + endTemp) / 2;
    } else if (typeof startTemp === 'number') {
        finalAvgTemp = startTemp;
    } else if (typeof endTemp === 'number') {
        finalAvgTemp = endTemp;
    }

    const avgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)) : 0;
    
    const formData = new FormData();
    formData.append('distance', totalDistance);
    formData.append('moving_time', secondsElapsed);
    formData.append('avg_speed', avgSpeed);
    formData.append('max_speed', maxSpeed);
    formData.append('avg_temp', finalAvgTemp.toFixed(1)); 
    formData.append('polyline', JSON.stringify(routePath));

    fetch('record.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            localStorage.removeItem('kayooh_backup'); 
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