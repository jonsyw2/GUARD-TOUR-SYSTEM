<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Dashboard</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .logout { color: #ef4444; text-decoration: none; font-weight: bold; }
        .badge { background: #3b82f6; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>Client Dashboard</h1>
            <a href="logout.php" class="logout">Logout</a>
        </div>
        <p>Welcome, <strong><?php echo $_SESSION['username']; ?></strong>! <span class="badge">CLIENT</span></p>
        <p>This is your client dashboard.</p>
    </div>
</body>
</html>
