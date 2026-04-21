<?php
// install.php - Setup Database & Registrasi Admin Kayooh
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
    } elseif ($password !== $password_confirm) {
        $pesan_error = 'Konfirmasi sandi tidak cocok!';
    } else {
        try {
            $pdo = new PDO("sqlite:" . $db_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
                    start_date TEXT NOT NULL,
                    polyline TEXT,
                    source TEXT DEFAULT 'KAYOOH'
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
    <title>Setup Kayooh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
</head>
<body>
    <div class="centered-container">
        <div class="box">
            <div class="brand-logo">KAYOOH</div>
            <p class="subtitle">Inisialisasi sistem pelacakan mandiri Anda</p>
            
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
                </div>
                <div class="input-group">
                    <label>Konfirmasi Kata Sandi</label>
                    <input type="password" name="password_confirm" id="password_confirm" placeholder="Ketik ulang sandi" required>
                </div>
                <button type="submit" class="btn-primary" <?= !is_writable(__DIR__) ? 'disabled style="background:gray;"' : '' ?>>PASANG SEKARANG</button>
            </form>
        </div>
    </div>
</body>
</html>