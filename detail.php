<?php
// detail.php - Detail Aktivitas & Mesin Broadcast Peleton (EPIC 3)
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
// EPIC 3: HANDLER UPLOAD GAMBAR BROADCAST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'broadcast') {
    $imgData = $_POST['image'] ?? '';
    $room = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['room'] ?? '');
    
    if (empty($room) || empty($imgData)) {
        echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
        exit;
    }

    // Decode Base64 Image
    $imgData = str_replace('data:image/png;base64,', '', $imgData);
    $imgData = str_replace(' ', '+', $imgData);
    $data = base64_decode($imgData);

    // Pastikan folder temp lokal tersedia di Debian
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }

    // Simpan gambar
    $file_name = "flex_{$room}.png";
    $file_path = $temp_dir . '/' . $file_name;
    file_put_contents($file_path, $data);

    // Buat Trigger File untuk Guest (Agar HP mereka otomatis ter-redirect)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $img_url = $protocol . $host . $base_dir . '/temp/' . $file_name;

    $trigger_file = $log_dir . '/' . $room . '_broadcast.json';
    file_put_contents($trigger_file, json_encode([
        'url' => $img_url, 
        'timestamp' => time()
    ]));

    echo json_encode(['status' => 'success']);
    exit;
}

// Fungsi Decoder Polyline PHP untuk Export GPX
function decodePolylinePHP($encoded) {
    $length = strlen($encoded);
    $index = 0; $points = array(); $lat = 0; $lng = 0;
    while ($index < $length) {
        $b = 0; $shift = 0; $result = 0;
        do {
            $b = ord(substr($encoded, $index++, 1)) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20);
        $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
        $lat += $dlat;
        
        $shift = 0; $result = 0;
        do {
            $b = ord(substr($encoded, $index++, 1)) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20);
        $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
        $lng += $dlng;
        $points[] = array($lat * 1e-5, $lng * 1e-5);
    }
    return $points;
}

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // AMBIL DATA UTAMA
    $ride = $pdo->prepare("SELECT * FROM rides WHERE id = ?");
    $ride->execute([$id]);
    $data = $ride->fetch();

    if (!$data) die("Data tidak ditemukan, wak!");

    // ==========================================
    // EPIC 3: DETEKSI PELETON TERAKHIR (MAX 2 JAM)
    // ==========================================
    $peleton_names = [];
    $active_room = '';
    
    if (is_dir($log_dir)) {
        $host_files = glob("{$log_dir}/*_Host.json");
        $latest_time = 0;
        foreach ($host_files as $hf) {
            // Cek apakah file dibuat kurang dari 2 jam yang lalu
            if (filemtime($hf) > $latest_time && (time() - filemtime($hf) < 7200)) {
                $latest_time = filemtime($hf);
                $filename = basename($hf);
                $active_room = explode('_', $filename)[0];
            }
        }
        
        // Jika ada room yang aktif, tarik semua nama pesertanya
        if (!empty($active_room)) {
            $all_files = glob("{$log_dir}/{$active_room}_*.json");
            foreach ($all_files as $af) {
                $u = explode('_', basename($af))[1];
                $u = str_replace('.json', '', $u);
                if (strtolower($u) !== 'host' && strtolower($u) !== 'broadcast') {
                    $peleton_names[] = htmlspecialchars($u);
                }
            }
        }
    }

    // LOGIKA EXPORT GPX
    if (isset($_GET['export']) && $_GET['export'] === 'gpx') {
        $polyline = $data['polyline'];
        $points = [];
        if (!empty($polyline)) {
            if (strpos($polyline, '[') === 0) {
                $points = json_decode($polyline, true); 
            } else {
                $points = decodePolylinePHP($polyline); 
            }
        }

        header('Content-Type: application/gpx+xml');
        header('Content-Disposition: attachment; filename="kayooh_' . date('Ymd_Hi', strtotime($data['start_date'])) . '.gpx"');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<gpx version="1.1" creator="Kayooh Tracker" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
        echo '<trk><name>' . htmlspecialchars($data['name']) . '</name><trkseg>' . "\n";
        foreach ($points as $p) {
            echo '<trkpt lat="' . $p[0] . '" lon="' . $p[1] . '"></trkpt>' . "\n";
        }
        echo '</trkseg></trk></gpx>';
        exit;
    }

    // LOGIKA HAPUS
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM rides WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: dashboard.php');
        exit;
    }

    // LOGIKA EDIT NAMA DENGAN BENTENG KEAMANAN (XSS PROTECTION)
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $nama_baru = htmlspecialchars(strip_tags(trim($_POST['new_name'])), ENT_QUOTES, 'UTF-8');
        if (!empty($nama_baru)) {
            $stmt = $pdo->prepare("UPDATE rides SET name = ? WHERE id = ?");
            $stmt->execute([$nama_baru, $id]);
        }
        header("Location: detail.php?id=$id");
        exit;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
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
    <link rel="manifest" href="assets/site.webmanifest">

    <style>
        /* Desain Khusus Template Minimalis Transparan */
        #capture-minimal {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            width: 350px;
            padding: 40px 20px;
            color: var(--text-color);
            background: transparent !important;
            
            position: absolute;
            left: -9999px; 
            top: 0;
        }
        .minimal-item { margin-bottom: 25px; }
        .minimal-label { 
            font-size: 14px; 
            color: var(--text-color); 
            opacity: 0.8; 
            font-weight: 600; 
            margin-bottom: 5px; 
        }
        .minimal-value { 
            font-size: 36px; 
            font-weight: 900; 
            color: var(--primary-color); 
            letter-spacing: -1px;
            line-height: 1;
        }
        .minimal-value small { font-size: 18px; }
        
        /* Memaksa map Leaflet minimalis center dan fix lebarnya */
        #minimal-map {
            width: 100% !important;
            max-width: 320px !important; 
            height: 220px !important; 
            background: transparent !important;
            margin: 0 auto 10px auto !important; 
            border: none;
            display: block;
        }
        
        /* FIX CROP: Memaksa SVG Leaflet tidak memotong gambar rute */
        .leaflet-container { background: transparent !important; }
        .leaflet-overlay-pane svg { overflow: visible !important; }
        
        .minimal-logo { height: 45px; opacity: 0.9; margin-top: 5px; }

        /* Grid Seragam untuk Tombol Aksi */
        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        .btn-grid-item {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            padding: 14px 10px;
            width: 100%;
            box-sizing: border-box;
            border-radius: 10px;
            text-decoration: none;
            height: 100%;
            cursor: pointer;
            border: none;
            color: white;
            transition: all 0.2s;
        }
    </style>
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

<div class="dashboard-container">
    <div class="header">
        <a href="activities.php" class="logout-link" style="color: var(--text-color); opacity: 0.7;">&larr; KEMBALI</a>
        
        <div class="header-actions" style="display: flex; gap: 15px; align-items: center;">
            <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
            <span class="source-badge <?= $data['source'] === 'STRAVA' ? 'badge-strava' : 'badge-kayooh' ?>" style="color: #ffffff;">
                <?= $data['source'] ?>
            </span>
        </div>
    </div>

    <div id="capture-standard" style="border-radius: 16px; transition: all 0.3s;">
        <div id="share-header-std" style="display: none; align-items: center; justify-content: space-between; margin-bottom: 20px; padding: 0 10px;">
            <img src="assets/kayooh.png" alt="Kayooh" style="height: 35px;">
            <div style="text-align: right;">
                <span style="font-weight: 900; color: var(--primary-color); font-size: 16px; letter-spacing: -0.5px;">#KayoohTerus</span>
                <div style="font-size: 10px; color: #7f8c8d;"><?= date('d M Y', strtotime($data['start_date'])) ?></div>
            </div>
        </div>

        <div id="view-title">
            <h2 style="margin-top: 10px; margin-bottom: 5px;">
                <?= htmlspecialchars($data['name']) ?> 
                <span id="edit-btn-icon" style="cursor:pointer; font-size:14px; opacity:0.5;" onclick="toggleEdit()">✏️</span>
            </h2>
            <p id="view-date" style="font-size: 12px; color: #7f8c8d; margin-bottom: 20px;">
                <?= date('l, d F Y - H:i', strtotime($data['start_date'])) ?>
            </p>
        </div>

        <div id="edit-title" style="display: none; margin-bottom: 20px; background: var(--white); padding: 15px; border-radius: 10px; border: 1px solid var(--input-border);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <label style="font-size: 11px; font-weight: bold; color: var(--text-color); text-transform: uppercase;">Ubah Nama Aktivitas</label>
                <input type="text" name="new_name" value="<?= htmlspecialchars($data['name']) ?>" required style="margin: 8px 0; padding: 10px;">
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
        </div>
    </div>
    
    <div class="action-grid">
        <?php if (!empty($active_room)): ?>
            <button onclick="broadcastPeleton()" id="btn-broadcast" class="btn-grid-item" style="background: #8e44ad; grid-column: span 2; box-shadow: 0 4px 15px rgba(142, 68, 173, 0.4);">📢 BROADCAST PELETON</button>
        <?php endif; ?>
        
        <button onclick="generateShare('standard')" id="btn-share-std" class="btn-grid-item" style="background: var(--primary-color);">📸 SHARE MAP</button>
        <button onclick="generateShare('minimal')" id="btn-share-min" class="btn-grid-item" style="background: #34495e;">✨ SHARE STATS</button>
        <a href="?id=<?= $id ?>&export=gpx" class="btn-grid-item" style="background-color: #7f8c8d;">📥 EXPORT GPX</a>
        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus gowes ini, wak?');" style="margin: 0; padding: 0;">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn-grid-item" style="background-color: #e74c3c;">🗑️ HAPUS</button>
        </form>
    </div>

</div>

<div id="capture-minimal">
    <div class="minimal-item">
        <div class="minimal-label">Jarak</div>
        <div class="minimal-value"><?= number_format($data['distance'], 2) ?> <small>km</small></div>
    </div>
    
    <div class="minimal-item">
        <div class="minimal-label">Elevasi</div>
        <div class="minimal-value"><?= number_format($data['total_elevation_gain'], 0) ?> <small>m</small></div>
    </div>
    
    <div class="minimal-item">
        <div class="minimal-label">Waktu</div>
        <div class="minimal-value"><?= gmdate("H:i:s", $data['moving_time']) ?></div>
    </div>
    
    <div class="minimal-item">
        <div class="minimal-label">Kecepatan rata-rata</div>
        <div class="minimal-value"><?= str_replace('.', ',', number_format($data['average_speed'], 1)) ?> <small>kpj</small></div>
    </div>

    <div class="minimal-item">
        <div class="minimal-label">Suhu</div>
        <div class="minimal-value"><?= (isset($data['avg_temp']) && $data['avg_temp'] > 0) ? str_replace('.', ',', number_format($data['avg_temp'], 1)) . ' <small>&deg;C</small>' : '-- <small>&deg;C</small>' ?></div>
    </div>
    
    <?php if (!empty($peleton_names)): ?>
    <div class="minimal-item" style="margin-top: -15px; margin-bottom: 20px;">
        <div class="minimal-label" style="font-size: 11px; color: #f39c12; text-transform: uppercase;">🚴 Gowes Bareng</div>
        <div style="font-size: 12px; font-weight: bold; color: var(--text-color); opacity: 0.9;">
            Anda, <?= implode(', ', $peleton_names) ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="minimal-map"></div>

    <img src="assets/kayooh.png" class="minimal-logo" alt="Kayooh">
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // ---------------------------------------------------------
    // FUNGSI TOGGLE TEMA
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
    });

    // ---------------------------------------------------------
    // LOGIKA UI LAINNYA
    // ---------------------------------------------------------
    function toggleEdit() {
        const viewDiv = document.getElementById('view-title');
        const editDiv = document.getElementById('edit-title');
        if (editDiv.style.display === 'none') {
            editDiv.style.display = 'block'; viewDiv.style.display = 'none';
        } else {
            editDiv.style.display = 'none'; viewDiv.style.display = 'block';
        }
    }

    function decodePolyline(encoded) {
        if (!encoded) return [];
        if (encoded.startsWith('[')) {
            try { return JSON.parse(encoded); } catch(e) { return []; }
        }
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

    const rawData = <?= json_encode($data['polyline'] ?? '') ?>;
    const coords = decodePolyline(rawData);

    // INISIALISASI PETA 1: STANDARD
    const mapStd = L.map('map', { zoomControl: false }); 
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap', crossOrigin: true 
    }).addTo(mapStd);

    if (coords.length > 0) {
        const polylineStd = L.polyline(coords, {color: '#FF6600', weight: 5, opacity: 0.8}).addTo(mapStd);
        mapStd.fitBounds(polylineStd.getBounds(), {padding: [30, 30]});
    } else {
        mapStd.setView([-7.801, 110.373], 13);
    }

    // INISIALISASI PETA 2: MINIMALIST
    const mapMin = L.map('minimal-map', { 
        zoomControl: false, 
        attributionControl: false,
        dragging: false, 
        scrollWheelZoom: false 
    });

    if (coords.length > 0) {
        const polylineMin = L.polyline(coords, {color: '#FF6600', weight: 6, opacity: 1}).addTo(mapMin);
        mapMin.fitBounds(polylineMin.getBounds(), {padding: [40, 40]});
    } else {
        mapMin.setView([-7.801, 110.373], 13);
    }

    // ==========================================
    // EPIC 3: ENGINE UPLOAD BROADCAST PELETON
    // ==========================================
    function broadcastPeleton() {
        const btn = document.getElementById('btn-broadcast');
        btn.textContent = "⏳ MERENDER GAMBAR...";
        btn.style.pointerEvents = "none";
        
        const targetArea = document.getElementById('capture-minimal');
        const originalScrollY = window.scrollY;
        window.scrollTo(0, 0);
        
        targetArea.style.position = 'relative';
        targetArea.style.left = '0';
        
        setTimeout(() => {
            mapMin.invalidateSize(false);
            if (coords.length > 0) {
                mapMin.fitBounds(L.polyline(coords).getBounds(), {padding: [40, 40], animate: false});
            }
            
            setTimeout(() => {
                html2canvas(targetArea, {
                    useCORS: true, 
                    allowTaint: false, 
                    backgroundColor: null, 
                    scale: 2,
                    scrollX: 0,
                    scrollY: 0
                }).then(canvas => {
                    targetArea.style.position = 'absolute';
                    targetArea.style.left = '-9999px';
                    window.scrollTo(0, originalScrollY);
                    
                    btn.textContent = "🚀 MENGUNGGAH KE SERVER...";
                    
                    const imgData = canvas.toDataURL('image/png');
                    const formData = new FormData();
                    formData.append('action', 'broadcast');
                    formData.append('image', imgData);
                    formData.append('room', '<?= $active_room ?>');
                    
                    fetch('detail.php?id=<?= $id ?>', { 
                        method: 'POST', 
                        body: formData 
                    })
                    .then(res => res.json())
                    .then(data => {
                        if(data.status === 'success') {
                            btn.textContent = "✅ BROADCAST SUKSES!";
                            btn.style.background = "#27ae60";
                            alert("Gambar berhasil di-render dan didistribusikan!\nHP teman-teman Peleton akan otomatis diarahkan ke gambar ini.");
                        } else {
                            btn.textContent = "📢 BROADCAST PELETON";
                            btn.style.pointerEvents = "auto";
                            alert("Gagal mengunggah gambar: " + (data.message || "Unknown error"));
                        }
                    }).catch(err => {
                        btn.textContent = "📢 BROADCAST PELETON";
                        btn.style.pointerEvents = "auto";
                        alert("Gagal menghubungi server.");
                    });
                });
            }, 1500);
        }, 100);
    }

    // ENGINE PEMBUAT GAMBAR LOKAL
    function generateShare(mode) {
        const btnStd = document.getElementById('btn-share-std');
        const btnMin = document.getElementById('btn-share-min');
        
        let targetArea;
        const originalScrollY = window.scrollY;
        window.scrollTo(0, 0);

        if (mode === 'standard') {
            btnStd.textContent = "⏳ PROSES...";
            targetArea = document.getElementById('capture-standard');
            
            document.getElementById('share-header-std').style.display = 'flex';
            document.getElementById('edit-btn-icon').style.display = 'none';
            document.getElementById('view-date').style.display = 'none';
            
            targetArea.style.background = 'transparent'; 
            targetArea.style.padding = '20px';
            
            const viewTitle = document.getElementById('view-title');
            const detailGrid = document.querySelector('.detail-grid');
            targetArea.insertBefore(viewTitle, detailGrid);
            
            viewTitle.style.textAlign = 'center';
            viewTitle.style.marginTop = '15px'; 
            viewTitle.style.marginBottom = '25px'; 

        } else {
            btnMin.textContent = "⏳ PROSES...";
            targetArea = document.getElementById('capture-minimal');
            targetArea.style.position = 'relative';
            targetArea.style.left = '0';
        }

        setTimeout(() => { 
            if (mode === 'standard') {
                mapStd.invalidateSize(false);
                if (coords.length > 0) {
                    mapStd.fitBounds(L.polyline(coords).getBounds(), {padding: [30, 30], animate: false});
                }
            } else {
                mapMin.invalidateSize(false);
                if (coords.length > 0) {
                    mapMin.fitBounds(L.polyline(coords).getBounds(), {padding: [40, 40], animate: false});
                }
            }

            setTimeout(() => {
                html2canvas(targetArea, {
                    useCORS: true,
                    allowTaint: false,
                    backgroundColor: null,
                    scale: 2,
                    scrollX: 0,
                    scrollY: 0
                }).then(canvas => {
                    if (mode === 'standard') {
                        document.getElementById('share-header-std').style.display = 'none';
                        document.getElementById('edit-btn-icon').style.display = 'inline';
                        document.getElementById('view-date').style.display = 'block';
                        
                        targetArea.style.padding = '0';
                        
                        const viewTitle = document.getElementById('view-title');
                        const mapDiv = document.getElementById('map');
                        targetArea.insertBefore(viewTitle, mapDiv);
                        
                        viewTitle.style.textAlign = 'left';
                        viewTitle.style.marginTop = '0';
                        viewTitle.style.marginBottom = '0';
                        
                        btnStd.textContent = "📸 SHARE MAP";
                    } else {
                        targetArea.style.position = 'absolute';
                        targetArea.style.left = '-9999px';
                        btnMin.textContent = "✨ SHARE STATS";
                    }

                    window.scrollTo(0, originalScrollY);

                    const link = document.createElement('a');
                    link.download = `Kayooh_${mode}_${Date.now()}.png`;
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                }).catch(err => {
                    window.scrollTo(0, originalScrollY);
                    alert("Wah gagal generate gambar: " + err);
                    btnStd.textContent = "📸 SHARE MAP";
                    btnMin.textContent = "✨ SHARE STATS";
                });
            }, 1500); 
        }, 100); 
    }
</script>

</body>
</html>