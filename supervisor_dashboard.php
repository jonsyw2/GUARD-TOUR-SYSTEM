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
if (!$supervisor_res || $supervisor_res->num_rows == 0) {
    die("Supervisor profile not found. Please contact admin.");
}
$supervisor = $supervisor_res->fetch_assoc();
$supervisor_id = $supervisor['id'];
$agency_id = $supervisor['agency_id'];

// Determine visible clients/sites for this supervisor
$visible_mapping_ids = [];
$sites_filter_sql = "";

if ($agency_id == 0) {
    // Global Supervisor - See everything
    $sites_filter_sql = "1=1";
} else {
    // Check if specifically assigned to sites
    $assigned_sites_res = $conn->query("SELECT id FROM agency_clients WHERE supervisor_id = $supervisor_id");
    if ($assigned_sites_res && $assigned_sites_res->num_rows > 0) {
        while($r = $assigned_sites_res->fetch_assoc()) $visible_mapping_ids[] = (int)$r['id'];
        $sites_filter_sql = "ac.id IN (" . implode(',', $visible_mapping_ids) . ")";
    } else {
        // Fallback: See all sites for their agency
        $sites_filter_sql = "ac.agency_id = $agency_id";
    }
}

// Fetch clients for filtering
$clients_sql = "
    SELECT ac.id as mapping_id, u.username as client_name 
    FROM agency_clients ac
    JOIN users u ON ac.client_id = u.id
    WHERE $sites_filter_sql
    ORDER BY u.username ASC
";
$clients_res = $conn->query($clients_sql);

// Fetch history with filters
$filter_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_end = $_GET['end_date'] ?? date('Y-m-d');
$filter_client = $_GET['mapping_id'] ?? '';

$where_clauses = [$sites_filter_sql];
$where_clauses[] = "s.scan_time >= '" . $conn->real_escape_string($filter_start . " 00:00:00") . "'";
$where_clauses[] = "s.scan_time <= '" . $conn->real_escape_string($filter_end . " 23:59:59") . "'";

if (!empty($filter_client)) {
    $where_clauses[] = "ac.id = " . (int)$filter_client;
}

$where_sql = implode(" AND ", $where_clauses);

$history_sql = "
    SELECT 
        s.scan_time,
        c.name as checkpoint_name,
        g.name as guard_name,
        u.username as client_name,
        s.status,
        s.justification
    FROM scans s
    JOIN checkpoints c ON s.checkpoint_id = c.id
    JOIN guards g ON s.guard_id = g.id
    JOIN agency_clients ac ON c.agency_client_id = ac.id
    JOIN users u ON ac.client_id = u.id
    WHERE $where_sql
    ORDER BY s.scan_time DESC
    LIMIT 100
";
$history_res = $conn->query($history_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard - Patrol History</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; color: #111827; min-height: 100vh; }
        .topbar { background: #111827; color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .main-content { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-label { font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .form-control { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; }
        .btn-primary { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
        .status-on-time { background: #d1fae5; color: #065f46; }
        .status-late { background: #fef3c7; color: #92400e; }
        .status-missed { background: #fee2e2; color: #991b1b; }
        .badge { background: #0ea5e9; color: white; padding: 4px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .logout-btn { background: #ef4444; color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; }
    </style>
</head>
<body>
    <header class="topbar">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h2 style="font-weight: 700; letter-spacing: -0.5px;">Guard Tour <span style="color: #10b981;">Supervisor</span></h2>
        </div>
        <div style="display: flex; align-items: center; gap: 20px;">
            <span style="font-size: 0.9rem;">Welcome, <strong><?php echo htmlspecialchars($supervisor['name']); ?></strong></span>
            <span class="badge">Supervisor Portal</span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </header>

    <main class="main-content">
        <div class="card">
            <h3 style="margin-bottom: 16px; color: #1e293b;">Patrol History Filters</h3>
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $filter_start; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $filter_end; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Select Site</label>
                    <select name="mapping_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Assigned Sites</option>
                        <?php while($c = $clients_res->fetch_assoc()): ?>
                            <option value="<?php echo $c['mapping_id']; ?>" <?php if($filter_client == $c['mapping_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($c['client_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn-primary">Apply</button>
                    <a href="supervisor_dashboard.php" style="font-size: 0.85rem; color: #64748b; margin-left: 10px; text-decoration: none;">Reset</a>
                </div>
            </form>
        </div>

        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Scan Time</th>
                            <th>Client Site</th>
                            <th>Checkpoint</th>
                            <th>Guard</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history_res && $history_res->num_rows > 0): ?>
                            <?php while($row = $history_res->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 600; font-size: 0.9rem;"><?php echo date('M d, h:i A', strtotime($row['scan_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td style="color: #64748b; font-size: 0.85rem;"><?php echo htmlspecialchars($row['justification'] ?? '---'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #64748b; font-style: italic;">No patrol records found for the selected period.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
