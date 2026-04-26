<?php
// detail.php - Detail Aktivitas & Mesin Broadcast Peleton (Fixed Map & Badge v5.0)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || !isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}

$id = (int)$_GET['id'];
$log_dir = __DIR__ . '/radar_logs';
$temp_dir = __DIR__ . '/temp';

// ==========================================
// HANDLER UPLOAD GAMBAR BROADCAST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'broadcast') {
    $imgData = $_POST['image'] ?? '';
    $room = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['room'] ?? '');
    
    if (empty($room) || empty($imgData)) {
        echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
        exit;
    }

    $imgData = str_replace('data:image/png;base64,', '', $imgData);
    $imgData = str_replace(' ', '+', $imgData);
    $data = base64_decode($imgData);

    if (!is_dir($temp_dir)) { mkdir($temp_dir, 0755, true); }

    $file_name = "flex_{$room}.png";
    $file_path = $temp_dir . '/' . $file_name;
    file_put_contents($file_path, $data);

    if (rand(1, 10) === 1) { 
        foreach (glob("{$temp_dir}/*.png") as $file) {
            if (filemtime($file) < (time() - 86400)) { unlink($file); }
        }
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $img_url = $protocol . $host . $base_dir . '/temp/' . $file_name;

    $trigger_file = $log_dir . '/' . $room . '_broadcast.json';
    file_put_contents($trigger_file, json_encode(['url' => $img_url, 'timestamp' => time()]));

    echo json_encode(['status' => 'success']);
    exit;
}

// ==========================================
// FUNGSI DECODER POLYLINE PHP
// ==========================================
function decodePolylinePHP($encoded) {
    $length = strlen($encoded); $index = 0; $points = array(); $lat = 0; $lng = 0;
    while ($index < $length) {
        $b = 0; $shift = 0; $result = 0;
        do { $b = ord(substr($encoded, $index++, 1)) - 63; $result |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20);
        $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1)); $lat += $dlat;
        $shift = 0; $result = 0;
        do { $b = ord(substr($encoded, $index++, 1)) - 63; $result |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20);
        $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1)); $lng += $dlng;
        $points[] = array($lat * 1e-5, $lng * 1e-5);
    }
    return $points;
}

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $captain_name = 'Kapten';
    try {
        $stmt_set = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'captain_name'");
        if ($row = $stmt_set->fetch()) { if (trim($row['setting_value']) !== '') $captain_name = trim($row['setting_value']); }
    } catch (PDOException $e) {}

    $ride = $pdo->prepare("SELECT * FROM rides WHERE id = ?");
    $ride->execute([$id]);
    $data = $ride->fetch();

    if (!$data) die("Data tidak ditemukan, wak!");

    // ==========================================
    // ANTI-BUG FILE LOKAL (Solusi Peta Kosong)
    // ==========================================
    $polyline_data = $data['polyline'];
    if (strpos($polyline_data, '.json') !== false && file_exists($polyline_data)) {
        $polyline_data = file_get_contents($polyline_data);
    }

    // ==========================================
    // LOGIKA PESERTA PELETON
    // ==========================================
    $saved_participants = [];
    if (!empty($data['participants']) && $data['participants'] !== '[]') {
        $saved_participants = json_decode($data['participants'], true);
        if (!is_array($saved_participants)) $saved_participants = [];
    }

    $peleton_names = [];
    $active_room = '';
    $peleton_stats = [];

    $peleton_stats[] = [
        'name' => $captain_name,
        'dist' => number_format($data['distance'], 2),
        'speed' => number_format($data['average_speed'], 1),
        'is_host' => true
    ];
    
    if (is_dir($log_dir)) {
        $host_files = glob("{$log_dir}/*_" . md5('Host') . ".json");
        if (empty($host_files)) $host_files = glob("{$log_dir}/*_Host.json");
        
        $latest_time = 0;
        foreach ($host_files as $hf) {
            if (filemtime($hf) > $latest_time && (time() - filemtime($hf) < 7200)) {
                $latest_time = filemtime($hf);
                $active_room = explode('_', basename($hf))[0];
            }
        }
        
        if (!empty($active_room)) {
            $all_files = glob("{$log_dir}/{$active_room}_*.json");
            foreach ($all_files as $af) {
                $rider_data = json_decode(file_get_contents($af), true);
                if ($rider_data) {
                    $u = $rider_data['user'];
                    if (strtolower($u) !== 'host' && strtolower($u) !== 'kapten' && strtolower($u) !== 'broadcast') {
                        $peleton_names[] = htmlspecialchars($u);
                        $r_dist = isset($rider_data['distance']) ? ($rider_data['distance'] / 1000) : 0;
                        $r_speed = isset($rider_data['speed']) ? $rider_data['speed'] : 0;
                        $peleton_stats[] = [ 'name' => htmlspecialchars($u), 'dist' => number_format($r_dist, 2), 'speed' => number_format($r_speed, 1), 'is_host' => false ];
                    }
                }
            }
        }
    }

    $flexing_peleton_text = "";
    if (strpos($data['source'], 'PELETON') !== false && strpos($data['source'], 'IMPORT') !== false) {
        $parts = explode(' | ', $data['name']);
        if (count($parts) > 1) {
            $data['name'] = trim(str_replace('👥', '', $parts[0])); 
            $flexing_peleton_text = trim($parts[1]);
        } else { $flexing_peleton_text = "Data Peleton (Impor Manual)"; }
    } elseif (!empty($saved_participants)) {
        $flexing_peleton_text = implode(', ', $saved_participants);
    } elseif (!empty($peleton_names)) {
        $flexing_peleton_text = $captain_name . ", " . implode(', ', $peleton_names);
    }

    // ==========================================
    // LOGIKA EXPORT GPX (UNIVERSAL)
    // ==========================================
    if (isset($_GET['export']) && $_GET['export'] === 'gpx') {
        $points = [];
        if (!empty($polyline_data)) {
            if (strpos($polyline_data, '[') === 0 || strpos($polyline_data, '{') === 0) {
                $raw = json_decode($polyline_data, true);
                foreach($raw as $p) {
                    if(isset($p[0])) $points[] = [$p[0], $p[1]];
                    elseif(isset($p['lat'])) $points[] = [$p['lat'], $p['lng']];
                }
            } elseif (strpos($polyline_data, 'http') === 0) {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $json_r2 = @file_get_contents($polyline_data, false, $ctx);
                if ($json_r2) {
                    $raw = json_decode($json_r2, true);
                    foreach($raw as $p) {
                        if(isset($p[0])) $points[] = [$p[0], $p[1]];
                        elseif(isset($p['lat'])) $points[] = [$p['lat'], $p['lng']];
                    }
                }
            } else {
                $points = decodePolylinePHP($polyline_data); 
            }
        }

        header('Content-Type: application/gpx+xml');
        header('Content-Disposition: attachment; filename="kayooh_' . date('Ymd_Hi', strtotime($data['start_date'])) . '.gpx"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<gpx version=\"1.1\" creator=\"Kayooh Tracker\" xmlns=\"http://www.topografix.com/GPX/1/1\">\n";
        echo '<trk><name>' . htmlspecialchars($data['name']) . '</name><trkseg>' . "\n";
        foreach ($points as $p) { echo '  <trkpt lat="' . $p[0] . '" lon="' . $p[1] . '"></trkpt>' . "\n"; }
        echo '</trkseg></trk></gpx>'; exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM rides WHERE id = ?"); $stmt->execute([$id]);
        header('Location: dashboard.php'); exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $nama_baru = htmlspecialchars(strip_tags(trim($_POST['new_name'])), ENT_QUOTES, 'UTF-8');
        if (!empty($nama_baru)) {
            if (strpos($data['source'], 'PELETON') !== false && $flexing_peleton_text !== "") {
                $nama_baru = "👥 " . $nama_baru . " | " . $flexing_peleton_text;
            }
            $stmt = $pdo->prepare("UPDATE rides SET name = ? WHERE id = ?"); $stmt->execute([$nama_baru, $id]);
        }
        header("Location: detail.php?id=$id"); exit;
    }

} catch (PDOException $e) { die("Database error: " . $e->getMessage()); }

// ==========================================
// LOGIKA SENSOR BADGE V5.0
// ==========================================
$src = strtoupper($data['source'] ?? 'KAYOOH');
if ($src === 'V5_CHUNKING') $src = 'KAYOOH';
$badge_class = 'badge-kayooh';
if (strpos($src, 'STRAVA') !== false) $badge_class = 'badge-strava';
elseif (strpos($src, 'PELETON') !== false) $badge_class = 'badge-peleton';
elseif (strpos($src, 'GPX') !== false) $badge_class = 'badge-gpx';
$src_display = str_replace('_', ' ', $src);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['name']) ?> - Kayooh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <style>
        /* FIX OFFSIDE: Terapkan Box Sizing Global */
        * { box-sizing: border-box; }
        
        .badge-kayooh { background: #e67e22; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; }
        .badge-strava { background: #fc4c02; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; }
        .badge-peleton { background: #e74c3c; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; }
        .badge-gpx { background: #27ae60; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; }

        /* Mencegah offside di HP */
        .dashboard-container { max-width: 100%; overflow-x: hidden; padding: 20px 15px; }
        
        /* ========================================================
           FIX LEAFLET TILE SEAMS (PETA TERBELAH)
           Mencegah CSS global mengacak-acak ukuran gambar Tile
        ======================================================== */
        .leaflet-container img.leaflet-tile {
            max-width: none !important;
            max-height: none !important;
            width: 256px !important;
            height: 256px !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        #capture-minimal {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; width: 350px; padding: 40px 20px; color: var(--text-color);
            background: transparent !important; position: absolute; left: -9999px; top: 0;
        }
        
        .minimal-item { margin-bottom: 25px; }
        .minimal-label { font-size: 14px; color: var(--text-color); opacity: 0.8; font-weight: 600; margin-bottom: 5px; }
        .minimal-value { font-size: 36px; font-weight: 900; color: var(--primary-color); letter-spacing: -1px; line-height: 1; }
        .minimal-value small { font-size: 18px; }
        
        #minimal-map { width: 100% !important; max-width: 320px !important; height: 220px !important; background: transparent !important; margin: 0 auto 10px auto !important; border: none; display: block; }
        .leaflet-container { background: transparent !important; }
        .leaflet-overlay-pane svg { overflow: visible !important; }
        .minimal-logo { height: 45px; opacity: 0.9; margin-top: 5px; }

        .action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 25px; margin-bottom: 15px; width: 100%; }
        .btn-grid-item { display: flex; align-items: center; justify-content: center; text-align: center; font-size: 12px; font-weight: bold; padding: 14px 10px; width: 100%; border-radius: 10px; text-decoration: none; cursor: pointer; border: none; color: white; transition: all 0.2s; }
        
        .peleton-card { grid-column: span 2; background: rgba(52, 152, 219, 0.1); border: 1px dashed #3498db; }
        .dark-mode .peleton-card { background: rgba(52, 152, 219, 0.05); border-color: rgba(52, 152, 219, 0.3); }
        .peleton-label { color: #2980b9; }
        .dark-mode .peleton-label { color: #3498db; }

        /* FIX PETA TERBELAH & TILE SEAMS */
        .leaflet-container img.leaflet-tile {
            max-width: none !important;
            max-height: none !important;
            width: 256px !important;
            height: 256px !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* Media Query HP */
        @media (max-width: 480px) {
            .action-grid { grid-template-columns: 1fr; }
            .btn-grid-item { padding: 16px 10px; font-size: 13px; }
            #btn-broadcast { grid-column: span 1 !important; }
        }
    </style>
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

<div class="dashboard-container">
    <div class="header" style="flex-wrap: wrap;">
        <a href="dashboard.php" class="logout-link" style="color: var(--text-color); opacity: 0.7;">&larr; KEMBALI</a>
        <div class="header-actions" style="display: flex; gap: 10px; align-items: center;">
            <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon" style="padding: 5px 10px;">🌙</button>
            <span class="<?= $badge_class ?>" style="color: #ffffff;"><?= htmlspecialchars($src_display) ?></span>
        </div>
    </div>

    <div id="capture-standard" style="border-radius: 16px; transition: all 0.3s; width: 100%;">
        <div id="share-header-std" style="display: none; align-items: center; justify-content: space-between; margin-bottom: 20px; padding: 0 10px;">
            <img src="assets/kayooh.png" alt="Kayooh" style="height: 35px;">
            <div style="text-align: right;">
                <span style="font-weight: 900; color: var(--primary-color); font-size: 16px; letter-spacing: -0.5px;">#KayoohTerus</span>
                <div style="font-size: 10px; color: #7f8c8d;"><?= date('d M Y', strtotime($data['start_date'])) ?></div>
            </div>
        </div>

        <div id="view-title">
            <h2 style="margin-top: 10px; margin-bottom: 5px; word-wrap: break-word;">
                <?= htmlspecialchars($data['name']) ?> 
                <span id="edit-btn-icon" style="cursor:pointer; font-size:14px; opacity:0.5;" onclick="toggleEdit()">✏️</span>
            </h2>
            <p id="view-date" style="font-size: 12px; color: var(--text-color); opacity: 0.8; margin-bottom: 20px;">
                <?php
                $ts = strtotime($data['start_date']);
                $hari_id = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
                $bulan_id = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
                echo $hari_id[date('l', $ts)] . ', ' . date('d', $ts) . ' ' . $bulan_id[date('F', $ts)] . ' ' . date('Y - H:i', $ts);
                ?>
            </p>
        </div>

        <div id="edit-title" style="display: none; margin-bottom: 20px; background: var(--white); padding: 15px; border-radius: 10px; border: 1px solid var(--input-border);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <label style="font-size: 11px; font-weight: bold; color: var(--text-color); text-transform: uppercase;">Ubah Nama Aktivitas</label>
                <input type="text" name="new_name" value="<?= htmlspecialchars($data['name']) ?>" required style="margin: 8px 0; padding: 10px; width: 100%;">
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn-grid-item" style="background: var(--primary-color);">SIMPAN</button>
                    <button type="button" class="btn-grid-item" style="background: #95a5a6;" onclick="toggleEdit()">BATAL</button>
                </div>
            </form>
        </div>

        <div id="map"></div>

        <div class="detail-grid">
            <div class="detail-card">
                <label>Jarak</label>
                <span><?= number_format($data['distance'], 2) ?> <small>km</small></span>
            </div>
            <div class="detail-card">
                <label>Waktu</label>
                <span><?= gmdate("H:i:s", $data['moving_time']) ?></span>
            </div>
            <div class="detail-card">
                <label>Kec. Rata-rata</label>
                <span><?= number_format($data['average_speed'], 1) ?> <small>km/h</small></span>
            </div>
            <div class="detail-card">
                <label>Elevasi</label>
                <span><?= number_format($data['total_elevation_gain'], 0) ?> <small>m</small></span>
            </div>
            <div class="detail-card" style="grid-column: span 2;">
                <label>Suhu Rata-rata</label>
                <span><?= (isset($data['avg_temp']) && $data['avg_temp'] > 0) ? number_format($data['avg_temp'], 1) . ' <small>&deg;C</small>' : '-- <small>&deg;C</small>' ?></span>
            </div>

            <?php if ($flexing_peleton_text !== ""): ?>
                <div class="detail-card peleton-card">
                    <label class="peleton-label">👥 Peserta Peleton</label>
                    <span style="font-size: 13px; font-weight: normal; white-space: normal; line-height: 1.5; margin-top: 5px; display: block;">
                        <?= htmlspecialchars($flexing_peleton_text) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="action-grid">
        <?php if (!empty($active_room)): ?>
            <button onclick="broadcastPeleton()" id="btn-broadcast" class="btn-grid-item" style="background: #8e44ad; grid-column: span 2; box-shadow: 0 4px 15px rgba(142, 68, 173, 0.4);">📢 BROADCAST PELETON</button>
        <?php endif; ?>
        <button onclick="generateShare('standard')" id="btn-share-std" class="btn-grid-item" style="background: var(--primary-color);">📸 SHARE MAP</button>
        <button onclick="generateShare('minimal')" id="btn-share-min" class="btn-grid-item" style="background: #34495e;">✨ SHARE STATS</button>
        <button onclick="window.location.href='video_flex.php?id=<?= $id ?>'" class="btn-grid-item" style="background: #6f42c1;">🎬 BUAT VIDEO</button>
        <a href="?id=<?= $id ?>&export=gpx" class="btn-grid-item" style="background-color: #7f8c8d;">📥 EXPORT GPX</a>
        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus gowes ini, wak?');" style="margin: 0; padding: 0;">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn-grid-item" style="background-color: #e74c3c;">🗑️ HAPUS</button>
        </form>
    </div>
</div>

<div id="capture-minimal">
    <?php if (count($peleton_stats) > 1): ?>
        <div style="width:100%; margin-top:-10px; margin-bottom:25px; background: rgba(52, 152, 219, 0.08); border-radius:12px; padding:15px; border: 1px dashed rgba(52, 152, 219, 0.4); text-align: left;">
            <div style="font-size:12px; font-weight:900; color:#2980b9; margin-bottom:12px; text-align: center; letter-spacing: 1px;">🏆 HASIL PELETON</div>
            <?php foreach($peleton_stats as $ps): ?>
                <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:8px; border-bottom:1px solid rgba(0,0,0,0.05); padding-bottom:6px;">
                    <span style="font-weight:bold; color:var(--text-color);"><?= $ps['is_host'] ? '🏁' : '🚴' ?> <?= $ps['name'] ?></span>
                    <span style="color:#64748b; font-family:monospace; font-weight:bold;">
                        <span style="color:var(--primary-color);"><?= $ps['dist'] ?>km</span> | <?= $ps['speed'] ?>kpj
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (count($peleton_stats) <= 1): ?>
        <div class="minimal-item"><div class="minimal-label">Jarak</div><div class="minimal-value"><?= number_format($data['distance'], 2) ?> <small>km</small></div></div>
        <div class="minimal-item"><div class="minimal-label">Elevasi</div><div class="minimal-value"><?= number_format($data['total_elevation_gain'], 0) ?> <small>m</small></div></div>
        <div class="minimal-item"><div class="minimal-label">Waktu</div><div class="minimal-value"><?= gmdate("H:i:s", $data['moving_time']) ?></div></div>
        <div class="minimal-item"><div class="minimal-label">Kecepatan rata-rata</div><div class="minimal-value"><?= str_replace('.', ',', number_format($data['average_speed'], 1)) ?> <small>kpj</small></div></div>
        <div class="minimal-item"><div class="minimal-label">Suhu</div><div class="minimal-value"><?= (isset($data['avg_temp']) && $data['avg_temp'] > 0) ? str_replace('.', ',', number_format($data['avg_temp'], 1)) . ' <small>&deg;C</small>' : '-- <small>&deg;C</small>' ?></div></div>
        
        <?php if ($flexing_peleton_text !== ""): ?>
        <div class="minimal-item" style="margin-top: -15px; margin-bottom: 20px;">
            <div class="minimal-label" style="font-size: 11px; color: #f39c12; text-transform: uppercase;">🚴 Gowes Bareng</div>
            <div style="font-size: 12px; font-weight: bold; color: var(--text-color); opacity: 0.9;">
                <?= htmlspecialchars($flexing_peleton_text) ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div id="minimal-map"></div>
    <img src="assets/kayooh.png" class="minimal-logo" alt="Kayooh">
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // --- 1. INISIALISASI PETA GLOBAL & THEME ENGINE ---
    const mapStd = L.map('map', { zoomControl: false }); 
    const mapMin = L.map('minimal-map', { zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false });
    
    let baseLayerStd = null, baseLayerMin = null;
    let coords = []; // Variabel global untuk rute

    const currentIsDark = localStorage.getItem('theme') === 'dark';
    const initTileUrl = currentIsDark 
        ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
        : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

    // Pasang Tile layer dinamis pertama kali
    baseLayerStd = L.tileLayer(initTileUrl, { attribution: '&copy; OpenStreetMap', crossOrigin: true }).addTo(mapStd);
    baseLayerMin = L.tileLayer(initTileUrl, { crossOrigin: true }).addTo(mapMin);

    window.addEventListener('DOMContentLoaded', () => { 
        if(localStorage.getItem('theme') === 'dark') { document.getElementById('theme-icon').textContent = '☀️'; }
    });

    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';

        const newTileUrl = isDark 
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

        if (mapStd && baseLayerStd) {
            mapStd.removeLayer(baseLayerStd);
            baseLayerStd = L.tileLayer(newTileUrl, { crossOrigin: true }).addTo(mapStd);
        }
        if (mapMin && baseLayerMin) {
            mapMin.removeLayer(baseLayerMin);
            baseLayerMin = L.tileLayer(newTileUrl, { crossOrigin: true }).addTo(mapMin);
        }
    }

    // --- 2. INTERAKSI UI ---
    function toggleEdit() {
        const viewDiv = document.getElementById('view-title');
        const editDiv = document.getElementById('edit-title');
        if (editDiv.style.display === 'none') {
            editDiv.style.display = 'block'; viewDiv.style.display = 'none';
        } else {
            editDiv.style.display = 'none'; viewDiv.style.display = 'block';
        }
    }

    // --- 3. MESIN PEMECAH SANDI POLYLINE STRAVA ---
    function decodePolyline(encoded) {
        if (!encoded) return [];
        if (encoded.startsWith('[')) { try { return JSON.parse(encoded); } catch(e) { return []; } }
        var points = []; var index = 0, len = encoded.length; var lat = 0, lng = 0;
        while (index < len) {
            var b, shift = 0, result = 0;
            do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
            var dlat = ((result & 1) ? ~(result >> 1) : (result >> 1)); lat += dlat;
            shift = 0; result = 0;
            do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
            var dlng = ((result & 1) ? ~(result >> 1) : (result >> 1)); lng += dlng;
            points.push([lat / 1e5, lng / 1e5]);
        }
        return points;
    }

    // --- 4. RENDER DATA RUTE ---
    const rawData = <?= json_encode($polyline_data ?? '') ?>;

    async function loadMapData() {
        if (!rawData || rawData.trim() === '') {
            mapStd.setView([-7.801, 110.373], 13); mapMin.setView([-7.801, 110.373], 13); return;
        }

        try {
            let rawCoords = [];
            if (rawData.startsWith('http')) {
                const response = await fetch(rawData);
                rawCoords = await response.json();
            } else if (rawData.startsWith('[') || rawData.startsWith('{')) {
                rawCoords = JSON.parse(rawData);
            } else {
                rawCoords = decodePolyline(rawData);
            }

            coords = rawCoords.map(p => {
                if (Array.isArray(p)) return [parseFloat(p[0]), parseFloat(p[1])];
                if (p.lat !== undefined) return [parseFloat(p.lat), parseFloat(p.lng)];
                return null;
            }).filter(p => p !== null && !isNaN(p[0]) && !isNaN(p[1]));

            if (coords && coords.length > 0) {
                const polylineStd = L.polyline(coords, {color: '#FF6600', weight: 5, opacity: 0.8}).addTo(mapStd);
                mapStd.fitBounds(polylineStd.getBounds(), {padding: [30, 30]});
                L.circleMarker(coords[0], { radius: 5, color: '#27ae60', fillOpacity: 1 }).addTo(mapStd);
                L.circleMarker(coords[coords.length - 1], { radius: 5, color: '#c0392b', fillOpacity: 1 }).addTo(mapStd);

                const polylineMin = L.polyline(coords, {color: '#FF6600', weight: 6, opacity: 1}).addTo(mapMin);
                mapMin.fitBounds(polylineMin.getBounds(), {padding: [40, 40]});

                // FIX PETA TERBELAH: Paksa render ulang tile setelah bounding box diset
                setTimeout(() => { 
                    mapStd.invalidateSize(); 
                    mapMin.invalidateSize(); 
                }, 500);
            }
        } catch (e) {
            console.error("Gagal memuat rute peta:", e);
            document.getElementById('map').innerHTML = "<div style='display:flex;justify-content:center;align-items:center;height:100%;color:#c0392b;font-weight:bold;text-align:center;padding:20px;'>❌ Gagal memuat peta. Cek konfigurasi satelit Anda.</div>";
        }
    }
    loadMapData();

    // --- 5. FUNGSI BROADCAST & SHARE KAMERA ---
    function broadcastPeleton() {
        const btn = document.getElementById('btn-broadcast');
        btn.textContent = "⏳ MERENDER..."; btn.style.pointerEvents = "none";
        const targetArea = document.getElementById('capture-minimal');
        const originalScrollY = window.scrollY; window.scrollTo(0, 0);
        targetArea.style.position = 'relative'; targetArea.style.left = '0';
        
        setTimeout(() => {
            mapMin.invalidateSize(false);
            if (coords.length > 0) mapMin.fitBounds(L.polyline(coords).getBounds(), {padding: [40, 40], animate: false});
            
            setTimeout(() => {
                html2canvas(targetArea, { useCORS: true, allowTaint: false, backgroundColor: null, scale: 2, scrollX: 0, scrollY: 0 }).then(canvas => {
                    targetArea.style.position = 'absolute'; targetArea.style.left = '-9999px'; window.scrollTo(0, originalScrollY);
                    btn.textContent = "🚀 MENGUNGGAH...";
                    const formData = new FormData();
                    formData.append('action', 'broadcast'); formData.append('image', canvas.toDataURL('image/png')); formData.append('room', '<?= $active_room ?>');
                    
                    fetch('detail.php?id=<?= $id ?>', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
                        if(data.status === 'success') { btn.textContent = "✅ SUKSES!"; btn.style.background = "#27ae60"; alert("Broadcast terkirim ke Peleton!"); } 
                        else { btn.textContent = "📢 BROADCAST PELETON"; btn.style.pointerEvents = "auto"; alert("Gagal!"); }
                    }).catch(err => { btn.textContent = "📢 BROADCAST PELETON"; btn.style.pointerEvents = "auto"; alert("Gagal server."); });
                });
            }, 1500);
        }, 100);
    }

    function generateShare(mode) {
        const btnStd = document.getElementById('btn-share-std');
        const btnMin = document.getElementById('btn-share-min');
        let targetArea; const originalScrollY = window.scrollY; window.scrollTo(0, 0);

        if (mode === 'standard') {
            btnStd.textContent = "⏳ PROSES..."; targetArea = document.getElementById('capture-standard');
            document.getElementById('share-header-std').style.display = 'flex'; document.getElementById('edit-btn-icon').style.display = 'none'; document.getElementById('view-date').style.display = 'none';
            targetArea.style.background = 'transparent'; targetArea.style.padding = '20px';
            const viewTitle = document.getElementById('view-title'); const detailGrid = document.querySelector('.detail-grid');
            targetArea.insertBefore(viewTitle, detailGrid); viewTitle.style.textAlign = 'center'; viewTitle.style.marginTop = '15px'; viewTitle.style.marginBottom = '25px'; 
        } else {
            btnMin.textContent = "⏳ PROSES..."; targetArea = document.getElementById('capture-minimal');
            targetArea.style.position = 'relative'; targetArea.style.left = '0';
        }

        setTimeout(() => { 
            if (mode === 'standard') { mapStd.invalidateSize(false); if (coords.length > 0) mapStd.fitBounds(L.polyline(coords).getBounds(), {padding: [30, 30], animate: false}); } 
            else { mapMin.invalidateSize(false); if (coords.length > 0) mapMin.fitBounds(L.polyline(coords).getBounds(), {padding: [40, 40], animate: false}); }

            setTimeout(() => {
                html2canvas(targetArea, { useCORS: true, allowTaint: false, backgroundColor: null, scale: 2, scrollX: 0, scrollY: 0 }).then(canvas => {
                    if (mode === 'standard') {
                        document.getElementById('share-header-std').style.display = 'none'; document.getElementById('edit-btn-icon').style.display = 'inline'; document.getElementById('view-date').style.display = 'block';
                        targetArea.style.padding = '0';
                        const viewTitle = document.getElementById('view-title'); const mapDiv = document.getElementById('map'); targetArea.insertBefore(viewTitle, mapDiv);
                        viewTitle.style.textAlign = 'left'; viewTitle.style.marginTop = '0'; viewTitle.style.marginBottom = '0';
                        btnStd.textContent = "📸 SHARE MAP";
                    } else {
                        targetArea.style.position = 'absolute'; targetArea.style.left = '-9999px'; btnMin.textContent = "✨ SHARE STATS";
                    }
                    window.scrollTo(0, originalScrollY);
                    const link = document.createElement('a'); link.download = `Kayooh_${mode}_${Date.now()}.png`; link.href = canvas.toDataURL('image/png'); link.click();
                }).catch(err => {
                    window.scrollTo(0, originalScrollY); alert("Gagal generate gambar!");
                    btnStd.textContent = "📸 SHARE MAP"; btnMin.textContent = "✨ SHARE STATS";
                });
            }, 1500); 
        }, 100); 
    }
</script>

</body>
</html>