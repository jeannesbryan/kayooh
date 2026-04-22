<?php
// radar_sync.php - Menara Pengawas Peleton Kayooh v3.0
// Strategi: Anti-Database-Locked (Menggunakan JSON murni untuk koordinat real-time)

$log_dir = __DIR__ . '/radar_logs';

// 1. Buat folder log jika belum ada & pastikan izin aksesnya benar
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// 2. Handler POST: Menerima data koordinat dari record.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Sanitasi Input: Hanya alfanumerik untuk keamanan file system (mencegah Path Traversal)
    $room = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['room'] ?? 'SINGLE_MODE');
    $user = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['user'] ?? 'Host');
    
    // Konversi tipe data agar presisi
    $lat   = (float)($_POST['lat'] ?? 0);
    $lon   = (float)($_POST['lon'] ?? 0);
    $speed = (float)($_POST['speed'] ?? 0);
    $timestamp = time();

    if ($lat != 0 && $lon != 0) {
        $payload = [
            'user'        => $user,
            'lat'         => $lat,
            'lon'         => $lon,
            'speed'       => $speed,
            'last_update' => $timestamp
        ];

        // Nama file unik per user di dalam satu room: radar_logs/ROOMID_USERNAME.json
        $file_path = "{$log_dir}/{$room}_{$user}.json";
        
        // Simpan data (Overwrites file lama dengan koordinat terbaru)
        file_put_contents($file_path, json_encode($payload));

        // --- SISTEM PEMBERSIHAN OTOMATIS (Cleanup) ---
        // Agar storage server sampeyan nggak penuh dengan file sampah gowes bulan lalu.
        // Berjalan secara random (1 dari 50 request) agar tidak memberatkan server.
        if (rand(1, 50) === 1) {
            foreach (glob("{$log_dir}/*.json") as $file) {
                // Jika file sudah tidak diupdate lebih dari 24 jam, hapus!
                if (filemtime($file) < (time() - 86400)) {
                    unlink($file);
                }
            }
        }

        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Koordinat tidak valid wak!']);
    }
    exit;
}

// Keamanan: Cegah akses langsung lewat browser
http_response_code(403);
die("Akses dilarang wak! Menara pengawas ini hanya menerima kiriman data GPS.");