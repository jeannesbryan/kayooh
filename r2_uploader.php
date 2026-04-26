<?php
// r2_uploader.php - Kurir Ekspedisi Cloudflare R2 Kayooh v5.0 (Jalur Tikus + Dynamic DB)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Panggil autoloader dari folder manual AWS (Jalur Tikus 2)
require __DIR__ . '/aws/aws-autoloader.php'; 

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$db_file = __DIR__ . '/kayooh.sqlite';

try {
    // 1. KONEKSI KE DATABASE DULU
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. SEDOT PENGATURAN CLOUDFLARE DARI TABLE SETTINGS
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('r2_access_key', 'r2_secret_key', 'r2_account_id', 'r2_bucket', 'r2_public_url')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $r2_access_key = $settings['r2_access_key'] ?? '';
    $r2_secret_key = $settings['r2_secret_key'] ?? '';
    $r2_account_id = $settings['r2_account_id'] ?? ''; 
    $r2_bucket     = $settings['r2_bucket'] ?? ''; 

    // LOGIC FLEKSIBEL:
    if (!empty($settings['r2_public_url'])) {
        // Pakai Custom Domain user (misal: r2.npc.my.id)
        $r2_public_base = rtrim($settings['r2_public_url'], '/');
    } else {
        // Rakit URL default Cloudflare
        $r2_public_base = "https://{$r2_bucket}.{$r2_account_id}.r2.cloudflarestorage.com";
    }

    // Cek apakah kunci sudah diisi di dashboard
    if (empty($r2_access_key) || empty($r2_secret_key) || empty($r2_account_id) || empty($r2_bucket)) {
        die("❌ Misi Dibatalkan: Kunci Cloudflare R2 belum disetting lengkap di Database/Dashboard!\n");
    }

    // 3. NYALAKAN MESIN KURIR S3 DENGAN KUNCI DARI DATABASE
    $s3 = new S3Client([
        'region'      => 'auto', 
        'endpoint'    => "https://{$r2_account_id}.r2.cloudflarestorage.com",
        'version'     => 'latest',
        'credentials' => [
            'key'    => $r2_access_key,
            'secret' => $r2_secret_key,
        ]
    ]);

    // 4. CARI PAKET GOWES YANG MASIH NYANGKUT DI SERVER LOKAL
    // (Perbaikan: Folder penyimpanan sementara adalah /temp/ bukan /temp_rides/)
    $stmt = $pdo->query("SELECT id, polyline FROM rides WHERE polyline LIKE '%/temp/ride_%'");
    $pending_rides = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($pending_rides) === 0) {
        die("✅ Server bersih! Tidak ada paket JSON yang perlu dikirim ke R2.\n");
    }

    // 5. EKSEKUSI PENGIRIMAN
    foreach ($pending_rides as $ride) {
        $local_file_path = $ride['polyline'];
        $ride_id = $ride['id'];

        if (file_exists($local_file_path)) {
            $file_name = basename($local_file_path); // contoh: ride_1718000_PLTN.json
            $r2_object_key = 'rides/' . $file_name;  // Folder tujuan di dalam R2

            echo "🚀 Menerbangkan $file_name ke R2... <br>";

            // Tembak file ke satelit
            $result = $s3->putObject([
                'Bucket'      => $r2_bucket,
                'Key'         => $r2_object_key,
                'SourceFile'  => $local_file_path,
                'ContentType' => 'application/json'
            ]);

            // Rakit link publiknya
            $public_file_url = $r2_public_base . '/' . $r2_object_key;

            // Update SQLite timpa tulisan folder lokal dengan link internet
            $update_stmt = $pdo->prepare("UPDATE rides SET polyline = ? WHERE id = ?");
            $update_stmt->execute([$public_file_url, $ride_id]);

            // Hapus file sampah lokal
            unlink($local_file_path);

            echo "✅ SUKSES! Tersimpan di: <a href='$public_file_url' target='_blank'>$public_file_url</a><br><hr>";
        } else {
            echo "❌ File lokal tidak ditemukan untuk ID Gowes: $ride_id<br><hr>";
        }
    }

} catch (AwsException $e) {
    die("❌ Error Cloudflare R2: " . $e->getMessage() . "\n");
} catch (Exception $e) {
    die("❌ Error Database: " . $e->getMessage() . "\n");
}
?>