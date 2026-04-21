<?php
// login.php - Pintu Masuk Kayooh (Secure + Rate Limiting)
session_start();

$lock_file = __DIR__ . '/install.lock';
$db_file = __DIR__ . '/kayooh.sqlite';

// Keamanan ekstra: Jika belum diinstal, arahkan ke instalasi
if (!file_exists($lock_file)) {
    header('Location: install.php');
    exit;
}

// Jika sudah login, langsung ke dashboard
if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$pesan_error = '';

// Security: Inisialisasi Rate Limiting
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['last_attempt_time'])) $_SESSION['last_attempt_time'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Security: Cek jika mencoba login >= 5 kali dalam waktu kurang dari 5 menit (300 detik)
    if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 300) {
        $pesan_error = "Sistem dikunci sementara wak! Coba lagi dalam 5 menit.";
    } else {
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];

        try {
            $pdo = new PDO("sqlite:" . $db_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Verifikasi password
            if ($user && password_verify($password, $user['password'])) {
                // Mencegah Session Fixation Attack
                session_regenerate_id(true); 
                
                $_SESSION['is_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['login_attempts'] = 0; // Reset counter saat berhasil masuk
                header('Location: dashboard.php');
                exit;
            } else {
                // Catat percobaan gagal
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
                $pesan_error = "Email atau password salah, wak!";
            }
        } catch (PDOException $e) {
            $pesan_error = "Masalah koneksi database: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kayooh</title>
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
            <p class="subtitle" style="color:var(--text-color); opacity:0.7; font-size:12px;">Secure GPS Tracker</p>
            
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
                    <input type="password" name="password" id="password" required>
                    <button type="button" class="toggle-btn" onclick="togglePassword()">LIHAT</button>
                </div>
                <button type="submit" class="btn-primary">MASUK</button>
            </form>
        </div>
    </div>

    <script>
        // Logika Toggle Tema
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';
        }
        
        // Sesuaikan ikon saat halamana selesai diload
        window.onload = () => { 
            if(localStorage.getItem('theme') === 'dark') {
                document.getElementById('theme-icon').textContent = '☀️';
            }
        }

        // Logika Sembunyikan/Lihat Sandi
        function togglePassword() {
            var pwd = document.getElementById("password");
            var btn = document.querySelector(".toggle-btn");
            if (pwd.type === "password") {
                pwd.type = "text";
                btn.textContent = "SEMBUNYI";
            } else {
                pwd.type = "password";
                btn.textContent = "LIHAT";
            }
        }
    </script>
</body>
</html>