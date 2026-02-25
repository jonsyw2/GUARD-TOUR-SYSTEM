<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client to filter scans/incidents
$maps_sql = "SELECT id FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = $r['id'];
    }
}

// Default metrics if no data
$total_patrols_today = 0;
$completed_checkpoints = 0;
$missed_checkpoints = 0;
$guards_on_duty = 0;
$active_alerts = 0;

$chart_labels = [];
$chart_on_time = [];
$chart_late = [];
$chart_missed = [];

if (!empty($mapping_ids)) {
    $ids_str = implode(',', $mapping_ids);
    
    // Total Patrols Today (Scans today for checkpoints assigned to this client)
    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    
    $patrols_sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN s.status IN ('on-time','late') THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN s.status = 'missed' THEN 1 ELSE 0 END) as missed
        FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($ids_str) AND s.scan_time BETWEEN '$today_start' AND '$today_end'
    ";
    if ($p_res = $conn->query($patrols_sql)) {
        $p_row = $p_res->fetch_assoc();
        $total_patrols_today = $p_row['total'];
        $completed_checkpoints = $p_row['completed'] ?? 0;
        $missed_checkpoints = $p_row['missed'] ?? 0;
    }

    // Guards on Duty (Unique guards who scanned today)
    $guards_sql = "
        SELECT COUNT(DISTINCT s.guard_id) as count
        FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($ids_str) AND s.scan_time BETWEEN '$today_start' AND '$today_end'
    ";
    if ($g_res = $conn->query($guards_sql)) {
        $guards_on_duty = $g_res->fetch_assoc()['count'];
    }

    // Active Alerts
    $alerts_sql = "SELECT COUNT(*) as count FROM incidents WHERE agency_client_id IN ($ids_str) AND status = 'Active'";
    if ($a_res = $conn->query($alerts_sql)) {
        $active_alerts = $a_res->fetch_assoc()['count'];
    }

    // Chart Data (Last 7 days)
    $chart_sql = "
        SELECT 
            DATE(s.scan_time) as scan_date,
            SUM(CASE WHEN s.status = 'on-time' THEN 1 ELSE 0 END) as on_time,
            SUM(CASE WHEN s.status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN s.status = 'missed' THEN 1 ELSE 0 END) as missed
        FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($ids_str) 
          AND s.scan_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(s.scan_time)
        ORDER BY scan_date ASC
    ";
    if ($c_res = $conn->query($chart_sql)) {
        while($row = $c_res->fetch_assoc()) {
            $chart_labels[] = date('M d', strtotime($row['scan_date']));
            $chart_on_time[] = $row['on_time'];
            $chart_late[] = $row['late'];
            $chart_missed[] = $row['missed'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Dashboard</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .logout { color: #ef4444; text-decoration: none; font-weight: bold; }
        .badge { background: #3b82f6; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>Client Dashboard</h1>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>
</body>
</html>
