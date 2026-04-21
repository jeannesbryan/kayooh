<?php
// update_db.php - Script Migrasi Database Kayooh v2.0 (Suhu & IP Blocker)
$db_file = __DIR__ . '/kayooh.sqlite';

echo "<h2>Proses Pembaruan Database Kayooh v2.0...</h2>";

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. MIGRASI FITUR SUHU (Tambah kolom avg_temp)
    try {
        $pdo->exec("ALTER TABLE rides ADD COLUMN avg_temp REAL");
        echo "<p>✅ <b>BERHASIL:</b> Kolom 'avg_temp' (Suhu) sukses ditambahkan ke tabel rides.</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "<p>✅ <b>AMAN:</b> Kolom 'avg_temp' (Suhu) ternyata sudah ada di database.</p>";
        } else {
            echo "<p>❌ <b>GAGAL (Suhu):</b> " . $e->getMessage() . "</p>";
        }
    }

    // 2. MIGRASI FITUR KEAMANAN (Buat tabel login_logs)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_logs (
                ip_address TEXT PRIMARY KEY,
                attempts INTEGER DEFAULT 1,
                last_attempt INTEGER NOT NULL
            );
        ");
        echo "<p>✅ <b>BERHASIL:</b> Tabel 'login_logs' (IP Blocker) siap digunakan.</p>";
    } catch (PDOException $e) {
        echo "<p>❌ <b>GAGAL (IP Blocker):</b> " . $e->getMessage() . "</p>";
    }

    echo "<h3>🎉 Semua proses selesai!</h3>";
    echo "<p style='color:red;'><b>PENTING:</b> Silakan hapus file <b>update_db.php</b> ini dari server demi keamanan.</p>";

} catch (PDOException $e) {
    echo "<h1>❌ KONEKSI DATABASE GAGAL!</h1><p>Error: " . $e->getMessage() . "</p>";
}
?>