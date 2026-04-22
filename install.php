<?php
// install.php - Setup Database & Registrasi Admin Kayooh (v3.0)
session_start();

$lock_file = __DIR__ . '/install.lock';
$db_file = __DIR__ . '/kayooh.sqlite';

if (file_exists($lock_file)) {
    header('Location: login.php');
    exit;
}

$pesan_error = '';

// Pengecekan Izin Folder (Wajib untuk SQLite)
if (!is_writable(__DIR__)) {
    $pesan_error = "Folder instalasi tidak bisa ditulisi (Permission Denied). Tolong ubah permission folder ini agar PHP bisa membuat database.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($pesan_error)) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if (empty($email) || empty($password)) {
        $pesan_error = 'Email dan Password wajib diisi, wak!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // IMPROVEMENT: Validasi format email agar tidak ada yang mendaftar tanpa @
        $pesan_error = 'Format email tidak valid, pastikan menggunakan format yang benar (contoh: admin@email.com)!';
    } elseif ($password !== $password_confirm) {
        $pesan_error = 'Konfirmasi sandi tidak cocok!';
    } else {
        try {
            $pdo = new PDO("sqlite:" . $db_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // EKSEKUSI PEMBUATAN STRUKTUR TABEL KAYOOH V3.0
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    password TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS rides (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    strava_id TEXT UNIQUE,
                    name TEXT NOT NULL,
                    distance REAL NOT NULL,
                    moving_time INTEGER NOT NULL,
                    average_speed REAL,
                    max_speed REAL,
                    total_elevation_gain REAL,
                    avg_temp REAL,
                    start_date TEXT NOT NULL,
                    polyline TEXT,
                    source TEXT DEFAULT 'KAYOOH'
                );
                CREATE TABLE IF NOT EXISTS login_logs (
                    ip_address TEXT PRIMARY KEY,
                    attempts INTEGER DEFAULT 1,
                    last_attempt INTEGER NOT NULL
                );
                -- TABEL BARU V3.0: Menyimpan Token Telegram & Pengaturan Lainnya
                CREATE TABLE IF NOT EXISTS settings (
                    setting_key TEXT PRIMARY KEY,
                    setting_value TEXT
                );
            ");

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
            $stmt->execute([$email, $hashed_password]);

            file_put_contents($lock_file, 'Instalasi selesai: ' . date('Y-m-d H:i:s'));
            $_SESSION['is_logged_in'] = true;
            header('Location: dashboard.php');
            exit;
        } catch (PDOException $e) {
            $pesan_error = "Gagal database: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Kayooh v3.0</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>
    
    <div style="position: absolute; top: 20px; right: 20px;">
        <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
    </div>

    <div class="centered-container">
        <div class="box">
            <img src="assets/kayooh.png" alt="Kayooh" style="height: 45px; margin-bottom: 10px;">
            <p class="subtitle" style="color:var(--text-color); opacity:0.7; font-size:12px;">Inisialisasi sistem pelacakan mandiri Anda</p>
            
            <?php if($pesan_error): ?>
                <div class="error"><?= htmlspecialchars($pesan_error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>Alamat Email</label>
                    <input type="email" name="email" placeholder="admin@kayooh.id" required>
                </div>
                <div class="input-group">
                    <label>Kata Sandi</label>
                    <input type="password" name="password" id="password" placeholder="Minimal 8 karakter" required>
                    <button type="button" class="toggle-btn" onclick="togglePassword('password', this)">LIHAT</button>
                </div>
                <div class="input-group">
                    <label>Konfirmasi Kata Sandi</label>
                    <input type="password" name="password_confirm" id="password_confirm" placeholder="Ketik ulang sandi" required>
                    <button type="button" class="toggle-btn" onclick="togglePassword('password_confirm', this)">LIHAT</button>
                </div>
                <button type="submit" class="btn-primary" <?= !is_writable(__DIR__) ? 'disabled style="background:gray;"' : '' ?>>PASANG SEKARANG</button>
            </form>
        </div>
    </div>

    <script>
        // Logika Sembunyikan/Lihat Sandi
        function togglePassword(inputId, btn) {
            var pwd = document.getElementById(inputId);
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