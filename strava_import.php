<?php
// strava_import.php - Sinkronisasi Strava (Dark Mode & PWA Ready)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$redirect_uri = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

$pesan = '';
$status = 'standby';
$generated_token = '';

// ==========================================
// 1. SETUP API & RESET API (Disimpan di Session)
// ==========================================
if (isset($_POST['setup_api'])) {
    $_SESSION['strava_client_id'] = trim($_POST['client_id']);
    $_SESSION['strava_client_secret'] = trim($_POST['client_secret']);
    header('Location: strava_import.php');
    exit;
}

if (isset($_GET['reset_api'])) {
    unset($_SESSION['strava_client_id'], $_SESSION['strava_client_secret'], $_SESSION['strava_token']);
    header('Location: strava_import.php');
    exit;
}

$client_id = $_SESSION['strava_client_id'] ?? '';
$client_secret = $_SESSION['strava_client_secret'] ?? '';

// ==========================================
// 2. AUTO-GENERATE TOKEN SAKTI (Hanya jalan jika API sudah disetup)
// ==========================================
if (isset($_GET['code']) && $client_id && $client_secret) {
    $code = $_GET['code'];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.strava.com/oauth/token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'grant_type' => 'authorization_code'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['access_token'])) {
        $generated_token = $data['access_token'];
        $pesan = "Berhasil! Token otomatis terisi. Silakan klik Sinkronisasi.";
        $status = 'success';
    } else {
        $pesan = "Gagal men-generate token otomatis. Cek kembali Client ID/Secret Anda.";
        $status = 'error';
    }
}

// ==========================================
// 3. PROSES SINKRONISASI KE SQLITE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_sync'])) {
    $token = trim($_POST['access_token']);

    if (empty($token)) {
        $pesan = "Token tidak boleh kosong, wak!";
        $status = 'error';
    } else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.strava.com/api/v3/athlete/activities?per_page=50");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $activities = json_decode($response, true);

        if ($http_code === 200 && is_array($activities)) {
            try {
                $pdo = new PDO("sqlite:" . $db_file);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $inserted = 0; $skipped = 0;
                $stmt = $pdo->prepare("INSERT INTO rides (strava_id, name, distance, moving_time, average_speed, max_speed, total_elevation_gain, start_date, polyline, source) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'STRAVA')");

                foreach ($activities as $act) {
                    if (isset($act['type']) && ($act['type'] === 'Ride' || $act['type'] === 'VirtualRide')) {
                        $check = $pdo->prepare("SELECT id FROM rides WHERE strava_id = ?");
                        $check->execute([$act['id']]);
                        
                        if (!$check->fetch()) {
                            $stmt->execute([
                                $act['id'], $act['name'], ($act['distance'] / 1000), 
                                $act['moving_time'], ($act['average_speed'] * 3.6), 
                                ($act['max_speed'] * 3.6), $act['total_elevation_gain'], 
                                $act['start_date_local'], ($act['map']['summary_polyline'] ?? '')
                            ]);
                            $inserted++;
                        } else {
                            $skipped++;
                        }
                    }
                }
                $pesan = "Mantap! $inserted gowes baru disedot. $skipped data dilewati.";
                $status = 'success';

            } catch (PDOException $e) {
                $pesan = "Error Database: " . $e->getMessage();
                $status = 'error';
            }
        } else {
            $error_msg = $activities['message'] ?? 'Tidak diketahui';
            if (strpos($error_msg, 'Authorization Error') !== false) {
                $pesan = "Gagal: Token tidak punya izin. Silakan gunakan tombol Dapatkan Token Sakti.";
            } else {
                $pesan = "Gagal: " . $error_msg;
            }
            $status = 'error';
        }
    }
}

// Merakit URL Otomatis
$strava_auth_url = "";
if ($client_id) {
    $strava_auth_url = "https://www.strava.com/oauth/authorize?" . http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'approval_prompt' => 'auto',
        'scope' => 'activity:read_all'
    ]);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Strava Sync - Kayooh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
    <style>
        /* Penyesuaian khusus Dark Mode untuk kotak Token Help */
        body.dark-mode .token-help {
            background-color: #161b22;
            border-color: #30363d;
            color: #c9d1d9;
        }
        body.dark-mode .token-help a {
            background-color: #0d1117 !important;
            border-color: #30363d !important;
            color: #00ff41 !important;
        }
    </style>
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

    <div style="position: absolute; top: 20px; right: 20px; z-index: 100;">
        <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
    </div>

<div class="strava-container">
    <div class="box" style="margin:0 auto; position: relative;">
        <img src="assets/kayooh.png" alt="Kayooh" style="height: 40px; margin-bottom: 10px;">
        <h2>Sync <span class="strava-brand">STRAVA</span></h2>
        
        <?php if ($pesan): ?>
            <div class="<?= $status === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($pesan) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($client_id) || empty($client_secret)): ?>
            <p class="subtitle" style="font-size: 13px;">Untuk pertama kali, atur identitas aplikasi Kayooh Anda terlebih dahulu.</p>
            <form method="POST">
                <input type="hidden" name="setup_api" value="1">
                <div class="input-group">
                    <label>Client ID Strava</label>
                    <input type="number" name="client_id" placeholder="Contoh: 123456" required>
                </div>
                <div class="input-group">
                    <label>Client Secret Strava</label>
                    <input type="password" name="client_secret" placeholder="Paste Secret di sini..." required>
                </div>
                <button type="submit" class="btn-primary" style="background-color: var(--text-color);">SIMPAN PENGATURAN</button>
            </form>
            <div style="font-size: 11px; color: #7f8c8d; margin-top: 15px; text-align: left;">
                *Data ini hanya disimpan sementara di sesi browser Anda dan tidak akan dikirim ke mana pun selain ke Strava.
            </div>

        <?php else: ?>
            <div class="token-help">
                <div style="margin-bottom: 10px; font-weight: bold;">Identitas Aplikasi Terpasang ✅</div>
                Klik tombol di bawah ini agar Kayooh bisa meminta izin otomatis ke Strava dan mengisi form token.<br><br>
                <a href="<?= htmlspecialchars($strava_auth_url) ?>" style="display:inline-block; background:#f8f9fa; border:1px solid var(--primary-color); padding:8px 12px; border-radius:6px; font-size:12px;">
                    🔑 Dapatkan Token Sakti
                </a>
            </div>

            <form method="POST">
                <input type="hidden" name="action_sync" value="1">
                <div class="input-group">
                    <label>Strava Access Token</label>
                    <input type="password" name="access_token" id="access_token" placeholder="Paste token..." value="<?= htmlspecialchars($generated_token) ?>" required>
                    <button type="button" class="toggle-btn" onclick="toggleToken()">LIHAT</button>
                </div>
                <button type="submit" class="btn-primary" style="background-color: #FC4C02;">SINKRONISASI SEKARANG</button>
            </form>

            <div style="margin-top: 15px;">
                <a href="?reset_api=1" style="color: #e74c3c; font-size: 11px; text-decoration: none; font-weight: bold;">[ Hapus Pengaturan API ]</a>
            </div>
        <?php endif; ?>

        <div style="margin-top: 25px; text-align: center;">
            <a href="dashboard.php" style="color: var(--text-color); text-decoration: none; font-weight: bold; font-size: 13px; opacity: 0.7;">&larr; KEMBALI KE DASHBOARD</a>
        </div>
    </div>
</div>

<script>
    // Logika Sembunyikan/Lihat Sandi
    function toggleToken() {
        var pwd = document.getElementById("access_token");
        var btn = document.querySelector(".toggle-btn");
        if (pwd.type === "password") {
            pwd.type = "text";
            btn.textContent = "SEMBUNYI";
        } else {
            pwd.type = "password";
            btn.textContent = "LIHAT";
        }
    }

    // Logika Toggle Tema
    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';
    }
    
    // Sesuaikan ikon saat halaman selesai dimuat
    window.addEventListener('DOMContentLoaded', () => { 
        if(localStorage.getItem('theme') === 'dark') {
            document.getElementById('theme-icon').textContent = '☀️';
        }
    });
</script>

</body>
</html>