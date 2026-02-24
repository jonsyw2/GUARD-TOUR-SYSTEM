<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id, is_disabled FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
$mapping_status = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
        $mapping_status[(int)$r['id']] = (int)$r['is_disabled'];
    }
}

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
}

// Fetch checkpoints and their latest scan
$qrs_sql = "
    SELECT 
        c.name as checkpoint_name,
        c.agency_client_id,
        MAX(s.scan_time) as last_scanned
    FROM checkpoints c
    LEFT JOIN scans s ON c.id = s.checkpoint_id
    WHERE c.agency_client_id IN ($mapping_ids_str)
    GROUP BY c.id
    ORDER BY c.name ASC
";
$qrs_result = $conn->query($qrs_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkpoints List - Client Portal</title>
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

        .content-area { padding: 32px; max-width: 1000px; margin: 0 auto; width: 100%; }

        .card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;}
        
        .view-only-badge { font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f8fafc; font-weight: 600; color: #475569; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1e293b; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f8fafc; }
        
        .status-active { color: #059669; font-weight: 600; }
        .status-inactive { color: #94a3b8; font-weight: 600; }

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
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_qrs.php" class="nav-link active">Checkpoints</a></li>
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
            <h2>Checkpoint Directory</h2>
        </header>

        <div class="content-area">
            
            <div class="card">
                <div class="card-header">
                    Assigned QR Locations
                    <span class="view-only-badge">Read Only</span>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Checkpoint Name</th>
                                <th>Status</th>
                                <th>Last Scanned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($qrs_result && $qrs_result->num_rows > 0): ?>
                                <?php while($row = $qrs_result->fetch_assoc()): ?>
                                    <?php
                                        // A checkpoint is considered inactive if the admin disabled the assigned agency-client mapping.
                                        $is_disabled = $mapping_status[$row['agency_client_id']] ?? 0;
                                        $status_text = $is_disabled ? 'Inactive' : 'Active';
                                        $status_class = $is_disabled ? 'status-inactive' : 'status-active';
                                        $last_scan = $row['last_scanned'] ? date('M d, Y h:i A', strtotime($row['last_scanned'])) : '<span style="color:#94a3b8">Never Scanned</span>';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['checkpoint_name']); ?></strong></td>
                                        <td class="<?php echo $status_class; ?>">&#9679; <?php echo $status_text; ?></td>
                                        <td><?php echo $last_scan; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="empty-state">No checkpoints have been assigned to your locations yet.</td>
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
