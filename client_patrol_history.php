<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client
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

// If no assigned mapping, they have no data to view
if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
    $agency_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
    $agency_ids_str = implode(',', $agency_ids);
}

// Fetch filter options: Guards
$guards_sql = "SELECT id, name FROM guards WHERE agency_id IN ($agency_ids_str) ORDER BY name ASC";
$guards_res = $conn->query($guards_sql);

// Fetch filter options: Checkpoints
$checkpoints_sql = "SELECT id, name FROM checkpoints WHERE agency_client_id IN ($mapping_ids_str) ORDER BY name ASC";
$checkpoints_res = $conn->query($checkpoints_sql);

// Handle Filter Submissions
$filter_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_end = $_GET['end_date'] ?? date('Y-m-d');
$filter_guard = $_GET['guard_id'] ?? '';
$filter_checkpoint = $_GET['checkpoint_id'] ?? '';

// Build dynamic WHERE clause
$where_clauses = ["c.agency_client_id IN ($mapping_ids_str)"];

if (!empty($filter_start)) {
    $start = $conn->real_escape_string($filter_start . " 00:00:00");
    $where_clauses[] = "s.scan_time >= '$start'";
}
if (!empty($filter_end)) {
    $end = $conn->real_escape_string($filter_end . " 23:59:59");
    $where_clauses[] = "s.scan_time <= '$end'";
}
if (!empty($filter_guard)) {
    $g_id = (int)$filter_guard;
    $where_clauses[] = "s.guard_id = $g_id";
}
if (!empty($filter_checkpoint)) {
    $c_id = (int)$filter_checkpoint;
    $where_clauses[] = "s.checkpoint_id = $c_id";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch Paginated or Limited History
$history_sql = "
    SELECT 
        s.scan_time,
        c.name as checkpoint_name,
        g.name as guard_name,
        s.status
    FROM scans s
    JOIN checkpoints c ON s.checkpoint_id = c.id
    JOIN guards g ON s.guard_id = g.id
    WHERE $where_sql
    ORDER BY s.scan_time DESC
    LIMIT 200
";

$history_res = $conn->query($history_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patrol History - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f8fafc; color: #1e293b; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #0f172a; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #1e293b; letter-spacing: 0.5px; color: #f8fafc; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #94a3b8; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1e293b; color: #fff; border-left-color: #3b82f6; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #1e293b; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.5rem; font-weight: 600; color: #0f172a; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }

        /* Filter Form */
        .filter-form { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 200px; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;}
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; background-color: #f8fafc;}
        .form-control:focus { outline: none; border-color: #3b82f6; background-color: #fff;}
        .btn-primary { padding: 10px 20px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; white-space: nowrap;}
        .btn-primary:hover { background-color: #2563eb; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f8fafc; font-weight: 600; color: #475569; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1e293b; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f8fafc; }
        
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-on-time { background-color: #d1fae5; color: #059669; }
        .status-late { background-color: #fef3c7; color: #b45309; }
        .status-missed { background-color: #fee2e2; color: #dc2626; }
        
        .empty-state { text-align: center; padding: 40px; color: #64748b; font-style: italic; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; }
        .modal-icon { width: 48px; height: 48px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-text { color: #64748b; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f1f5f9; color: #334155; }
        .btn-confirm { background: #ef4444; color: white; text-decoration: none; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Client Portal
        </div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="client_patrol_history.php" class="nav-link active">Patrol History</a></li>
            <li><a href="client_qrs.php" class="nav-link">Checkpoints</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incidents & Alerts</a></li>
            <li><a href="client_reports.php" class="nav-link">Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="topbar">
            <h2>Activity Logs</h2>
        </header>

        <div class="content-area">
            
            <div class="card">
                <form class="filter-form" method="GET" action="client_patrol_history.php">
                    <div class="form-group">
                        <label class="form-label" for="start_date">Date From</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">Date To</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="guard_id">Guard</label>
                        <select id="guard_id" name="guard_id" class="form-control">
                            <option value="">-- All Guards --</option>
                            <?php if ($guards_res && $guards_res->num_rows > 0): ?>
                                <?php while($g = $guards_res->fetch_assoc()): ?>
                                    <option value="<?php echo $g['id']; ?>" <?php if($filter_guard == $g['id']) echo 'selected'; ?>><?php echo htmlspecialchars($g['name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="checkpoint_id">Location</label>
                        <select id="checkpoint_id" name="checkpoint_id" class="form-control">
                            <option value="">-- All Locations --</option>
                            <?php if ($checkpoints_res && $checkpoints_res->num_rows > 0): ?>
                                <?php while($c = $checkpoints_res->fetch_assoc()): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php if($filter_checkpoint == $c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Apply Filters</button>
                    <a href="client_patrol_history.php" class="btn-primary" style="background: #94a3b8; text-decoration: none; text-align:center;">Reset</a>
                </form>
            </div>

            <div class="card">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Checkpoint Name</th>
                                <th>Guard Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history_res && $history_res->num_rows > 0): ?>
                                <?php while($row = $history_res->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo date('My d, Y h:i A', strtotime($row['scan_time'])); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="empty-state">No patrol activity found for the selected criteria.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">&#10006;</div>
            <h3 class="modal-title">Ready to Leave?</h3>
            <p class="modal-text">Select "Log Out" below if you are ready to end your current dashboard session.</p>
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
