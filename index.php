<?php
// index.php - Router Utama Kayooh
session_start();

$lock_file = __DIR__ . '/install.lock';

// 1. Cek apakah sistem SUDAH diinstal?
if (!file_exists($lock_file)) {
    // Jika belum diinstal (tidak ada install.lock), lempar ke setup
    header('Location: install.php');
    exit;
}

// 2. Cek apakah user SUDAH login?
if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    // Jika sudah login, lempar ke dashboard
    header('Location: dashboard.php');
    exit;
} else {
    // Jika sudah diinstal tapi belum login, lempar ke form login
    header('Location: login.php');
    exit;
}