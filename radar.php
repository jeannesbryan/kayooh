<?php
// radar.php - Live Tracker untuk Peserta & Viewer (Kayooh v5.0)
$room = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['room'] ?? '');
if (empty($room)) {
    die("<h2 style='text-align:center; font-family:sans-serif; color:#e74c3c; margin-top:50px;'>⛔ Sinyal Hilang: Room ID tidak valid.</h2>");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Radar Peleton - <?= htmlspecialchars($room) ?></title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root { --primary: #3b82f6; --dark: #121212; --panel: rgba(30, 30, 30, 0.9); --text: #f1f5f9; }
        body, html { margin: 0; padding: 0; height: 100%; font-family: 'Segoe UI', sans-serif; background-color: var(--dark); color: var(--text); overflow: hidden; }
        #map { height: 100vh; width: 100vw; z-index: 1; }
        
        .overlay-screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(8px); z-index: 9999; display: flex; align-items: center; justify-content: center; flex-direction: column; }
        .box-panel { background: #1e1e1e; padding: 30px; border-radius: 15px; text-align: center; border: 1px solid #333; width: 85%; max-width: 350px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        
        .join-input { width: 100%; padding: 12px; margin: 15px 0; background: #2d2d2d; border: 1px solid #444; color: white; border-radius: 8px; font-size: 16px; box-sizing: border-box; text-align: center; }
        .join-input:focus { outline: none; border-color: var(--primary); }
        
        .btn-primary { background: var(--primary); color: white; border: none; padding: 14px; width: 100%; border-radius: 8px; font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.2s; margin-bottom: 10px; }
        .btn-primary:active { transform: scale(0.98); }
        .btn-success { background: #10b981; }
        
        .radar-panel { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 400px; background: var(--panel); backdrop-filter: blur(10px); border: 1px solid #333; border-radius: 12px; padding: 15px; z-index: 1000; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        .radar-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 10px; }
        .radar-header h3 { margin: 0; font-size: 16px; display: flex; align-items: center; gap: 6px; }
        
        .pulse { display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        
        .rider-list { display: flex; flex-direction: column; gap: 8px; max-height: 150px; overflow-y: auto; }
        .rider-item { display: flex; justify-content: space-between; align-items: center; background: #2d2d2d; padding: 8px 12px; border-radius: 8px; border: 1px solid #444; }
        .rider-name { font-weight: bold; font-size: 14px; }
        .rider-stats { text-align: right; }
        .rider-speed { font-family: monospace; color: #38bdf8; font-size: 14px; font-weight: bold; display: block; }
        
        .custom-marker { background-color: #3b82f6; border: 2px solid white; border-radius: 50%; width: 16px; height: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        .host-marker { background-color: #ef4444; width: 18px; height: 18px; }
        .me-marker { background-color: #10b981; } 
        .hidden { display: none !important; }
    </style>
</head>
<body>

<div id="join-overlay" class="overlay-screen">
    <div class="box-panel">
        <h2 style="margin-top:0; font-size:22px;">🤝 Gabung Peleton</h2>
        <p style="color:#94a3b8; font-size:13px;">Sinyal GPS Anda akan disiarkan ke Radar. Biarkan kosong jika hanya ingin memantau (Viewer).</p>
        <div style="background:#0284c7; padding:5px; border-radius:6px; font-weight:bold; font-size:14px;">ROOM: <?= htmlspecialchars($room) ?></div>
        <input type="text" id="guest-name" class="join-input" placeholder="Nama Panggilan" autocomplete="off" maxlength="15">
        <button onclick="joinPeleton()" class="btn-primary">🚀 MULAI TRACKING</button>
        <button onclick="startViewer()" class="btn-primary" style="background:#475569;">👁️ HANYA PANTAU</button>
        <div id="join-error" style="color:#ef4444; font-size:12px; margin-top:10px; font-weight:bold;"></div>
    </div>
</div>

<div id="finish-overlay" class="overlay-screen hidden">
    <div class="box-panel">
        <div style="font-size: 45px; margin-bottom: 10px;">🏁</div>
        <h2 style="margin-top:0; font-size:22px;">Peleton Selesai!</h2>
        <p style="color:#94a3b8; font-size:13px; margin-bottom: 20px;">Sinyal Kapten telah berhenti. Sesi Peleton resmi dibubarkan.</p>
        <div style="display: flex; justify-content: space-around; background: #2d2d2d; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #444;">
            <div>
                <div style="font-size: 11px; color: #94a3b8; font-weight: bold;">JARAK ANDA</div>
                <div style="font-size: 24px; color: #10b981; font-weight: bold;" id="final-distance">0.0 km</div>
            </div>
        </div>
        <button onclick="downloadGPX()" class="btn-primary btn-success" id="btn-gpx">📥 AMANKAN GPX SAYA</button>
    </div>
</div>

<div id="map"></div>

<div class="radar-panel">
    <div class="radar-header">
        <h3><span class="pulse" id="pulse-indicator"></span> RADAR LIVE</h3>
        <span style="font-size: 12px; background: #333; padding: 2px 6px; border-radius: 4px;">ID: <b><?= htmlspecialchars($room) ?></b></span>
    </div>
    <div class="rider-list" id="rider-container">
        <div style="text-align: center; font-size: 12px; color: #94a3b8;">Menunggu sinyal satelit...</div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const ROOM_ID = "<?= htmlspecialchars($room) ?>";
    let guestName = '';
    let isTracking = false;
    let isViewer = false;
    let watchId = null;
    let wakeLock = null;
    
    let totalDistanceMeters = 0;
    let prevLat = null, prevLon = null;
    let routeArray = []; 
    let startTimeIso = null;
    let missingHostCount = 0;

    const map = L.map('map', { zoomControl: false }).setView([-2.5489, 118.0149], 5);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(map);
    let markers = {}; let isFirstLoad = true;

    async function requestWakeLock() {
        if ('wakeLock' in navigator) try { wakeLock = await navigator.wakeLock.request('screen'); } catch(e){}
    }

    function hitungJarak(lat1, lon1, lat2, lon2) {
        const R = 6371e3, p1 = lat1 * Math.PI/180, p2 = lat2 * Math.PI/180, dp = (lat2-lat1) * Math.PI/180, dl = (lon2-lon1) * Math.PI/180;
        const a = Math.sin(dp/2) * Math.sin(dp/2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl/2) * Math.sin(dl/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    function startViewer() {
        isViewer = true;
        document.getElementById('join-overlay').classList.add('hidden');
        // Polling pasif untuk Viewer
        setInterval(() => {
            fetch(`radar_sync.php?room=${ROOM_ID}&action=sync`).then(res => res.json())
            .then(data => { if(data.participants) updateRadar(data.participants); });
        }, 3000);
    }

    function joinPeleton() {
        const inputName = document.getElementById('guest-name').value.trim();
        const errorDiv = document.getElementById('join-error');
        if(inputName === '') { errorDiv.innerText = 'Nama tidak boleh kosong!'; return; }
        
        guestName = inputName;
        document.getElementById('join-overlay').classList.add('hidden');
        isTracking = true; startTimeIso = new Date().toISOString();
        
        requestWakeLock();
        startGPS();
    }

    function startGPS() {
        if (!navigator.geolocation) return;
        watchId = navigator.geolocation.watchPosition((pos) => {
            const lat = pos.coords.latitude, lon = pos.coords.longitude, ele = pos.coords.altitude || 0;
            const speed = pos.coords.speed ? (pos.coords.speed * 3.6) : 0;

            routeArray.push({ lat, lon, ele, time: new Date(pos.timestamp).toISOString() });
            if (prevLat !== null) totalDistanceMeters += hitungJarak(prevLat, prevLon, lat, lon);
            prevLat = lat; prevLon = lon;

            // Jurus 1 Tarikan Nafas: Kirim Posisi ANDA, sekaligus Minta Posisi TEMAN!
            fetch(`radar_sync.php?lat=${lat}&lng=${lon}&speed=${speed}&user=${guestName}&room=${ROOM_ID}&action=sync`)
                .then(res => res.json())
                .then(data => { if(data.participants) updateRadar(data.participants); })
                .catch(e => console.log(e));

        }, (err) => console.log(err), { enableHighAccuracy: true });
    }

    function updateRadar(participants) {
        const container = document.getElementById('rider-container');
        container.innerHTML = '';
        if (participants.length === 0) return;

        let bounds = [];
        let hostFound = false;

        participants.forEach(p => {
            bounds.push([p.lat, p.lon]);
            const isHost = p.user.toLowerCase().includes('kapten') || p.user.toLowerCase() === 'host';
            const isMe = p.user === guestName;
            if (isHost) hostFound = true;

            let distDisplay = isMe ? `<span style="font-size:11px; color:#10b981;">${(totalDistanceMeters/1000).toFixed(2)} km</span>` : '';

            container.innerHTML += `
                <div class="rider-item" ${isMe ? 'style="border-left: 3px solid #10b981;"' : ''}>
                    <span class="rider-name">${isHost ? '🏁 ' : '🚴 '}${p.user} ${isMe ? '(Anda)' : ''}</span>
                    <div class="rider-stats"><span class="rider-speed">${p.speed.toFixed(1)} km/h</span><br>${distDisplay}</div>
                </div>`;

            if (markers[p.user]) { markers[p.user].setLatLng([p.lat, p.lon]); } 
            else {
                let iconClass = 'custom-marker';
                if (isHost) iconClass += ' host-marker'; else if (isMe) iconClass += ' me-marker';
                markers[p.user] = L.marker([p.lat, p.lon], { icon: L.divIcon({ className: iconClass, iconSize: isHost ? [18, 18] : [16, 16] }) }).addTo(map);
            }
        });

        if (isFirstLoad && bounds.length > 0) { map.fitBounds(bounds, { padding: [40, 40] }); isFirstLoad = false; }

        // AUTO-FINISH TRIGGER: Jika sinyal Kapten putus 3x berturut-turut
        if (!hostFound && isTracking) {
            missingHostCount++;
            if (missingHostCount >= 3) finishPeleton();
        } else { missingHostCount = 0; }
    }

    function finishPeleton() {
        isTracking = false;
        if(watchId) navigator.geolocation.clearWatch(watchId);
        if(wakeLock) wakeLock.release();
        
        document.getElementById('pulse-indicator').style.animation = 'none';
        document.getElementById('pulse-indicator').style.backgroundColor = '#444';
        
        document.getElementById('final-distance').innerText = (totalDistanceMeters / 1000).toFixed(2) + " km";
        document.getElementById('finish-overlay').classList.remove('hidden');
        if (routeArray.length === 0) document.getElementById('btn-gpx').style.display = 'none';
    }

    function downloadGPX() {
        let trksegs = routeArray.map(pt => `      <trkpt lat="${pt.lat.toFixed(6)}" lon="${pt.lon.toFixed(6)}">\n        <ele>${pt.ele.toFixed(1)}</ele>\n        <time>${pt.time}</time>\n      </trkpt>`).join('\n');
        let gpxData = `<?xml version="1.0" encoding="UTF-8"?>\n<gpx version="1.1" creator="Kayooh v5.0"><trk><name>Peleton - ${guestName}</name><trkseg>\n${trksegs}\n</trkseg></trk></gpx>`;
        
        const blob = new Blob([gpxData], {type: 'application/gpx+xml'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = `Peleton_${guestName}_${Date.now()}.gpx`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    }
</script>
</body>
</html>