<?php
// dashboard.php - Pusat Aktivitas Kayooh (Dark Mode & UI Enhanced)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

// 1. Proteksi Halaman: Wajib Login
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 2. Koneksi Database
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ambil Statistik Ringkas
    $stats = $pdo->query("SELECT 
        COUNT(*) as total_rides, 
        SUM(distance) as total_dist, 
        SUM(total_elevation_gain) as total_elev 
        FROM rides")->fetch();

    // Ambil 5 Aktivitas Terakhir
    $stmt = $pdo->query("SELECT * FROM rides ORDER BY start_date DESC LIMIT 5");
    $recent_rides = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// 3. Logika Logout
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
        <img src="assets/kayooh.png" alt="Kayooh" class="nav-logo">
        <div class="header-actions" style="display: flex; gap: 15px; align-items: center;">
            <button onclick="toggleTheme()" class="theme-toggle" id="theme-icon">🌙</button>
            <a href="?logout=1" class="logout-link">LOGOUT</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">🗺️</div>
            <h3>Jarak Total</h3>
            <p><?= number_format($stats['total_dist'] ?? 0, 1) ?> <small style="font-size: 12px;">km</small></p>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⛰️</div>
            <h3>Elevasi</h3>
            <p><?= number_format($stats['total_elev'] ?? 0, 0) ?> <small style="font-size: 12px;">m</small></p>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚴</div>
            <h3>Total Rides</h3>
            <p><?= $stats['total_rides'] ?? 0 ?></p>
        </div>
    </div>

    <div class="action-buttons">
        <a href="record.php" class="btn-action btn-record">🔴 RECORD RIDE</a>
        <a href="strava_import.php" class="btn-action btn-strava">SYNC STRAVA</a>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 style="margin: 0; font-size: 18px;">Aktivitas Terakhir</h2>
        <a href="activities.php" style="font-size: 12px; color: var(--primary-color); font-weight: bold; text-decoration: none;">LIHAT SEMUA &rarr;</a>
    </div>

    <div class="activity-list">
        <?php if (empty($recent_rides)): ?>
            <div class="activity-item" style="justify-content: center; color: #7f8c8d; padding: 30px 15px;">
                Belum ada data gowes, wak. Yuk mengkayooh!
            </div>
        <?php else: ?>
            <?php foreach ($recent_rides as $ride): ?>
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
                        
                        <span><?= date('l, d M Y', strtotime($ride['start_date'])) ?></span>
                        
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
</div>

<script>
  // PWA Service Worker Registration
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js');
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