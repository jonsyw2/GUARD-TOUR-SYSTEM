<?php
// Detect environment: local XAMPP vs live cPanel server
$is_local = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);

if ($is_local) {
    // --- Local XAMPP credentials ---
    $host     = 'localhost';
    $dbname   = 'ojt';
    $username = 'root';
    $password = '';
} else {
    // --- Live cPanel credentials (update these!) ---
    $host     = 'localhost';
    $dbname   = 'ccbisphi_guardtour';   // ← change to your cPanel DB name
    $username = 'ccbisphi_guarduser';    // ← change to your cPanel DB username
    $password = 'YOUR_DB_PASSWORD_HERE'; // ← change to your cPanel DB password
}

// Create connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Secret key for JWT signing.
define('JWT_SECRET_KEY', 'guard_tour_super_secret_key_2026!');
?>
