<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id, site_name FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
$sites = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
        $sites[] = $r['site_name'];
    }
}
$mapping_ids_str = !empty($mapping_ids) ? implode(',', $mapping_ids) : '0';

// Metrics
$scans_today = 0;
$guards_active = 0;
$open_incidents = 0;

if ($mapping_ids_str !== '0') {
    // Scans Today
    $scans_today = $conn->query("
        SELECT COUNT(*) as count FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($mapping_ids_str) AND DATE(s.scan_time) = CURDATE()
    ")->fetch_assoc()['count'];

    // Guards Active (who scanned today)
    $guards_active = $conn->query("
        SELECT COUNT(DISTINCT s.guard_id) as count FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($mapping_ids_str) AND DATE(s.scan_time) = CURDATE()
    ")->fetch_assoc()['count'];

    // Open Incidents
    $open_incidents = $conn->query("
        SELECT COUNT(*) as count FROM incidents 
        WHERE agency_client_id IN ($mapping_ids_str) AND status != 'Resolved'
    ")->fetch_assoc()['count'];

    // Recent Activity (10 rows)
    $recent_activity = $conn->query("
        SELECT g.name as guard_name, c.name as checkpoint_name, s.scan_time, ac.site_name
        FROM scans s
        JOIN guards g ON s.guard_id = g.id
        JOIN checkpoints c ON s.checkpoint_id = c.id
        JOIN agency_clients ac ON c.agency_client_id = ac.id
        WHERE ac.client_id = $client_id
        ORDER BY s.scan_time DESC
        LIMIT 10
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --bg-main: #f3f4f6;
            --sidebar-bg: #111827;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: #fff; display: flex; flex-direction: column; flex-shrink: 0; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: var(--primary); }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .badge { background: #dbeafe; color: var(--primary); padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        
        /* Stats Styles */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .stat-card { background: white; padding: 24px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border); display: flex; align-items: center; gap: 16px; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        .stat-value { font-size: 1.75rem; font-weight: 700; color: var(--text-main); }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border); margin-bottom: 24px; }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        
        .activity-table { width: 100%; border-collapse: collapse; }
        .activity-table th { text-align: left; padding: 12px 16px; background: #f9fafb; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        .activity-table td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link active">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Security Overview</h2>
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 0.9rem;">Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            <!-- Summary Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">📊</div>
                    <div>
                        <div class="stat-value"><?php echo $scans_today; ?></div>
                        <div class="stat-label">Scans Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f0fdf4; color: #10b981;">🛡️</div>
                    <div>
                        <div class="stat-value"><?php echo $guards_active; ?></div>
                        <div class="stat-label">Active Guards</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fef2f2; color: #ef4444;">⚠️</div>
                    <div>
                        <div class="stat-value"><?php echo $open_incidents; ?></div>
                        <div class="stat-label">Open Alerts</div>
                    </div>
                </div>
            </div>

            <!-- Site Activity -->
            <div class="card">
                <div class="card-header">
                    <span>Recent Patrol Activity</span>
                    <a href="client_patrol_history.php" style="font-size: 0.85rem; color: var(--primary); text-decoration: none; font-weight: 500;">View History →</a>
                </div>
                <div style="overflow-x: auto;">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>Guard Name</th>
                                <th>Checkpoint</th>
                                <th>Site Location</th>
                                <th>Scan Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($recent_activity) && $recent_activity->num_rows > 0): ?>
                                <?php while($act = $recent_activity->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($act['guard_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($act['checkpoint_name']); ?></td>
                                        <td><span style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem;"><?php echo htmlspecialchars($act['site_name']); ?></span></td>
                                        <td style="color: var(--text-muted);"><?php echo date('M d, h:i A', strtotime($act['scan_time'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 40px;">No patrol records found for your sites.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
