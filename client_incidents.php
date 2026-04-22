<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
    }
}

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
}

// Fetch Incidents
$incidents_sql = "
    SELECT 
        i.created_at,
        c.name as checkpoint_name,
        g.name as guard_name,
        i.incident_type,
        i.description,
        i.status,
        i.photo_path,
        i.report_category,
        i.approved_by,
        i.report_what,
        i.report_who,
        i.report_when,
        i.report_where,
        i.report_why,
        i.report_how
    FROM incidents i
    LEFT JOIN checkpoints c ON i.checkpoint_id = c.id
    LEFT JOIN guards g ON i.guard_id = g.id
    WHERE i.agency_client_id IN ($mapping_ids_str) AND i.report_what IS NULL
    ORDER BY i.created_at DESC
    LIMIT 100
";
$incidents_result = $conn->query($incidents_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incidents & Alerts - Client Portal</title>
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

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        /* Incidents Grid */
        .incidents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
        
        .incident-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); overflow: hidden; display: flex; flex-direction: column; position: relative; border-left: 4px solid #cbd5e1; }
        
        /* Type colors */
        .incident-card.type-emergency { border-left-color: #ef4444; }
        .incident-card.type-missed_patrol { border-left-color: #f59e0b; }
        .incident-card.type-general { border-left-color: #3b82f6; }

        .card-image { width: 100%; height: 160px; background-color: #f1f5f9; object-fit: cover; border-bottom: 1px solid #e5e7eb;}
        .no-image { height: 160px; display: flex; align-items: center; justify-content: center; background: #f9fafb; color: #9ca3af; font-size: 0.85rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; }

        .card-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        
        .card-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .incident-date { font-size: 0.8rem; color: #6b7280; font-weight: 600; }
        
        .incident-type-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-emergency { background-color: #fee2e2; color: #dc2626; }
        .badge-missed { background-color: #fef3c7; color: #b45309; }
        .badge-general { background-color: #e0e7ff; color: #4338ca; }

        .incident-title { font-size: 1.125rem; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .incident-location { font-size: 0.85rem; color: #4b5563; margin-bottom: 12px; display: flex; align-items: center; gap: 4px; }
        .incident-desc { font-size: 0.9rem; line-height: 1.6; color: #374151; margin-bottom: 16px; }

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
            max-width: 500px; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); 
            position: relative;
            margin: auto;
            animation: modalFadeIn 0.3s ease-out forwards;
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
            <li><a href="manage_tour.php" class="nav-link">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="client_incidents.php" class="nav-link active">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
            <li><a href="client_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Incident Reports & Alerts</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            <div class="incidents-grid">
                <?php if ($incidents_result && $incidents_result->num_rows > 0): ?>
                    <?php while($row = $incidents_result->fetch_assoc()): ?>
                        <?php 
                            $type_class = 'type-' . htmlspecialchars($row['incident_type']);
                            $badge_class = 'badge-general';
                            $display_type = 'General Alert';
                            
                            if ($row['incident_type'] === 'emergency') {
                                $badge_class = 'badge-emergency';
                                $display_type = 'Emergency';
                            } else if ($row['incident_type'] === 'missed_patrol') {
                                $badge_class = 'badge-missed';
                                $display_type = 'Missed Patrol';
                            }

                            $status_class = strtolower($row['status']) === 'active' ? 'status-active' : 'status-resolved';
                        ?>
                        <div class="incident-card <?php echo $type_class; ?>">
                            <div style="position: absolute; top: 12px; left: 12px; z-index: 5; background: rgba(0,0,0,0.6); color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase;">
                                <?php echo ucfirst($row['report_category']); ?> Report
                            </div>
                            <?php if (!empty($row['photo_path']) && $row['photo_path'] !== 'NULL'): ?>
                                <img src="<?php echo htmlspecialchars($row['photo_path']); ?>" alt="Incident Evidence" class="card-image">
                            <?php else: ?>
                                <div class="no-image">No Photo Attached</div>
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <div class="card-meta">
                                    <span class="incident-date"><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></span>
                                </div>
                                <h3 class="incident-title"><?php echo ucfirst($row['report_category']); ?> Report</h3>
                                <p class="incident-desc"><?php echo nl2br(htmlspecialchars($row['description'])); ?></p>
                                
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f1f5f9; font-size: 0.8rem; color: #64748b;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                        <span>Recorded by:</span>
                                        <span style="font-weight: 600; color: #334155;"><?php echo htmlspecialchars($row['guard_name'] ?: ($row['recorded_by'] ?: '---')); ?></span>
                                    </div>
                                    <button class="btn-detail" style="width: 100%; margin-top: 10px; padding: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; color: #3b82f6; font-weight: 600; cursor: pointer; font-size: 0.75rem;" 
                                        onclick="showReportDetails(
                                            '<?php echo addslashes($row['report_category']); ?>', 
                                            '<?php echo addslashes($row['description']); ?>', 
                                            '<?php echo addslashes($row['recorded_by'] ?: $row['guard_name']); ?>', 
                                            '<?php echo addslashes($row['noted_by']); ?>', 
                                            '<?php echo addslashes($row['investigated_by']); ?>', 
                                            '<?php echo addslashes($row['approved_by']); ?>',
                                            <?php echo json_encode([
                                                'what' => $row['report_what'],
                                                'who' => $row['report_who'],
                                                'when' => $row['report_when'],
                                                'where' => $row['report_where'],
                                                'why' => $row['report_why'],
                                                'how' => $row['report_how']
                                            ]); ?>
                                        )">View Details (Noted/Approved)</button>
                                </div>

                                <div class="card-footer" style="margin-top: 10px;">
                                    <span>Status: <span class="status <?php echo $status_class; ?>"><?php echo htmlspecialchars($row['status']); ?></span></span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin:0 auto 16px; opacity:0.5;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <h3>All Secure</h3>
                        <p>No incidents or alerts have been reported for your locations.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

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

    <!-- Report Details Modal -->
    <div class="modal-overlay" id="reportDetailModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 id="modal_report_title" style="margin-bottom: 16px; font-size: 1.25rem; font-weight: 700; color: #111827; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">Report Details</h3>
            
            <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e5e7eb;">
                <p id="modal_report_desc" style="font-size: 0.95rem; line-height: 1.6; color: #374151; margin-bottom: 12px;"></p>
                
                <!-- 5W1H Section -->
                <div id="w5h_container" style="display: none; border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 8px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.85rem;">
                        <div><strong style="color: #64748b;">WHAT:</strong> <span id="w_what"></span></div>
                        <div><strong style="color: #64748b;">WHO:</strong> <span id="w_who"></span></div>
                        <div><strong style="color: #64748b;">WHEN:</strong> <span id="w_when"></span></div>
                        <div><strong style="color: #64748b;">WHERE:</strong> <span id="w_where"></span></div>
                        <div><strong style="color: #64748b;">WHY:</strong> <span id="w_why"></span></div>
                        <div><strong style="color: #64748b;">HOW:</strong> <span id="w_how"></span></div>
                    </div>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-item">
                    <div class="detail-label">Recorded by</div>
                    <div class="detail-value" id="modal_recorded">---</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Noted by</div>
                    <div class="detail-value" id="modal_noted">---</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Investigated by</div>
                    <div class="detail-value" id="modal_investigated">---</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Approved by</div>
                    <div class="detail-value" id="modal_approved">---</div>
                </div>
            </div>

            <div style="margin-top: 32px;">
                <button class="btn-modal btn-cancel" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; background: white; border-radius: 8px; font-weight: 600; cursor: pointer;" onclick="closeAllModals()">Close Details</button>
            </div>
        </div>
    </div>

    <script>
        function showReportDetails(category, desc, recorded, noted, investigated, approved, w5h = null) {
            document.getElementById('modal_report_title').innerText = category === 'investigation' ? 'Professional Investigation Report' : 'General Incident Report';
            document.getElementById('modal_report_desc').innerText = desc;

            if (w5h && w5h.what) {
                document.getElementById('w5h_container').style.display = 'block';
                document.getElementById('w_what').innerText = w5h.what || '---';
                document.getElementById('w_who').innerText = w5h.who || '---';
                document.getElementById('w_when').innerText = w5h.when || '---';
                document.getElementById('w_where').innerText = w5h.where || '---';
                document.getElementById('w_why').innerText = w5h.why || '---';
                document.getElementById('w_how').innerText = w5h.how || '---';
            } else {
                document.getElementById('w5h_container').style.display = 'none';
            }

            document.getElementById('modal_recorded').innerText = recorded || '---';
            document.getElementById('modal_noted').innerText = noted || '---';
            document.getElementById('modal_investigated').innerText = investigated || '---';
            document.getElementById('modal_approved').innerText = approved || '---';
            document.getElementById('reportDetailModal').classList.add('show');
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
