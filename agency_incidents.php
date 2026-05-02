<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'];

// Auto-migration: Ensure incidents table exists and has correct columns
$conn->query("
    CREATE TABLE IF NOT EXISTS incidents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agency_id INT NOT NULL,
        agency_client_id INT DEFAULT NULL,
        guard_id INT DEFAULT NULL,
        checkpoint_id INT DEFAULT NULL,
        report_category ENUM('general', 'investigation') DEFAULT 'general',
        incident_type ENUM('emergency', 'missed_patrol', 'general') DEFAULT 'general',
        description TEXT NOT NULL,
        recorded_by VARCHAR(255) DEFAULT NULL,
        noted_by VARCHAR(255) DEFAULT NULL,
        investigated_by VARCHAR(255) DEFAULT NULL,
        approved_by VARCHAR(255) DEFAULT NULL,
        photo_path VARCHAR(255) DEFAULT NULL,
        status ENUM('active', 'resolved') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (agency_id),
        INDEX (agency_client_id),
        INDEX (guard_id),
        INDEX (checkpoint_id)
    ) ENGINE=InnoDB
");

// Ensure columns are nullable for older schemas
$conn->query("ALTER TABLE incidents MODIFY COLUMN agency_client_id INT DEFAULT NULL");
$conn->query("ALTER TABLE incidents MODIFY COLUMN guard_id INT DEFAULT NULL");

// Double check columns in case table existed with old schema
$result = $conn->query("SHOW COLUMNS FROM incidents LIKE 'agency_id'");
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE incidents ADD COLUMN agency_id INT NOT NULL AFTER id, ADD INDEX (agency_id)");
}

$columns_to_check = [
    'report_category' => "ENUM('general', 'investigation') DEFAULT 'general' AFTER checkpoint_id",
    'recorded_by' => "VARCHAR(255) DEFAULT NULL AFTER description",
    'noted_by' => "VARCHAR(255) DEFAULT NULL AFTER recorded_by",
    'approved_by' => "VARCHAR(255) DEFAULT NULL AFTER investigated_by",
    'report_what' => "TEXT DEFAULT NULL AFTER checkpoint_id",
    'report_who' => "TEXT DEFAULT NULL AFTER report_what",
    'report_when' => "TEXT DEFAULT NULL AFTER report_who",
    'report_where' => "TEXT DEFAULT NULL AFTER report_when",
    'report_why' => "TEXT DEFAULT NULL AFTER report_where",
    'report_how' => "TEXT DEFAULT NULL AFTER report_why"
];

foreach ($columns_to_check as $col => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM incidents LIKE '$col'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE incidents ADD COLUMN $col $definition");
    }
}

$message = '';
$message_type = '';

// Handle Create Incident
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_incident'])) {
    $category = $conn->real_escape_string($_POST['report_category']);
    $desc = $conn->real_escape_string($_POST['description']);
    $status = $conn->real_escape_string($_POST['status']);
    
    $rec_by = $conn->real_escape_string($_POST['recorded_by']);
    $noted_by = $conn->real_escape_string($_POST['noted_by']);
    $inv_by = $conn->real_escape_string($_POST['investigated_by']);
    $app_by = $conn->real_escape_string($_POST['approved_by']);
    
    $photo_path = "NULL";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "uploads/incidents/";
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
        
        $file_ext = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
        $new_filename = "inc_" . time() . "_" . rand(1000, 9999) . "." . $file_ext;
        $target_file = $target_dir . $new_filename;
        
        if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
            $photo_path = "'" . $conn->real_escape_string($target_file) . "'";
        }
    }

    // Site and Guard are now implicitly NULL during professional report creation unless we decide otherwise
    $insert_sql = "INSERT INTO incidents 
        (agency_id, report_category, description, recorded_by, noted_by, investigated_by, approved_by, photo_path, status) 
        VALUES 
        ($agency_id, '$category', '$desc', '$rec_by', '$noted_by', '$inv_by', '$app_by', $photo_path, '$status')";
    
    if ($conn->query($insert_sql)) {
        $message = "Incident report created successfully!";
        $message_type = "success";
    } else {
        $message = "Error creating report: " . $conn->error;
        $message_type = "error";
    }
}

// Fetch Incidents for this agency
$incidents_sql = "
    SELECT i.*, g.name as guard_name, ac.site_name
    FROM incidents i
    LEFT JOIN guards g ON i.guard_id = g.id
    LEFT JOIN agency_clients ac ON i.agency_client_id = ac.id
    WHERE i.agency_id = $agency_id AND i.report_what IS NULL
    ORDER BY i.created_at DESC
";
$incidents_res = $conn->query($incidents_sql);

// Fetch data for the form - REMOVED since site/guard selection is gone
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Reports - Agency Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --bg-main: #f3f4f6;
            --sidebar-bg: #111827;
            --card-bg: #ffffff;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
        }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); margin: 0; padding: 0; gap: 0; overflow-x: hidden; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: #fff; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; transition: transform 0.3s ease; z-index: 2000; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: var(--primary); }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; width: 100%; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; }
        .badge { background: #d1fae5; color: #10b981; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 24px; border: 1px solid var(--border); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
        .card-title { font-size: 1.125rem; font-weight: 600; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f9fafb; padding: 12px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        td { padding: 16px; font-size: 0.9rem; border-bottom: 1px solid var(--border); }
        
        .type-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .type-emergency { background: #fee2e2; color: #dc2626; }
        .type-missed { background: #fef3c7; color: #b45309; }
        .type-general { background: #dbeafe; color: #1e40af; }

        .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .status-active { background: #fef2f2; color: #ef4444; }
        .status-resolved { background: #f0fdf4; color: #10b981; }

        .btn { padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-size: 0.9rem; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #f3f4f6; color: #374151; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 2100; backdrop-filter: blur(4px); overflow-y: auto; padding: 20px; }
        .modal.show { display: flex; align-items: flex-start; justify-content: center; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); position: relative; margin: auto; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-close { position: absolute; top: 12px; right: 12px; font-size: 24px; background: none; border: none; color: #9ca3af; cursor: pointer; transition: color 0.2s; line-height: 1; padding: 4px; border-radius: 4px; }
        .modal-close:hover { color: #111827; background: #f3f4f6; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; outline: none; }
        .form-control:focus { border-color: var(--primary); }

        /* Mobile Menu Toggle */
        .mobile-toggle { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-main); padding: 8px; }
        .sidebar-close { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; position: absolute; top: 20px; right: 20px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1999; backdrop-filter: blur(2px); }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; left: -250px; top: 0; bottom: 0; z-index: 2000; transition: transform 0.3s ease; }
            .sidebar.show { transform: translateX(250px); }
            .sidebar-close, .mobile-toggle, .sidebar-overlay.show { display: block; }
            .main-content { border-radius: 0; border: none; }
            .topbar { padding: 16px 20px; }
            .content-area { padding: 24px 16px; }

            /* Table Cards */
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tr { border: 1px solid var(--border); border-radius: 12px; margin-bottom: 16px; padding: 12px; background: white; }
            td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border: none !important; border-bottom: 1px solid #f3f4f6 !important; text-align: right; }
            td:last-child { border-bottom: none !important; flex-direction: column; align-items: stretch; gap: 8px; }
            td::before { content: attr(data-label); font-weight: 700; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; text-align: left; }
            
            .modal-content { width: 95%; padding: 24px; }
        }
    </style>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
    </script>
</head>
<body>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
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
            <li><a href="agency_incidents.php" class="nav-link active">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                <h2>Incident Reports</h2>
            </div>
            <div class="user-info">
                <span class="badge">Professional reports</span>
            </div>
        </header>

        <div class="content-area">
            <?php if ($message): ?>
                <div style="padding: 16px; border-radius: 8px; margin-bottom: 24px; background: <?php echo $message_type === 'success' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#15803d' : '#b91c1c'; ?>; border: 1px solid <?php echo $message_type === 'success' ? '#86efac' : '#fecaca'; ?>;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Incidents</span>
                    <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('show')">+ New Report</button>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($incidents_res && $incidents_res->num_rows > 0): ?>
                                <?php while($row = $incidents_res->fetch_assoc()): ?>
                                    <tr>
                                        <td data-label="Date"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                        <td data-label="Category">
                                            <span style="font-weight: 600; color: #334155;">
                                                <?php echo ucfirst($row['report_category']); ?> Report
                                            </span>
                                        </td>
                                        <td data-label="Description">
                                            <div style="max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.85rem;" title="<?php echo htmlspecialchars($row['description']); ?>">
                                                <?php echo htmlspecialchars($row['description']); ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px;">
                                                By: <?php echo htmlspecialchars($row['guard_name'] ?: ($row['recorded_by'] ?: '---')); ?>
                                                <?php if ($row['site_name']): ?> | Site: <?php echo htmlspecialchars($row['site_name']); ?><?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status-badge status-<?php echo $row['status']; ?>">
                                                <?php echo ucfirst($row['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Actions">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <?php if (!empty($row['photo_path']) && $row['photo_path'] !== 'NULL'): ?>
                                                    <a href="<?php echo $row['photo_path']; ?>" target="_blank" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;">View Photo</a>
                                                <?php endif; ?>
                                                <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;" 
                                                    onclick="showIncidentReport(
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
                                                    )">Details</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 40px;">No incidents reported yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 style="margin-bottom: 20px;">Create Incident Report</h3>
            <form action="agency_incidents.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label">Report Category</label>
                    <div style="display: flex; gap: 20px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 0.875rem; cursor: pointer;">
                            <input type="radio" name="report_category" value="general" checked> General Report
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 0.875rem; cursor: pointer;">
                            <input type="radio" name="report_category" value="investigation"> Investigation Report
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Explain what happened..." required></textarea>
                </div>
                <div class="create-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">Recorded by</label>
                        <input type="text" name="recorded_by" class="form-control" placeholder="Name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Noted by</label>
                        <input type="text" name="noted_by" class="form-control" placeholder="Name">
                    </div>
                </div>
                <div class="create-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">Investigated by</label>
                        <input type="text" name="investigated_by" class="form-control" placeholder="Name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Approved by</label>
                        <input type="text" name="approved_by" class="form-control" placeholder="Name">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Photo Evidence (Optional)</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                </div>
                <div class="form-group">
                    <label class="form-label">Initial Status</label>
                    <select name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="document.getElementById('createModal').classList.remove('show')">Cancel</button>
                    <button type="submit" name="create_incident" class="btn btn-primary" style="flex: 1;">Submit Report</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content" style="text-align: left;">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 style="margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px;" id="detail_title">Report Details</h3>
            
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0;">
                <p id="detail_desc" style="font-size: 0.95rem; line-height: 1.6; color: #334155; margin-bottom: 12px;"></p>
                
                <!-- 5W1H Section -->
                <div id="w5h_container" style="display: none; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 8px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.85rem;">
                        <div><strong style="color: #64748b;">WHAT:</strong> <span id="w_what"></span></div>
                        <div><strong style="color: #64748b;">WHO:</strong> <span id="w_who"></span></div>
                        <div><strong style="color: #64748b;">WHEN:</strong> <span id="w_when"></span></div>
                        <div><strong style="color: #64748b;">WHERE:</strong> <span id="w_where"></span></div>
                        <div><strong style="color: #64748b;">WHY:</strong> <span id="w_why"></span></div>
                        <div><strong style="color: #64748b;">HOW:</strong> <span id="w_how"></span></div>
                    </div>
                </div>

                <div style="font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase; margin-top: 12px;" id="detail_category">Category: ---</div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" style="color: var(--text-muted); font-size: 0.75rem;">Recorded by</label>
                    <div id="detail_recorded" style="font-weight: 600;">---</div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="color: var(--text-muted); font-size: 0.75rem;">Noted by</label>
                    <div id="detail_noted" style="font-weight: 600;">---</div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="color: var(--text-muted); font-size: 0.75rem;">Investigated by</label>
                    <div id="detail_investigated" style="font-weight: 600;">---</div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="color: var(--text-muted); font-size: 0.75rem;">Approved by</label>
                    <div id="detail_approved" style="font-weight: 600;">---</div>
                </div>
            </div>

            <button type="button" class="btn btn-secondary" style="width: 100%; margin-top: 20px;" onclick="document.getElementById('detailModal').classList.remove('show')">Close</button>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 style="margin-bottom: 20px;">Ready to Logout?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn btn-secondary" style="flex: 1;" onclick="document.getElementById('logoutModal').classList.remove('show')">Cancel</button>
                <a href="logout.php" class="btn" style="flex: 1; background: #ef4444; color: white;">Logout</a>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }

        function showIncidentReport(category, desc, recorded, noted, investigated, approved, w5h = null) {
            document.getElementById('detail_title').innerText = category === 'investigation' ? 'Investigation Report' : 'General Incident Report';
            document.getElementById('detail_desc').innerText = desc;
            
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

            document.getElementById('detail_category').innerText = 'Category: ' + category.toUpperCase();
            document.getElementById('detail_recorded').innerText = recorded || '---';
            document.getElementById('detail_noted').innerText = noted || '---';
            document.getElementById('detail_investigated').innerText = investigated || '---';
            document.getElementById('detail_approved').innerText = approved || '---';
            document.getElementById('detailModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.classList.remove('show');
            });
            document.body.style.overflow = '';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeAllModals();
            }
        }
    </script>
    <!-- VERSION: 2.1 -->
</body>
</html>
