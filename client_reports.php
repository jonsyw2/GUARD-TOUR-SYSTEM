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

// Fetch guards for filtering
$agency_ids_res = $conn->query("SELECT DISTINCT agency_id FROM agency_clients WHERE client_id = $client_id");
$agency_ids = [];
if ($agency_ids_res) while($r = $agency_ids_res->fetch_assoc()) $agency_ids[] = (int)$r['agency_id'];
$agency_ids_str = empty($agency_ids) ? "0" : implode(',', $agency_ids);
$guards_res = $conn->query("SELECT id, name FROM guards WHERE agency_id IN ($agency_ids_str) ORDER BY name ASC");

// Handle POST request for Report Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    
    $period = $_POST['period'] ?? 'daily';
    $format = $_POST['format'] ?? 'csv';
    $filter_guard = $_POST['guard_id'] ?? '';
    $filter_shift = $_POST['shift'] ?? '';
    
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

    $where_clauses = [
        "c.agency_client_id IN ($mapping_ids_str)",
        "s.scan_time BETWEEN '$start_date' AND '$end_date'"
    ];

    if (!empty($filter_guard)) {
        $g_id = (int)$filter_guard;
        $where_clauses[] = "s.guard_id = $g_id";
    }
    if (!empty($filter_shift)) {
        $shift = $conn->real_escape_string($filter_shift);
        $where_clauses[] = "s.shift = '$shift'";
    }

    $where_sql = implode(" AND ", $where_clauses);

    // Fetch Data
    $report_sql = "
        SELECT 
            s.scan_time,
            c.name as checkpoint_name,
            g.name as guard_name,
            s.status,
            s.shift,
            s.justification
        FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        JOIN guards g ON s.guard_id = g.id
        WHERE $where_sql
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
        fputcsv($output, ['Date/Time', 'Shift', 'Location', 'Guard Name', 'Status', 'Justification']);
        
        // Data
        foreach ($records as $row) {
            fputcsv($output, [
                date('Y-m-d H:i:s', strtotime($row['scan_time'])),
                $row['shift'] ?? 'N/A',
                $row['checkpoint_name'],
                $row['guard_name'],
                strtoupper($row['status']),
                $row['justification'] ?? ''
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
                body { font-family: 'Inter', sans-serif; color: #1f2937; margin: 40px; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #111827; padding-bottom: 20px; }
                .header h1 { margin: 0; font-size: 24px; color: #111827; }
                .header p { margin: 10px 0 0; color: #6b7280; font-size: 14px; }
                table { width: 100%; border-collapse: collapse; margin-top: 30px; }
                th, td { border: 1px solid #e5e7eb; padding: 12px; text-align: left; font-size: 13px; }
                th { background-color: #f9fafb; font-weight: 600; color: #4b5563; text-transform: uppercase; letter-spacing: 0.05em; }
                .status-badge { text-transform: uppercase; font-weight: 700; font-size: 11px; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px; padding: 20px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; color: #1e40af; font-size: 14px;">
                <strong>Printing Instructions:</strong> The print dialog should open automatically. Under Printer/Destination, select <strong>"Save as PDF"</strong> to save to your device.
                <div style="margin-top: 10px; display: flex; gap: 10px;">
                    <button onclick="window.print()" style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor:pointer; font-weight: 600;">Print Again</button>
                    <button onclick="window.close()" style="padding: 8px 16px; background: #94a3b8; color: white; border: none; border-radius: 6px; cursor:pointer; font-weight: 600;">Close Tab</button>
                </div>
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
                        <th>Shift</th>
                        <th>Checkpoint Location</th>
                        <th>Guard Name</th>
                        <th>Status</th>
                        <th>Justification</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($records)): ?>
                        <tr><td colspan="6" style="text-align:center;">No patrol activity recorded during this period.</td></tr>
                    <?php else: ?>
                        <?php foreach($records as $row): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($row['scan_time'])); ?></td>
                                <td><?php echo htmlspecialchars($row['shift'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                <td class="status-badge"><?php echo strtoupper(htmlspecialchars($row['status'])); ?></td>
                                <td style="font-size: 11px; font-style: italic;"><?php echo htmlspecialchars($row['justification'] ?? '---'); ?></td>
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
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0; overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1000px; margin: 0 auto; width: 100%; }

        /* Report Form Card */
        .card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 32px; border: 1px solid #e5e7eb; }
        .card-header { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .card-desc { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; }

        .form-group { margin-bottom: 24px; }
        .form-label { display: block; font-size: 0.9rem; font-weight: 600; color: #374151; margin-bottom: 12px; }
        
        .radio-group { display: flex; flex-direction: column; gap: 12px; }
        .radio-label { display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 16px; border: 1px solid #e5e7eb; border-radius: 12px; background: #f9fafb; transition: all 0.2s; }
        .radio-label:hover { border-color: #3b82f6; background: #eff6ff; }
        .radio-input { width: 18px; height: 18px; accent-color: #3b82f6; }
        .radio-text { font-weight: 600; color: #111827; display: block; }
        .radio-sub { font-size: 0.8rem; color: #6b7280; display: block; margin-top: 2px; }

        .submit-btn { width: 100%; padding: 14px; background-color: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; margin-top: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .submit-btn:hover { background-color: #2563eb; }
        
        /* Log Table Styles */
        .log-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .log-table th { text-align: left; padding: 12px; border-bottom: 2px solid #f3f4f6; color: #6b7280; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .log-table td { padding: 16px 12px; border-bottom: 1px solid #f3f4f6; font-size: 0.9rem; }
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .btn-view { padding: 6px 12px; background: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: 600; transition: 0.2s; }
        .btn-view:hover { background: #dbeafe; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 600px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); position: relative; animation: modalFadeIn 0.3s ease-out; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .modal-close { position: absolute; top: 16px; right: 16px; font-size: 24px; cursor: pointer; color: #94a3b8; }
        
        .w5h-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; text-align: left; }
        .w5h-item { background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .w5h-label { font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .w5h-value { font-size: 0.9rem; color: #1e293b; line-height: 1.5; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link active">General Reports</a></li>
            <li><a href="client_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Performance & Official Reports</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['company_name'] ?? $_SESSION['username']); ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            <!-- 5W1H Report Log Section -->
            <div class="card">
                <h3 class="card-header">Official Guard Reports (5W1H)</h3>
                <p class="card-desc">Recent structured reports submitted by guards from the field.</p>
                
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reported By</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $log_sql = "
                            SELECT i.*, 
                                   CASE WHEN i.reporter_role = 'inspector' THEN ins.name ELSE g.name END as guard_name
                            FROM incidents i
                            LEFT JOIN guards g ON i.guard_id = g.id AND (i.reporter_role = 'guard' OR i.reporter_role IS NULL)
                            LEFT JOIN inspectors ins ON i.guard_id = ins.id AND i.reporter_role = 'inspector'
                            WHERE i.agency_client_id IN ($mapping_ids_str) AND i.report_what IS NOT NULL
                            ORDER BY i.created_at DESC LIMIT 10
                        ";
                        $log_res = $conn->query($log_sql);
                        if ($log_res && $log_res->num_rows > 0):
                            while($row = $log_res->fetch_assoc()): 
                                $prefix = (isset($row['reporter_role']) && $row['reporter_role'] == 'inspector') ? 'Insp. ' : 'SG. ';
                                $display_name = $prefix . ($row['guard_name'] ?: 'Unknown');
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($display_name); ?></strong></td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo strtolower($row['status']) === 'resolved' ? '#dcfce7' : '#fef3c7'; ?>; color: <?php echo strtolower($row['status']) === 'resolved' ? '#166534' : '#92400e'; ?>;">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <button class="btn-view" onclick='showReport(<?php echo json_encode($row); ?>, "<?php echo $display_name; ?>")'>View</button>
                                            <button class="btn-view" style="background:#f0fdf4; color:#166534; border-color:#bbf7d0;" onclick='downloadPDF(<?php echo json_encode($row); ?>, "<?php echo $display_name; ?>")'>PDF</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding:30px; color:#9ca3af;">No official reports submitted yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Report Export Card -->
            <div class="card">
                <h3 class="card-header">Export Activity Logs</h3>
                <p class="card-desc">Generate CSV or PDF reports for your internal records.</p>
                
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
                    <button type="submit" name="generate_report" value="1" class="submit-btn">
                        Download Export
                    </button>
                </form>
            </div>
        </div>
    </main>

    <!-- Report Detail Modal -->
    <div class="modal-overlay" id="reportModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <h3 style="margin-bottom: 8px; color: #1e293b;">Report Details</h3>
            <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 24px;" id="modal_meta"></p>
            
            <div class="w5h-grid">
                <div class="w5h-item">
                    <div class="w5h-label">What happened?</div>
                    <div class="w5h-value" id="v_what"></div>
                </div>
                <div class="w5h-item">
                    <div class="w5h-label">Who was involved?</div>
                    <div class="w5h-value" id="v_who"></div>
                </div>
                <div class="w5h-item">
                    <div class="w5h-label">When did it occur?</div>
                    <div class="w5h-value" id="v_when"></div>
                </div>
                <div class="w5h-item">
                    <div class="w5h-label">Where exactly?</div>
                    <div class="w5h-value" id="v_where"></div>
                </div>
                <div class="w5h-item">
                    <div class="w5h-label">Why did it happen?</div>
                    <div class="w5h-value" id="v_why"></div>
                </div>
                <div class="w5h-item">
                    <div class="w5h-label">How was it handled?</div>
                    <div class="w5h-value" id="v_how"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <h3 class="modal-title">Log Out?</h3>
            <p class="modal-text">Are you sure you want to end your session?</p>
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button class="btn-view" style="flex:1; padding:12px;" onclick="document.getElementById('logoutModal').classList.remove('show')">Cancel</button>
                <a href="logout.php" style="flex:1; padding:12px; background:#ef4444; color:white; text-decoration:none; border-radius:8px; font-weight:600;">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function showReport(data, displayName) {
            document.getElementById('v_what').innerText = data.report_what || '---';
            document.getElementById('v_who').innerText = data.report_who || '---';
            document.getElementById('v_when').innerText = data.report_when || '---';
            document.getElementById('v_where').innerText = data.report_where || '---';
            document.getElementById('v_why').innerText = data.report_why || '---';
            document.getElementById('v_how').innerText = data.report_how || '---';
            
            const date = new Date(data.created_at).toLocaleString();
            document.getElementById('modal_meta').innerText = `Reported by ${displayName} on ${date}`;
            
            document.getElementById('reportModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function downloadPDF(data, displayName) {
            window.open('generate_5w1h_pdf.php?id=' + data.id, '_blank');
        }

        function closeModal() {
            document.getElementById('reportModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeModal();
                document.getElementById('logoutModal').classList.remove('show');
            }
        }
    </script>
</body>
</html>
</body>
</html>
            document.getElementById('reportModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeModal();
            }
        });
    </script>
    <?php include 'admin_layout/footer.php'; ?>
