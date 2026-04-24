<?php
// video_flex.php - Kayooh Studio Mode V7 (Performance Optimized, Preload, Expanded Readability)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || !isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}
$id = (int)$_GET['id'];
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("SELECT * FROM rides WHERE id = ?");
    $stmt->execute([$id]);
    $ride = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ride) die("Aktivitas tidak ditemukan!");

    // --- LOGIKA PELETON ---
    $participants = [];
    if (!empty($ride['participants'])) {
        $participants = json_decode($ride['participants'], true);
        if (!is_array($participants)) {
            $participants = [];
        }
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Video Flexing - Kayooh</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <script>
        const savedTheme = localStorage.getItem('theme');
        const systemLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        if (savedTheme === 'light' || (!savedTheme && systemLight)) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    </script>

    <style>
        :root { 
            --primary: #FF6600; 
            --bg-color: #050505;
            --panel-bg: rgba(10, 10, 10, 0.85);
            --border-color: rgba(255,102,0, 0.2);
            --text-main: #ffffff;
            --text-muted: #cccccc;
            --stat-bg: rgba(255,255,255,0.05);
            --overlay-bg: rgba(5, 5, 5, 0.9);
            --controls-bg: rgba(0,0,0,0.8);
        }

        [data-theme="light"] {
            --bg-color: #e5e5e5;
            --panel-bg: rgba(255, 255, 255, 0.9);
            --border-color: rgba(255,102,0, 0.4);
            --text-main: #222222;
            --text-muted: #666666;
            --stat-bg: rgba(0,0,0,0.05);
            --overlay-bg: rgba(240, 240, 240, 0.95);
            --controls-bg: rgba(255,255,255,0.9);
        }

        body, html { 
            margin: 0; 
            padding: 0; 
            height: 100%; 
            background: var(--bg-color); 
            font-family: sans-serif; 
            overflow: hidden; 
        }
        
        #video-container {
            position: relative; 
            width: 100vw; 
            height: 100vh;
            max-width: 500px; 
            margin: 0 auto; 
            background: var(--bg-color); 
            overflow: hidden;
        }

        #map { 
            width: 100%; 
            height: 100%; 
            background: var(--bg-color); 
        }

        .stat-overlay-top {
            position: absolute; 
            top: 20px; 
            left: 20px; 
            right: 20px;
            background: var(--panel-bg); 
            border-radius: 15px; 
            padding: 15px 20px;
            z-index: 1000; 
            color: var(--text-main); 
            backdrop-filter: blur(5px);
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .stat-overlay-top h2 { 
            margin: 0 0 15px 0; 
            font-size: 20px; 
            color: var(--text-main); 
            text-align: center; 
        }

        .grid-stats { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 15px; 
            text-align: center; 
        }

        .stat-item { 
            background: var(--stat-bg); 
            padding: 8px; 
            border-radius: 10px; 
        }

        .stat-item label { 
            display: block; 
            font-size: 11px; 
            opacity: 0.7; 
            margin-bottom: 3px; 
            letter-spacing: 1px; 
            color: var(--text-main); 
        }

        .stat-item span { 
            font-size: 22px; 
            font-weight: bold; 
            color: var(--primary); 
        }

        .stat-item small { 
            font-size: 12px; 
            color: var(--text-muted); 
        }

        /* Peleton CSS */
        .rider-label { 
            text-align: center; 
            white-space: nowrap; 
        }

        .rider-dot { 
            width: 14px; 
            height: 14px; 
            border-radius: 50%; 
            border: 2px solid #fff; 
            margin: 0 auto; 
            box-shadow: 0 0 10px rgba(0,0,0,0.6); 
        }

        .rider-name { 
            font-size: 10px; 
            font-weight: bold; 
            color: #fff; 
            text-shadow: 1px 1px 2px #000; 
            margin-top: 3px; 
            background: rgba(0,0,0,0.5); 
            padding: 2px 6px; 
            border-radius: 4px; 
        }

        .watermark { 
            position: absolute; 
            bottom: 40px; 
            left: 50%; 
            transform: translateX(-50%); 
            z-index: 1000; 
            text-align: center; 
            transition: opacity 0.5s; 
        }

        .watermark img { 
            height: 35px; 
            opacity: 0.8; 
        }

        #ending-overlay {
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%;
            background: var(--overlay-bg); 
            z-index: 3000;
            display: none; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center;
            text-align: center; 
            color: var(--text-main);
        }

        #ending-overlay p { 
            font-size: 14px; 
            font-weight: normal; 
            margin: 0 0 10px 0; 
            letter-spacing: 2px; 
            opacity: 0.8; 
            text-transform: uppercase; 
        }

        #ending-overlay img { 
            height: 60px; 
        }

        #controls { 
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: var(--controls-bg); 
            z-index: 2000; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
        }
        
        .btn-play {
            background: var(--primary); 
            color: #fff; 
            border: none; 
            padding: 15px 40px;
            border-radius: 50px; 
            font-size: 16px; 
            font-weight: bold; 
            cursor: pointer;
            box-shadow: 0 0 20px rgba(255,102,0,0.5); 
            transition: opacity 0.3s;
        }

        /* Style untuk tombol saat preload */
        .btn-play:disabled { 
            cursor: not-allowed; 
            opacity: 0.5; 
            box-shadow: none; 
        }
        
        .instructions { 
            color: var(--text-main); 
            text-align: center; 
            margin-top: 20px; 
            font-size: 14px; 
            opacity: 0.8; 
            max-width: 80%; 
        }

        #countdown { 
            display: none; 
            font-size: 100px; 
            color: var(--primary); 
            font-weight: bold; 
            text-shadow: 0 0 30px var(--primary); 
        }
    </style>
</head>
<body>

<div id="video-container">
    <div id="map"></div>

    <div id="ending-overlay">
        <p>Powered by</p>
        <img src="assets/kayooh.png" alt="Kayooh">
    </div>

    <div class="stat-overlay-top">
        <h2><?= htmlspecialchars($ride['name']) ?></h2>
        <div class="grid-stats">
            <div class="stat-item">
                <label>JARAK</label>
                <span id="v-dist">0.00</span> <small>km</small>
            </div>
            <div class="stat-item">
                <label>WAKTU</label>
                <span id="v-time">00:00:00</span>
            </div>
            <div class="stat-item">
                <label>ELEVASI</label>
                <span id="v-elev">0</span> <small>m</small>
            </div>
            <div class="stat-item">
                <label>SUHU</label>
                <span id="v-temp">0.0</span> <small>°C</small>
            </div>
            <div class="stat-item" style="grid-column: span 2;">
                <label>SPEED</label>
                <span id="v-speed">0.0</span> <small>km/h</small>
            </div>
        </div>
    </div>

    <div class="watermark">
        <img src="assets/kayooh.png" alt="Kayooh">
    </div>

    <div id="controls">
        <div id="countdown">3</div>
        <button id="btn-start" class="btn-play" onclick="prepareStudio()" disabled>⏳ MEMUAT PETA...</button>
        <p id="inst-text" class="instructions">Tekan tombol, lalu nyalakan fitur <b>Rekam Layar</b> di HP Anda saat hitung mundur!</p>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const rawPolyline = <?= json_encode($ride['polyline']) ?>;
    const totalDistTarget = <?= (float)$ride['distance'] ?>;
    const totalElevTarget = <?= (int)$ride['total_elevation_gain'] ?>;
    const movingTimeTarget = <?= (int)$ride['moving_time'] ?>;
    const avgTempTarget = <?= (float)$ride['avg_temp'] ?>;

    const ridersList = <?= json_encode($participants) ?>; 
    const isPeleton = ridersList && ridersList.length > 0;

    function decodePolyline(encoded) {
        if (!encoded) return [];
        if (typeof encoded === 'string' && encoded.trim().startsWith('[')) {
            try { return JSON.parse(encoded); } catch(e) { return []; }
        }
        let points = [];
        let index = 0;
        let len = encoded.length;
        let lat = 0;
        let lng = 0;

        while (index < len) {
            let b, shift = 0, result = 0;
            do { 
                b = encoded.charCodeAt(index++) - 63; 
                result |= (b & 0x1f) << shift; 
                shift += 5; 
            } while (b >= 0x20);
            let dlat = ((result & 1) ? ~(result >> 1) : (result >> 1)); 
            lat += dlat;
            
            shift = 0;
            result = 0;
            do { 
                b = encoded.charCodeAt(index++) - 63; 
                result |= (b & 0x1f) << shift; 
                shift += 5; 
            } while (b >= 0x20);
            let dlng = ((result & 1) ? ~(result >> 1) : (result >> 1)); 
            lng += dlng;
            
            points.push([lat / 1e5, lng / 1e5]);
        }
        return points;
    }
    const fullPath = decodePolyline(rawPolyline);

    const startCoord = fullPath.length > 0 ? fullPath[0] : [-7.801, 110.373];
    const map = L.map('map', { zoomControl: false, attributionControl: false }).setView(startCoord, 14);
    
    const isLightMode = document.documentElement.getAttribute('data-theme') === 'light';
    const tileUrl = isLightMode 
        ? 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png' 
        : 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
    
    const baseLayer = L.tileLayer(tileUrl).addTo(map);

    // --- FITUR PRELOAD PETA ---
    // Dengarkan event 'load' dari TileLayer CartoDB
    baseLayer.on('load', function() {
        const btn = document.getElementById('btn-start');
        btn.disabled = false;
        btn.innerText = "🎬 SIAPKAN REKAMAN";
    });

    const animatedLine = L.polyline([], { color: '#FF6600', weight: 6, opacity: 1 }).addTo(map);
    
    let markers = [];
    const pelotonColors = ['#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF'];

    if (!isPeleton) {
        const bikeIcon = L.divIcon({
            className: 'custom-bike',
            html: '<div style="font-size: 24px; text-shadow: 0 0 10px #FF6600, 0 0 20px #FF6600; text-align: center;">🚴‍♂️</div>',
            iconSize: [30, 30], iconAnchor: [15, 15]
        });
        markers.push(L.marker(startCoord, { icon: bikeIcon }).addTo(map));
    } else {
        ridersList.forEach((name, i) => {
            const color = pelotonColors[i % pelotonColors.length];
            const dotIcon = L.divIcon({
                className: 'rider-label',
                html: `<div class="rider-dot" style="background:${color}"></div><div class="rider-name">${name}</div>`,
                iconSize: [60, 40], iconAnchor: [30, 6]
            });
            markers.push(L.marker(startCoord, { icon: dotIcon }).addTo(map));
        });
    }

    let animationRunning = false;
    const duration = 25000;
    let startTime = null;
    let frameCount = 0; // Tambahan untuk Optimasi HP Kentang

    function prepareStudio() {
        if (fullPath.length <= 1) { 
            alert("Rute kosong!"); 
            return; 
        }

        const elem = document.documentElement;
        if (elem.requestFullscreen) {
            elem.requestFullscreen().catch(err => console.log("Gagal fullscreen", err));
        } else if (elem.webkitRequestFullscreen) {
            elem.webkitRequestFullscreen();
        }
        
        document.getElementById('btn-start').style.display = 'none';
        document.getElementById('inst-text').innerHTML = "Nyalakan Perekam Layar HP Anda Sekarang!";
        document.getElementById('countdown').style.display = 'block';

        let count = 3;
        let interval = setInterval(() => {
            count--;
            if (count > 0) {
                document.getElementById('countdown').innerText = count;
            } else {
                clearInterval(interval);
                document.getElementById('controls').style.display = 'none';
                
                animationRunning = true;
                requestAnimationFrame((timestamp) => {
                    startTime = timestamp;
                    animate(timestamp);
                });
            }
        }, 1000);
    }

    function animate(currentTime) {
        if (!startTime) startTime = currentTime;
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        frameCount++; // Hitung frame yang sedang berjalan

        const currentIndex = Math.floor(progress * (fullPath.length - 1));
        const currentPath = fullPath.slice(0, currentIndex + 1);
        
        animatedLine.setLatLngs(currentPath);
        const lastPoint = fullPath[currentIndex];
        
        markers.forEach((m, i) => {
            const offset = isPeleton ? (i * 0.00005) : 0; 
            m.setLatLng([lastPoint[0] + offset, lastPoint[1] + offset]);
        });
        
        // --- OPTIMASI HP KENTANG ---
        // Panning kamera hanya dilakukan setiap 3 frame sekali (mengurangi beban GPU)
        if (frameCount % 3 === 0 || progress >= 1) {
            const latOffset = (map.getBounds().getNorth() - map.getBounds().getSouth()) * 0.15;
            map.panTo([lastPoint[0] + latOffset, lastPoint[1]], { animate: false });
        }

        document.getElementById('v-dist').innerText = (progress * totalDistTarget).toFixed(2);
        document.getElementById('v-elev').innerText = Math.floor(progress * totalElevTarget);

        if (avgTempTarget > 0) {
            const tempFluctuation = Math.sin(progress * 15) * 1.5; 
            document.getElementById('v-temp').innerText = (avgTempTarget + tempFluctuation).toFixed(1);
        }

        const speedBase = movingTimeTarget > 0 ? (totalDistTarget / (movingTimeTarget / 3600)) : 0;
        const speedFluctuation = Math.sin(progress * 20) * 3;
        document.getElementById('v-speed').innerText = Math.max(0, speedBase + speedFluctuation).toFixed(1);

        const currentSecs = Math.floor(progress * movingTimeTarget);
        const h = Math.floor(currentSecs / 3600).toString().padStart(2, '0');
        const m = Math.floor((currentSecs % 3600) / 60).toString().padStart(2, '0');
        const s = (currentSecs % 60).toString().padStart(2, '0');
        document.getElementById('v-time').innerText = `${h}:${m}:${s}`;

        if (progress < 1) {
            requestAnimationFrame(animate);
        } else {
            setTimeout(() => {
                document.querySelector('.watermark').style.opacity = '0';
                document.getElementById('ending-overlay').style.display = 'flex';
                
                if (document.exitFullscreen) {
                    document.exitFullscreen().catch(() => {});
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
                
                animationRunning = false;
            }, 1500);
        }
    }
</script>
</body>
</html>