<?php
// api_radio.php - Kayooh v7.0 (Backend Menara Radio & Auto-Sweeper)
session_start();

// 1. KEAMANAN DASAR
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']);
    exit;
}

// 2. PERSIAPAN GUDANG SUARA
$radio_dir = __DIR__ . '/temp/radio';
if (!is_dir($radio_dir)) {
    mkdir($radio_dir, 0755, true); // Bikin folder otomatis kalau belum ada
}

// 3. FITUR SAKTI: AUTO-SWEEPER (TUKANG SAPU)
// Setiap kali file ini dipanggil, dia akan mengecek dan menghapus 
// file suara yang umurnya sudah lebih dari 3 menit (180 detik)
$files = glob($radio_dir . '/*.webm');
$now = time();
foreach ($files as $file) {
    if (is_file($file)) {
        if ($now - filemtime($file) > 180) { 
            unlink($file); // Sapu bersih dari hardisk Debian!
        }
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==========================================
// MESIN PENERIMA PESAN SUARA (UPLOAD)
// ==========================================
if ($action === 'upload') {
    $room = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['room'] ?? '');
    $user = preg_replace('/[^a-zA-Z0-9_ ]/', '', $_POST['user'] ?? 'Unknown');
    
    if (empty($room) || !isset($_FILES['audio'])) {
        echo json_encode(['status' => 'error', 'message' => 'Data suara tidak valid']);
        exit;
    }

    $audio = $_FILES['audio'];
    
    // Format nama file: timestamp---ROOM---User.webm
    // Delimiter '---' dipakai agar tidak bentrok kalau nama user pakai spasi/garis bawah
    $filename = time() . '---' . $room . '---' . $user . '.webm';
    $destination = $radio_dir . '/' . $filename;

    if (move_uploaded_file($audio['tmp_name'], $destination)) {
        echo json_encode(['status' => 'success', 'filename' => $filename]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan ke server']);
    }
    exit;
}

// ==========================================
// MESIN PEMBAGI PESAN SUARA (SYNC)
// ==========================================
if ($action === 'sync') {
    $room = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['room'] ?? '');
    $last_sync = (int)($_GET['last_sync'] ?? 0); // Kapan terakhir HP ngecek?
    
    if (empty($room)) {
        echo json_encode(['status' => 'error', 'message' => 'Room tidak valid']);
        exit;
    }

    $new_messages = [];
    // Cari semua file suara yang cocok dengan Room ID ini
    $files = glob($radio_dir . '/*---' . $room . '---*.webm');
    
    foreach ($files as $file) {
        $file_time = filemtime($file);
        
        // Kalau file ini lebih baru dari waktu cek terakhir HP, berarti ini pesan baru!
        if ($file_time > $last_sync) {
            $basename = basename($file, '.webm');
            $parts = explode('---', $basename);
            $msg_user = isset($parts[2]) ? $parts[2] : 'Unknown';

            $new_messages[] = [
                'file_url' => 'temp/radio/' . basename($file),
                'user' => $msg_user,
                'timestamp' => $file_time
            ];
        }
    }

    echo json_encode([
        'status' => 'success', 
        'server_time' => time(), // Kirim jam server biar HP tersinkronisasi
        'messages' => $new_messages
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Perintah tidak dikenali']);
?>