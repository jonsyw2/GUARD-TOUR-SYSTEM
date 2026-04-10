<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'supervisor') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch Supervisor details
$supervisor_res = $conn->query("SELECT * FROM supervisors WHERE user_id = $user_id");
if (!$supervisor_res || $supervisor_res->num_rows == 0) {
    die("Supervisor profile not found. Please contact admin.");
}
$supervisor = $supervisor_res->fetch_assoc();
$supervisor_id = $supervisor['id'];
$agency_id = $supervisor['agency_id'];

// Determine visible clients/sites for this supervisor
$visible_mapping_ids = [];
$sites_filter_sql = "";

if ($agency_id == 0) {
    // Global Supervisor - See everything
    $sites_filter_sql = "1=1";
} else {
    // Check if specifically assigned to sites
    $assigned_sites_res = $conn->query("SELECT id FROM agency_clients WHERE supervisor_id = $supervisor_id");
    if ($assigned_sites_res && $assigned_sites_res->num_rows > 0) {
        while($r = $assigned_sites_res->fetch_assoc()) $visible_mapping_ids[] = (int)$r['id'];
        $sites_filter_sql = "ac.id IN (" . implode(',', $visible_mapping_ids) . ")";
    } else {
        // Fallback: See all sites for their agency
        $sites_filter_sql = "ac.agency_id = $agency_id";
    }
}

// Fetch clients for filtering
$clients_sql = "
    SELECT ac.id as mapping_id, u.username as client_name 
    FROM agency_clients ac
    JOIN users u ON ac.client_id = u.id
    WHERE $sites_filter_sql
    ORDER BY u.username ASC
";
$clients_res = $conn->query($clients_sql);

// Fetch history with filters
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_client = $_GET['mapping_id'] ?? '';
$filter_shift = $_GET['shift'] ?? '';

$where_clauses = [$sites_filter_sql];
if (!empty($filter_date)) {
    $target_date = $conn->real_escape_string($filter_date);
    $next_date = date('Y-m-d', strtotime($target_date . ' +1 day'));
    $where_clauses[] = "s.scan_time >= '$target_date 06:00:00'";
    $where_clauses[] = "s.scan_time < '$next_date 06:00:00'";
}

if (!empty($filter_shift)) {
    $where_clauses[] = "s.shift = '" . $conn->real_escape_string($filter_shift) . "'";
}

if (!empty($filter_client)) {
    $where_clauses[] = "ac.id = " . (int)$filter_client;
}

$where_sql = implode(" AND ", $where_clauses);

$history_sql = "
    SELECT 
        s.scan_time,
        s.shift,
        s.tour_session_id,
        c.name as checkpoint_name,
        g.name as guard_name,
        u.username as client_name,
        ac.id as mapping_id,
        s.status,
        s.justification
    FROM scans s
    JOIN checkpoints c ON s.checkpoint_id = c.id
    JOIN guards g ON s.guard_id = g.id
    JOIN agency_clients ac ON c.agency_client_id = ac.id
    JOIN users u ON ac.client_id = u.id
    WHERE $where_sql
    ORDER BY s.scan_time DESC
    LIMIT 100
";
$history_res = $conn->query($history_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard - Patrol History</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="js/patrol_map_viewer.js?v=1.3"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; color: #111827; min-height: 100vh; }
        .topbar { background: #111827; color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .main-content { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-label { font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .form-control { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; }
        .btn-primary { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
        .status-on-time { background: #d1fae5; color: #065f46; }
        .status-late { background: #fef3c7; color: #92400e; }
        .status-missed { background: #fee2e2; color: #991b1b; }
        .badge { background: #0ea5e9; color: white; padding: 4px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .logout-btn { background: #ef4444; color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; }

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
            margin: auto;
            background: white;
            padding: 32px;
            border-radius: 12px;
            position: relative;
        }
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
        .modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: 24px;
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h2 style="font-weight: 700; letter-spacing: -0.5px;">Guard Tour <span style="color: #10b981;">Supervisor</span></h2>
        </div>
        <div style="display: flex; align-items: center; gap: 20px;">
            <span style="font-size: 0.9rem;">Welcome, <strong><?php echo htmlspecialchars($supervisor['name']); ?></strong></span>
            <span class="badge">Supervisor Portal</span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </header>

    <main class="main-content">
        <div class="card">
            <h3 style="margin-bottom: 16px; color: #1e293b;">Patrol History Filters</h3>
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Select Date</label>
                    <input type="date" name="date" class="form-control" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group">
                    <label class="form-label">Client Site</label>
                    <select name="mapping_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- All My Sites --</option>
                        <?php if ($clients_res): ?>
                            <?php mysqli_data_seek($clients_res, 0); ?>
                            <?php while($c = $clients_res->fetch_assoc()): ?>
                                <option value="<?php echo $c['mapping_id']; ?>" <?php if($filter_client == $c['mapping_id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['client_name']); ?></option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Shift</label>
                    <select name="shift" class="form-control" onchange="this.form.submit()">
                        <option value="">-- All Shifts --</option>
                        <option value="Day Shift" <?php if($filter_shift == 'Day Shift') echo 'selected'; ?>>Day Shift</option>
                        <option value="Night Shift" <?php if($filter_shift == 'Night Shift') echo 'selected'; ?>>Night Shift</option>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn-primary">Apply Filters</button>
                    <a href="supervisor_dashboard.php" style="font-size: 0.85rem; color: #64748b; margin-left: 10px; text-decoration: none;">Reset Filters</a>
                </div>
            </form>
        </div>

        <div class="table-container" style="border: none;">
            <?php 
            if ($history_res && $history_res->num_rows > 0): 
                // Process data into groups
                $grouped = [];
                while($row = $history_res->fetch_assoc()) {
                    $ts = strtotime($row['scan_time']);
                    $s_date = date('Y-m-d', $ts);
                    $hour = (int)date('H', $ts);
                    $s_type = $row['shift'] ?? 'Day Shift';
                    
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
                            'scans' => []
                        ];
                    }
                    $grouped[$shift_key]['tours'][$tour_id]['scans'][] = $row;
                    if (!isset($grouped[$shift_key]['tours'][$tour_id]['mapping_id'])) {
                        $grouped[$shift_key]['tours'][$tour_id]['mapping_id'] = $row['mapping_id'];
                    }
                }

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
                                        </div>
                                        <div style="display: flex; align-items: center;">
                                            <button class="btn-visual-3d" onclick="open3DVisual('<?php echo $t_id; ?>', '<?php echo $t_data['mapping_id'] ?? ''; ?>', event)">
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
                                                <th>Site</th>
                                                <th>Checkpoint</th>
                                                <th>Status</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($t_data['scans'] as $row): ?>
                                                <tr>
                                                    <td style="width: 120px;"><strong><?php echo date('h:i:s A', strtotime($row['scan_time'])); ?></strong></td>
                                                    <td style="font-weight: 500; font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($row['checkpoint_name']); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                                            <?php echo htmlspecialchars($row['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="font-size: 0.85rem; color: #64748b; font-style: italic;">
                                                        <?php echo !empty($row['justification']) ? htmlspecialchars($row['justification']) : '---'; ?>
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
                <div class="empty-history" style="padding: 40px;">No patrol records found for the selected period.</div>
            <?php endif; ?>
        </div>
        
    <div class="modal-overlay" id="visual3DModal">
        <div class="modal-content large">
            <button class="modal-close" onclick="closeAllModals()">&times;</button>
            <h3 style="margin-bottom: 20px; font-weight: 700; color: #1e293b;">Patrol Tour Visualization</h3>
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
            <div style="margin-top: 8px; display: flex; flex-direction: column; align-items: center;">
                <button class="btn-primary" onclick="closeAllModals()" style="width: 100%; background: #64748b;">Close Viewer</button>
            </div>
        </div>
    </div>
</main>

    <script>
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
    </script>
</body>
</html>
