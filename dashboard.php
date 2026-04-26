<?php
// dashboard.php - Kayooh v5.0 (Fixed Modal UI & Safe DB Restore)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// --- LOGIKA AMAN DOWNLOAD DATABASE (Harus di paling atas agar tidak error Header) ---
if (isset($_GET['download_db'])) {
    if (file_exists($db_file)) {
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="Kayooh_Backup_' . date('Y-m-d_H-i') . '.sqlite"');
        header('Content-Length: ' . filesize($db_file));
        readfile($db_file);
        exit;
    }
}

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $msg_settings = $msg_password = $msg_db = '';
    $show_modal = $show_db_modal = false;

    // --- LOGIKA SIMPAN PENGATURAN (PROFIL, TELEGRAM, R2) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $show_modal = true;
        $fields = ['captain_name', 'telegram_bot_token', 'telegram_chat_id', 'r2_account_id', 'r2_access_key', 'r2_secret_key', 'r2_bucket', 'r2_public_url'];
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            foreach ($fields as $key) {
                $val = trim($_POST[$key] ?? '');
                if ($key === 'captain_name' && empty($val)) $val = 'Kapten';
                $stmt->execute([$key, $val]);
            }
            $msg_settings = "<div class='alert success'>✅ Pengaturan Kayooh berhasil disimpan!</div>";
        } catch (Exception $e) { $msg_settings = "<div class='alert error'>❌ Gagal: {$e->getMessage()}</div>"; }
    }

    // --- LOGIKA GANTI PASSWORD ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $show_modal = true;
        $new_pass = $_POST['new_password'] ?? '';
        if (empty($new_pass)) $msg_password = "<div class='alert error'>❌ Password tidak boleh kosong!</div>";
        elseif ($new_pass !== ($_POST['confirm_password'] ?? '')) $msg_password = "<div class='alert error'>❌ Konfirmasi tidak cocok!</div>";
        else {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'password'")->execute([password_hash($new_pass, PASSWORD_DEFAULT)]);
            $msg_password = "<div class='alert success'>✅ Password diperbarui!</div>";
        }
    }

    // --- LOGIKA HAPUS GOWES ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ride'])) {
        $pdo->prepare("DELETE FROM rides WHERE id = ?")->execute([(int)$_POST['ride_id']]);
        header("Location: dashboard.php"); exit;
    }

    // --- LOGIKA IMPORT (RESTORE) DATABASE SQLITE BINER ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_db'])) {
        $show_db_modal = true;
        if (isset($_FILES['db_file']) && $_FILES['db_file']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['db_file']['name'], PATHINFO_EXTENSION);
            if (strtolower($ext) === 'sqlite' || strtolower($ext) === 'db') {
                // Langsung timpa file fisiknya!
                if (move_uploaded_file($_FILES['db_file']['tmp_name'], $db_file)) {
                    $msg_db = "<div class='alert success'>✅ Database Kayooh berhasil di-restore!</div>";
                } else {
                    $msg_db = "<div class='alert error'>❌ Gagal memindahkan file database.</div>";
                }
            } else {
                $msg_db = "<div class='alert error'>❌ Format salah! Harus .sqlite atau .db</div>";
            }
        }
    }

    // --- AMBIL DATA SETTINGS & STATS ---
    $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $total_km = $pdo->query("SELECT SUM(distance) FROM rides")->fetchColumn() ?: 0;
    $total_hours = floor(($pdo->query("SELECT SUM(moving_time) FROM rides")->fetchColumn() ?: 0) / 3600);

    $current_month = date('Y-m');
    $month_km = $pdo->query("SELECT SUM(distance) FROM rides WHERE strftime('%Y-%m', start_date) = '$current_month'")->fetchColumn() ?: 0;
    $month_rides = $pdo->query("SELECT COUNT(*) FROM rides WHERE strftime('%Y-%m', start_date) = '$current_month'")->fetchColumn() ?: 0;

    // --- PAGING & SEARCH ---
    $search = trim($_GET['q'] ?? '');
    $limit = 10;
    $offset = (max(1, (int)($_GET['page'] ?? 1)) - 1) * $limit;

    if ($search) {
        $stmt = $pdo->prepare("SELECT * FROM rides WHERE name LIKE ? ORDER BY start_date DESC LIMIT ? OFFSET ?");
        $stmt->execute(["%$search%", $limit, $offset]);
        $total_rides = $pdo->prepare("SELECT COUNT(*) FROM rides WHERE name LIKE ?");
        $total_rides->execute(["%$search%"]);
        $total_rides = $total_rides->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM rides ORDER BY start_date DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $total_rides = $pdo->query("SELECT COUNT(*) FROM rides")->fetchColumn() ?: 0;
    }
    
    $rides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_pages = ceil($total_rides / $limit);

} catch (PDOException $e) { die("Database error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kayooh - Dashboard</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root { --bg-body: #f4f7f6; --text-main: #2c3e50; --text-muted: #7f8c8d; --card-bg: #ffffff; --card-border: #ecf0f1; --primary: #e67e22; --primary-hover: #d35400; --accent: #3498db; --danger: #e74c3c; --success: #2ecc71; --modal-overlay: rgba(0,0,0,0.6); }
        body.dark-mode { --bg-body: #121212; --text-main: #ecf0f1; --text-muted: #bdc3c7; --card-bg: #1e1e1e; --card-border: #333333; --primary: #f39c12; --primary-hover: #e67e22; --accent: #2980b9; --modal-overlay: rgba(0,0,0,0.8); }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 20px; transition: background 0.3s, color 0.3s; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid var(--card-border); padding-bottom: 15px; }
        .header h1 { margin: 0; color: var(--primary); font-size: 24px; display: flex; align-items: center; gap: 10px; }
        .header-actions { display: flex; gap: 10px; }
        .btn { padding: 10px 15px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-accent { background-color: var(--accent); color: #fff; }
        .btn-outline { background-color: transparent; border: 1px solid var(--text-muted); color: var(--text-main); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--card-bg); padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center; border: 1px solid var(--card-border); transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .stat-value { font-size: 28px; font-weight: 900; color: var(--primary); margin: 5px 0; }
        .stat-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
        .search-bar { width: 100%; padding: 12px 15px; border: 1px solid var(--card-border); border-radius: 10px; background: var(--card-bg); color: var(--text-main); margin-bottom: 20px; font-size: 14px; box-sizing: border-box; }
        .ride-list { display: flex; flex-direction: column; gap: 12px; }
        .ride-item { background: var(--card-bg); padding: 15px 20px; border-radius: 10px; border: 1px solid var(--card-border); display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.2s; position: relative; overflow: hidden; }
        .ride-item::before { content: ''; position: absolute; left: 0; top: 0; width: 4px; height: 100%; background: var(--primary); opacity: 0; transition: 0.2s; }
        .ride-item:hover::before { opacity: 1; }
        .ride-item:hover { background: var(--card-border); }
        .ride-info { flex-grow: 1; }
        .ride-title { font-weight: bold; font-size: 16px; margin-bottom: 5px; }
        .ride-date { font-size: 12px; color: var(--text-muted); }
        .ride-stats { display: flex; gap: 15px; margin-top: 8px; font-size: 13px; font-weight: 500; }
        .ride-stats span { display: flex; align-items: center; gap: 4px; }
        .pagination { display: flex; justify-content: center; gap: 10px; margin-top: 30px; }
        .page-link { padding: 8px 12px; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 6px; color: var(--text-main); text-decoration: none; font-size: 14px; transition: 0.2s; }
        .page-link.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .page-link:hover:not(.active) { background: var(--card-border); }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: var(--modal-overlay); backdrop-filter: blur(5px); }
        .modal-content { background-color: var(--card-bg); margin: 5vh auto; padding: 30px; border-radius: 15px; width: 90%; max-width: 500px; max-height: 85vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3); position: relative; }
        .close-btn { position: absolute; top: 20px; right: 20px; font-size: 24px; font-weight: bold; color: var(--text-muted); cursor: pointer; line-height: 1; }
        .close-btn:hover { color: var(--danger); }
        #modal-map { height: 250px; width: 100%; background: #eee; border-radius: 10px; margin: 15px 0; border: 1px solid var(--card-border); z-index: 1; }
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 5px; font-weight: bold; }
        .form-group input[type="text"], .form-group input[type="password"] { width: 100%; padding: 10px 12px; border: 1px solid var(--card-border); border-radius: 8px; background: var(--bg-body); color: var(--text-main); box-sizing: border-box; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; text-align: center; }
        .alert.success { background: rgba(46, 204, 113, 0.2); color: #27ae60; border: 1px solid #2ecc71; }
        .alert.error { background: rgba(231, 76, 60, 0.2); color: #c0392b; border: 1px solid #e74c3c; }
        .r2-box { background: rgba(230, 126, 34, 0.05); padding: 15px; border-radius: 10px; border: 1px dashed var(--primary); margin: 20px 0; }
        @media (max-width: 600px) { .header { flex-direction: column; align-items: flex-start; gap: 15px; } .header-actions { width: 100%; justify-content: space-between; } }
    </style>
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

    <div class="header">
        <h1>🦅 KAYOOH <span style="font-size:12px; opacity:0.6;">v5.0</span></h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="startRide()">🚀 SOLO</button>
            <button class="btn btn-accent" onclick="openModal('peletonModal')">👥 PELETON</button>
            <button class="btn btn-outline" onclick="window.location.href='sync_strava.php'" style="background:#fc4c02; color:white; border:none;">🧡 SYNC</button>
            <button class="btn btn-outline" onclick="window.location.href='gpx_import.php'" style="background:#27ae60; color:white; border:none;">📥 GPX</button>
            <button class="btn btn-outline" onclick="window.location.href='heatmap.php'" style="background:#e74c3c; color:white; border:none;">🔥 HEATMAP</button>
            <button class="btn btn-outline" onclick="openModal('settingsModal')">⚙️</button>
            <button class="btn btn-outline" onclick="toggleTheme()" id="theme-btn">🌙</button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Jarak</div>
            <div class="stat-value"><?= number_format($total_km, 1) ?> <span style="font-size:14px;">km</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Aktivitas</div>
            <div class="stat-value"><?= $total_rides ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Waktu</div>
            <div class="stat-value"><?= $total_hours ?> <span style="font-size:14px;">jam</span></div>
        </div>
        <div class="stat-card" style="border-left: 3px solid var(--success);">
            <div class="stat-label">Bulan Ini (<?= date('M') ?>)</div>
            <div class="stat-value" style="color: var(--success);"><?= number_format($month_km, 1) ?> <span style="font-size:14px;">km</span></div>
            <div class="stat-label" style="margin-top: 5px;"><?= $month_rides ?> Gowes</div>
        </div>
    </div>

    <form method="GET"><input type="text" name="q" class="search-bar" placeholder="🔍 Cari aktivitas..." value="<?= htmlspecialchars($search) ?>"></form>

    <div class="ride-list">
        <?php if ($rides): foreach ($rides as $ride): ?>
            <div class="ride-item" onclick='viewRide(<?= htmlspecialchars(json_encode($ride), ENT_QUOTES, 'UTF-8') ?>)'>
                <div class="ride-info">
                    <div class="ride-title"><?= htmlspecialchars($ride['name']) ?></div>
                    <div class="ride-date">📅 <?= date('d M Y - H:i', strtotime($ride['start_date'])) ?></div>
                    <div class="ride-stats">
                        <span style="color:var(--primary);">📏 <?= number_format($ride['distance'], 2) ?> km</span>
                        <span style="color:var(--accent);">⏱️ <?= gmdate("H:i:s", $ride['moving_time']) ?></span>
                        <span style="color:var(--success);">⛰️ <?= $ride['total_elevation_gain'] ?> m</span>
                    </div>
                </div>
                <div style="font-size:20px; color:var(--text-muted);">❯</div>
            </div>
        <?php endforeach; else: ?>
            <div class="stat-card">Belum ada data gowes. Mulai sekarang, Kapten!</div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="page-link <?= ($i === $page) ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <div id="peletonModal" class="modal">
        <div class="modal-content" style="text-align: center;">
            <span class="close-btn" onclick="closeModal('peletonModal')">&times;</span>
            <h2 style="color: var(--accent); margin-top: 0;">👥 Mode Peleton</h2>
            <div class="form-group" style="text-align: left;">
                <label>NAMA ROOM (Opsional):</label>
                <input type="text" id="roomInput" placeholder="Contoh: PLTN_SUNDAY" style="text-align: center; text-transform: uppercase;">
            </div>
            <button class="btn btn-accent" style="width: 100%; padding: 15px;" onclick="startPeleton()">🚴‍♂️ GAS PELETON</button>
        </div>
    </div>

    <div id="rideModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('rideModal')">&times;</span>
            <h2 id="modal-title" style="margin-top:0;">Detail</h2>
            <div id="modal-date" style="font-size:12px; color:var(--text-muted); margin-bottom:10px;"></div>
            
            <div class="stats-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:0;">
                <div class="stat-card" style="padding:10px;"><div class="stat-label">Jarak</div><div class="stat-value" id="modal-dist" style="font-size:20px;">0</div></div>
                <div class="stat-card" style="padding:10px;"><div class="stat-label">Waktu</div><div class="stat-value" id="modal-time" style="font-size:20px; color:var(--accent);">0</div></div>
                <div class="stat-card" style="padding:10px;"><div class="stat-label">Elevasi</div><div class="stat-value" id="modal-elev" style="font-size:20px; color:var(--success);">0</div></div>
                <div class="stat-card" style="padding:10px;"><div class="stat-label">Avg Speed</div><div class="stat-value" id="modal-avg-spd" style="font-size:20px; color:var(--accent);">0</div></div>
            </div>

            <div id="modal-map"></div>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button class="btn btn-primary" style="flex:1;" id="btn-full-detail">🔍 Rincian</button>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Hapus sejarah ini?');">
                    <input type="hidden" name="ride_id" id="delete-id">
                    <button type="submit" name="delete_ride" class="btn btn-danger">🗑️</button>
                </form>
            </div>
        </div>
    </div>

    <div id="settingsModal" class="modal" style="<?= $show_modal ? 'display:block' : '' ?>">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('settingsModal')">&times;</span>
            <h2 style="margin-top:0; color:var(--primary);">⚙️ Pengaturan</h2>
            <?= $msg_settings . $msg_password ?>

            <form method="POST">
                <div class="form-group"><label>Nama Kapten:</label><input type="text" name="captain_name" value="<?= htmlspecialchars($settings['captain_name'] ?? '') ?>"></div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>TG Token:</label><input type="password" name="telegram_bot_token" value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>"></div>
                    <div class="form-group"><label>Chat ID:</label><input type="text" name="telegram_chat_id" value="<?= htmlspecialchars($settings['telegram_chat_id'] ?? '') ?>"></div>
                </div>

                <div class="r2-box">
                    <h3 style="margin-top:0; font-size:14px; color:var(--primary);">☁️ Cloudflare R2 (v5.0)</h3>
                    <div class="form-group"><label>Account ID:</label><input type="text" name="r2_account_id" value="<?= htmlspecialchars($settings['r2_account_id'] ?? '') ?>"></div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div class="form-group"><label>Access Key:</label><input type="password" name="r2_access_key" value="<?= htmlspecialchars($settings['r2_access_key'] ?? '') ?>"></div>
                        <div class="form-group"><label>Secret Key:</label><input type="password" name="r2_secret_key" value="<?= htmlspecialchars($settings['r2_secret_key'] ?? '') ?>"></div>
                    </div>
                    <div class="form-group"><label>Bucket Name:</label><input type="text" name="r2_bucket" value="<?= htmlspecialchars($settings['r2_bucket'] ?? '') ?>"></div>
                    <div class="form-group"><label>Custom URL:</label><input type="text" name="r2_public_url" value="<?= htmlspecialchars($settings['r2_public_url'] ?? '') ?>"></div>
                </div>
                <button type="submit" name="save_settings" class="btn btn-primary" style="width:100%;">💾 SIMPAN PENGATURAN</button>
            </form>

            <hr style="margin:25px 0; border:0; border-top:1px solid var(--card-border);">

            <form method="POST">
                <h3 style="font-size:14px;">🔒 GANTI PASSWORD</h3>
                <div class="form-group"><input type="password" name="new_password" placeholder="Password Baru"></div>
                <div class="form-group"><input type="password" name="confirm_password" placeholder="Ulangi Password"></div>
                <button type="submit" name="change_password" class="btn btn-outline" style="width:100%;">🔑 UPDATE PASSWORD</button>
            </form>
            
            <hr style="margin:25px 0; border:0; border-top:1px solid var(--card-border);">
            <div style="display:flex; gap:10px;">
                <button class="btn btn-outline" style="flex:1;" onclick="closeModal('settingsModal'); openModal('dbModal');">🗄️ DATABASE</button>
                <button class="btn btn-danger" style="flex:1;" onclick="window.location.href='logout.php'">🚪 LOGOUT</button>
            </div>
        </div>
    </div>

    <div id="dbModal" class="modal" style="<?= $show_db_modal ? 'display:block' : '' ?>">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('dbModal')">&times;</span>
            <h2 style="margin-top:0;">🗄️ Database</h2>
            <?= $msg_db ?>
            <div class="stat-card" style="margin-bottom:15px; text-align:left;">
                <h3 style="margin-top:0;">⬇️ Download SQLite</h3>
                <a href="?download_db=1" class="btn btn-accent" style="width:100%; box-sizing:border-box;">📥 BACKUP DATABASE</a>
            </div>
            <div class="stat-card" style="text-align:left; border-color:var(--danger);">
                <h3 style="margin-top:0; color:var(--danger);">⬆️ Restore File (.sqlite)</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="db_file" accept=".sqlite,.db" required style="margin-bottom:10px; width:100%;">
                    <button type="submit" name="import_db" class="btn btn-danger" style="width:100%;">⚠️ RESTORE SEKARANG</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // --- FITUR AUTO-BOUNCE (BANTING SESI) ---
        let activeSession = localStorage.getItem('active_session');
        if (activeSession) { window.location.href = activeSession; }
        let currentRoomId = 'SINGLE_MODE'; 

        // --- THEME ENGINE ---
        const toggleTheme = () => {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.getElementById('theme-btn').innerText = isDark ? '☀️' : '🌙';
        };

        window.addEventListener('DOMContentLoaded', () => { 
            if (localStorage.getItem('theme') === 'dark') {
                document.getElementById('theme-btn').innerText = '☀️';
            }
        });

        // --- INTERAKSI MODAL & ROUTING ---
        const openModal = id => document.getElementById(id).style.display = 'block';
        const closeModal = id => document.getElementById(id).style.display = 'none';
        const startRide = () => window.location.href = 'record_single.php?room=SINGLE_MODE';
        
        const startPeleton = () => {
            let val = document.getElementById('roomInput').value.trim().toUpperCase();
            let room = val ? val.replace(/[^A-Z0-9_]/g, '') : 'PLTN_' + Math.random().toString(36).substring(2, 8).toUpperCase();
            window.location.href = `record_peleton.php?room=${room}`;
        };

        // --- MESIN PEMECAH SANDI POLYLINE STRAVA (V4.0 LAGGACY) ---
        function decodePolyline(encoded) {
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

        // --- HYBRID MAP ENGINE (v5.0 - SUPPORT ALL FORMATS) ---
        let modalMap = null, activeLine = null, activeMarkers = [];

        async function viewRide(ride) {
            openModal('rideModal');
            
            // 1. RENDER TEKS
            document.getElementById('modal-title').innerText = ride.name;
            let d = new Date(ride.start_date);
            let formattedDate = isNaN(d.getTime()) ? ride.start_date : 
                d.toLocaleDateString('id-ID', {day: '2-digit', month: 'short', year: 'numeric'}) + ' - ' + 
                d.toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'});
                
            document.getElementById('modal-date').innerText = `📅 ${formattedDate}`;
            document.getElementById('modal-dist').innerText = parseFloat(ride.distance).toFixed(2);
            document.getElementById('modal-elev').innerText = ride.total_elevation_gain;
            document.getElementById('modal-avg-spd').innerText = parseFloat(ride.average_speed || 0).toFixed(1);
            
            let s = parseInt(ride.moving_time) || 0;
            document.getElementById('modal-time').innerText = 
                `${String(Math.floor(s/3600)).padStart(2,'0')}:${String(Math.floor((s%3600)/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}`;
            
            document.getElementById('btn-full-detail').onclick = () => window.location.href = `detail.php?id=${ride.id}`;
            document.getElementById('delete-id').value = ride.id;

            // 2. INITIALIZE MAP
            if (!modalMap) {
                modalMap = L.map('modal-map', { zoomControl: false, attributionControl: false }).setView([-2.5489, 118.0149], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(modalMap);
            }
            
            if (activeLine) modalMap.removeLayer(activeLine);
            activeMarkers.forEach(m => modalMap.removeLayer(m));
            activeMarkers = [];
            
            setTimeout(() => { modalMap.invalidateSize(); }, 200);

            // 3. GAMBAR RUTELINE (UNIVERSAL PARSER)
            if (ride.polyline && ride.polyline.trim() !== '') {
                try {
                    let rawStr = ride.polyline.trim();
                    let coords = [];

                    // Skenario A: Fetch JSON dari Cloudflare R2
                    if (rawStr.startsWith('http')) {
                        let res = await fetch(rawStr);
                        let jsonRaw = await res.json();
                        coords = jsonRaw.map(p => (p.lat !== undefined) ? [parseFloat(p.lat), parseFloat(p.lng)] : null);
                    } 
                    // Skenario B: JSON Array dari SQLite
                    else if (rawStr.startsWith('[') || rawStr.startsWith('{') || rawStr.startsWith('"[')) {
                        let parsed = JSON.parse(rawStr);
                        if (typeof parsed === 'string') parsed = JSON.parse(parsed); // Fix double stringify
                        coords = parsed.map(p => {
                            if (Array.isArray(p)) return [parseFloat(p[0]), parseFloat(p[1])];
                            if (p.lat !== undefined) return [parseFloat(p.lat), parseFloat(p.lng)];
                            return null;
                        });
                    } 
                    // Skenario C: Encoded Polyline (Sandi Keriting v4.0)
                    else {
                        console.log("🔓 Memecahkan Sandi Polyline v4.0...");
                        coords = decodePolyline(rawStr);
                    }

                    // Bersihkan kordinat null/cacat
                    coords = coords.filter(p => p !== null && !isNaN(p[0]) && !isNaN(p[1]));

                    // 4. EKSEKUSI LEAFLET
                    if (coords.length > 1) {
                        let clr = getComputedStyle(document.body).getPropertyValue('--primary').trim() || '#e67e22';
                        activeLine = L.polyline(coords, { color: clr, weight: 4, opacity: 0.8 }).addTo(modalMap);
                        
                        activeMarkers.push(
                            L.circleMarker(coords[0], { radius: 5, color: '#2ecc71', fillOpacity: 1 }).addTo(modalMap),
                            L.circleMarker(coords[coords.length - 1], { radius: 5, color: '#e74c3c', fillOpacity: 1 }).addTo(modalMap)
                        );

                        setTimeout(() => {
                            modalMap.fitBounds(activeLine.getBounds(), { padding: [15, 15] });
                        }, 300);
                    } else if (coords.length === 1) {
                        L.circleMarker(coords[0], { radius: 6, color: '#e74c3c', fillOpacity: 1 }).addTo(modalMap);
                        setTimeout(() => { modalMap.setView(coords[0], 15); }, 300);
                    }
                } catch (e) { 
                    console.error("❌ Gagal menggambar Routeline:", e); 
                }
            }
        }
    </script>
</body>
</html>