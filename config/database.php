<?php
/**
 * Database Configuration (includes/database.php)
 * Production me DB_USER/DB_PASS environment se do, root + blank password nahi.
 */
date_default_timezone_set('Asia/Kolkata');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'client_flow');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''); // XAMPP default blank

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // PHP (Asia/Kolkata) aur MySQL ka time ab same rahega
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'",
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    die('Database connection failed. Please contact the administrator.');
}
// NOTE: auto-create tables / ALTER checks hata diye (har request par slow the aur errors chhupate the).
// Fresh setup ke liye database.sql ko phpMyAdmin se import karo.