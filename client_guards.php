<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs and agency IDs for this client
$maps_sql = "SELECT id, agency_id FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
$agency_ids = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
        $agency_ids[] = (int)$r['agency_id'];
    }
}
$agency_ids = array_unique($agency_ids);

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
    $agency_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
    $agency_ids_str = implode(',', $agency_ids);
}

// Fetch guards assigned to this client's sites via guard_assignments
$guards_sql = "
    SELECT DISTINCT
        g.id as guard_id,
        g.name as guard_name,
        u.username,
        a.username as agency_name,
        ga.assigned_at,
        (
            SELECT COUNT(*) FROM scans s
            JOIN checkpoints c ON s.checkpoint_id = c.id
            WHERE s.guard_id = g.id AND c.agency_client_id IN ($mapping_ids_str)
              AND DATE(s.scan_time) = CURDATE()
        ) as scans_today
    FROM guard_assignments ga
    JOIN guards g ON ga.guard_id = g.id
    JOIN users u ON g.user_id = u.id
    JOIN users a ON g.agency_id = a.id
    WHERE ga.agency_client_id IN ($mapping_ids_str)
    ORDER BY g.name ASC
";
$guards_res = $conn->query($guards_sql);

$total_guards = $guards_res ? $guards_res->num_rows : 0;

// Guards who scanned today
$active_today = 0;
if (!empty($mapping_ids)) {
    $active_sql = "
        SELECT COUNT(DISTINCT s.guard_id) as count
        FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($mapping_ids_str)
          AND DATE(s.scan_time) = CURDATE()
    ";
    $ar = $conn->query($active_sql);
    if ($ar) $active_today = $ar->fetch_assoc()['count'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Guards - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0;}
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
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
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .btn-confirm:hover { background: #be123c; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        .stat-icon-blue { background: #dbeafe; }
        .stat-icon-green { background: #d1fae5; }
        .stat-info .label { font-size: 0.8rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-info .value { font-size: 2rem; font-weight: 700; color: #111827; line-height: 1.2; }

        /* Card */
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px; }
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }

        /* Guards Grid */
        .guards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .guard-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; display: flex; align-items: center; gap: 16px; transition: all 0.2s; }
        .guard-card:hover { border-color: #3b82f6; background: #eff6ff; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.1); }
        .guard-avatar { width: 52px; height: 52px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; font-weight: 700; flex-shrink: 0; }
        .guard-info { flex: 1; min-width: 0; }
        .guard-name { font-size: 1rem; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .guard-username { font-size: 0.82rem; color: #6b7280; margin-top: 2px; }
        .guard-agency { font-size: 0.78rem; color: #3b82f6; font-weight: 500; margin-top: 4px; }
        .guard-scans { margin-top: 8px; display: flex; align-items: center; gap: 6px; }
        .activity-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .dot-active { background: #10b981; }
        .dot-inactive { background: #d1d5db; }
        .scans-text { font-size: 0.82rem; color: #6b7280; }

        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; color: #6b7280; }
        .empty-state svg { margin: 0 auto 16px; display: block; opacity: 0.3; }
        .empty-state p { font-size: 1rem; }

        /* Table styles for overview */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }

        .status-active { display: inline-flex; align-items: center; gap: 6px; color: #059669; font-size: 0.85rem; font-weight: 600; }
        .status-idle { display: inline-flex; align-items: center; gap: 6px; color: #9ca3af; font-size: 0.85rem; font-weight: 600; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link active">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>My Guards</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">

            <!-- Stats Row -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-blue">👮</div>
                    <div class="stat-info">
                        <div class="label">Total Guards Assigned</div>
                        <div class="value"><?php echo $total_guards; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon stat-icon-green">✅</div>
                    <div class="stat-info">
                        <div class="label">Active Today</div>
                        <div class="value"><?php echo $active_today; ?></div>
                    </div>
                </div>
            </div>

            <!-- Guards Grid -->
            <div class="card">
                <div class="card-header">
                    <span>Guards Assigned to Your Site</span>
                    <span style="font-size: 0.85rem; color: #6b7280; font-weight: 400;">Managed by the agency. Contact your agency to add or remove guards.</span>
                </div>

                <?php if ($total_guards > 0): ?>
                    <div class="guards-grid">
                        <?php
                        $guards_res->data_seek(0);
                        while($g = $guards_res->fetch_assoc()):
                            $initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($g['guard_name'])))));
                            $initials = substr($initials, 0, 2);
                            $is_active = $g['scans_today'] > 0;
                        ?>
                            <div class="guard-card">
                                <div class="guard-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                <div class="guard-info">
                                    <div class="guard-name"><?php echo htmlspecialchars($g['guard_name']); ?></div>
                                    <div class="guard-username">@<?php echo htmlspecialchars($g['username']); ?></div>
                                    <div class="guard-agency">Agency: <?php echo htmlspecialchars($g['agency_name']); ?></div>
                                    <div class="guard-scans">
                                        <div class="activity-dot <?php echo $is_active ? 'dot-active' : 'dot-inactive'; ?>"></div>
                                        <span class="scans-text">
                                            <?php if ($is_active): ?>
                                                <?php echo $g['scans_today']; ?> scan<?php echo $g['scans_today'] != 1 ? 's' : ''; ?> today
                                            <?php else: ?>
                                                No activity today
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <p>No guards have been assigned to your site yet.</p>
                        <p style="font-size: 0.875rem; margin-top: 8px;">Please contact your agency to assign guards.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Summary Table -->
            <?php if ($total_guards > 0): ?>
            <div class="card">
                <div class="card-header">Guard Assignment Details</div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Guard Name</th>
                                <th>Username</th>
                                <th>Assigned Agency</th>
                                <th>Date Assigned</th>
                                <th>Today's Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $guards_res->data_seek(0);
                            while($g = $guards_res->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($g['guard_name']); ?></strong></td>
                                    <td style="color: #6b7280;">@<?php echo htmlspecialchars($g['username']); ?></td>
                                    <td><?php echo htmlspecialchars($g['agency_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($g['assigned_at'])); ?></td>
                                    <td>
                                        <?php if ($g['scans_today'] > 0): ?>
                                            <span class="status-active">
                                                <span style="width:8px;height:8px;background:#10b981;border-radius:50%;display:inline-block;"></span>
                                                <?php echo $g['scans_today']; ?> scan<?php echo $g['scans_today'] != 1 ? 's' : ''; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-idle">
                                                <span style="width:8px;height:8px;background:#d1d5db;border-radius:50%;display:inline-block;"></span>
                                                No activity
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </div>
            <h3 class="modal-title">Ready to Leave?</h3>
            <p class="modal-text">Select "Log Out" below if you are ready to end your current session.</p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" class="btn-modal btn-confirm">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>
