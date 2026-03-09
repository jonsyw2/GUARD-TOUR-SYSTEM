<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'inspector') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; align-items: center; justify-content: center; height: 100vh; background-color: #f3f4f6; color: #1f2937; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); text-align: center; max-width: 400px; width: 100%; }
        h1 { margin-bottom: 10px; font-size: 1.5rem; }
        p { color: #6b7280; margin-bottom: 30px; }
        .logout-btn { display: inline-block; padding: 12px 24px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Welcome, Inspector!</h1>
        <p>You are logged in as <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>.</p>
        <p>The inspector portal is currently under development. Please check back later for more features.</p>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</body>
</html>
