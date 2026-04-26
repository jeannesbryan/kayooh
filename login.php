<?php
// login.php - Pintu Masuk Kayooh v5.0 (Secure & Refactored)
session_start();

$lock_file = __DIR__ . '/install.lock';
$db_file = __DIR__ . '/kayooh.sqlite';

// Jika belum diinstal, arahkan ke instalasi
if (!file_exists($lock_file)) { header('Location: install.php'); exit; }
// Jika sudah login, langsung ke dashboard
if ($_SESSION['is_logged_in'] ?? false) { header('Location: dashboard.php'); exit; }

$pesan_error = '';

// Helper Security: Dapatkan IP asli (Tembus Cloudflare/Proxy)
function getClientIP() {
    return $_SERVER["HTTP_CF_CONNECTING_IP"] ?? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]) ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = getClientIP();
    
    try {
        $pdo = new PDO("sqlite:" . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Security 1: Sapu bersih log IP yang kadaluarsa (> 5 menit)
        $pdo->exec("DELETE FROM login_logs WHERE last_attempt < " . (time() - 300));

        // Security 2: Cek status IP saat ini
        $stmt_check = $pdo->prepare("SELECT attempts FROM login_logs WHERE ip_address = ?");
        $stmt_check->execute([$ip]);
        $log = $stmt_check->fetch();

        // Jika percobaan gagal >= 5 kali, Banned sementara!
        if ($log && $log['attempts'] >= 5) {
            $pesan_error = "Sistem dikunci sementara! Terlalu banyak percobaan gagal dari IP Anda. Coba lagi dalam 5 menit.";
        } else {
            $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Verifikasi
            if ($user && password_verify($_POST['password'], $user['password'])) {
                // Sukses: Hapus dosa IP & Cegah Session Fixation
                $pdo->prepare("DELETE FROM login_logs WHERE ip_address = ?")->execute([$ip]);
                session_regenerate_id(true); 
                
                $_SESSION['is_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                header('Location: dashboard.php');
                exit;
            } else {
                // Gagal: Catat IP / Tambah percobaan
                if ($log) {
                    $pdo->prepare("UPDATE login_logs SET attempts = attempts + 1, last_attempt = ? WHERE ip_address = ?")->execute([time(), $ip]);
                } else {
                    $pdo->prepare("INSERT INTO login_logs (ip_address, attempts, last_attempt) VALUES (?, 1, ?)")->execute([$ip, time()]);
                }
                $pesan_error = "Email atau password salah, Kapten!";
            }
        }
    } catch (PDOException $e) { $pesan_error = "Masalah koneksi database: " . $e->getMessage(); }
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
            <p class="subtitle" style="color:var(--text-color); opacity:0.7; font-size:12px;">Secure GPS Tracker v5.0</p>
            
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
        // --- THEME ENGINE ---
        const toggleTheme = () => {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.getElementById('theme-icon').textContent = isDark ? '☀️' : '🌙';
        };

        window.addEventListener('DOMContentLoaded', () => { 
            if(localStorage.getItem('theme') === 'dark') {
                document.getElementById('theme-icon').textContent = '☀️';
            }
        });

        // --- PASSWORD TOGGLE ---
        const togglePassword = () => {
            const pwd = document.getElementById("password");
            const btn = document.querySelector(".toggle-btn");
            const isText = pwd.type === "text";
            
            pwd.type = isText ? "password" : "text";
            btn.textContent = isText ? "LIHAT" : "SEMBUNYI";
        };
    </script>
</body>
</html>