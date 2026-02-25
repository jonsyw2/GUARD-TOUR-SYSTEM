<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'guard') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guard Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; color: #1f2937; display: flex; flex-direction: column; height: 100vh; }
        header { background: #111827; color: white; padding: 20px; text-align: center; }
        main { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; text-align: center; }
        .card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); max-width: 400px; width: 100%; }
        h1 { margin-bottom: 16px; font-size: 1.5rem; }
        p { color: #6b7280; margin-bottom: 24px; line-height: 1.5; }
        .logout-btn { display: inline-block; padding: 12px 24px; background: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <header>
        <h2>Guard Portal</h2>
    </header>
    <main>
        <div class="card">
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></h1>
            <p>Your mobile patrol interface is currently under development. This page will soon allow you to scan QR codes and report incidents.</p>
            <a href="logout.php" class="logout-btn">Log Out</a>
        </div>
    </main>
</body>
</html>
