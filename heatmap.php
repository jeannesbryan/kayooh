<?php
// heatmap.php - Visualisasi Jejak Panas (Live Dynamic Basemap v4.0)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 1. Ambil Semua Polyline dari Database
$all_polylines = [];
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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
    <link rel="stylesheet" href="assets/style.css">
    
    <style>
        /* CSS Variabel untuk Dinamika Tema */
        :root {
            --hm-bg: #f8fafc;
            --hm-text: #1e293b;
            --hm-panel: rgba(255, 255, 255, 0.9);
            --hm-border: rgba(255, 102, 0, 0.4);
            --hm-shadow: rgba(0, 0, 0, 0.1);
        }

        body.dark-mode {
            --hm-bg: #000000;
            --hm-text: #f1f5f9;
            --hm-panel: rgba(15, 23, 42, 0.85);
            --hm-border: rgba(255, 102, 0, 0.3);
            --hm-shadow: rgba(0, 0, 0, 0.5);
        }

        body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; background: var(--hm-bg); }
        #map { height: 100vh; width: 100vw; background: var(--hm-bg); }
        
        .heatmap-header {
            position: absolute; top: 20px; left: 20px; z-index: 1000;
            background: var(--hm-panel); backdrop-filter: blur(10px);
            padding: 15px 20px; border-radius: 12px; border: 1px solid var(--hm-border);
            color: var(--hm-text); box-shadow: 0 4px 15px var(--hm-shadow);
            transition: all 0.3s ease;
            min-width: 250px;
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
            font-size: 16px; margin-left: 15px; transition: background 0.3s;
        }
        .theme-toggle-hm:hover { background: rgba(127,140,141,0.2); }

        /* Loader Overlay */
        #loader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: var(--hm-bg); z-index: 9999; display: flex;
            flex-direction: column; align-items: center; justify-content: center;
            color: #FF6600; font-family: sans-serif; transition: background 0.3s;
        }
    </style>
</head>
<body>
    <script>
        // SENSOR TEMA HTML UTAMA
        const initDark = localStorage.getItem('theme') === 'dark';
        if (initDark) document.body.classList.add('dark-mode');
    </script>

<div id="loader">
    <div style="font-size: 40px; margin-bottom: 20px;">🔥</div>
    <div id="loader-text" style="font-weight: bold;">Membakar Jejak Aspal...</div>
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
    // --- 1. SET IKON AWAL ---
    document.getElementById('theme-icon-hm').textContent = document.body.classList.contains('dark-mode') ? '☀️' : '🌙';

    // --- 2. DATA DARI PHP ---
    const polylines = <?= json_encode($all_polylines) ?>;
    
    // --- 3. DECODER POLYLINE ---
    function decodePolyline(encoded) {
        if (!encoded) return [];
        if (encoded.startsWith('[')) { try { return JSON.parse(encoded); } catch(e) { return []; } }
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

    // --- 4. INISIALISASI PETA ---
    const map = L.map('map', { zoomControl: false, attributionControl: false }).setView([-7.801, 110.373], 13);
    
    // Variabel penyimpan Layer Peta saat ini
    let currentBaseMap = null;

    // Fungsi Pengubah Peta (Bisa dipanggil berulang-ulang)
    function setBasemap(isDark) {
        if (currentBaseMap !== null) {
            map.removeLayer(currentBaseMap); // Cabut peta lama
        }
        
        const tileUrl = isDark
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';

        currentBaseMap = L.tileLayer(tileUrl, { maxZoom: 19 }).addTo(map); // Tempel peta baru
    }

    // Tembak peta pertama kali sesuai tema
    setBasemap(document.body.classList.contains('dark-mode'));

    // --- 5. SIHIR HEATMAP (Overlapping Opacity) ---
    if (polylines.length > 0) {
        let allCoords = [];
        
        polylines.forEach(str => {
            const coords = decodePolyline(str);
            if (coords.length > 0) {
                allCoords.push(coords);
                L.polyline(coords, {
                    color: '#FF4500', // Oranye kemerahan menyala
                    weight: 3,        
                    opacity: 0.07,    // Super Transparan untuk efek penumpukan
                    smoothFactor: 1.5,
                    interactive: false
                }).addTo(map);
            }
        });

        // Autofocus kamera
        if (allCoords.length > 0) {
            const bounds = L.polyline(allCoords.flat()).getBounds();
            map.fitBounds(bounds, { padding: [50, 50] });
        }
    }

    // --- 6. FUNGSI TOGGLE TEMA LIVE ---
    function toggleThemeHM() {
        document.body.classList.toggle('dark-mode');
        const isNowDark = document.body.classList.contains('dark-mode');
        
        localStorage.setItem('theme', isNowDark ? 'dark' : 'light');
        document.getElementById('theme-icon-hm').textContent = isNowDark ? '☀️' : '🌙';
        
        // Panggil fungsi ganti peta Leaflet secara Live!
        setBasemap(isNowDark);
    }

    // Hilangkan loader saat render selesai
    window.onload = () => {
        document.getElementById('loader').style.display = 'none';
    };
</script>
</body>
</html>