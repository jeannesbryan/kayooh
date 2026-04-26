<?php
// logout.php - Menutup Sesi Kayooh v5.0 (Sat-Set Langsung Eksekusi)
session_start();

// 1. Hancurkan sesi di server
$_SESSION = [];
session_destroy();
?>
<script>
    // 2. Bersihkan memori Black Box di browser
    localStorage.removeItem('active_session');
    localStorage.removeItem('kayooh_single_state');
    localStorage.removeItem('kayooh_peleton_state');
    
    // 3. Langsung tendang ke login tanpa delay
    window.location.replace('login.php');
</script>