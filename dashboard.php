<?php
// dashboard.php - Pusat Aktivitas Kayooh (Modal Settings Lengkap, Ride Mode v4.0 & Auto-Bounce)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

// 1. Proteksi Halaman: Wajib Login
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 2. Koneksi Database & Fetch Data
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $msg_settings = '';
    $msg_password = '';
    $show_modal = false; 

    // --- LOGIKA SIMPAN PENGATURAN UMUM (PROFIL & TELEGRAM) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $captain_name = trim($_POST['captain_name'] ?? 'Kapten');
        $token        = trim($_POST['telegram_bot_token'] ?? '');
        $chat_id      = trim($_POST['telegram_chat_id'] ?? '');
        $show_modal   = true;
        
        try {
            // Gunakan INSERT OR REPLACE agar tidak error jika key belum pernah dibuat
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute(['captain_name', $captain_name]);
            $stmt->execute(['telegram_bot_token', $token]);
            $stmt->execute(['telegram_chat_id', $chat_id]);
            
            $msg_settings = '<div class="alert alert-success">✅ Profil & Telegram berhasil disimpan!</div>';
        } catch (PDOException $e) {
            $msg_settings = '<div class="alert alert-danger">❌ Gagal menyimpan: ' . $e->getMessage() . '</div>';
        }
    }

    // --- LOGIKA GANTI PASSWORD (Menembak tabel 'users') ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_password'])) {
        $new_password = $_POST['new_password'] ?? '';
        $show_modal   = true;

        if (strlen($new_password) < 4) {
            $msg_password = '<div class="alert alert-danger">❌ Password minimal 4 karakter!</div>';
        } else {
            try {
                // Enkripsi password untuk keamanan
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $user_id = $_SESSION['user_id'];
                
                // Update langsung ke tabel users berdasarkan sesi login
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                $msg_password = '<div class="alert alert-success">✅ Password berhasil diubah! Gunakan saat login berikutnya.</div>';
            } catch (PDOException $e) {
                $msg_password = '<div class="alert alert-danger">❌ Gagal mengubah password: ' . $e->getMessage() . '</div>';
            }
        }
    }

    // --- AMBIL DATA PENGATURAN SAAT INI ---
    $captain_name   = 'Kapten'; // Default jika belum diatur
    $telegram_token = '';
    $telegram_chat  = '';
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id', 'captain_name')");
        while ($row = $stmt->fetch()) {
            if ($row['setting_key'] === 'captain_name')       $captain_name   = $row['setting_value'];
            if ($row['setting_key'] === 'telegram_bot_token') $telegram_token = $row['setting_value'];
            if ($row['setting_key'] === 'telegram_chat_id')   $telegram_chat  = $row['setting_value'];
        }
    } catch (PDOException $e) {
        // Abaikan jika tabel settings belum sempurna
    }

    // --- AMBIL STATISTIK & RIWAYAT GOWES ---
    $stats = $pdo->query("SELECT COUNT(*) as total_rides, SUM(distance) as total_dist, SUM(total_elevation_gain) as total_elev FROM rides")->fetch();
    $stmt = $pdo->query("SELECT * FROM rides ORDER BY start_date DESC LIMIT 5");
    $recent_rides = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

if (isset($_GET['logout'])) { 
    session_destroy(); 
    header('Location: login.php'); 
    exit; 
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Kayooh</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
    <link rel="stylesheet" href="assets/style.css">
    
    <style>
        /* CSS MODAL & SETTINGS - FIXED VERTICAL CENTER */
        .modal {
            display: none; 
            position: fixed;
            z-index: 1000;
            left: 0; 
            top: 0;
            width: 100%; 
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--bg-color, #fff);
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 450px; 
            position: relative;
            color: var(--text-color);
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
            margin: 0; 
        }

        .close-modal {
            position: absolute; 
            right: 20px; 
            top: 15px;
            font-size: 24px; 
            cursor: pointer; 
            color: #7f8c8d;
        }

        .settings-toggle {
            background: none; 
            border: none; 
            font-size: 20px; 
            cursor: pointer; 
            padding: 5px;
        }

        .form-input {
            width: 100%; 
            padding: 12px; 
            border-radius: 8px;
            border: 1px solid var(--border-color, #ccc);
            background: var(--bg-color, #fff); 
            color: var(--text-color);
            box-sizing: border-box; 
            margin-top: 8px; 
            font-family: monospace;
        }

        .btn-save {
            background-color: #0088cc; 
            color: white; 
            border: none;
            width: 100%; 
            padding: 12px; 
            border-radius: 8px;
            font-weight: bold; 
            margin-top: 15px; 
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .btn-save:hover {
            opacity: 0.8;
        }
        
        .btn-danger {
            background-color: #e74c3c;
        }

        .alert {
            padding: 10px; 
            border-radius: 8px; 
            margin-bottom: 15px; 
            font-size: 13px; 
            border: 1px solid transparent;
        }

        .alert-success { 
            color: #27ae60; 
            background: #e8f8f5; 
            border-color: #2ecc71; 
        }

        .alert-danger { 
            color: #c0392b; 
            background: #fdedec; 
            border-color: #e74c3c; 
        }

        .settings-section {
            background: rgba(0,0,0,0.03);
            border: 1px solid rgba(0,0,0,0.05);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        /* CSS KHUSUS RIDE MODE */
        .ride-option {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .info-box {
            font-size: 11px; 
            color: #d97706; 
            background: #fef3c7; 
            padding: 8px; 
            border-radius: 6px; 
            margin-bottom: 10px; 
            border: 1px solid #fde68a;
        }

        /* --- DARK MODE RULES --- */
        .dark-mode .modal-content {
            background-color: #1e293b;
            color: #f1f5f9;
        }
        .dark-mode .modal-content hr { border-top-color: #334155 !important; }
        .dark-mode .close-modal { color: #94a3b8; }
        .dark-mode .form-input {
            background-color: #0f172a;
            color: #f1f5f9;
            border-color: #334155;
        }
        .dark-mode .alert-success { 
            background: rgba(39, 174, 96, 0.2); 
            color: #2ecc71; 
            border-color: rgba(46, 204, 113, 0.3); 
        }
        .dark-mode .alert-danger { 
            background: rgba(192, 57, 43, 0.2); 
            color: #e74c3c; 
            border-color: rgba(231, 76, 60, 0.3); 
        }
        .dark-mode .settings-section {
            background: rgba(255,255,255,0.02);
            border-color: rgba(255,255,255,0.05);
        }
        .dark-mode .ride-option {
            background-color: #1e293b;
            border-color: #334155;
        }
        .dark-mode .ride-option p { color: #94a3b8 !important; }
        .dark-mode .info-box {
            background: rgba(217, 119, 6, 0.1); 
            border-color: rgba(217, 119, 6, 0.3); 
            color: #fcd34d;
        }
        .dark-mode .room-id-box {
            background: rgba(52, 152, 219, 0.1) !important;
            border-color: rgba(52, 152, 219, 0.4) !important;
        }
        .dark-mode .room-id-box span { color: #38bdf8 !important; }
    </style>
</head>
<body>
    <script>
        // ---------------------------------------------------------
        // SANG SATPAM (AUTO-REDIRECT / BANTING SESI)
        // ---------------------------------------------------------
        const activeSession = localStorage.getItem('active_session');
        if (activeSession) {
            // Jika memori masih mencatat ada gowes yang menggantung, banting balik!
            window.location.href = 'record.php?room=' + activeSession;
        }

        // Terapkan Dark Mode jika disetting
        if(localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    </script>

<div class="dashboard-container">
    <div class="header">
        <img src="assets/kayooh.png" alt="Kayooh" class="nav-logo">
        <div class="header-actions" style="display: flex; gap: 12px; align-items: center;">
            <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
            <button onclick="openModal()" class="settings-toggle">⚙️</button>
            <a href="?logout=1" class="logout-link">LOGOUT</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Jarak Total</h3>
            <p><?= number_format($stats['total_dist'] ?? 0, 1) ?> km</p>
        </div>
        <div class="stat-card">
            <h3>Elevasi</h3>
            <p><?= number_format($stats['total_elev'] ?? 0, 0) ?> m</p>
        </div>
        <div class="stat-card">
            <h3>Total Rides</h3>
            <p><?= $stats['total_rides'] ?? 0 ?></p>
        </div>
    </div>

    <div class="action-buttons" style="grid-template-columns: 1fr 1fr; display: grid; gap: 10px;">
        <a href="javascript:void(0)" onclick="openRideModal()" class="btn-action btn-record" style="margin:0;">🔴 RECORD RIDE</a>
        
        <a href="heatmap.php" class="btn-action" style="background-color: #34495e; color: white; margin:0;">🔥 HEATMAP</a>
        
        <a href="strava_import.php" class="btn-action btn-strava" style="margin:0;">SYNC STRAVA</a>
        
        <a href="gpx_import.php" class="btn-action" style="background-color: #27ae60; color: white; margin:0;">📥 IMPORT GPX</a>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 style="margin: 0; font-size: 18px;">Aktivitas Terakhir</h2>
        <a href="activities.php" style="font-size: 12px; color: var(--primary-color); font-weight: bold; text-decoration: none;">LIHAT SEMUA &rarr;</a>
    </div>

    <div class="activity-list">
        <?php foreach ($recent_rides as $ride): ?>
            <div class="activity-item">
                <div class="activity-info">
                    <div class="activity-info">
                        <h4>
                            <a href="detail.php?id=<?= $ride['id'] ?>" style="color: var(--text-color); text-decoration: none;">
                                <?= htmlspecialchars($ride['name']) ?>
                            </a>
                            <?php if (!empty($ride['participants']) && $ride['participants'] !== '[]'): ?>
                                <span style="font-size: 12px; margin-left: 5px;" title="Gowes Peleton">👥</span>
                            <?php endif; ?>
                        </h4>
                        <span>
                            <?php
                                $ts = strtotime($ride['start_date']);
                                $bulan_id = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
                                echo date('d', $ts) . ' ' . $bulan_id[date('F', $ts)] . ' ' . date('Y', $ts);
                            ?>
                        </span>
                    </div>
                </div>
                <div class="activity-data"><?= number_format($ride['distance'], 1) ?> km</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="settingsModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 style="margin-top: 0; font-size: 20px;">⚙️ Pengaturan</h2>
        <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">
        
        <form method="POST" action="dashboard.php" class="settings-section">
            <?= $msg_settings ?>
            
            <h3 style="font-size: 14px; color: var(--primary-color); margin-top: 0;">🚴‍♂️ Profil Pesepeda</h3>
            <label style="font-size: 12px; font-weight: bold;">Nama Kapten (Peleton):</label>
            <input type="text" name="captain_name" class="form-input" value="<?= htmlspecialchars($captain_name) ?>" placeholder="Masukkan Nama Anda">
            
            <hr style="border: 0; border-top: 1px dashed #ccc; margin: 20px 0 15px 0;">

            <h3 style="font-size: 14px; color: #0088cc; margin-top: 0;">📡 Radar Telegram</h3>
            <label style="font-size: 12px; font-weight: bold;">Bot Token API:</label>
            <input type="text" name="telegram_bot_token" class="form-input" value="<?= htmlspecialchars($telegram_token) ?>" placeholder="Token dari @BotFather">
            
            <label style="font-size: 12px; font-weight: bold; margin-top: 10px; display: block;">Chat ID Grup:</label>
            <input type="text" name="telegram_chat_id" class="form-input" value="<?= htmlspecialchars($telegram_chat) ?>" placeholder="Contoh: -100xxx">
            
            <button type="submit" name="save_settings" class="btn-save">💾 SIMPAN PENGATURAN</button>
        </form>

        <form method="POST" action="dashboard.php" class="settings-section" style="margin-bottom: 0;">
            <?= $msg_password ?>
            
            <h3 style="font-size: 14px; color: #e74c3c; margin-top: 0;">🔒 Keamanan</h3>
            <label style="font-size: 12px; font-weight: bold;">Ganti Password Login:</label>
            <input type="password" name="new_password" class="form-input" placeholder="Masukkan password baru">
            
            <button type="submit" name="save_password" class="btn-save btn-danger">🔑 UPDATE PASSWORD</button>
        </form>
    </div>
</div>

<div id="rideModeModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeRideModal()">&times;</span>
        <h2 style="margin-top: 0; font-size: 20px;">🚲 Pilih Mode Gowes</h2>
        <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">

        <div class="ride-option">
            <h3 style="margin-top: 0; font-size: 16px; color: #e74c3c;">👤 Single Ride</h3>
            <p style="font-size: 12px; color: #7f8c8d; margin-bottom: 10px;">Gowes sendirian. Live Tracking Telegram aktif.</p>
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="copyRadarLink('SINGLE_MODE')" style="flex: 1; background: #95a5a6; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: bold;">📋 COPY LINK</button>
                <button type="button" onclick="startRide('SINGLE_MODE')" style="flex: 2; background: #e74c3c; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: bold;">🔴 MULAI</button>
            </div>
        </div>

        <div class="ride-option">
            <h3 style="margin-top: 0; font-size: 16px; color: #3498db;">🤝 Co-Gowes (Peleton)</h3>
            <p style="font-size: 12px; color: #7f8c8d; margin-bottom: 5px;">Mabar GPS. Semua orang tampil di satu radar.</p>
            
            <div class="room-id-box" style="background: #e8f4f8; border: 1px dashed #3498db; padding: 8px; border-radius: 6px; text-align: center; margin-bottom: 10px;">
                <span style="font-size: 12px; color: #2980b9;">Room ID: <b id="room-id-display" style="font-size: 16px;">----</b></span>
            </div>

            <div class="info-box">
                ⚠️ <b>KAPTEN PELETON:</b> Hanya Anda yang bisa mengakhiri rekaman radar ini.
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="copyRadarLink('PELETON')" style="flex: 1; background: #3498db; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: bold;">📋 COPY LINK</button>
                <button type="button" onclick="startRide('PELETON')" style="flex: 2; background: #2980b9; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: bold;">▶️ GAS PELETON</button>
            </div>
        </div>
    </div>
</div>

<script>
    // ---------------------------------------------------------
    // LOGIKA MODAL & TEMA
    // ---------------------------------------------------------
    const modalSettings = document.getElementById("settingsModal");
    const modalRide = document.getElementById("rideModeModal");
    
    // Auto-generate 4 huruf acak untuk Room Peleton
    let currentRoomId = Math.random().toString(36).substring(2, 6).toUpperCase();

    function openModal() { 
        modalSettings.style.display = "flex"; 
    }
    
    function closeModal() { 
        modalSettings.style.display = "none"; 
    }
    
    function openRideModal() { 
        modalRide.style.display = "flex"; 
        document.getElementById('room-id-display').innerText = currentRoomId;
    }
    
    function closeRideModal() { 
        modalRide.style.display = "none"; 
    }

    // Menutup modal jika user klik area luar kotak
    window.onclick = (e) => { 
        if (e.target == modalSettings) closeModal(); 
        if (e.target == modalRide) closeRideModal();
    }

    // Buka modal settings otomatis jika baru saja menyimpan data
    <?php if ($show_modal): ?>
    window.onload = () => { openModal(); };
    <?php endif; ?>

    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';
    }

    // Memastikan ikon tema sesuai saat halaman diload
    window.addEventListener('DOMContentLoaded', () => { 
        if(localStorage.getItem('theme') === 'dark') {
            document.getElementById('theme-icon').textContent = '☀️';
        }
    });

    // ---------------------------------------------------------
    // LOGIKA START RIDE & COPY LINK RADAR
    // ---------------------------------------------------------
    function copyRadarLink(mode) {
        let room = mode === 'SINGLE_MODE' ? 'SINGLE_MODE' : currentRoomId;
        
        let baseUrl = window.location.href.split('?')[0]; 
        baseUrl = baseUrl.replace('dashboard.php', '');
        if (!baseUrl.endsWith('/')) baseUrl += '/';
        const radarUrl = baseUrl + 'guest.php?room=' + room;
        
        navigator.clipboard.writeText(radarUrl).then(() => {
            alert('✅ Link Radar berhasil disalin!\nBagikan ke teman atau keluarga Anda.\n\nURL: ' + radarUrl);
        }).catch(err => {
            prompt('Gagal menyalin otomatis. Silakan copy manual URL di bawah ini:', radarUrl);
        });
    }

    function startRide(mode) {
        let room = mode === 'SINGLE_MODE' ? 'SINGLE_MODE' : currentRoomId;
        window.location.href = 'record.php?room=' + room;
    }
</script>
</body>
</html>