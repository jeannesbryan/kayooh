<?php
// activities.php - Timeline Aktivitas Kayooh (Infinite Scroll, Peleton Badge & Dark Mode v4.0)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $limit = 20; // Jumlah data per load
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    // =========================================================
    // JALUR 1: LOGIKA AJAX UNTUK INFINITE SCROLL (DATA KE-21 DST)
    // =========================================================
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        $stmt = $pdo->prepare("SELECT * FROM rides ORDER BY start_date DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $rides = $stmt->fetchAll();
        
        if (empty($rides)) exit; // Berhenti jika data habis

        foreach ($rides as $ride) {
            $ts = strtotime($ride['start_date']);
            $hari_id = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
            $bulan_id = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
            
            $source = $ride['source'] ?? '';
            $badge_class = $source === 'STRAVA' ? 'badge-strava' : 'badge-kayooh';
            $badge_text = $source === 'STRAVA' ? 'STRAVA' : 'KAYOOH';
            $name = htmlspecialchars($ride['name']);
            
            // Format Tanggal Indonesia
            $date = $hari_id[date('l', $ts)] . ', ' . date('d', $ts) . ' ' . $bulan_id[date('F', $ts)] . ' ' . date('Y - H:i', $ts);
            
            $elev = number_format($ride['total_elevation_gain'], 0);
            $time = gmdate("H:i:s", $ride['moving_time']);
            $dist = number_format($ride['distance'], 1);

            // Logika Indikator Peleton untuk output AJAX
            $peleton_badge = '';
            if (!empty($ride['participants']) && $ride['participants'] !== '[]') {
                $peleton_badge = "<span style='font-size: 12px; margin-left: 5px;' title='Gowes Peleton'>👥</span>";
            }

            echo "
            <div class='activity-item'>
                <div class='activity-info'>
                    <div style='display: flex; align-items: center;'>
                        <h4 style='margin: 0; display: flex; align-items: center;'>
                            <a href='detail.php?id={$ride['id']}' style='color: var(--text-color); text-decoration: none;'>{$name}</a>
                            {$peleton_badge}
                        </h4>
                        <span class='source-badge {$badge_class}' style='color: #ffffff; margin-left: 10px;'>{$badge_text}</span>
                    </div>
                    <span style='display: block; margin-top: 4px;'>{$date}</span>
                    <div class='activity-details'>
                        <span>Elevasi: <b>{$elev}m</b></span>
                        <span>Waktu: <b>{$time}</b></span>
                    </div>
                </div>
                <div class='activity-data'>
                    {$dist} <small style='font-size: 10px;'>km</small>
                </div>
            </div>";
        }
        exit; // Pastikan AJAX terhenti di sini
    }

    // =========================================================
    // JALUR 2: LOGIKA AWAL / RENDER PERTAMA (HALAMAN BARU DIBUKA)
    // =========================================================
    $stmt = $pdo->prepare("SELECT * FROM rides ORDER BY start_date DESC LIMIT ? OFFSET 0");
    $stmt->execute([$limit]);
    $initial_rides = $stmt->fetchAll();
    
    // Ambil total seluruh aktivitas untuk info di paling bawah
    $total_rides = $pdo->query("SELECT COUNT(*) FROM rides")->fetchColumn();

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

    <div class="activity-list" id="activity-container">
        <?php if (empty($initial_rides)): ?>
            <div class="activity-item" style="justify-content: center; color: #7f8c8d; padding: 40px;">
                Belum ada rekaman jejak, wak. Yuk gowes!
            </div>
        <?php else: ?>
            <?php foreach ($initial_rides as $ride): ?>
                <div class="activity-item">
                    <div class="activity-info">
                        <div style="display: flex; align-items: center;">
                            <h4 style="margin: 0; display: flex; align-items: center;">
                                <a href="detail.php?id=<?= $ride['id'] ?>" style="color: var(--text-color); text-decoration: none;">
                                    <?= htmlspecialchars($ride['name']) ?>
                                </a>
                                <?php if (!empty($ride['participants']) && $ride['participants'] !== '[]'): ?>
                                    <span style="font-size: 12px; margin-left: 5px;" title="Gowes Peleton">👥</span>
                                <?php endif; ?>
                            </h4>
                            
                            <?php if (($ride['source'] ?? '') === 'STRAVA'): ?>
                                <span class="source-badge badge-strava" style="color: #ffffff; margin-left: 10px;">STRAVA</span>
                            <?php else: ?>
                                <span class="source-badge badge-kayooh" style="color: #ffffff; margin-left: 10px;">KAYOOH</span>
                            <?php endif; ?>
                        </div>
                        
                        <span style="display: block; margin-top: 4px;">
                            <?php
                            $ts = strtotime($ride['start_date']);
                            $hari_id = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
                            $bulan_id = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
                            
                            echo $hari_id[date('l', $ts)] . ', ' . date('d', $ts) . ' ' . $bulan_id[date('F', $ts)] . ' ' . date('Y - H:i', $ts);
                            ?>
                        </span>
                        
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

    <div id="loading-indicator" style="display: none; text-align: center; padding: 20px; color: var(--primary-color); font-weight: bold; font-size: 14px;">
        ⏳ Memuat data...
    </div>
    
    <div id="end-indicator" style="margin-top: 30px; text-align: center; font-size: 12px; color: #7f8c8d; <?= $total_rides > $limit ? 'display: none;' : '' ?>">
        Total <b><?= $total_rides ?></b> aktivitas telah dimuat seluruhnya.
    </div>
</div>

<script>
    // --- LOGIKA INFINITE SCROLL ---
    let currentPage = 1;
    let isLoading = false;
    let hasMoreData = <?= $total_rides > $limit ? 'true' : 'false' ?>;

    window.addEventListener('scroll', () => {
        if (isLoading || !hasMoreData) return;
        
        // Cek jika user sudah men-scroll ke dekat bagian paling bawah layar
        if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 200) {
            loadMoreActivities();
        }
    });

    function loadMoreActivities() {
        isLoading = true;
        currentPage++;
        document.getElementById('loading-indicator').style.display = 'block';

        fetch(`activities.php?ajax=1&page=${currentPage}`)
            .then(response => response.text())
            .then(html => {
                document.getElementById('loading-indicator').style.display = 'none';
                
                if (html.trim() === '') {
                    hasMoreData = false; // Data sudah habis
                    document.getElementById('end-indicator').style.display = 'block';
                } else {
                    // Suntikkan data baru ke dalam list HTML
                    document.getElementById('activity-container').insertAdjacentHTML('beforeend', html);
                }
                isLoading = false;
            })
            .catch(err => {
                console.error("Gagal memuat data:", err);
                isLoading = false;
                document.getElementById('loading-indicator').style.display = 'none';
            });
    }

    // --- LOGIKA TOGGLE TEMA ---
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
</script>
</body>
</html>