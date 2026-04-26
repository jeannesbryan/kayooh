<?php
// radar_sync.php - Menara Pengawas Peleton (Kayooh v5.0 - Universal Sync)
// Fungsi: Menerima sinyal GPS dan mengembalikan posisi semua teman di Room yang sama

$log_dir = __DIR__ . '/radar_logs';

// 1. Pastikan folder log tersedia
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

header('Content-Type: application/json');

// 2. Tangkap Data (Support metode GET dan POST dari v5.0)
$room   = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['room'] ?? 'SINGLE_MODE');
$user   = preg_replace('/[^a-zA-Z0-9 _-]/', '', $_REQUEST['user'] ?? 'Host');
$lat    = (float)($_REQUEST['lat'] ?? 0);
// Ambil 'lng' (v5) atau 'lon' (v4) agar kompatibel
$lon    = (float)($_REQUEST['lng'] ?? $_REQUEST['lon'] ?? 0); 
$speed  = (float)($_REQUEST['speed'] ?? 0);
$action = $_REQUEST['action'] ?? '';

// 3. SIMPAN POSISI KAPTEN/PESERTA
if ($lat != 0 && $lon != 0) {
    $payload = [
        'user'        => $user,
        'lat'         => $lat,
        'lon'         => $lon,
        'speed'       => $speed,
        'last_update' => time()
    ];

    // Simpan ke file JSON per-user agar tidak tabrakan (Database-Locked Free)
    $safe_user = md5($user);
    $file_path = "{$log_dir}/{$room}_{$safe_user}.json";
    file_put_contents($file_path, json_encode($payload));
}

// 4. GARBAGE COLLECTOR (Pembersih Otomatis)
// Berjalan sesekali (peluang 1 banding 20) agar hemat RAM Server
if (rand(1, 20) === 1) {
    foreach (glob("{$log_dir}/*.json") as $file) {
        // Hapus jejak gowes yang sudah usang (> 12 Jam)
        if (filemtime($file) < (time() - 43200)) {
            unlink($file);
        }
    }
}

// 5. RADAR PANTUL (Kirim Balik Posisi Kawan)
// Jika aplikasi meminta data kawan (action=sync), kumpulkan semua titik di Room ini!
if ($action === 'sync') {
    $participants = [];
    $cutoff_time = time() - 300; // Hanya pantau peserta yang aktif bergerak dalam 5 menit terakhir
    
    foreach (glob("{$log_dir}/{$room}_*.json") as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['last_update']) && $data['last_update'] > $cutoff_time) {
            $participants[] = $data;
        }
    }
    echo json_encode(['status' => 'success', 'participants' => $participants]);
    exit;
}

// Jika bukan minta sync, balas sukses biasa
echo json_encode(['status' => 'success']);
?>