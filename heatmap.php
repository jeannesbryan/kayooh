<?php
// heatmap.php - Visualisasi Jejak Panas (Kayooh v5.0 - Universal R2 Support)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$all_polylines = [];
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Ambil data polyline yang tidak kosong
    $stmt = $pdo->query("SELECT polyline FROM rides WHERE polyline IS NOT NULL AND polyline != ''");
    $all_polylines = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Personal Heatmap - Kayooh</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <style>
        :root {
            --hm-bg: #f8fafc; --hm-text: #1e293b; --hm-panel: rgba(255, 255, 255, 0.9);
            --hm-border: rgba(255, 102, 0, 0.4); --hm-shadow: rgba(0, 0, 0, 0.1);
        }
        body.dark-mode {
            --hm-bg: #000000; --hm-text: #f1f5f9; --hm-panel: rgba(15, 23, 42, 0.85);
            --hm-border: rgba(255, 102, 0, 0.3); --hm-shadow: rgba(0, 0, 0, 0.5);
        }
        * { box-sizing: border-box; }
        body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; background: var(--hm-bg); font-family: 'Segoe UI', sans-serif;}
        #map { height: 100vh; width: 100vw; background: var(--hm-bg); z-index: 1;}
        
        .heatmap-header {
            position: absolute; top: 20px; left: 20px; z-index: 1000;
            background: var(--hm-panel); backdrop-filter: blur(10px);
            padding: 15px 20px; border-radius: 12px; border: 1px solid var(--hm-border);
            color: var(--hm-text); box-shadow: 0 4px 15px var(--hm-shadow);
            transition: all 0.3s ease; max-width: 90vw;
        }
        .header-flex { display: flex; justify-content: space-between; align-items: flex-start; }
        .heatmap-header h2 { margin: 0; font-size: 18px; color: #FF6600; text-transform: uppercase; letter-spacing: 1px; }
        .heatmap-header p { margin: 5px 0 0 0; font-size: 12px; opacity: 0.8; font-weight: 500; }
        
        .btn-back {
            display: inline-block; margin-top: 15px; padding: 8px 15px;
            background: #334155; color: white; text-decoration: none;
            border-radius: 6px; font-size: 12px; font-weight: bold; transition: 0.2s;
        }
        .btn-back:hover { background: #475569; }

        .theme-toggle-hm {
            background: none; border: 1px solid var(--hm-border); 
            border-radius: 50%; width: 35px; height: 35px; 
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 16px; margin-left: 15px; transition: background 0.3s; color: var(--hm-text);
        }
        .theme-toggle-hm:hover { background: rgba(127,140,141,0.2); }

        #loader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.9); z-index: 9999; display: flex;
            flex-direction: column; align-items: center; justify-content: center;
            color: #FF6600; transition: background 0.3s;
        }
        .progress-bar { width: 60%; max-width: 300px; background: #333; height: 8px; border-radius: 4px; margin-top: 15px; overflow: hidden; }
        #progressFill { width: 0%; height: 100%; background: #FF6600; transition: width 0.1s; }
    </style>
</head>
<body>
    <script>
        if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');
    </script>

<div id="loader">
    <div style="font-size: 40px; margin-bottom: 10px; animation: pulse 1s infinite;">🔥</div>
    <div id="loader-text" style="font-weight: bold; font-size: 14px;">Membakar Jejak Aspal...</div>
    <div class="progress-bar"><div id="progressFill"></div></div>
</div>

<div class="heatmap-header">
    <div class="header-flex">
        <div>
            <h2>Personal Heatmap</h2>
            <p>Total Jejak: <b style="color: #FF6600;"><?= count($all_polylines) ?> Aktivitas</b></p>
        </div>
        <button onclick="toggleThemeHM()" class="theme-toggle-hm" id="theme-icon-hm">🌙</button>
    </div>
    <a href="dashboard.php" class="btn-back">&larr; KEMBALI</a>
</div>

<div id="map"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    document.getElementById('theme-icon-hm').textContent = document.body.classList.contains('dark-mode') ? '☀️' : '🌙';

    const polylines = <?= json_encode($all_polylines) ?>;
    const map = L.map('map', { zoomControl: false, attributionControl: false }).setView([-2.5489, 118.0149], 5);
    
    let currentBaseMap = null;

    function setBasemap(isDark) {
        if (currentBaseMap !== null) map.removeLayer(currentBaseMap);
        const tileUrl = isDark
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
        currentBaseMap = L.tileLayer(tileUrl, { maxZoom: 19 }).addTo(map);
    }
    setBasemap(document.body.classList.contains('dark-mode'));

    // --- MESIN PEMECAH SANDI STRAVA ---
    function decodePolylineStrava(encoded) {
        if (!encoded) return [];
        let points = [], index = 0, len = encoded.length, lat = 0, lng = 0;
        while (index < len) {
            let b, shift = 0, result = 0;
            do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
            lat += ((result & 1) ? ~(result >> 1) : (result >> 1));
            shift = 0; result = 0;
            do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
            lng += ((result & 1) ? ~(result >> 1) : (result >> 1));
            points.push([lat / 1e5, lng / 1e5]);
        }
        return points;
    }

    // --- PEMBAKAR JEJAK ASINKRONUS ---
    async function drawHeatmap() {
        let allCoords = [];
        let total = polylines.length;
        let loaded = 0;
        
        for (let str of polylines) {
            try {
                let coords = [];
                let rawStr = str.trim();

                // 1. Fetch R2 JSON
                if (rawStr.startsWith('http')) {
                    let res = await fetch(rawStr);
                    let jsonRaw = await res.json();
                    coords = jsonRaw.map(p => (p.lat !== undefined) ? [parseFloat(p.lat), parseFloat(p.lng)] : null);
                } 
                // 2. Local JSON
                else if (rawStr.startsWith('[') || rawStr.startsWith('{') || rawStr.startsWith('"[')) {
                    let parsed = JSON.parse(rawStr);
                    if (typeof parsed === 'string') parsed = JSON.parse(parsed);
                    coords = parsed.map(p => {
                        if (Array.isArray(p)) return [parseFloat(p[0]), parseFloat(p[1])];
                        if (p.lat !== undefined) return [parseFloat(p.lat), parseFloat(p.lng)];
                        return null;
                    });
                } 
                // 3. Strava Encoded
                else {
                    coords = decodePolylineStrava(rawStr);
                }

                coords = coords.filter(p => p !== null && !isNaN(p[0]) && !isNaN(p[1]));

                if (coords.length > 1) {
                    allCoords.push(coords);
                    L.polyline(coords, {
                        color: '#FF4500', // Oranye kemerahan menyala
                        weight: 4,        
                        opacity: 0.05,    // Dibuat super tipis opacitynya agar efek penumpukan (panas) terasa
                        smoothFactor: 1.5,
                        interactive: false
                    }).addTo(map);
                }
            } catch (e) {
                console.error("Skipped bad polyline:", e);
            }
            
            // Update UI Loader
            loaded++;
            document.getElementById('progressFill').style.width = (loaded / total * 100) + '%';
            document.getElementById('loader-text').innerText = `Membakar Jejak... (${loaded}/${total})`;
        }

        // Auto Focus Kamera
        if (allCoords.length > 0) {
            const bounds = L.polyline(allCoords.flat()).getBounds();
            map.fitBounds(bounds, { padding: [30, 30] });
        }
        
        // Hapus Loader
        setTimeout(() => { document.getElementById('loader').style.display = 'none'; }, 500);
    }

    function toggleThemeHM() {
        document.body.classList.toggle('dark-mode');
        const isNowDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isNowDark ? 'dark' : 'light');
        document.getElementById('theme-icon-hm').textContent = isNowDark ? '☀️' : '🌙';
        setBasemap(isNowDark);
    }

    // Jalankan mesin setelah HTML dimuat
    window.onload = () => { drawHeatmap(); };
</script>
</body>
</html>