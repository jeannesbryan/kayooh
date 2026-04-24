<?php
// guest.php - Layar Pemantauan & Mesin Pelacak Guest (Peleton GPX Generator v4.0)
$room = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? '');
if (empty($room)) {
    die("<h2 style='text-align:center; font-family:sans-serif; color:#e74c3c; margin-top:50px;'>⛔ Room ID tidak valid atau sesi telah berakhir.</h2>");
}

$log_dir = __DIR__ . '/radar_logs';

// ==========================================
// API INTERNAL 1: Menyediakan data untuk Peta
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $riders = [];
    if (is_dir($log_dir)) {
        $files = glob("{$log_dir}/{$room}_*.json");
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && (time() - $data['last_update'] < 900)) {
                $riders[] = $data;
            }
        }
    }
    echo json_encode($riders);
    exit;
}

// ==========================================
// API INTERNAL 2: Cek Trigger Broadcast
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '2') {
    header('Content-Type: application/json');
    $broadcast_file = $log_dir . '/' . $room . '_broadcast.json';
    if (file_exists($broadcast_file)) {
        $broadcast_data = json_decode(file_get_contents($broadcast_file), true);
        echo json_encode(['status' => 'ready', 'url' => $broadcast_data['url']]);
    } else {
        echo json_encode(['status' => 'waiting']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kayooh Live Tracker - <?= htmlspecialchars($room) ?></title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style>
        body, html { 
            margin: 0; padding: 0; height: 100%; 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
            background-color: #f4f4f9; 
        }
        
        #map { height: 100vh; width: 100vw; z-index: 1; }
        
        /* Layar Join & Finish (Overlay) */
        .overlay-screen {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(244, 244, 249, 0.95); backdrop-filter: blur(10px);
            z-index: 9999; display: flex; align-items: center; 
            justify-content: center; flex-direction: column;
        }
        
        .box-panel {
            background: white; padding: 30px; border-radius: 15px; 
            text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
            width: 85%; max-width: 350px;
        }
        
        .join-input {
            width: 100%; padding: 12px; margin: 15px 0; 
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 16px; box-sizing: border-box; text-align: center;
        }
        
        .btn-primary {
            background: #3b82f6; color: white; border: none; 
            padding: 14px; width: 100%; border-radius: 8px; 
            font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.2s; margin-bottom: 10px;
        }
        .btn-primary:active { background: #2563eb; transform: scale(0.98); }
        
        .btn-success { background: #10b981; }
        .btn-success:active { background: #059669; }

        /* Panel Info Floating */
        .radar-panel {
            position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%);
            width: 90%; max-width: 400px; background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px); border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 15px; color: #334155; z-index: 1000; box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .radar-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 10px;
        }
        
        .radar-header h3 { margin: 0; font-size: 16px; color: #0f172a; display: flex; align-items: center; gap: 6px; }
        
        .pulse { 
            display: inline-block; width: 8px; height: 8px; background-color: #ef4444; 
            border-radius: 50%; animation: pulse 1.5s infinite; 
        }
        @keyframes pulse { 
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 
            70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); } 
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } 
        }
        
        .rider-list { display: flex; flex-direction: column; gap: 8px; max-height: 150px; overflow-y: auto; }
        .rider-item {
            display: flex; justify-content: space-between; align-items: center;
            background: #f8fafc; padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0;
        }
        .rider-name { font-weight: bold; font-size: 14px; color: #1e293b; }
        .rider-stats { text-align: right; }
        .rider-speed { font-family: monospace; color: #0ea5e9; font-size: 14px; font-weight: bold; display: block; }
        .rider-distance { font-size: 11px; color: #64748b; font-weight: bold; }
        
        /* Custom Marker CSS */
        .custom-marker { background-color: #3b82f6; border: 2px solid white; border-radius: 50%; width: 16px; height: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .host-marker { background-color: #ef4444; width: 18px; height: 18px; }
        .me-marker { background-color: #10b981; } 

        .hidden { display: none !important; }
    </style>
</head>
<body>

<div id="join-overlay" class="overlay-screen">
    <div class="box-panel">
        <h2 style="margin-top:0; color:#0f172a; font-size:22px;">🤝 Gabung Peleton</h2>
        <p style="color:#64748b; font-size:13px;">Sinyal GPS Anda akan disiarkan ke rombongan. Data rute akan direkam otomatis di HP Anda.</p>
        <div style="background:#e8f4f8; padding:5px; border-radius:6px; font-weight:bold; color:#0ea5e9; font-size:14px;">ROOM: <?= htmlspecialchars($room) ?></div>
        <input type="text" id="guest-name" class="join-input" placeholder="Masukkan Nama Anda" autocomplete="off" maxlength="15">
        <button onclick="joinPeleton()" class="btn-primary">🚀 MULAI GOWES</button>
        <div id="join-error" style="color:#ef4444; font-size:12px; margin-top:10px; font-weight:bold;"></div>
    </div>
</div>

<div id="finish-overlay" class="overlay-screen hidden">
    <div class="box-panel">
        <div style="font-size: 45px; margin-bottom: 10px;">🏁</div>
        <h2 style="margin-top:0; color:#0f172a; font-size:22px;">Peleton Selesai!</h2>
        <p style="color:#64748b; font-size:13px; margin-bottom: 20px;">Kapten telah menyudahi sesi. Ini statistik pribadi Anda:</p>
        
        <div style="display: flex; justify-content: space-around; background: #f8fafc; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #e2e8f0;">
            <div>
                <div style="font-size: 11px; color: #64748b; font-weight: bold;">JARAK TOTAL</div>
                <div style="font-size: 20px; color: #10b981; font-weight: bold;" id="final-distance">0.0 km</div>
            </div>
        </div>

        <button onclick="downloadGPX()" class="btn-primary btn-success">📥 AMANKAN GPX SAYA</button>
        <button id="btn-view-photo" class="btn-primary" style="background: #334155;">📸 LIHAT FOTO PELETON</button>
        
        <div style="font-size: 11px; color: #94a3b8; margin-top: 15px; text-align: left;">
            *Unduh file GPX Anda, lalu impor ke aplikasi Kayooh/Strava pribadi Anda. Data Peleton sudah tertanam di dalamnya.
        </div>
    </div>
</div>

<div id="map"></div>

<div class="radar-panel">
    <div class="radar-header">
        <h3><span class="pulse" id="pulse-indicator"></span> LIVE TRACKING</h3>
        <span style="font-size: 12px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">ID: <b><?= htmlspecialchars($room) ?></b></span>
    </div>
    <div class="rider-list" id="rider-container">
        <div style="text-align: center; font-size: 12px; color: #64748b;">Menunggu sinyal masuk...</div>
    </div>
</div>

<script>
    // --- VARIABEL GLOBAL GUEST ---
    let guestName = '';
    let watchId = null;
    let wakeLock = null;
    let isTracking = false;
    let radarInterval = null;
    let broadcastInterval = null;
    let allRidersList = []; 
    let hostNameStr = 'Host'; // Default

    // --- VARIABEL KALKULATOR BLACK BOX ---
    let totalDistanceMeters = 0;
    let prevLat = null;
    let prevLon = null;
    let startTimeIso = null;
    let routeArray = []; // Menyimpan {lat, lon, ele, time}

    // --- FUNGSI WAKE LOCK ---
    async function requestWakeLock() {
        if ('wakeLock' in navigator) {
            try { wakeLock = await navigator.wakeLock.request('screen'); } 
            catch (err) { console.error('Gagal menahan layar:', err.message); }
        }
    }
    function releaseWakeLock() {
        if (wakeLock !== null) { wakeLock.release(); wakeLock = null; }
    }
    document.addEventListener('visibilitychange', async () => {
        if (isTracking && document.visibilityState === 'visible') { await requestWakeLock(); }
    });

    // --- RUMUS HAVERSINE (KALKULATOR JARAK) ---
    function hitungJarakMeters(lat1, lon1, lat2, lon2) {
        const R = 6371e3;
        const p1 = lat1 * Math.PI/180;
        const p2 = lat2 * Math.PI/180;
        const dp = (lat2-lat1) * Math.PI/180;
        const dl = (lon2-lon1) * Math.PI/180;
        const a = Math.sin(dp/2) * Math.sin(dp/2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl/2) * Math.sin(dl/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    // --- 1. LOGIKA GUEST MASUK PELETON ---
    function joinPeleton() {
        const inputName = document.getElementById('guest-name').value.trim();
        const errorDiv = document.getElementById('join-error');
        
        if(inputName === '') { errorDiv.innerText = 'Nama tidak boleh kosong wak!'; return; }
        if(inputName.toLowerCase() === 'host' || inputName.toLowerCase() === 'kapten') {
            errorDiv.innerText = 'Nama dilarang karena bentrok dengan role Kapten.'; return;
        }
        
        guestName = inputName;
        document.getElementById('join-overlay').classList.add('hidden');
        
        isTracking = true;
        startTimeIso = new Date().toISOString();
        requestWakeLock();
        startGuestTracking();
        
        radarInterval = setInterval(fetchRadarData, 3000);
        broadcastInterval = setInterval(checkBroadcast, 5000);
    }

    // --- 2. MESIN PELACAK & PEREKAM (BLACK BOX GUEST) ---
    function startGuestTracking() {
        if (!navigator.geolocation) { alert('GPS tidak didukung.'); return; }

        watchId = navigator.geolocation.watchPosition((pos) => {
            const lat = pos.coords.latitude;
            const lon = pos.coords.longitude;
            const ele = pos.coords.altitude || 0;
            const speed = pos.coords.speed ? (pos.coords.speed * 3.6) : 0;
            const timeStr = new Date(pos.timestamp).toISOString();

            // Perekaman Rute ke Memori
            routeArray.push({ lat: lat, lon: lon, ele: ele, time: timeStr });

            // Kalkulasi Jarak Pribadi
            if (prevLat !== null && prevLon !== null) {
                totalDistanceMeters += hitungJarakMeters(prevLat, prevLon, lat, lon);
            }
            prevLat = lat; prevLon = lon;

            // Pancarkan Data ke Server Host
            const formData = new FormData();
            formData.append('room', '<?= htmlspecialchars($room) ?>');
            formData.append('user', guestName);
            formData.append('lat', lat);
            formData.append('lon', lon);
            formData.append('speed', speed);
            formData.append('distance', totalDistanceMeters);

            fetch('radar_sync.php', { method: 'POST', body: formData }).catch(e => console.error(e));
        }, (err) => { console.warn('GPS Gagal:', err); }, { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 });
    }

    // --- 3. MESIN RADAR (VIEWER) ---
    const map = L.map('map', { zoomControl: false }).setView([-6.200000, 106.816666], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap', maxZoom: 19 }).addTo(map);
    let markers = {}; let isFirstLoad = true;

    function fetchRadarData() {
        fetch('?room=<?= $room ?>&ajax=1')
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('rider-container');
                container.innerHTML = '';
                if (data.length === 0) return;

                let bounds = [];
                allRidersList = []; // Reset daftar untuk metadata GPX

                data.forEach(rider => {
                    const latlng = [rider.lat, rider.lon];
                    bounds.push(latlng);
                    
                    const isHost = rider.user.toLowerCase() === 'host' || rider.user.toLowerCase() === 'kapten';
                    const isMe = rider.user === guestName;
                    const speedKmh = rider.speed.toFixed(1);
                    
                    // Simpan nama aslinya untuk GPX
                    allRidersList.push(rider.user);
                    if (isHost) hostNameStr = rider.user;

                    // Jika ini pengguna sendiri, ambil jarak dari memori HP-nya (Lebih Akurat)
                    let distDisplay = '';
                    if (isMe) {
                        distDisplay = `<span class="rider-distance">${(totalDistanceMeters / 1000).toFixed(2)} km</span>`;
                    }

                    container.innerHTML += `
                        <div class="rider-item" ${isMe ? 'style="border-left: 4px solid #10b981;"' : ''}>
                            <div>
                                <span class="rider-name">${isHost ? '🏁 ' : '🚴 '}${rider.user} ${isMe ? '(Anda)' : ''}</span>
                            </div>
                            <div class="rider-stats">
                                <span class="rider-speed">${speedKmh} km/h</span>
                                ${distDisplay}
                            </div>
                        </div>
                    `;

                    if (markers[rider.user]) {
                        markers[rider.user].setLatLng(latlng);
                    } else {
                        let iconClass = 'custom-marker';
                        if (isHost) iconClass += ' host-marker';
                        else if (isMe) iconClass += ' me-marker';
                        
                        const customIcon = L.divIcon({ className: iconClass, iconSize: isHost ? [18, 18] : [16, 16] });
                        markers[rider.user] = L.marker(latlng, { icon: customIcon }).addTo(map);
                    }
                });

                if (isFirstLoad && bounds.length > 0) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                    isFirstLoad = false;
                }
            }).catch(err => console.error("Radar Error:", err));
    }

    // --- 4. POLLING PENYELESAIAN (BROADCAST) ---
    function checkBroadcast() {
        fetch('?room=<?= $room ?>&ajax=2')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'ready' && data.url) {
                    // STOP SEMUA MESIN KETIKA HOST SELESAI
                    isTracking = false;
                    clearInterval(radarInterval);
                    clearInterval(broadcastInterval);
                    navigator.geolocation.clearWatch(watchId);
                    releaseWakeLock();
                    document.getElementById('pulse-indicator').style.animation = 'none';
                    document.getElementById('pulse-indicator').style.backgroundColor = '#94a3b8';

                    // Tampilkan Layar Selesai
                    document.getElementById('final-distance').innerText = (totalDistanceMeters / 1000).toFixed(2) + " km";
                    document.getElementById('finish-overlay').classList.remove('hidden');
                    
                    // Fungsikan Tombol Lihat Foto
                    document.getElementById('btn-view-photo').onclick = () => { window.location.href = data.url; };
                }
            }).catch(err => console.error("Broadcast Check Error:", err));
    }

    // --- 5. MESIN GENERATOR METADATA GPX LURING ---
    function downloadGPX() {
        if (routeArray.length === 0) { alert('Jejak kosong, Anda belum bergerak!'); return; }

        // Bangun Metadata Catatan Rahasia (Peleton Injection)
        let participants = allRidersList.filter(n => n !== hostNameStr).join(', ');
        let descTag = `Peleton Mode: Kapten [${hostNameStr}], Peserta: [${participants}]`;

        // Susun tag XML dari array memori
        let trksegs = routeArray.map(pt => 
            `      <trkpt lat="${pt.lat.toFixed(6)}" lon="${pt.lon.toFixed(6)}">\n        <ele>${pt.ele.toFixed(1)}</ele>\n        <time>${pt.time}</time>\n      </trkpt>`
        ).join('\n');

        let gpxData = `<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="Kayooh v4.0" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <time>${startTimeIso}</time>
  </metadata>
  <trk>
    <name>Peleton - ${guestName}</name>
    <desc>${descTag}</desc>
    <trkseg>
${trksegs}
    </trkseg>
  </trk>
</gpx>`;

        // Konversi string ke format unduhan File Blob
        const blob = new Blob([gpxData], {type: 'application/gpx+xml'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `Peleton_${guestName}_${new Date().getTime()}.gpx`;
        document.body.appendChild(a);
        a.click();
        
        // Bersihkan memori blob
        setTimeout(() => {
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }, 0);
    }
</script>

</body>
</html>