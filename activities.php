<?php
// activities.php - Timeline Aktivitas Kayooh (Dark Mode Supported)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ambil SEMUA data tanpa LIMIT, urutkan dari yang terbaru
    $all_rides = $pdo->query("SELECT * FROM rides ORDER BY start_date DESC")->fetchAll();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timeline Aktivitas - Kayooh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
    <link rel="manifest" href="assets/site.webmanifest">
</head>
<body>
    <script>if(localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');</script>

<div class="dashboard-container">
    <div class="header">
        <a href="dashboard.php" style="text-decoration: none;">
            <img src="assets/kayooh.png" alt="Kayooh" class="nav-logo">
        </a>
        
        <div class="header-actions" style="display: flex; gap: 15px; align-items: center;">
            <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
            <a href="dashboard.php" class="logout-link" style="color: var(--text-color); opacity: 0.7;">&larr; KEMBALI</a>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Timeline Aktivitas</h2>

    <div class="activity-list">
        <?php if (empty($all_rides)): ?>
            <div class="activity-item" style="justify-content: center; color: #7f8c8d; padding: 40px;">
                Belum ada rekaman jejak, wak. Yuk gowes!
            </div>
        <?php else: ?>
            <?php foreach ($all_rides as $ride): ?>
                <div class="activity-item">
                    <div class="activity-info">
                        <div style="display: flex; align-items: center;">
                            <h4><a href="detail.php?id=<?= $ride['id'] ?>" style="color: var(--text-color); text-decoration: none;"><?= htmlspecialchars($ride['name']) ?></a></h4>
                            
                            <?php if (($ride['source'] ?? '') === 'STRAVA'): ?>
                                <span class="source-badge badge-strava">STRAVA</span>
                            <?php else: ?>
                                <span class="source-badge badge-kayooh">KAYOOH</span>
                            <?php endif; ?>
                        </div>
                        <span><?= date('l, d M Y - H:i', strtotime($ride['start_date'])) ?></span>
                        
                        <div class="activity-details">
                            <span>Elevasi: <b><?= number_format($ride['total_elevation_gain'], 0) ?>m</b></span>
                            <span>Waktu: <b><?= gmdate("H:i:s", $ride['moving_time']) ?></b></span>
                        </div>
                    </div>
                    <div class="activity-data">
                        <?= number_format($ride['distance'], 1) ?> <small style="font-size: 10px;">km</small>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="margin-top: 30px; text-align: center; font-size: 12px; color: #7f8c8d;">
        Menampilkan total <?= count($all_rides) ?> aktivitas.
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
  
  // Sesuaikan ikon saat halaman selesai dimuat
  window.addEventListener('DOMContentLoaded', () => { 
      if(localStorage.getItem('theme') === 'dark') {
          document.getElementById('theme-icon').textContent = '☀️';
      }
  });
</script>
</body>
</html>