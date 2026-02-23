<?php
session_start();
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agency Dashboard</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .logout { color: #ef4444; text-decoration: none; font-weight: bold; }
        .badge { background: #10b981; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>Agency Dashboard</h1>
            <a href="logout.php" class="logout">Logout</a>
        </div>
        <p>Welcome, <strong><?php echo $_SESSION['username']; ?></strong>! <span class="badge">AGENCY</span></p>
        <p>This is the agency portal.</p>
    </div>
</body>
</html>
