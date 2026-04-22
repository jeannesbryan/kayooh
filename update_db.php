<?php
// upgrade_db.php - Script Migrasi Database Kayooh v3.0 (Telegram Settings)
$db_file = __DIR__ . '/kayooh.sqlite';

echo "<h2>Proses Pembaruan Database Kayooh v3.0...</h2>";

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // MIGRASI FITUR TELEGRAM LIVE TRACKING (v3.0)
    try {
        // Buat tabel settings jika belum ada
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT
            );
        ");
        
        // Masukkan key default untuk Telegram jika belum ada
        $pdo->exec("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('telegram_bot_token', '')");
        $pdo->exec("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('telegram_chat_id', '')");
        
        echo "<p>✅ <b>BERHASIL:</b> Tabel 'settings' siap digunakan.</p>";
    } catch (PDOException $e) {
        echo "<p>❌ <b>GAGAL (Telegram Settings):</b> " . $e->getMessage() . "</p>";
    }

    echo "<h3>🎉 Migrasi v3.0 selesai!</h3>";
    echo "<p style='color:red;'><b>PENTING:</b> Silakan hapus file <b>upgrade_db.php</b> ini dari server demi keamanan.</p>";

} catch (PDOException $e) {
    echo "<h1>❌ KONEKSI DATABASE GAGAL!</h1><p>Error: " . $e->getMessage() . "</p>";
}
?>