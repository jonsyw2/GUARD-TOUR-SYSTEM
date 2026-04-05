<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'];

// Data repair: Fix invalid scan_time (0000-00-00) in patrol scans
$conn->query("UPDATE scans SET scan_time = CURRENT_TIMESTAMP WHERE scan_time = '0000-00-00 00:00:00' OR scan_time IS NULL");

// Fetch all clients for this agency to populate filter
$clients_sql = "
    SELECT ac.id as mapping_id, u.username as client_name 
    FROM agency_clients ac
    JOIN users u ON ac.client_id = u.id
    WHERE ac.agency_id = $agency_id
    ORDER BY u.username ASC
";
$clients_res = $conn->query($clients_sql);
$mapping_ids = [];
if ($clients_res) {
    while($r = $clients_res->fetch_assoc()) $mapping_ids[] = (int)$r['mapping_id'];
}
$mapping_ids_str = empty($mapping_ids) ? "0" : implode(',', $mapping_ids);

// Reset pointer for filter dropdown
if ($clients_res) mysqli_data_seek($clients_res, 0);

// Fetch guards for filtering
$guards_sql = "SELECT id, name FROM guards WHERE agency_id = $agency_id ORDER BY name ASC";
$guards_res = $conn->query($guards_sql);

// Handle Filter Submissions
$filter_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_end = $_GET['end_date'] ?? date('Y-m-d');
$filter_guard = $_GET['guard_id'] ?? '';
$filter_client = $_GET['mapping_id'] ?? '';
$filter_checkpoint = $_GET['checkpoint_id'] ?? '';
$filter_shift = $_GET['shift'] ?? '';

// Fetch checkpoints for filtering (Scope to site if selected)
$cp_scope_sql = !empty($filter_client) ? "agency_client_id = " . (int)$filter_client : "agency_client_id IN ($mapping_ids_str)";
$cp_filter_sql = "SELECT id, name FROM checkpoints WHERE $cp_scope_sql ORDER BY name ASC";
$checkpoints_res = $conn->query($cp_filter_sql);

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
if (!empty($filter_client)) {
    $m_id = (int)$filter_client;
    $where_clauses[] = "c.agency_client_id = $m_id";
}
if (!empty($filter_checkpoint)) {
    $c_id = (int)$filter_checkpoint;
    $where_clauses[] = "s.checkpoint_id = $c_id";
}
if (!empty($filter_shift)) {
    $shift = $conn->real_escape_string($filter_shift);
    $where_clauses[] = "s.shift = '$shift'";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch history
$history_sql = "
    SELECT 
        DATE(s.scan_time) as scan_date,
        s.scan_time,
        s.tour_session_id,
        c.name as checkpoint_name,
        g.name as guard_name,
        u.username as client_name,
        s.status,
        s.shift,
        s.justification,
        s.photo_path,
        s.justification_photo_path
    FROM scans s
    JOIN checkpoints c ON s.checkpoint_id = c.id
    JOIN guards g ON s.guard_id = g.id
    JOIN agency_clients ac ON c.agency_client_id = ac.id
    JOIN users u ON ac.client_id = u.id
    WHERE $where_sql
    ORDER BY DATE(s.scan_time) DESC, COALESCE(s.tour_session_id, '') DESC, s.scan_time ASC
    LIMIT 200
";

$history_res = null;
if (!empty($filter_client)) {
    $history_res = $conn->query($history_sql);
}

// Handle Download
if (isset($_GET['download_csv']) && $_GET['download_csv'] == '1' && !empty($filter_client)) {
    // Re-run query WITHOUT the limit for full export
    $csv_sql = str_replace("LIMIT 200", "", $history_sql);
    $csv_res = $conn->query($csv_sql);

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=patrol_history_' . date('Ymd_His') . '.xls');
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head>
        <meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">
        <style>
            table { border-collapse: collapse; }
            th { background-color: #10b981; color: white; border: 1px solid #cbd5e1; padding: 12px; text-align: center; font-weight: bold; }
            td { border: 1px solid #cbd5e1; padding: 10px; vertical-align: middle; white-space: nowrap; }
            .status-on-time { color: #059669; font-weight: bold; }
            .status-late { color: #b45309; font-weight: bold; }
            .status-missed { color: #dc2626; font-weight: bold; }
            .text-center { text-align: center; }
        </style>
    </head>
    <body>
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Client</th>
                    <th>Checkpoint</th>
                    <th>Guard</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>';
    
    if ($csv_res && $csv_res->num_rows > 0) {
        $current_csv_session = '';
        while ($row = $csv_res->fetch_assoc()) {
            // Unify session ID: exclude guard if no specific guard is filtered
            $session_id = ($row['tour_session_id'] ?? '') ?: ($row['scan_date'] . '|' . $row['shift'] . (empty($filter_guard) ? '' : '|' . $row['guard_name']));
            
            if ($current_csv_session !== $session_id) {
                $current_csv_session = $session_id;
                
                // Robust date parsing to avoid 1970
                $raw_time = $row['tour_session_id'] ?: $row['scan_time'];
                $ts = strtotime($raw_time);
                $display_time = ($ts && $ts > 0) ? date('M d, Y h:i A', $ts) : date('M d, Y h:i A'); // Fallback to current time if invalid
                
                $display_shift = htmlspecialchars($row['shift'] ?? 'No Shift');
                $display_guard = htmlspecialchars($row['guard_name']);
                
                $header_guard_info = empty($filter_guard) ? "Participating Guards" : "Guard: " . $display_guard;
                echo '<tr style="background-color: #f1f5f9;"><td colspan="6" style="font-weight: bold; text-align: left; padding: 10px; border: 1px solid #cbd5e1;">TOUR CYCLE: ' . $display_time . ' | ' . $display_shift . ' Shift | ' . $header_guard_info . '</td></tr>';
            }
            $status_class = 'status-' . strtolower($row['status']);
            echo '<tr>';
            echo '<td class="text-center">' . date('h:i:s A', strtotime($row['scan_time'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['client_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['checkpoint_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['guard_name']) . '</td>';
            echo '<td class="text-center ' . $status_class . '">' . htmlspecialchars($row['status']) . '</td>';
            echo '<td>' . htmlspecialchars($row['justification'] ?? '---') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></body></html>';
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patrol History - Agency Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #d1fae5; color: #10b981; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1400px; margin: 0 auto; width: 100%; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }

        /* Filter Form */
        .filter-form { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 180px; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #4b5563; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;}
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; background-color: #f9fafb; transition: all 0.2s; }
        .form-control:focus { outline: none; border-color: #10b981; background-color: #fff; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
        .btn-primary { padding: 10px 20px; background-color: #10b981; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center; }
        .btn-primary:hover { background-color: #059669; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-on-time { background-color: #d1fae5; color: #059669; }
        .status-late { background-color: #fef3c7; color: #b45309; }
        .status-missed { background-color: #fee2e2; color: #dc2626; }
        
        .empty-state { text-align: center; padding: 40px; color: #6b7280; font-style: italic; }

        /* Modal Styles */
        .modal-overlay { 
            display: none; 
            position: fixed; 
            top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(17, 24, 39, 0.7); 
            z-index: 2000; 
            backdrop-filter: blur(4px); 
            overflow-y: auto; 
            padding: 20px;
        }
        .modal-overlay.show { 
            display: flex; 
            align-items: flex-start; 
            justify-content: center; 
        }
        .modal-content { 
            background: white; 
            padding: 32px; 
            border-radius: 12px; 
            width: 100%; 
            max-width: 450px; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); 
            text-align: center; 
            animation: modalFadeIn 0.3s ease-out forwards; 
            position: relative;
            margin: auto;
        }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: 24px;
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
            padding: 4px;
            border-radius: 4px;
        }
        .modal-close:hover { color: #111827; background: #f3f4f6; }
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .btn-confirm:hover { background: #be123c; }

        /* Photo View Styles */
        .btn-view-photo {
            padding: 6px 12px;
            background-color: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-view-photo:hover { background-color: #2563eb; }
        .btn-view-photo.justification { background-color: #f59e0b; }
        .btn-view-photo.justification:hover { background-color: #d97706; }
        
        .photo-modal-content { max-width: 800px !important; }
        .modal-image-container { 
            position: relative; 
            width: 100%; 
            background: #f3f4f6; 
            border-radius: 8px; 
            overflow: auto; 
            margin: 20px 0; 
            max-height: 65vh;
            border: 1px solid #e5e7eb;
        }
        #modalImage { 
            max-width: 100%; 
            display: block; 
            border-radius: 8px; 
            margin: 0 auto;
            cursor: zoom-in;
        }
        .modal-reason-container { text-align: left; background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b; margin-top: 10px; display: none; }
        .modal-reason-label { font-size: 0.75rem; font-weight: 700; color: #b45309; text-transform: uppercase; margin-bottom: 4px; display: block; }
        .modal-reason-text { font-size: 0.95rem; color: #1f2937; line-height: 1.5; }
        
        .table-header-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Agency Portal
        </div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>

            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link active">Patrol History</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>

        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Detailed Activity Logs</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Agency'; ?></strong></span>
                <span class="badge">AGENCY</span>
            </div>
        </header>

        <div class="content-area">
            
            <div class="card">
                <div class="card-header">Filter Patrol History</div>
                <form class="filter-form" method="GET" action="agency_patrol_history.php">
                    <div class="form-group">
                        <label class="form-label" for="start_date">Date From</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">Date To</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mapping_id">Client Site</label>
                        <select id="mapping_id" name="mapping_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Select A Client --</option>
                            <?php if ($clients_res && $clients_res->num_rows > 0): ?>
                                <?php mysqli_data_seek($clients_res, 0); ?>
                                <?php while($c = $clients_res->fetch_assoc()): ?>
                                    <option value="<?php echo $c['mapping_id']; ?>" <?php if($filter_client == $c['mapping_id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['client_name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="guard_id">Guard</label>
                        <select id="guard_id" name="guard_id" class="form-control" onchange="this.form.submit()" <?php if(empty($filter_client)) echo 'disabled title="Please select a Client Site first"'; ?>>
                            <option value="">-- All Guards --</option>
                            <?php if ($guards_res && $guards_res->num_rows > 0): ?>
                                <?php mysqli_data_seek($guards_res, 0); ?>
                                <?php while($g = $guards_res->fetch_assoc()): ?>
                                    <option value="<?php echo $g['id']; ?>" <?php if($filter_guard == $g['id']) echo 'selected'; ?>><?php echo htmlspecialchars($g['name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="checkpoint_id">Checkpoint</label>
                        <select id="checkpoint_id" name="checkpoint_id" class="form-control" onchange="this.form.submit()" <?php if(empty($filter_client)) echo 'disabled title="Please select a Client Site first"'; ?>>
                            <option value="">-- All Checkpoints --</option>
                            <?php if ($checkpoints_res && $checkpoints_res->num_rows > 0): ?>
                                <?php mysqli_data_seek($checkpoints_res, 0); ?>
                                <?php while($cp = $checkpoints_res->fetch_assoc()): ?>
                                    <option value="<?php echo $cp['id']; ?>" <?php if($filter_checkpoint == $cp['id']) echo 'selected'; ?>><?php echo htmlspecialchars($cp['name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="shift">Shift</label>
                        <select id="shift" name="shift" class="form-control" onchange="this.form.submit()">
                            <option value="">-- All Shifts --</option>
                            <option value="Morning" <?php if($filter_shift == 'Morning') echo 'selected'; ?>>Morning</option>
                            <option value="Afternoon" <?php if($filter_shift == 'Afternoon') echo 'selected'; ?>>Afternoon</option>
                            <option value="Night" <?php if($filter_shift == 'Night') echo 'selected'; ?>>Night</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn-primary">Apply Filters</button>
                        <a href="agency_patrol_history.php" class="btn-primary" style="background: #94a3b8; text-decoration: none;">Reset</a>
                    </div>
                </form>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                    <button type="button" class="btn-primary" style="background: #0ea5e9; <?php if (!$history_res || $history_res->num_rows == 0) echo 'opacity: 0.5; cursor: not-allowed;'; ?>" onclick="downloadHistoryCSV()" <?php if (!$history_res || $history_res->num_rows == 0) echo 'disabled title="No data to download"'; ?>>Download</button>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Client</th>
                                <th>Checkpoint</th>
                                <th>Guard</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Photos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filter_client)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">Please select a Client Site to view patrol history.</td>
                                </tr>
                            <?php elseif ($history_res && $history_res->num_rows > 0): ?>
                                <?php 
                                $current_session = ''; 
                                while($row = $history_res->fetch_assoc()): 
                                    // Unify session ID: exclude guard if no specific guard is filtered to avoid creating separate reports
                                    $session_id = ($row['tour_session_id'] ?? '') ?: ($row['scan_date'] . '|' . $row['shift'] . (empty($filter_guard) ? '' : '|' . $row['guard_name']));

                                    if ($current_session !== $session_id):
                                        $current_session = $session_id;
                                        
                                        // Robust date parsing to avoid 1970
                                        $raw_cycle = $row['tour_session_id'] ?: $row['scan_time'];
                                        $cycle_ts = strtotime($raw_cycle);
                                        $cycle_time = ($cycle_ts && $cycle_ts > 0) ? date('M d, Y h:i A', $cycle_ts) : date('M d, Y h:i A');

                                        $display_shift = htmlspecialchars($row['shift'] ?? 'No Shift');
                                        $display_guard = htmlspecialchars($row['guard_name']);
                                        $header_guard_info = empty($filter_guard) ? "Participating Guards" : "Guard: <strong style='color: #1e293b;'>" . $display_guard . "</strong>";
                                ?>
                                    <tr style="background-color: #f8fafc; border-top: 2px solid #e2e8f0; border-bottom: 2px solid #e2e8f0;">
                                        <td colspan="7" style="font-weight: 700; color: #334155; padding: 14px 16px; font-size: 0.9rem; background: linear-gradient(to right, #f8fafc, #ffffff);">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="background: #e2e8f0; padding: 6px; border-radius: 8px; color: #64748b;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path></svg>
                                                </div>
                                                <span style="text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-size: 0.75rem;">Tour Cycle:</span>
                                                <span style="color: #1e293b;"><?php echo $cycle_time; ?></span>
                                                <span style="color: #cbd5e1;">&bull;</span>
                                                <span style="color: #64748b;"><?php echo $display_shift; ?> Shift</span>
                                                <span style="color: #cbd5e1;">&bull;</span>
                                                <span style="color: #64748b;"><?php echo $header_guard_info; ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                    <tr>
                                        <td><strong><?php echo date('h:i:s A', strtotime($row['scan_time'])); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                         <td style="font-size: 0.85rem; color: #4b5563; font-style: italic;">
                                                <?php if (!empty($row['justification'])): ?>
                                                    <?php echo htmlspecialchars($row['justification']); ?>
                                                    <button class="btn-view-photo justification" style="margin-left: 8px; transform: scale(0.85);" onclick="viewPhoto('<?php echo htmlspecialchars($row['justification_photo_path'] ?? ''); ?>', 'Justification - <?php echo htmlspecialchars($row['checkpoint_name']); ?>', '<?php echo addslashes(htmlspecialchars($row['justification'])); ?>')">View Full</button>
                                                <?php else: ?>
                                                    ---
                                                <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 4px;">
                                                <?php if (!empty($row['photo_path'])): ?>
                                                    <button class="btn-view-photo" onclick="viewPhoto('<?php echo htmlspecialchars($row['photo_path']); ?>', 'Patrol Selfie - <?php echo htmlspecialchars($row['checkpoint_name']); ?>', '<?php echo addslashes(htmlspecialchars($row['justification'] ?? '')); ?>')">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                                                        Selfie
                                                    </button>
                                                <?php endif; ?>
                                                
                                                 <?php if (!empty($row['justification_photo_path'])): ?>
                                                    <button class="btn-view-photo justification" onclick="viewPhoto('<?php echo htmlspecialchars($row['justification_photo_path']); ?>', 'Justification Photo - <?php echo htmlspecialchars($row['checkpoint_name']); ?>', '<?php echo addslashes(htmlspecialchars($row['justification'] ?? '')); ?>')">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                                                        Justification
                                                    </button>
                                                <?php elseif (!empty($row['justification'])): ?>
                                                    <button class="btn-view-photo justification" onclick="viewPhoto('', 'Justification - <?php echo htmlspecialchars($row['checkpoint_name']); ?>', '<?php echo addslashes(htmlspecialchars($row['justification'])); ?>')">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                                        Reason
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if (empty($row['photo_path']) && empty($row['justification_photo_path']) && empty($row['justification'])): ?>
                                                    <span style="color: #9ca3af; font-size: 0.75rem;">No Photo</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">No patrol activity found for the selected criteria.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- Photo Modal -->
    <div class="modal-overlay" id="photoModal">
        <div class="modal-content photo-modal-content">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 class="modal-title" id="photoModalTitle">Patrol Photo</h3>
            <div class="modal-image-container">
                <img id="modalImage" src="" alt="Patrol Photo" onclick="window.open(this.src, '_blank')" title="Click to view full size">
            </div>
            <div class="modal-reason-container" id="modalReasonContainer">
                <span class="modal-reason-label">Reason / Remark:</span>
                <p class="modal-reason-text" id="modalReasonText"></p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="closeAllModals()" style="width: 100%;">Close</button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </div>
            <h3 class="modal-title">Ready to Leave?</h3>
            <p class="modal-text">Select "Log Out" below if you are ready to end your current dashboard session.</p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="closeAllModals()">Cancel</button>
                <a href="logout.php" class="btn-modal btn-confirm">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function downloadHistoryCSV() {
            const url = new URL(window.location.href);
            url.searchParams.set('download_csv', '1');
            window.location.href = url.toString();
        }

        function viewPhoto(url, title, reason = '') {
            const modal = document.getElementById('photoModal');
            const modalImg = document.getElementById('modalImage');
            const modalTitle = document.getElementById('photoModalTitle');
            const reasonContainer = document.getElementById('modalReasonContainer');
            const reasonText = document.getElementById('modalReasonText');
            
            modalTitle.innerText = title;
            modalImg.src = url;
            
            if (reason && reason.trim() !== '') {
                reasonText.innerText = reason;
                reasonContainer.style.display = 'block';
            } else {
                reasonContainer.style.display = 'none';
            }
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeAllModals() {
            document.querySelectorAll('.modal-overlay').forEach(modal => {
                modal.classList.remove('show');
            });
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeAllModals();
            }
        }
    </script>
</body>
</html>
