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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard - Overview</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-icon { width: 48px; height: 48px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-text { color: #64748b; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f1f5f9; color: #334155; }
        .btn-cancel:hover { background: #e2e8f0; }
        .btn-confirm { background: #ef4444; color: white; text-decoration: none; }
        .btn-confirm:hover { background: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.5rem; font-weight: 600; color: #0f172a; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #e0e7ff; color: #4338ca; padding: 6px 14px; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        /* Metric Cards */
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .metric-card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .metric-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
        .metric-card.today::before { background-color: #3b82f6; } /* Blue */
        .metric-card.completed::before { background-color: #10b981; } /* Green */
        .metric-card.guards::before { background-color: #f59e0b; } /* Yellow */
        .metric-card.alerts::before { background-color: #ef4444; } /* Red */
        
        .metric-title { font-size: 0.875rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .metric-value { font-size: 2.25rem; font-weight: 700; color: #0f172a; display: flex; align-items: baseline; gap: 8px; }
        .metric-sub { font-size: 0.875rem; color: #64748b; font-weight: 500; }
        
        /* Status Badges */
        .status-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; }
        .dot-green { background-color: #10b981; }
        .dot-yellow { background-color: #f59e0b; }
        .dot-red { background-color: #ef4444; }

        /* Chart Section */
        .chart-container { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .chart-header { margin-bottom: 20px; font-size: 1.125rem; font-weight: 600; color: #0f172a; }

    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Client Portal
        </div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link active">Dashboard</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
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
        <!-- Topbar -->
        <header class="topbar">
            <h2>Overview</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">client</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            
            <div class="metrics-grid">
                <div class="metric-card today">
                    <div class="metric-title">Total Patrols Today</div>
                    <div class="metric-value"><?php echo $total_patrols_today; ?></div>
                </div>
                
                <div class="metric-card completed">
                    <div class="metric-title">Checkpoints Scanned</div>
                    <div class="metric-value">
                        <?php echo $completed_checkpoints; ?> 
                        <span class="metric-sub">/ <?php echo $missed_checkpoints; ?> missed</span>
                    </div>
                </div>

                <div class="metric-card guards">
                    <div class="metric-title">Guards On Duty</div>
                    <div class="metric-value">
                        <span class="status-dot dot-green"></span>
                        <?php echo $guards_on_duty; ?>
                    </div>
                </div>

                <div class="metric-card alerts">
                    <div class="metric-title">Active Alerts</div>
                    <div class="metric-value">
                        <?php if($active_alerts > 0): ?>
                            <span class="status-dot dot-red"></span>
                            <span style="color: #ef4444;"><?php echo $active_alerts; ?></span>
                        <?php else: ?>
                            <span class="status-dot dot-green"></span>
                            0
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="chart-container">
                <h3 class="chart-header">7-Day Patrol Activity</h3>
                <canvas id="patrolChart" height="80"></canvas>
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

    <!-- Chart.js Setup -->
    <script>
        const ctx = document.getElementById('patrolChart').getContext('2d');
        const patrolChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'On-Time',
                        data: <?php echo json_encode($chart_on_time); ?>,
                        backgroundColor: '#10b981', // green
                        borderRadius: 4
                    },
                    {
                        label: 'Late',
                        data: <?php echo json_encode($chart_late); ?>,
                        backgroundColor: '#f59e0b', // yellow
                        borderRadius: 4
                    },
                    {
                        label: 'Missed',
                        data: <?php echo json_encode($chart_missed); ?>,
                        backgroundColor: '#ef4444', // red
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { mode: 'index', intersect: false }
                }
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>
