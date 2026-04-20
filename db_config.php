<?php
// Detect environment: local XAMPP vs live cPanel server
$is_local = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || PHP_SAPI === 'cli');

if ($is_local) {
    // --- Local XAMPP credentials ---
    $host     = '127.0.0.1';
    $dbname   = 'ojt';
    $username = 'root';
    $password = '';
} else {
    // --- Live cPanel credentials (update these!) ---
    $host     = 'localhost';
    $dbname   = 'ccbisphi_guardtour';   // ← change to your cPanel DB name
    $username = 'ccbisphi_guardtour';    // ← change to your cPanel DB username
    $password = 'i{Pn{_FkI+*ddkLH'; // ← change to your cPanel DB password
}

// Set default timezone (e.g., Asia/Manila for UTC+8)
date_default_timezone_set('Asia/Manila');

// Create connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure MySQL session also matches the PHP timezone
$conn->query("SET time_zone = '+08:00'");

// Secret key for JWT signing.
define('JWT_SECRET_KEY', 'guard_tour_super_secret_key_2026!');
?>
