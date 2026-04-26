<?php
// index.php - Pintu Gerbang Utama Kayooh v5.0
session_start();

// Lempar sesuai kondisi (Sat-Set)
if (!file_exists(__DIR__ . '/install.lock')) {
    header('Location: install.php');
} elseif ($_SESSION['is_logged_in'] ?? false) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;