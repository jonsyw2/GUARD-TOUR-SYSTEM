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
<<<<<<< HEAD
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
=======
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_guard = $_GET['guard_id'] ?? '';
>>>>>>> ebf9f8370c8ff83319b8d41fe172d700784cdbe9
$filter_client = $_GET['mapping_id'] ?? '';
$filter_shift = $_GET['shift'] ?? '';

// Fetch checkpoints for filtering (Scope to site if selected)
$cp_scope_sql = !empty($filter_client) ? "agency_client_id = " . (int)$filter_client : "agency_client_id IN ($mapping_ids_str)";
$cp_filter_sql = "SELECT id, name FROM checkpoints WHERE $cp_scope_sql ORDER BY name ASC";
$checkpoints_res = $conn->query($cp_filter_sql);

// Build dynamic WHERE clause
$where_clauses = ["c.agency_client_id IN ($mapping_ids_str)"];

if (!empty($filter_date)) {
<<<<<<< HEAD
    $date = $conn->real_escape_string($filter_date);
    $where_clauses[] = "DATE(s.scan_time) = '$date'";
=======
    $target_date = $conn->real_escape_string($filter_date);
    $next_date = date('Y-m-d', strtotime($target_date . ' +1 day'));
    // Capture the 24-hour shift window: 6 AM today to 6 AM tomorrow
    $where_clauses[] = "s.scan_time >= '$target_date 06:00:00'";
    $where_clauses[] = "s.scan_time < '$next_date 06:00:00'";
}
if (!empty($filter_guard)) {
    $g_id = (int)$filter_guard;
    $where_clauses[] = "s.guard_id = $g_id";
>>>>>>> ebf9f8370c8ff83319b8d41fe172d700784cdbe9
}
if (!empty($filter_client)) {
    $m_id = (int)$filter_client;
    $where_clauses[] = "c.agency_client_id = $m_id";
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
        ac.id as mapping_id,
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

$history_res = $conn->query($history_sql);

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
                
<<<<<<< HEAD
                // Use scan_time for robust date display (tour_session_id may be a UUID)
                $ts = strtotime($row['scan_time']);
                $display_time = ($ts && $ts > 0) ? date('M d, Y h:i A', $ts) : date('M d, Y h:i A');
=======
                // Use scan_time for the header display
                $ts = strtotime($row['scan_time']);
                $display_time = ($ts && $ts > 0) ? date('M d, Y h:i A', $ts) : date('M d, Y h:i A'); // Fallback only if database time is invalid
>>>>>>> ebf9f8370c8ff83319b8d41fe172d700784cdbe9
                
                $display_shift = htmlspecialchars($row['shift'] ?? '');
                $header_info = 'TOUR CYCLE: ' . $display_time;
                
                if (!empty($display_shift) && strtolower($display_shift) !== 'no shift') {
                    $header_info .= ' | ' . $display_shift . ' Shift';
                }
                
                if (!empty($filter_guard)) {
                    $header_info .= ' | Guard: ' . htmlspecialchars($row['guard_name']);
                }
                
                echo '<tr style="background-color: #f1f5f9;"><td colspan="6" style="font-weight: bold; text-align: left; padding: 10px; border: 1px solid #cbd5e1;">' . $header_info . '</td></tr>';
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
    <script src="js/patrol_map_viewer.js?v=1.3"></script>
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

<<<<<<< HEAD
        .btn-visual {
            background-color: #6366f1;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-visual:hover { background-color: #4f46e5; }

        /* Visual Map Styles */
        .modal-content.large { max-width: 900px; width: 95%; }
        .visual-container {
            width: 100%;
            height: 400px;
            background-color: #f8fafc;
            background-image: 
                linear-gradient(rgba(203, 213, 225, 0.2) 1.5px, transparent 1.5px),
                linear-gradient(90deg, rgba(203, 213, 225, 0.2) 1.5px, transparent 1.5px);
            background-size: 30px 30px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            margin-top: 15px;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
        }
        .checkpoint-circle {
            width: 44px;
            height: 44px;
            border-radius: 50% 50% 50% 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.1rem;
            position: absolute;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            user-select: none;
            border: 3px solid #ffffff;
            transform: rotate(-45deg);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .checkpoint-circle > span {
            transform: rotate(45deg);
            display: block;
        }
        .checkpoint-circle.start { background: linear-gradient(135deg, #10b981, #111827); color: white; z-index: 10; }
        .checkpoint-circle.end { background: linear-gradient(135deg, #ea580c, #111827); color: white; z-index: 10; }
        .checkpoint-circle.regular { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #ffffff; }
        .checkpoint-circle .label {
            position: absolute;
            bottom: -45px;
            white-space: nowrap;
            font-size: 0.85rem;
            color: #111827;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.95);
            padding: 2px 10px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transform: rotate(45deg);
            transform-origin: center;
            border: 1px solid #e2e8f0;
        }
        .cp-status-on-time { background: linear-gradient(135deg, #10b981, #059669) !important; color: white !important; }
        .cp-status-late { background: linear-gradient(135deg, #ef4444, #dc2626) !important; color: white !important; }
        .cp-status-none { background: linear-gradient(135deg, #94a3b8, #64748b) !important; color: white !important; }

        .visual-svg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
        .visual-path { stroke: #6366f1; stroke-width: 3; stroke-linecap: round; fill: none; stroke-dasharray: 8 4; filter: drop-shadow(0 0 2px rgba(99, 102, 241, 0.5)); }
        .static-arrow { fill: #6366f1; display: none; }
        .download-mode .static-arrow { display: block !important; }
        .path-status-on-time { stroke: #10b981 !important; animation: pulse-line-on-time 2s infinite; }
        .path-status-late { stroke: #ef4444 !important; animation: pulse-line-late 2s infinite; }
        @keyframes pulse-line-on-time {
            0% { filter: drop-shadow(0 0 2px rgba(16, 185, 129, 0.4)); opacity: 0.7; }
            50% { filter: drop-shadow(0 0 8px rgba(16, 185, 129, 1)); opacity: 1; }
            100% { filter: drop-shadow(0 0 2px rgba(16, 185, 129, 0.4)); opacity: 0.7; }
        }
        @keyframes pulse-line-late {
            0% { filter: drop-shadow(0 0 2px rgba(239, 68, 68, 0.4)); opacity: 0.7; }
            50% { filter: drop-shadow(0 0 8px rgba(239, 68, 68, 1)); opacity: 1; }
            100% { filter: drop-shadow(0 0 2px rgba(239, 68, 68, 0.4)); opacity: 0.7; }
=======
        /* Grouped History Styles */
        .history-card { border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 24px; overflow: hidden; background: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .shift-header { background: #1e293b; color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.2s; }
        .shift-header:hover { background: #334155; }
        .shift-info { display: flex; align-items: center; gap: 16px; }
        .shift-badge { background: #10b981; color: white; padding: 4px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .shift-badge.night { background: #6366f1; }
        
        .tour-group { border-top: 1px solid #f1f5f9; padding: 0 24px; }
        .tour-header { padding: 16px 0; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-bottom: 1px dashed #e2e8f0; }
        .tour-header:hover { background: #f8fafc; }
        .tour-title { font-weight: 600; color: #334155; display: flex; align-items: center; gap: 8px; }
        
        .tour-content { padding: 16px 0; overflow-x: auto; display: none; }
        .tour-content.active { display: block; }
        
        .chevron { transition: transform 0.3s; }
        .open .chevron { transform: rotate(180deg); }
        
        .empty-history { text-align: center; padding: 60px; color: #64748b; font-style: italic; }
        .btn-visual-3d {
            background: #10b981;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            margin-right: 12px;
        }
        .btn-visual-3d:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        .visual-3d-container {
            width: 100%;
            height: 500px;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .modal-content.large {
            max-width: 900px;
            width: 95%;
>>>>>>> ebf9f8370c8ff83319b8d41fe172d700784cdbe9
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
            <li><a href="agency_inspector_history.php" class="nav-link">Inspector Visits</a></li>
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
<<<<<<< HEAD
                        <label class="form-label" for="filter_date">Patrol Date</label>
                        <input type="date" id="filter_date" name="filter_date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
=======
                        <label class="form-label" for="date">Select Date</label>
                        <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
>>>>>>> ebf9f8370c8ff83319b8d41fe172d700784cdbe9
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
                        <label class="form-label" for="shift">Shift</label>
                        <select id="shift" name="shift" class="form-control" onchange="this.form.submit()">
                            <option value="">-- All Shifts --</option>
                            <option value="Day Shift" <?php if($filter_shift == 'Day Shift') echo 'selected'; ?>>Day Shift</option>
                            <option value="Night Shift" <?php if($filter_shift == 'Night Shift') echo 'selected'; ?>>Night Shift</option>
                        </select>
                    </div>
                </form>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 20px;">
                    <button type="button" class="btn-primary" style="background: #0ea5e9; <?php if (!$history_res || $history_res->num_rows == 0) echo 'opacity: 0.5; cursor: not-allowed;'; ?>" onclick="downloadHistoryCSV()" <?php if (!$history_res || $history_res->num_rows == 0) echo 'disabled title="No data to download"'; ?>>Download Report</button>
                    <button type="button" class="btn-visual" 
                       style="<?php if (empty($filter_client)) echo 'opacity: 0.5; cursor: not-allowed;'; ?>"
                       onclick="openVisualDesigner()"
                       <?php if (empty($filter_client)) echo 'disabled title="Please select a Client Site first"'; ?>>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line></svg>
                        Visual
                    </button>
                </div>

                <div class="table-container" style="border: none;">
                    <?php 
                    if (empty($filter_client)): ?>
                        <div class="empty-history">Please select a Client Site to view patrol history.</div>
                    <?php elseif ($history_res && $history_res->num_rows > 0): 
                        // Process data into groups
                        $grouped = [];
                        while($row = $history_res->fetch_assoc()) {
                            $ts = strtotime($row['scan_time']);
                            $s_date = date('Y-m-d', $ts);
                            $hour = (int)date('H', $ts);
                            $s_type = $row['shift'] ?? 'Day Shift';
                            
                            // Adjust date for Night Shift rollover (00:00 - 05:59)
                            if ($s_type === 'Night Shift' && $hour < 6) {
                                $s_date = date('Y-m-d', strtotime($s_date . ' -1 day'));
                            }
                            
                            $shift_key = $s_date . '_' . $s_type;
                            $tour_id = ($row['tour_session_id'] ?? '') ?: 'adhoc_' . $s_date . '_' . $s_type;
                            
                            if (!isset($grouped[$shift_key])) {
                                $grouped[$shift_key] = [
                                    'date' => $s_date,
                                    'shift' => $s_type,
                                    'tours' => []
                                ];
                            }
                            if (!isset($grouped[$shift_key]['tours'][$tour_id])) {
                                $grouped[$shift_key]['tours'][$tour_id] = [
                                    'start_time' => $row['scan_time'],
                                    'mapping_id' => $row['mapping_id'],
                                    'scans' => []
                                ];
                            }
                            $grouped[$shift_key]['tours'][$tour_id]['scans'][] = $row;
                        }

<<<<<<< HEAD
                                    if ($current_session !== $session_id):
                                        $current_session = $session_id;
                                        
                                        // Robust date parsing: use scan_time as fallback for display (tour_session_id may be a UUID)
                                        $cycle_ts = strtotime($row['scan_time']);
                                        $cycle_time = ($cycle_ts && $cycle_ts > 0) ? date('M d, Y h:i A', $cycle_ts) : date('M d, Y h:i A');

                                        $display_shift = $row['shift'] ?? '';
                                        $has_shift = !empty($display_shift) && strtolower($display_shift) !== 'no shift';
                                        $has_guard = !empty($filter_guard);
                                ?>
                                    <tr style="background-color: #f8fafc; border-top: 2px solid #e2e8f0; border-bottom: 2px solid #e2e8f0;">
                                        <td colspan="7" style="font-weight: 700; color: #334155; padding: 14px 16px; font-size: 0.9rem; background: linear-gradient(to right, #f8fafc, #ffffff);">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="background: #e2e8f0; padding: 6px; border-radius: 8px; color: #64748b;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path></svg>
                                                </div>
                                                <span style="text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-size: 0.75rem;">Tour Cycle:</span>
                                                <span style="color: #1e293b;"><?php echo $cycle_time; ?></span>
                                                
                                                <?php if ($has_shift): ?>
                                                    <span style="color: #cbd5e1;">&bull;</span>
                                                    <span style="color: #64748b;"><?php echo htmlspecialchars($display_shift); ?> Shift</span>
                                                <?php endif; ?>

                                                <?php if ($has_guard): ?>
                                                    <span style="color: #cbd5e1;">&bull;</span>
                                                    <span style="color: #64748b;">Guard: <strong style='color: #1e293b;'><?php echo htmlspecialchars($row['guard_name']); ?></strong></span>
                                                <?php endif; ?>
=======
                        foreach($grouped as $sk => $s_data):
                            $is_night = ($s_data['shift'] === 'Night Shift');
                    ?>
                        <div class="history-card">
                            <div class="shift-header" onclick="toggleShift(this)">
                                <div class="shift-info">
                                    <div style="font-weight: 700; font-size: 1.1rem;"><?php echo date('F d, Y', strtotime($s_data['date'])); ?></div>
                                    <div class="shift-badge <?php echo $is_night ? 'night' : ''; ?>">
                                        <?php echo $is_night ? '🌙' : '☀️'; ?> <?php echo $s_data['shift']; ?>
                                    </div>
                                </div>
                                <svg class="chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            
                            <div class="shift-body" style="display: block;">
                                <?php foreach($s_data['tours'] as $t_id => $t_data): 
                                    $tour_label = strpos($t_id, 'adhoc') !== false ? "Ad-hoc Scan Session" : "Patrol Tour Cycle";
                                    $t_start = date('h:i A', strtotime($t_data['start_time']));
                                ?>
                                    <div class="tour-group">
                                        <div class="tour-header" onclick="toggleTour(this)">
                                            <div class="tour-title">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                <?php echo $tour_label; ?> <span style="color: #94a3b8; font-weight: normal; margin-left: 8px;">started at <?php echo $t_start; ?></span>
>>>>>>> ebf9f8370c8ff83319b8d41fe172d700784cdbe9
                                            </div>
                                            <div style="display: flex; align-items: center;">
                                                <button class="btn-visual-3d" onclick="open3DVisual('<?php echo $t_id; ?>', '<?php echo $t_data['mapping_id']; ?>', event)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 19 21 12 17 5 21 12 2"></polygon></svg>
                                                    Visual
                                                </button>
                                                <svg class="chevron" style="width: 16px; height: 16px;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                            </div>
                                        </div>
                                        
                                        <div class="tour-content active">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Time</th>
                                                        <th>Guard</th>
                                                        <th>Checkpoint</th>
                                                        <th>Status</th>
                                                        <th>Remarks</th>
                                                        <th>Evidence</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($t_data['scans'] as $row): ?>
                                                        <tr>
                                                            <td style="width: 120px;"><strong><?php echo date('h:i:s A', strtotime($row['scan_time'])); ?></strong></td>
                                                            <td style="font-weight: 500; font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                                            <td style="font-weight: 500;"><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                                            <td>
                                                                <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                                                    <?php echo htmlspecialchars($row['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td style="font-size: 0.85rem; color: #4b5563; font-style: italic;">
                                                                <?php echo !empty($row['justification']) ? htmlspecialchars($row['justification']) : '---'; ?>
                                                            </td>
                                                            <td>
                                                                <div style="display: flex; gap: 4px;">
                                                                    <?php if (!empty($row['photo_path'])): ?>
                                                                        <button class="btn-view-photo" onclick="viewPhoto('<?php echo htmlspecialchars($row['photo_path']); ?>', 'Patrol Selfie', '<?php echo addslashes(htmlspecialchars($row['justification'] ?? '')); ?>')">Selfie</button>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($row['justification_photo_path'])): ?>
                                                                        <button class="btn-view-photo justification" onclick="viewPhoto('<?php echo htmlspecialchars($row['justification_photo_path']); ?>', 'Justification Photo', '<?php echo addslashes(htmlspecialchars($row['justification'] ?? '')); ?>')">Photo</button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-history">No patrol activity found for the selected criteria.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <div class="modal-overlay" id="visual3DModal">
        <div class="modal-content large">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 class="modal-title">Patrol Tour Visualization</h3>
            <div id="visual3DContainer" class="visual-3d-container">
                <!-- Map Canvas will be injected here -->
            </div>
            <!-- Legend Bar -->
            <div style="display: flex; gap: 18px; align-items: center; justify-content: center; padding: 10px 12px 4px; flex-wrap: wrap;">
                <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:#374151;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#10b981;display:inline-block;"></span>Scanned
                </span>
                <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:#374151;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#ef4444;display:inline-block;"></span>Missed
                </span>
                <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:#374151;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#cbd5e1;border:2px solid #94a3b8;display:inline-block;"></span>Pending
                </span>
            </div>
            <div class="modal-actions" style="margin-top: 8px;">
                <button class="btn-modal btn-cancel" onclick="closeAllModals()" style="width: 100%;">Close Viewer</button>
            </div>
        </div>
    </div>

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

    <!-- Visual Map Modal -->
    <div id="visualDesignerModal" class="modal-overlay">
        <div class="modal-content large">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; padding-bottom: 12px;">
                <h3 class="modal-title" style="margin-bottom: 0;">Visual Patrol Map</h3>
                <button type="button" class="modal-close" style="position: static;" onclick="closeAllModals()">&times;</button>
            </div>
            <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px; text-align: left;">
                Draggable overview of checkpoints. <strong>Green (S)</strong> is Start, <strong>Orange (E)</strong> is End, and <strong>White</strong> are checkpoints.
            </p>
            <div id="visual-canvas" class="visual-container">
                <svg id="visual-svg" class="visual-svg">
                    <defs>
                        <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                            <polygon points="0 0, 10 3.5, 0 7" fill="#6366f1" />
                        </marker>
                    </defs>
                </svg>
            </div>
            <div class="modal-actions" style="margin-top: 20px;">
                <button type="button" class="btn-modal" style="background: #111827; color: white;" onclick="downloadVisualMap()">Download Map (PDF)</button>
                <button type="button" class="btn-modal btn-cancel" onclick="closeAllModals()">Close View</button>
            </div>
        </div>
    </div>

    <!-- General Alert Modal -->
    <div id="alertModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-icon" style="background: #ecfdf5; color: #10b981;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 16 16 12 12 8"></polyline><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            </div>
            <h3>Notice</h3>
            <p id="alertModalText" style="color: #6b7280; margin-bottom: 24px; margin-top: 10px;"></p>
            <button class="btn-modal btn-cancel" style="width: 100%;" onclick="closeAllModals()">OK</button>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        const selectedMappingId = '<?php echo $filter_client; ?>';
        let visualCheckpoints = [];

        function downloadHistoryCSV() {
            const url = new URL(window.location.href);
            url.searchParams.set('download_csv', '1');
            window.location.href = url.toString();
        }

        function toggleShift(header) {
            header.parentElement.classList.toggle('open');
            const body = header.nextElementSibling;
            body.style.display = body.style.display === 'none' ? 'block' : 'none';
        }

        function toggleTour(header) {
            header.parentElement.classList.toggle('open');
            const content = header.nextElementSibling;
            content.classList.toggle('active');
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

        let visualizer = null;

        function open3DVisual(tourId, mappingId, event) {
            if (event) event.stopPropagation();
            
            document.getElementById('visual3DModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            if (!visualizer) {
                visualizer = new PatrolMapViewer('visual3DContainer');
            }
            
            visualizer.renderTour(tourId, mappingId);
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

        /********** Visual Designer Map Logic **********/
        function openVisualDesigner() {
            if (!selectedMappingId) {
                showAlert("Please select a Client Site first to view the visual map.");
                return;
            }

            const modal = document.getElementById('visualDesignerModal');
            const canvas = document.getElementById('visual-canvas');
            const filterDate = document.getElementById('filter_date').value;
            
            modal.classList.add('show');
            canvas.querySelectorAll('.checkpoint-circle').forEach(c => c.remove());
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'visual-loading';
            loadingDiv.style.cssText = 'display:flex; align-items:center; justify-content:center; height:100%; color:#64748b;';
            loadingDiv.textContent = 'Loading checkpoints...';
            canvas.appendChild(loadingDiv);

            fetch(`agency_patrol_management.php?ajax_checkpoints=1&mapping_id=${selectedMappingId}&date=${filterDate}`)
                .then(response => response.json())
                .then(data => {
                    const loader = document.getElementById('visual-loading');
                    if (loader) loader.remove();
                    
                    visualCheckpoints = data.checkpoints || [];

                    if (visualCheckpoints.length === 0) {
                        const emptyDiv = document.createElement('div');
                        emptyDiv.id = 'visual-empty';
                        emptyDiv.style.cssText = 'display:flex; align-items:center; justify-content:center; height:100%; color:#64748b;';
                        emptyDiv.textContent = 'No checkpoints configured for this site.';
                        canvas.appendChild(emptyDiv);
                        return;
                    }

                    visualCheckpoints.forEach((cp, index) => {
                        const circle = document.createElement('div');
                        circle.id = `cp-circle-${cp.id}`;
                        
                        let statusClass = 'cp-status-none';
                        const status = (cp.latest_status || '').toLowerCase();
                        if (status === 'on-time' || status === 'on time') statusClass = 'cp-status-on-time';
                        else if (status === 'late') statusClass = 'cp-status-late';

                        circle.className = `checkpoint-circle ${cp.isStart ? 'start' : (cp.isEnd ? 'end' : 'regular')} ${statusClass}`;
                        circle.style.zIndex = (cp.isStart || cp.isEnd) ? 10 : 5;
                        const nameTrim = (cp.name || '').trim();
                        const isNumeric = /^\d+$/.test(nameTrim);
                        let innerText = isNumeric ? nameTrim : (nameTrim ? nameTrim.charAt(0).toUpperCase() : '');
                        
                        circle.innerHTML = `
                            <span>${innerText}</span>
                            ${isNumeric ? '' : `<div class="label">${cp.name}</div>`}
                        `;
                        
                        let x = parseInt(cp.visual_pos_x) || 0;
                        let y = parseInt(cp.visual_pos_y) || 0;
                        
                        if (x === 0 && y === 0) {
                            const containerWidth = canvas.offsetWidth || 750;
                            const spacingX = 120;
                            const spacingY = 120;
                            const itemsPerRow = Math.floor((containerWidth - 60) / spacingX) || 5;
                            x = 40 + (index % itemsPerRow) * spacingX;
                            y = 40 + Math.floor(index / itemsPerRow) * spacingY;
                        }
                        
                        circle.style.left = x + 'px';
                        circle.style.top = y + 'px';
                        canvas.appendChild(circle);
                    });

                    drawArrows();
                })
                .catch(err => {
                    const loader = document.getElementById('visual-loading');
                    if (loader) loader.remove();
                    console.error(err);
                });
        }

        function drawArrows() {
            const svg = document.getElementById('visual-svg');
            const canvas = document.getElementById('visual-canvas');
            svg.querySelectorAll('.visual-path, .flowing-arrow, .static-arrow').forEach(el => el.remove());

            if (visualCheckpoints.length < 2) return;

            for (let i = 0; i < visualCheckpoints.length - 1; i++) {
                const startCp = visualCheckpoints[i];
                const endCp = visualCheckpoints[i+1];
                const startEl = document.getElementById(`cp-circle-${startCp.id}`);
                const endEl = document.getElementById(`cp-circle-${endCp.id}`);
                
                if (!startEl || !endEl) continue;

                const r = 22;
                const x1 = parseInt(startEl.style.left) + r;
                const y1 = parseInt(startEl.style.top) + r;
                const x2 = parseInt(endEl.style.left) + r;
                const y2 = parseInt(endEl.style.top) + r;

                const angle = Math.atan2(y2 - y1, x2 - x1);
                const edgeX1 = x1 + r * Math.cos(angle);
                const edgeY1 = y1 + r * Math.sin(angle);
                const edgeX2 = x2 - (r + 5) * Math.cos(angle);
                const edgeY2 = y2 - (r + 5) * Math.sin(angle);

                const d = `M ${edgeX1} ${edgeY1} L ${edgeX2} ${edgeY2}`;
                const status = (endCp.latest_status || '').toLowerCase();
                let pathStatusClass = '';
                if (status === 'on-time' || status === 'on time') pathStatusClass = ' path-status-on-time';
                else if (status === 'late') pathStatusClass = ' path-status-late';

                const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
                path.setAttribute("d", d);
                path.setAttribute("class", "visual-path" + pathStatusClass);
                svg.appendChild(path);

                const midX = (edgeX1 + edgeX2) / 2;
                const midY = (edgeY1 + edgeY2) / 2;
                const deg = angle * (180 / Math.PI);
                const sArrow = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
                sArrow.setAttribute("points", "-6,-6 6,0 -6,6");
                sArrow.setAttribute("class", "static-arrow");
                sArrow.setAttribute("transform", `translate(${midX}, ${midY}) rotate(${deg})`);
                svg.appendChild(sArrow);
            }
        }

        function downloadVisualMap() {
            const canvas = document.getElementById('visual-canvas');
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = 'Generating...';
            btn.disabled = true;

            canvas.classList.add('download-mode');
            html2canvas(canvas, { backgroundColor: '#ffffff', scale: 2, useCORS: true }).then(imageCanvas => {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('l', 'mm', 'a4');
                const imgData = imageCanvas.toDataURL('image/png');
                const pdfWidth = pdf.internal.pageSize.getWidth();
                pdf.text(`Patrol Map Overview`, 15, 15);
                pdf.addImage(imgData, 'PNG', 15, 25, pdfWidth - 30, 0);
                pdf.save(`Patrol_Map_${new Date().getTime()}.pdf`);
                canvas.classList.remove('download-mode');
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }

        function showAlert(message) {
            document.getElementById('alertModalText').textContent = message;
            document.getElementById('alertModal').classList.add('show');
        }
    </script>
    <!-- VERSION: 2.1 -->
</body>
</html>
