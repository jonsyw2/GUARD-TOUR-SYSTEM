<?php
session_start();
session_unset();
session_destroy();
// Clear the JWT cookie
setcookie('jwt_token', '', time() - 3600, '/', '', isset($_SERVER["HTTPS"]), true);
header("Location: login.php");
exit();
?>
