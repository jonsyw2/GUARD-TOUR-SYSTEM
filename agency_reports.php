<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: transform 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; flex-shrink: 0; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; width: 100%; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        
        /* Table Styles */
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; overflow: hidden; }
        .card-header { padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f8fafc; padding: 12px 24px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 16px 24px; font-size: 0.9rem; color: #334155; border-bottom: 1px solid #f1f5f9; }
        tr:hover { background: #f8fafc; }

        .status-badge { padding: 4px 8px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .status-active { background: #fef3c7; color: #92400e; }
        .status-resolved { background: #dcfce7; color: #166534; }

        .btn-view { padding: 6px 12px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-view:hover { background: #dbeafe; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 600px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); position: relative; animation: modalFadeIn 0.3s ease-out; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .modal-close { position: absolute; top: 16px; right: 16px; font-size: 24px; cursor: pointer; color: #94a3b8; }
        
        .w5h-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
        .w5h-item { background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .w5h-label { font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .w5h-value { font-size: 0.9rem; color: #1e293b; line-height: 1.5; }

        /* Mobile Menu Toggle */
        .mobile-toggle { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #1f2937; padding: 8px; }
        .sidebar-close { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; position: absolute; top: 20px; right: 20px; }
        .sidebar-overlay-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; backdrop-filter: blur(2px); }

        @media (max-width: 1024px) {
            body { padding: 0; gap: 0; }
            .sidebar { position: fixed; left: -250px; top: 0; bottom: 0; z-index: 100; }
            .sidebar.show { transform: translateX(250px); }
            .sidebar-close, .mobile-toggle, .sidebar-overlay-bg.show { display: block; }
            .main-content { border-radius: 0; border: none; }
            .topbar { padding: 16px 20px; }
            .content-area { padding: 24px 16px; }

            /* Table Cards */
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tr { border-bottom: 1px solid #e5e7eb; padding: 16px; background: white; }
            td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border: none !important; text-align: right; }
            td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 0.7rem; text-transform: uppercase; text-align: left; }
            
            .modal-content { width: 95%; padding: 24px; }
            .w5h-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php
    $agency_id = $_SESSION['user_id'];
    $reports_sql = "
        SELECT i.*, 
               CASE WHEN i.reporter_role = 'inspector' THEN ins.name ELSE g.name END as guard_name,
               ac.site_name
        FROM incidents i
        LEFT JOIN guards g ON i.guard_id = g.id AND (i.reporter_role = 'guard' OR i.reporter_role IS NULL)
        LEFT JOIN inspectors ins ON i.guard_id = ins.id AND i.reporter_role = 'inspector'
        LEFT JOIN agency_clients ac ON i.agency_client_id = ac.id
        WHERE i.agency_id = $agency_id AND i.report_what IS NOT NULL
        ORDER BY i.created_at DESC
    ";
    $reports_res = $conn->query($reports_sql);
    ?>
    <div class="sidebar-overlay-bg" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <button class="sidebar-close" onclick="toggleSidebar()">✕</button>
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>
            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="manage_supervisors.php" class="nav-link">Manage Supervisors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link active">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                <h2>Official Field Reports</h2>
            </div>
            <div class="user-info">
                <span class="badge" style="background: #e0f2fe; color: #0369a1;">REPORTING CENTER</span>
            </div>
        </header>
        <div class="content-area">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent 5W1H Submissions</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Reported By</th>
                            <th>Site/Location</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reports_res && $reports_res->num_rows > 0): ?>
                            <?php while($row = $reports_res->fetch_assoc()): 
                                $prefix = (isset($row['reporter_role']) && $row['reporter_role'] == 'inspector') ? 'Insp. ' : 'SG. ';
                                $display_name = $prefix . ($row['guard_name'] ?: 'Unknown');
                                ?>
                                <tr>
                                    <td data-label="Date & Time"><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                    <td data-label="Reported By"><strong><?php echo htmlspecialchars($display_name); ?></strong></td>
                                    <td data-label="Site/Location"><?php echo htmlspecialchars($row['site_name'] ?: 'General Site'); ?></td>
                                    <td data-label="Status">
                                        <span class="status-badge <?php echo strtolower($row['status']) === 'resolved' ? 'status-resolved' : 'status-active'; ?>">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Actions">
                                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                            <button class="btn-view" onclick='showReport(<?php echo json_encode($row); ?>, "<?php echo $display_name; ?>")'>View</button>
                                            <button class="btn-view" style="background:#f0fdf4; color:#166534; border-color:#bbf7d0;" onclick='downloadPDF(<?php echo json_encode($row); ?>, "<?php echo $display_name; ?>")'>PDF</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:40px; color:#64748b;">No app-submitted reports found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.sidebar-overlay-bg').classList.toggle('show');
        }

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
    <!-- VERSION: 2.1 -->
</body>
</html>
