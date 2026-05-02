<?php
// auth_check.php
// Middleware script included at the top of protected pages

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_config.php';
require_once 'jwt_helper.php';

// Prevent browser caching of protected pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check if JWT token exists in cookies
if (!isset($_COOKIE['jwt_token'])) {
    header("Location: login.php?error=not_logged_in");
    exit();
}

$jwt_token = $_COOKIE['jwt_token'];
$decoded_payload = verify_jwt($jwt_token);

if ($decoded_payload === false) {
    // Token is invalid or expired
    // Clear the invalid cookie
    setcookie('jwt_token', '', time() - 3600, '/', '', isset($_SERVER["HTTPS"]), true);
    header("Location: login.php?error=session_expired");
    exit();
}

// Token is valid. Populate session variables just in case the legacy code relies on them
// Although ideally, they should rely solely on the token hereafter.
$_SESSION['user_id'] = $decoded_payload['user_id'];
$_SESSION['username'] = $decoded_payload['username'];
$_SESSION['user_level'] = $decoded_payload['user_level'];

// Populate company_name for client users from agency_clients table
if ($_SESSION['user_level'] === 'client' && !isset($_SESSION['company_name'])) {
    $c_id = $_SESSION['user_id'];
    $comp_res = $conn->query("SELECT company_name FROM agency_clients WHERE client_id = $c_id LIMIT 1");
    if ($comp_res && $comp_row = $comp_res->fetch_assoc()) {
        $_SESSION['company_name'] = $comp_row['company_name'];
    }
}

// (Optional) Enforce specific access levels on different pages.
// Here we just ensure they are logged in. The caller script can verify $_SESSION['user_level'].
?>
