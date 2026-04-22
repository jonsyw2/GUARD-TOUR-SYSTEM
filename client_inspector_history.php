<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Auto-migration: Ensure inspector tables exist
$conn->query("
    CREATE TABLE IF NOT EXISTS inspectors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agency_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (agency_id)
    ) ENGINE=InnoDB
");

$conn->query("
    CREATE TABLE IF NOT EXISTS inspector_scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inspector_id INT NOT NULL,
        agency_client_id INT NOT NULL,
        checkpoint_id INT DEFAULT 0,
        scan_time DATETIME NOT NULL,
        status VARCHAR(50) DEFAULT 'Routine',
        remarks TEXT,
        photo_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (inspector_id),
        INDEX (agency_client_id)
    ) ENGINE=InnoDB
");

// Schema update for existing tables
$conn->query("ALTER TABLE inspector_scans ADD COLUMN IF NOT EXISTS checkpoint_id INT DEFAULT 0 AFTER agency_client_id");
$conn->query("ALTER TABLE inspector_scans ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) DEFAULT NULL AFTER remarks");

// Data repair: Fix invalid scan_time (0000-00-00) by using created_at
$conn->query("UPDATE inspector_scans SET scan_time = created_at WHERE scan_time = '0000-00-00 00:00:00' OR scan_time IS NULL");

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id, agency_id FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
$agency_ids = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
        $agency_ids[] = (int)$r['agency_id'];
    }
}
$agency_ids = array_unique($agency_ids);

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
    $agency_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
    $agency_ids_str = implode(',', $agency_ids);
}

// Fetch filter options: Inspectors
$inspectors_sql = "SELECT id, name FROM inspectors WHERE agency_id IN ($agency_ids_str) ORDER BY name ASC";
$inspectors_res = $conn->query($inspectors_sql);

// Handle Filter Submissions
$filter_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_end = $_GET['end_date'] ?? date('Y-m-d');
$filter_inspector = $_GET['inspector_id'] ?? '';

// Build dynamic WHERE clause
$where_clauses = ["iscn.agency_client_id IN ($mapping_ids_str)"];

if (!empty($filter_start)) {
    $start = $conn->real_escape_string($filter_start . " 00:00:00");
    $where_clauses[] = "iscn.scan_time >= '$start'";
}
if (!empty($filter_end)) {
    $end = $conn->real_escape_string($filter_end . " 23:59:59");
    $where_clauses[] = "iscn.scan_time <= '$end'";
}
if (!empty($filter_inspector)) {
    $i_id = (int)$filter_inspector;
    $where_clauses[] = "iscn.inspector_id = $i_id";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch history
$history_sql = "
    SELECT 
        iscn.scan_time,
        ac.site_name,
        i.name as inspector_name,
        iscn.status,
        iscn.remarks,
        iscn.photo_path
    FROM inspector_scans iscn
    JOIN agency_clients ac ON iscn.agency_client_id = ac.id
    JOIN inspectors i ON iscn.inspector_id = i.id
    WHERE $where_sql
    ORDER BY iscn.scan_time DESC
    LIMIT 200
";

$history_res = $conn->query($history_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Visit History - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }

        /* Filter Form */
        .filter-form { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 200px; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #4b5563; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;}
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; background-color: #f9fafb; transition: all 0.2s; }
        .form-control:focus { outline: none; border-color: #3b82f6; background-color: #fff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .btn-primary { padding: 10px 20px; background-color: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center; }
        .btn-primary:hover { background-color: #2563eb; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-routine { background-color: #d1fae5; color: #059669; }
        .status-emergency { background-color: #fee2e2; color: #dc2626; }
        .status-follow-up { background-color: #dbeafe; color: #2563eb; }
        .status-missed { background-color: #fef3c7; color: #b45309; }
        
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
        }
        .btn-view-photo:hover { background-color: #2563eb; }
        
        .photo-modal-content { max-width: 800px !important; }
        .modal-image-container { 
            position: relative; 
            width: 100%; 
            background: #f3f4f6; 
            border-radius: 8px; 
            margin: 20px 0; 
            max-height: 65vh;
            overflow: auto;
        }
        #modalImage { 
            max-width: 100%; 
            display: block; 
            border-radius: 8px; 
            margin: 0 auto;
        }
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
            <li><a href="client_inspector_history.php" class="nav-link active">Inspector Visits</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
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
            <h2>Inspector Visit History</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            
            <div class="card">
                <div class="card-header">Filter Inspector Visits</div>
                <form class="filter-form" method="GET" action="client_inspector_history.php">
                    <div class="form-group">
                        <label class="form-label" for="start_date">Date From</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">Date To</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="inspector_id">Inspector</label>
                        <select id="inspector_id" name="inspector_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- All Inspectors --</option>
                            <?php if ($inspectors_res && $inspectors_res->num_rows > 0): ?>
                                <?php while($i = $inspectors_res->fetch_assoc()): ?>
                                    <option value="<?php echo $i['id']; ?>" <?php if($filter_inspector == $i['id']) echo 'selected'; ?>><?php echo htmlspecialchars($i['name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn-primary">Apply Filters</button>
                        <a href="client_inspector_history.php" class="btn-primary" style="background: #94a3b8; text-decoration: none;">Reset</a>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Site Name</th>
                                <th>Inspector Name</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Photo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history_res && $history_res->num_rows > 0): ?>
                                <?php while($row = $history_res->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo date('M d, Y h:i A', strtotime($row['scan_time'])); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['site_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['inspector_name']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace([' '], ['-'], $row['status'])); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 0.85rem; color: #4b5563; font-style: italic;">
                                            <?php echo htmlspecialchars($row['remarks'] ?? '---'); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['photo_path'])): ?>
                                                <button class="btn-view-photo" onclick="viewPhoto('<?php echo htmlspecialchars($row['photo_path']); ?>', 'Inspector Report - <?php echo htmlspecialchars($row['inspector_name']); ?>')">View Photo</button>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 0.75rem;">No Photo</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">No inspector visits found for the selected criteria.</td>
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
            <h3 class="modal-title" id="photoModalTitle">Inspector Photo</h3>
            <div class="modal-image-container">
                <img id="modalImage" src="" alt="Inspection Photo">
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
        function viewPhoto(url, title) {
            const modal = document.getElementById('photoModal');
            const modalImg = document.getElementById('modalImage');
            const modalTitle = document.getElementById('photoModalTitle');
            
            modalTitle.innerText = title;
            modalImg.src = url;
            
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
