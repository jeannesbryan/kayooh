<?php
// guest.php - Layar Pemantauan & Mesin Pelacak Guest (Kayooh v3.0)
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
// API INTERNAL 2: Cek Trigger Broadcast (EPIC 3)
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
        body, html { margin: 0; padding: 0; height: 100%; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f9; }
        #map { height: 100vh; width: 100vw; z-index: 1; }
        
        /* Layar Join Peleton */
        #join-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(244, 244, 249, 0.95); backdrop-filter: blur(10px);
            z-index: 9999; display: flex; align-items: center; justify-content: center; flex-direction: column;
        }
        .join-box {
            background: white; padding: 30px; border-radius: 15px; text-align: center;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 85%; max-width: 350px;
        }
        .join-input {
            width: 100%; padding: 12px; margin: 15px 0; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 16px; box-sizing: border-box; text-align: center;
        }
        .btn-join {
            background: #3b82f6; color: white; border: none; padding: 14px; width: 100%;
            border-radius: 8px; font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.2s;
        }
        .btn-join:active { background: #2563eb; transform: scale(0.98); }

        /* Panel Info Floating Normal/Sporty */
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
        .pulse { display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        
        .rider-list { display: flex; flex-direction: column; gap: 8px; max-height: 150px; overflow-y: auto; }
        .rider-item {
            display: flex; justify-content: space-between; align-items: center;
            background: #f8fafc; padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0;
        }
        .rider-name { font-weight: bold; font-size: 14px; color: #1e293b; }
        .rider-speed { font-family: monospace; color: #0ea5e9; font-size: 14px; font-weight: bold; }
        
        /* Custom Marker CSS */
        .custom-marker { background-color: #3b82f6; border: 2px solid white; border-radius: 50%; width: 16px; height: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .host-marker { background-color: #ef4444; width: 18px; height: 18px; }
        .me-marker { background-color: #10b981; } /* Warna hijau untuk diri sendiri */
    </style>
</head>
<body>

<div id="join-overlay">
    <div class="join-box">
        <h2 style="margin-top:0; color:#0f172a; font-size:22px;">🤝 Gabung Peleton</h2>
        <p style="color:#64748b; font-size:13px;">Anda diundang untuk masuk ke radar. Sinyal GPS Anda akan disiarkan ke rombongan.</p>
        <div style="background:#e8f4f8; padding:5px; border-radius:6px; font-weight:bold; color:#0ea5e9; font-size:14px; letter-spacing:1px;">
            ROOM: <?= htmlspecialchars($room) ?>
        </div>
        <input type="text" id="guest-name" class="join-input" placeholder="Masukkan Nama Anda" autocomplete="off" maxlength="15">
        <button onclick="joinPeleton()" class="btn-join">🚀 MULAI GOWES</button>
        <div id="join-error" style="color:#ef4444; font-size:12px; margin-top:10px; font-weight:bold;"></div>
    </div>
</div>

<div id="map"></div>

<div class="radar-panel">
    <div class="radar-header">
        <h3><span class="pulse"></span> LIVE TRACKING</h3>
        <span style="font-size: 12px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">ID: <b><?= htmlspecialchars($room) ?></b></span>
    </div>
    <div class="rider-list" id="rider-container">
        <div style="text-align: center; font-size: 12px; color: #64748b;">Menunggu sinyal masuk...</div>
    </div>
</div>

<script>
    let guestName = '';
    let watchId = null;

    // --- 1. LOGIKA GUEST MASUK PELETON ---
    function joinPeleton() {
        const inputName = document.getElementById('guest-name').value.trim();
        const errorDiv = document.getElementById('join-error');
        
        if(inputName === '') {
            errorDiv.innerText = 'Nama tidak boleh kosong wak!';
            return;
        }
        if(inputName.toLowerCase() === 'host') {
            errorDiv.innerText = 'Nama "Host" khusus untuk Kapten Peleton.';
            return;
        }
        
        guestName = inputName;
        document.getElementById('join-overlay').style.display = 'none'; // Sembunyikan layar Join
        
        // Meminta Izin GPS & Mulai Memancarkan
        startGuestTracking();
    }

    // --- 2. MESIN PELACAK KHUSUS GUEST ---
    function startGuestTracking() {
        if (!navigator.geolocation) {
            alert('GPS tidak didukung di perangkat ini.');
            return;
        }

        // Tembakkan koordinat setiap kali ada pergerakan
        watchId = navigator.geolocation.watchPosition((pos) => {
            const lat = pos.coords.latitude;
            const lon = pos.coords.longitude;
            const speed = pos.coords.speed ? (pos.coords.speed * 3.6) : 0;

            const formData = new FormData();
            formData.append('room', '<?= htmlspecialchars($room) ?>');
            formData.append('user', guestName);
            formData.append('lat', lat);
            formData.append('lon', lon);
            formData.append('speed', speed);

            // Diam-diam kirim koordinat Guest ke radar_sync.php
            fetch('radar_sync.php', { method: 'POST', body: formData })
                .catch(e => console.error("Guest Sync Error:", e));

        }, (err) => {
            console.warn('GPS Gagal:', err);
        }, { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 });
    }

    // --- 3. MESIN RADAR (VIEWER) ---
    const map = L.map('map', { zoomControl: false }).setView([-6.200000, 106.816666], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 19
    }).addTo(map);

    let markers = {}; 
    let isFirstLoad = true;

    function fetchRadarData() {
        // Jangan jalankan radar kalau user belum memasukkan nama
        if (guestName === '') return;

        fetch('?room=<?= $room ?>&ajax=1')
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('rider-container');
                container.innerHTML = '';
                if (data.length === 0) return;

                let bounds = [];
                data.forEach(rider => {
                    const latlng = [rider.lat, rider.lon];
                    bounds.push(latlng);
                    const isHost = rider.user.toLowerCase() === 'host';
                    const isMe = rider.user === guestName;
                    const speedKmh = rider.speed.toFixed(1);
                    
                    container.innerHTML += `
                        <div class="rider-item" ${isMe ? 'style="border-left: 4px solid #10b981;"' : ''}>
                            <span class="rider-name">${isHost ? '🏁 ' : '🚴 '}${rider.user} ${isMe ? '(Anda)' : ''}</span>
                            <span class="rider-speed">${speedKmh} km/h</span>
                        </div>
                    `;

                    if (markers[rider.user]) {
                        markers[rider.user].setLatLng(latlng);
                    } else {
                        // Tentukan warna marker: Merah untuk Host, Hijau untuk Diri Sendiri, Biru untuk Teman Lain
                        let iconClass = 'custom-marker';
                        if (isHost) iconClass += ' host-marker';
                        else if (isMe) iconClass += ' me-marker';
                        
                        const customIcon = L.divIcon({ className: iconClass, iconSize: isHost ? [18, 18] : [16, 16] });
                        markers[rider.user] = L.marker(latlng, { icon: customIcon })
                            .bindTooltip(`<b>${rider.user}</b><br>${speedKmh} km/h`, { direction: 'top', offset: [0, -10] })
                            .addTo(map);
                    }
                    markers[rider.user].setTooltipContent(`<b>${rider.user}</b><br>${speedKmh} km/h`);
                });

                if (isFirstLoad && bounds.length > 0) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                    isFirstLoad = false;
                }
            })
            .catch(err => console.error("Radar Error:", err));
    }

    // Polling Radar Data setiap 3 detik
    setInterval(fetchRadarData, 3000);

    // --- 4. POLLING BROADCAST GAMBAR PELETON (EPIC 3) ---
    function checkBroadcast() {
        // Cek secara background apakah Host sudah menembak gambar hasil gowes
        fetch('?room=<?= $room ?>&ajax=2')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'ready' && data.url) {
                    // Kalau gambar tersedia, paksa browser redirect ke link tersebut!
                    window.location.href = data.url;
                }
            })
            .catch(err => console.error("Broadcast Check Error:", err));
    }

    // Polling Trigger Broadcast ini jalan independen setiap 5 detik
    setInterval(checkBroadcast, 5000);
</script>

</body>
</html>