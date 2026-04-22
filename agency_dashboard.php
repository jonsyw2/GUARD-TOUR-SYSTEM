<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'];

// Fetch Stats
$total_clients = $conn->query("SELECT COUNT(*) as count FROM agency_clients WHERE agency_id = $agency_id")->fetch_assoc()['count'];
$total_guards = $conn->query("SELECT COUNT(*) as count FROM guards WHERE agency_id = $agency_id")->fetch_assoc()['count'];
$total_supervisors = $conn->query("SELECT COUNT(*) as count FROM supervisors WHERE agency_id = $agency_id")->fetch_assoc()['count'];
$total_inspector_visits = $conn->query("
    SELECT COUNT(*) as count FROM inspector_scans iscn 
    JOIN agency_clients ac ON iscn.agency_client_id = ac.id 
    WHERE ac.agency_id = $agency_id
")->fetch_assoc()['count'];

// Get mapping IDs for this agency to filter checkpoints/scans
$mapping_ids_res = $conn->query("SELECT id FROM agency_clients WHERE agency_id = $agency_id");
$mapping_ids = [];
while($r = $mapping_ids_res->fetch_assoc()) $mapping_ids[] = (int)$r['id'];
$mapping_ids_str = !empty($mapping_ids) ? implode(',', $mapping_ids) : '0';

$total_checkpoints = $conn->query("SELECT COUNT(*) as count FROM checkpoints WHERE agency_client_id IN ($mapping_ids_str) AND is_zero_checkpoint = 0")->fetch_assoc()['count'];

$scans_today = 0;
if ($mapping_ids_str !== '0') {
    $scans_today = $conn->query("
        SELECT COUNT(*) as count FROM scans s 
        JOIN checkpoints c ON s.checkpoint_id = c.id 
        WHERE c.agency_client_id IN ($mapping_ids_str) AND DATE(s.scan_time) = CURDATE()
    ")->fetch_assoc()['count'];
}

// Fetch 5 recent scans (Keep for sidebar/footer context if needed, but we'll focus on the map)
$recent_scans = $conn->query("
    SELECT g.name as guard_name, c.name as checkpoint_name, s.scan_time, ac.site_name
    FROM scans s
    JOIN guards g ON s.guard_id = g.id
    JOIN checkpoints c ON s.checkpoint_id = c.id
    JOIN agency_clients ac ON c.agency_client_id = ac.id
    WHERE ac.agency_id = $agency_id
    ORDER BY s.scan_time DESC
    LIMIT 5
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --bg-main: #f3f4f6;
            --sidebar-bg: #111827;
            --card-bg: #ffffff;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); padding: 0 16px 0 0; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0; overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: var(--primary); }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; }
        .btn-confirm:hover { background: #be123c; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; border-radius: 16px; border: 1px solid var(--border); background: white; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #d1fae5; color: #10b981; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }


        .form-select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; font-family: inherit; color: var(--text-main); outline: none; transition: border-color 0.2s; }
        .form-select:focus { border-color: var(--primary); }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Agency Portal
        </div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link active">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>

            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Overview</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Agency'; ?></strong></span>
                <span class="badge">AGENCY</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <style>
                .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
                .stat-card { background: white; padding: 28px; border-radius: 16px; box-shadow: var(--shadow); display: flex; align-items: center; gap: 20px; border: 1px solid var(--border); transition: transform 0.2s, box-shadow 0.2s; text-decoration: none; color: inherit; }
                .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.12); }
                .stat-icon { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
                .stat-info .label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
                .stat-info .value { font-size: 1.75rem; font-weight: 700; color: var(--text-main); }
                
                .list-layout { display: grid; grid-template-columns: 1fr; gap: 24px; }
                .activity-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                .activity-table th, .activity-table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border); }
                .activity-table th { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; background: #f9fafb; }
                .activity-table td { font-size: 0.9rem; }
            </style>

            <div class="stats-grid">
                <a href="agency_client_management.php" class="stat-card">
                    <div class="stat-icon" style="background: #dcfce7; color: #166534;">💼</div>
                    <div class="stat-info">
                        <div class="label">Total Client</div>
                        <div class="value"><?php echo $total_clients; ?></div>
                    </div>
                </a>
                <a href="manage_guards.php" class="stat-card">
                    <div class="stat-icon" style="background: #dbeafe; color: #1e40af;">👮</div>
                    <div class="stat-info">
                        <div class="label">Total Guards</div>
                        <div class="value"><?php echo $total_guards; ?></div>
                    </div>
                </a>
                <a href="agency_patrol_management.php" class="stat-card">
                    <div class="stat-icon" style="background: #fef9c3; color: #854d0e;">📍</div>
                    <div class="stat-info">
                        <div class="label">Checkpoints</div>
                        <div class="value"><?php echo $total_checkpoints; ?></div>
                    </div>
                </a>
                <a href="agency_patrol_history.php" class="stat-card">
                    <div class="stat-icon" style="background: #f0fdf4; color: #166534; border: 2px solid #10b981;">✅</div>
                    <div class="stat-info">
                        <div class="label">Scans Today</div>
                        <div class="value"><?php echo $scans_today; ?></div>
                    </div>
                </a>
                <a href="agency_inspector_history.php" class="stat-card">
                    <div class="stat-icon" style="background: #e0f2fe; color: #0369a1;">🕵️</div>
                    <div class="stat-info">
                        <div class="label">Inspector Visits</div>
                        <div class="value"><?php echo $total_inspector_visits; ?></div>
                    </div>
                </a>
            </div>

            <div class="list-layout">

                <div class="card">
                    <div class="card-header">Recent Scans Today</div>
                    <div style="overflow-x: auto;">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th>Guard</th>
                                    <th>Checkpoint</th>
                                    <th>Site</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_scans && $recent_scans->num_rows > 0): ?>
                                    <?php while($scan = $recent_scans->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($scan['guard_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($scan['checkpoint_name']); ?></td>
                                            <td><span class="badge" style="background: #f1f5f9; color: #475569;"><?php echo htmlspecialchars($scan['site_name']); ?></span></td>
                                            <td style="color: var(--text-muted);"><?php echo date('h:i:s A', strtotime($scan['scan_time'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 40px;">No scan activity recorded today.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </div>
            <h3 class="modal-title">Ready to Leave?</h3>
            <p class="modal-text">Select "Log Out" below if you are ready to end your current dashboard session.</p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" class="btn-modal btn-confirm">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
    <!-- VERSION: 2.1 -->
</body>
</html>
