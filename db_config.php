<?php
$host = 'localhost';
$dbname = 'ojt';
$username = 'root';
$password = '';

// Create connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Secret key for JWT signing. In a real production app, keep this out of version control and in a secure .env file.
define('JWT_SECRET_KEY', 'guard_tour_super_secret_key_2026!');
?>
