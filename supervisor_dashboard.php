<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'supervisor') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch Supervisor details
$supervisor_res = $conn->query("SELECT * FROM supervisors WHERE user_id = $user_id");
$supervisor = $supervisor_res->fetch_assoc();
$agency_id = $supervisor['agency_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; color: #111827; display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { background: #111827; color: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; }
        .main-content { flex: 1; padding: 40px; max-width: 1000px; margin: 0 auto; width: 100%; }
        .welcome-card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); text-align: center; }
        .badge { background: #10b981; color: white; padding: 4px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .logout-btn { background: #ef4444; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <header class="topbar">
        <h2>Supervisor Portal</h2>
        <div style="display: flex; align-items: center; gap: 20px;">
            <span>Welcome, <strong><?php echo htmlspecialchars($supervisor['name']); ?></strong></span>
            <span class="badge">Supervisor</span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </header>

    <main class="main-content">
        <div class="welcome-card">
            <div style="font-size: 4rem; margin-bottom: 20px;">🏢</div>
            <h1 style="margin-bottom: 16px;">Welcome to your Dashboard</h1>
            <p style="color: #6b7280; font-size: 1.1rem; line-height: 1.6;">
                You are logged in as a <strong>Supervisor</strong> for your agency. 
                <br>Currently, the supervisor portal is being prepared with advanced tracking features.
            </p>
            <div style="margin-top: 40px; padding-top: 40px; border-top: 1px solid #e5e7eb; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Your Access Key</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b;"><?php echo $username; ?></div>
                </div>
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Role Profile</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b;">Site Supervisor</div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
