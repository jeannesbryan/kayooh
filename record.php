<?php
// record.php - GPS Tracker Kayooh (v3.0 - Live Tracking Telegram, Dual-Interval, Peleton Radar, Auto-Flexing)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ---------------------------------------------------------
// TANGKAP ROOM ID DARI DASHBOARD (EPIC 2)
// ---------------------------------------------------------
$room_id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? 'SINGLE_MODE');

// ==========================================
// KONEKSI DATABASE & AMBIL PENGATURAN TELEGRAM
// ==========================================
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$tg_token = ''; 
$tg_chat_id = '';
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')");
    while ($row = $stmt->fetch()) {
        if ($row['setting_key'] === 'telegram_bot_token') $tg_token = $row['setting_value'];
        if ($row['setting_key'] === 'telegram_chat_id') $tg_chat_id = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Abaikan jika belum ada tabel (fallback aman jika user lupa jalankan upgrade_db)
}

// ==========================================
// HANDLER AJAX TELEGRAM (LIVE LOCATION v3.0)
// ==========================================
// Backend PHP berkomunikasi dengan API Telegram agar Token aman dari Frontend
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tg_action'])) {
    header('Content-Type: application/json');
    if (empty($tg_token) || empty($tg_chat_id)) {
        echo json_encode(['status' => 'disabled']);
        exit;
    }

    $action = $_POST['tg_action'];
    $lat = $_POST['lat'] ?? 0;
    $lon = $_POST['lon'] ?? 0;
    $msg_id = $_POST['message_id'] ?? null;
    $tg_room = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['room'] ?? 'SINGLE_MODE');
    
    $api_url = "https://api.telegram.org/bot{$tg_token}/";

    // Layer 2 (Web Radar) - Tombol Hybrid yang menempel pada Live Location, sekarang Dinamis!
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $radar_url = $protocol . $host . "/guest.php?room=" . $tg_room; 
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🌐 Buka Radar Web Kayooh', 'url' => $radar_url]]
        ]
    ];
    $reply_markup = json_encode($keyboard);

    if ($action === 'start') {
        $url = $api_url . "sendLocation";
        $data = [
            'chat_id' => $tg_chat_id,
            'latitude' => $lat,
            'longitude' => $lon,
            'live_period' => 28800, // Aktif maksimal 8 Jam
            'reply_markup' => $reply_markup
        ];
    } elseif ($action === 'update') {
        $url = $api_url . "editMessageLiveLocation";
        $data = [
            'chat_id' => $tg_chat_id,
            'message_id' => $msg_id,
            'latitude' => $lat,
            'longitude' => $lon,
            'reply_markup' => $reply_markup
        ];
    } elseif ($action === 'stop') {
        $url = $api_url . "stopMessageLiveLocation";
        $data = [
            'chat_id' => $tg_chat_id,
            'message_id' => $msg_id
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode($response, true);
    if (isset($resData['ok']) && $resData['ok']) {
        $returnMsgId = $resData['result']['message_id'] ?? $msg_id;
        echo json_encode(['status' => 'success', 'message_id' => $returnMsgId]);
    } else {
        echo json_encode(['status' => 'error', 'response' => $resData]);
    }
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
    curl_setopt($ch, CURLOPT_USERAGENT, 'KayoohTracker/3.0'); // Identitas aplikasi (Wajib untuk OSRM)
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

// Proses Simpan Data via AJAX & Auto-Flexing Telegram (EPIC 3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distance'])) {
    try {
        $raw_points = json_decode($_POST['polyline'], true);
        if (!is_array($raw_points)) $raw_points = [];
        
        $distance = (float)$_POST['distance'];
        $moving_time = (int)$_POST['moving_time'];
        $avg_speed = (float)$_POST['avg_speed'];
        $max_speed = (float)$_POST['max_speed'];
        $avg_temp = isset($_POST['avg_temp']) ? (float)$_POST['avg_temp'] : 0; 
        $active_mode = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['room_id'] ?? 'SINGLE_MODE');

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

        // =======================================================
        // EPIC 3: AUTO-DISTRIBUTION (GROUP FLEXING KE TELEGRAM)
        // =======================================================
        if (!empty($tg_token) && !empty($tg_chat_id)) {
            $f_time = gmdate("H:i:s", $moving_time);
            $f_dist = number_format($distance, 2) . " km";
            $f_avg  = number_format($avg_speed, 1) . " km/h";
            $f_max  = number_format($max_speed, 1) . " km/h";
            $f_elev = $elevation_gain . " m";
            $f_temp = $avg_temp . " °C";

            // Rakit pesan estetik
            $msg = "🏁 <b>Aktivitas Kayooh Tersimpan!</b>\n\n";
            $msg .= "🚴‍♂️ <b>{$name}</b>\n";
            $msg .= "📏 Jarak: <b>{$f_dist}</b>\n";
            $msg .= "⏱ Waktu: <b>{$f_time}</b>\n";
            $msg .= "⛰ Elevasi: <b>{$f_elev}</b>\n";
            $msg .= "⚡ Rata-rata: <b>{$f_avg}</b>\n";
            $msg .= "🚀 Maksimal: <b>{$f_max}</b>\n";
            $msg .= "🌡 Suhu: <b>{$f_temp}</b>\n";

            // Tambahkan catatan jika mode Peleton
            if ($active_mode !== 'SINGLE_MODE') {
                $msg .= "\n<i>* Catatan: Karena gowes mode Peleton, statistik akhir di atas diselaraskan mengikuti metrik GPS milik Kapten/Host.</i>";
            }

            $url_tg = "https://api.telegram.org/bot{$tg_token}/sendMessage";
            $data_tg = [
                'chat_id' => $tg_chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML'
            ];

            // Tembak pesan di background (Timeout 3 detik agar UI tidak hang)
            $ch = curl_init($url_tg);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_tg);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            curl_close($ch);
        }

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
    
    <div style="text-align: center; color: #7f8c8d; font-size: 12px; margin-top: -10px; margin-bottom: 15px; font-weight: bold;">
        Mode: <?= $room_id === 'SINGLE_MODE' ? '👤 Single Ride' : '🤝 Peleton (ID: <span style="color:var(--primary-color);">'.htmlspecialchars($room_id).'</span>)' ?>
    </div>

    <?php if ($room_id !== 'SINGLE_MODE'): ?>
    <div style="background: #e8f4f8; border: 1px dashed #3498db; padding: 8px; border-radius: 6px; text-align: center; font-size: 11px; color: #2980b9; margin-bottom: 15px; margin-top: -5px;">
        ⚠️ <b>INFO PELETON:</b> Statistik akhir yang direkam & dibagikan akan menggunakan metrik GPS Anda (Host).
    </div>
    <?php endif; ?>

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

// Variabel Suhu (Sampling Interval 15 Menit)
let tempLog = []; 
let lastTempFetchTime = 0;
let isFetchingTemp = false; // Mencegah API ketembak dobel

// --- VARIABEL DUAL-INTERVAL (TELEGRAM v3.0) ---
const isTgEnabled = <?= (!empty($tg_token) && !empty($tg_chat_id)) ? 'true' : 'false' ?>;
let tgMessageId = null;
let lastTgSyncTime = 0;

// --- VARIABEL RADAR PELETON (EPIC 2) ---
const activeRoomId = "<?= $room_id ?>";
const activeUser = "Host"; // Admin selalu Host
let lastRadarSyncTime = 0;

const btnMain = document.getElementById('btn-main');
const btnSave = document.getElementById('btn-save');
const gpsInfo = document.getElementById('gps-info');

// ---------------------------------------------------------
// FUNGSI KOMUNIKASI TELEGRAM AJAX (v3.0)
// ---------------------------------------------------------
function triggerTelegram(action, lat = 0, lon = 0) {
    if (!isTgEnabled) return;
    
    const formData = new FormData();
    formData.append('tg_action', action);
    formData.append('room', activeRoomId); // Kirim Room ID untuk link radar di Telegram
    formData.append('lat', lat);
    formData.append('lon', lon);
    if (tgMessageId) formData.append('message_id', tgMessageId);

    fetch('record.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && data.message_id) {
                tgMessageId = data.message_id;
                saveBlackBox(); // Amankan Message ID agar tidak hilang kalau ter-refresh
            }
        })
        .catch(e => console.error("Telegram API Error:", e));
}

// ---------------------------------------------------------
// FUNGSI SINKRONISASI RADAR PELETON (EPIC 2)
// ---------------------------------------------------------
function triggerRadarSync(lat, lon, speed) {
    const formData = new FormData();
    formData.append('room', activeRoomId);
    formData.append('user', activeUser);
    formData.append('lat', lat);
    formData.append('lon', lon);
    formData.append('speed', speed);

    fetch('radar_sync.php', { method: 'POST', body: formData })
        .catch(e => console.error("Radar Sync Error:", e));
}

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
            tempLog: tempLog,
            lastTempFetchTime: lastTempFetchTime,
            tgMessageId: tgMessageId,          
            lastTgSyncTime: lastTgSyncTime,    
            lastRadarSyncTime: lastRadarSyncTime // v3.0 Save Radar State
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
                
                // Pemulihan Log Suhu
                tempLog = data.tempLog || []; 
                lastTempFetchTime = data.lastTempFetchTime || 0;
                
                // Pemulihan State Telegram & Radar v3.0
                tgMessageId = data.tgMessageId || null; 
                lastTgSyncTime = data.lastTgSyncTime || 0;
                lastRadarSyncTime = data.lastRadarSyncTime || 0;

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

            // [INTERVAL BERAT] LOGIKA SAMPLING SUHU (AWAL & INTERVAL 15 MENIT / 900 DETIK)
            if (!isFetchingTemp) {
                if (tempLog.length === 0) {
                    isFetchingTemp = true;
                    fetchTemperature(latitude, longitude).then(temp => {
                        if (temp !== null) tempLog.push(temp);
                        isFetchingTemp = false;
                        saveBlackBox();
                    });
                } else if (secondsElapsed - lastTempFetchTime >= 900) {
                    isFetchingTemp = true;
                    lastTempFetchTime = secondsElapsed; 
                    fetchTemperature(latitude, longitude).then(temp => {
                        if (temp !== null) tempLog.push(temp);
                        isFetchingTemp = false;
                        saveBlackBox();
                    });
                }
            }

            // [INTERVAL MENENGAH] TELEGRAM LIVE LOCATION (1 Menit / 60 Detik)
            if (isTgEnabled && !isAutoPaused) {
                if (!tgMessageId) {
                    triggerTelegram('start', latitude, longitude);
                    lastTgSyncTime = secondsElapsed;
                } else if (secondsElapsed - lastTgSyncTime >= 60) {
                    triggerTelegram('update', latitude, longitude);
                    lastTgSyncTime = secondsElapsed;
                }
            }

            // [INTERVAL KILAT] WEB RADAR SYNC (Tiap 3 Detik)
            if (!isAutoPaused && (secondsElapsed - lastRadarSyncTime >= 3 || !lastRadarSyncTime)) {
                triggerRadarSync(latitude, longitude, currentSpeed);
                lastRadarSyncTime = secondsElapsed;
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
    
    // Terminasi Telegram Map saat Stop Ride ditekan
    if (isTgEnabled && tgMessageId) {
        triggerTelegram('stop');
    }
    
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

    // AMBIL SUHU AKHIR SEBAGAI PENUTUP SAMPLING
    if (lastCoord) {
        let endTemp = await fetchTemperature(lastCoord.lat, lastCoord.lon);
        if (endTemp !== null) {
            tempLog.push(endTemp);
        }
    }

    // HITUNG SUHU RATA-RATA KESELURUHAN
    let finalAvgTemp = 0;
    if (tempLog.length > 0) {
        let sum = tempLog.reduce((a, b) => a + b, 0);
        finalAvgTemp = sum / tempLog.length;
    }

    const avgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)) : 0;
    
    const formData = new FormData();
    formData.append('distance', totalDistance);
    formData.append('moving_time', secondsElapsed);
    formData.append('avg_speed', avgSpeed);
    formData.append('max_speed', maxSpeed);
    formData.append('avg_temp', finalAvgTemp.toFixed(1)); 
    formData.append('room_id', activeRoomId); // Tambahan EPIC 3 (Kirim ID Room saat save)
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