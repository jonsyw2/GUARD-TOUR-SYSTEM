<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];
$client_name = $_SESSION['username']; // fallback

// Get mapping IDs
$maps_sql = "SELECT id FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) $mapping_ids[] = (int)$r['id'];
}
$mapping_ids_str = empty($mapping_ids) ? "0" : implode(',', $mapping_ids);

// Handle POST request for Report Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    
    $period = $_POST['period'] ?? 'daily';
    $format = $_POST['format'] ?? 'csv';
    
    // Calculate Date Range
    $end_date = date('Y-m-d 23:59:59');
    if ($period === 'daily') {
        $start_date = date('Y-m-d 00:00:00');
        $title_period = "Daily";
    } elseif ($period === 'weekly') {
        $start_date = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $title_period = "Weekly";
    } else {
        $start_date = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $title_period = "Monthly";
    }

    // Fetch Data
    $report_sql = "
        SELECT 
            s.scan_time,
            c.name as checkpoint_name,
            g.name as guard_name,
            s.status
        FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        JOIN guards g ON s.guard_id = g.id
        WHERE c.agency_client_id IN ($mapping_ids_str)
          AND s.scan_time BETWEEN '$start_date' AND '$end_date'
        ORDER BY s.scan_time ASC
    ";
    $report_res = $conn->query($report_sql);

    $records = [];
    if ($report_res && $report_res->num_rows > 0) {
        while($row = $report_res->fetch_assoc()) {
            $records[] = $row;
        }
    }

    $filename = "{$title_period}_Patrol_Report_" . date('Ymd_His');

    // Handle CSV Export
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.csv');
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, ['Date/Time', 'Location', 'Guard Name', 'Status']);
        
        // Data
        foreach ($records as $row) {
            fputcsv($output, [
                date('Y-m-d H:i:s', strtotime($row['scan_time'])),
                $row['checkpoint_name'],
                $row['guard_name'],
                strtoupper($row['status'])
            ]);
        }
        fclose($output);
        exit();
    }
    
    // Handle PDF (Printable HTML) Export
    if ($format === 'pdf') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title><?php echo $filename; ?></title>
            <style>
                body { font-family: Arial, sans-serif; color: #333; margin: 40px; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 10px; }
                .header h1 { margin: 0; font-size: 24px; }
                .header p { margin: 5px 0 0; color: #666; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                th { background-color: #f5f5f5; font-weight: bold; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px; padding: 10px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 4px;">
                <strong>Printing Instructions:</strong> The print dialog should open automatically. Under Printer/Destination, select <strong>"Save as PDF"</strong> to save to your device.
                <button onclick="window.print()" style="margin-left: 10px; padding: 5px 10px; cursor:pointer;">Print Again</button>
                <button onclick="window.close()" style="margin-left: 10px; padding: 5px 10px; cursor:pointer;">Close Tab</button>
            </div>
            
            <div class="header">
                <h1><?php echo $title_period; ?> Patrol Compliance Report</h1>
                <p>Client ID: <?php echo $client_id; ?> | Generated on <?php echo date('M d, Y h:i A'); ?></p>
                <p>Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Checkpoint Location</th>
                        <th>Guard Name</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($records)): ?>
                        <tr><td colspan="4" style="text-align:center;">No patrol activity recorded during this period.</td></tr>
                    <?php else: ?>
                        <?php foreach($records as $row): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($row['scan_time'])); ?></td>
                                <td><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                <td><?php echo strtoupper(htmlspecialchars($row['status'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <script>
                // Auto-trigger print dialog for 'Save as PDF'
                window.onload = function() {
                    window.print();
                };
            </script>
        </body>
        </html>
        <?php
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Compliance - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f8fafc; color: #1e293b; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #0f172a; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0;}
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

        .content-area { padding: 32px; max-width: 800px; margin: 0 auto; width: 100%; }

        /* Report Form Card */
        .card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card-header { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .card-desc { color: #64748b; font-size: 0.95rem; margin-bottom: 24px; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 0.9rem; font-weight: 600; color: #334155; margin-bottom: 8px; }
        
        .radio-group { display: flex; flex-direction: column; gap: 12px; }
        .radio-label { display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; transition: all 0.2s; }
        .radio-label:hover { border-color: #cbd5e1; background: #f1f5f9; }
        .radio-input { width: 18px; height: 18px; accent-color: #3b82f6; }
        .radio-text { font-weight: 500; color: #1e293b; }
        .radio-sub { font-size: 0.8rem; color: #64748b; display: block; margin-top: 2px; }

        /* Custom selected state styles (using a bit of JS to toggle visually if needed, or pure CSS focus/checked tricks) */
        input[type="radio"]:checked + .radio-content { font-weight: 700; }

        .submit-btn { width: 100%; padding: 14px; background-color: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; margin-top: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .submit-btn:hover { background-color: #2563eb; }
        
        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); text-align: center; }
        .modal-actions { display: flex; gap: 12px; margin-top: 24px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; }
        .btn-cancel { background: #f1f5f9; color: #334155; }
        .btn-confirm { background: #ef4444; color: white; text-decoration: none; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_qrs.php" class="nav-link">Checkpoints</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incidents & Alerts</a></li>
            <li><a href="client_reports.php" class="nav-link active">Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Generate Reports</h2>
        </header>

        <div class="content-area">
            <div class="card">
                <h3 class="card-header">Activity & Compliance Export</h3>
                <p class="card-desc">Download a comprehensive log of all guard activities for an official record. Select your preferred timeframe and export format.</p>
                
                <form action="client_reports.php" method="POST" target="_blank">
                    
                    <div class="form-group">
                        <span class="form-label">Report Period</span>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio" name="period" value="daily" class="radio-input" checked>
                                <span class="radio-content">
                                    <span class="radio-text">Daily Summary</span>
                                    <span class="radio-sub">Activity from the last 24 hours</span>
                                </span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="period" value="weekly" class="radio-input">
                                <span class="radio-content">
                                    <span class="radio-text">Weekly Summary</span>
                                    <span class="radio-sub">Activity from the last 7 days</span>
                                </span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="period" value="monthly" class="radio-input">
                                <span class="radio-content">
                                    <span class="radio-text">Monthly Compliance</span>
                                    <span class="radio-sub">Complete audit from the last 30 days</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 32px;">
                        <span class="form-label">Export Format</span>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio" name="format" value="pdf" class="radio-input" checked>
                                <span class="radio-content">
                                    <span class="radio-text">PDF Document (Printable)</span>
                                    <span class="radio-sub">A clean, formatted page ready for printing or saving as PDF.</span>
                                </span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="format" value="csv" class="radio-input">
                                <span class="radio-content">
                                    <span class="radio-text">CSV Spreadsheet</span>
                                    <span class="radio-sub">Raw data format, easily importable into Excel or Google Sheets.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="generate_report" value="1" class="submit-btn" onclick="setTimeout(() => { document.getElementById('successMsg').style.display='block'; }, 1500);">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Download Report
                    </button>
                    <p id="successMsg" style="display:none; color:#059669; font-weight:600; text-align:center; margin-top:16px;">Report generated successfully in a new tab!</p>
                </form>
            </div>
        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <h3 class="modal-title">Ready to Leave?</h3>
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
