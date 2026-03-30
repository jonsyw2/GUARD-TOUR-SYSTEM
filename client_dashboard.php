<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id, site_name FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
$sites = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
        $sites[] = $r;
    }
}
$mapping_ids_str = !empty($mapping_ids) ? implode(',', $mapping_ids) : '0';

// Determine active site for visual map
$selected_mapping_id = isset($_GET['mapping_id']) ? (int)$_GET['mapping_id'] : ($mapping_ids[0] ?? null);

// Metrics
$scans_today = 0;
$guards_active = 0;
$open_incidents = 0;

if ($mapping_ids_str !== '0') {
    // Scans Today
    $scans_today = $conn->query("
        SELECT COUNT(*) as count FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($mapping_ids_str) AND DATE(s.scan_time) = CURDATE()
    ")->fetch_assoc()['count'];

    // Guards Active (who scanned today)
    $guards_active = $conn->query("
        SELECT COUNT(DISTINCT s.guard_id) as count FROM scans s
        JOIN checkpoints c ON s.checkpoint_id = c.id
        WHERE c.agency_client_id IN ($mapping_ids_str) AND DATE(s.scan_time) = CURDATE()
    ")->fetch_assoc()['count'];

    // Open Incidents
    $open_incidents = $conn->query("
        SELECT COUNT(*) as count FROM incidents 
        WHERE agency_client_id IN ($mapping_ids_str) AND status != 'Resolved'
    ")->fetch_assoc()['count'];

    // Fetch checkpoints for the visual map of selected site
    $checkpoints = [];
    if ($selected_mapping_id) {
        $cp_res = $conn->query("
            SELECT cp.id, cp.name, cp.visual_pos_x, cp.visual_pos_y, cp.is_zero_checkpoint,
            (SELECT scan_time FROM scans WHERE checkpoint_id = cp.id AND DATE(scan_time) = CURDATE() ORDER BY scan_time DESC LIMIT 1) as last_scan_today
            FROM checkpoints cp
            WHERE cp.agency_client_id = $selected_mapping_id
            ORDER BY cp.is_zero_checkpoint DESC, (SELECT ta.sort_order FROM tour_assignments ta WHERE ta.checkpoint_id = cp.id AND ta.agency_client_id = $selected_mapping_id LIMIT 1) ASC
        ");
        if ($cp_res) {
            while ($row = $cp_res->fetch_assoc()) {
                $checkpoints[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --bg-main: #f3f4f6;
            --sidebar-bg: #111827;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); padding: 0 16px 0 0; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: #fff; display: flex; flex-direction: column; flex-shrink: 0; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: var(--primary); }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; border-radius: 16px; border: 1px solid var(--border); background: white; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .badge { background: #dbeafe; color: var(--primary); padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        
        /* Stats Styles */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .stat-card { background: white; padding: 24px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border); display: flex; align-items: center; gap: 16px; transition: transform 0.2s, box-shadow 0.2s; text-decoration: none; color: inherit; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.12); }
        .stat-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        .stat-value { font-size: 1.75rem; font-weight: 700; color: var(--text-main); }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border); margin-bottom: 24px; }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        
        .activity-table { width: 100%; border-collapse: collapse; }
        .activity-table th { text-align: left; padding: 12px 16px; background: #f9fafb; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        .activity-table td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .btn-confirm:hover { background: #be123c; }
        /* Visual Map Styles */
        .visual-map-card { background: white; padding: 28px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border); margin-bottom: 24px; position: relative; }
        .visual-container {
            width: 100%;
            height: 450px;
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
        .checkpoint-pin {
            width: 36px;
            height: 36px;
            border-radius: 50% 50% 50% 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            position: absolute;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 2px solid #ffffff;
            transform: rotate(-45deg);
            z-index: 5;
            transition: transform 0.2s;
        }
        .checkpoint-pin:hover { transform: rotate(-45deg) scale(1.15); z-index: 10; }
        .checkpoint-pin > span { transform: rotate(45deg); display: block; }
        .pin-label {
            position: absolute;
            bottom: -35px;
            white-space: nowrap;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.9);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            transform: rotate(45deg);
            pointer-events: none;
        }

        /* Status Colors */
        .status-scanned { background: linear-gradient(135deg, #10b981, #059669); color: white; animation: pulse-green 2s infinite; }
        .status-pending { background: linear-gradient(135deg, #94a3b8, #64748b); color: white; }
        .status-start { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; }

        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .visual-svg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
        .visual-path { stroke: #6366f1; stroke-width: 2; stroke-linecap: round; fill: none; stroke-dasharray: 6 3; }
        .flowing-arrow { fill: #4f46e5; animation: flow 4s linear infinite; }
        @keyframes flow { from { offset-distance: 0%; } to { offset-distance: 100%; } }

        .form-select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; font-family: inherit; color: var(--text-main); outline: none; transition: border-color 0.2s; }
        .form-select:focus { border-color: var(--primary); }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link active">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
            <li><a href="client_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Security Overview</h2>
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 0.9rem;">Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            <div class="stats-row">
                <a href="client_patrol_history.php" class="stat-card">
                    <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">📊</div>
                    <div>
                        <div class="stat-value"><?php echo $scans_today; ?></div>
                        <div class="stat-label">Scans Today</div>
                    </div>
                </a>
                <a href="client_guards.php" class="stat-card">
                    <div class="stat-icon" style="background: #f0fdf4; color: #10b981;">🛡️</div>
                    <div>
                        <div class="stat-value"><?php echo $guards_active; ?></div>
                        <div class="stat-label">Active Guards</div>
                    </div>
                </a>
                <a href="client_incidents.php" class="stat-card">
                    <div class="stat-icon" style="background: #fef2f2; color: #ef4444;">⚠️</div>
                    <div>
                        <div class="stat-value"><?php echo $open_incidents; ?></div>
                        <div class="stat-label">Open Alerts</div>
                    </div>
                </a>
            </div>

            <!-- Visual Patrol Map -->
            <div class="visual-map-card">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span>Live Patrol Map</span>
                        <?php if (count($sites) > 1): ?>
                            <form action="client_dashboard.php" method="GET" style="margin: 0;">
                                <select name="mapping_id" class="form-select" onchange="this.form.submit()">
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?php echo $site['id']; ?>" <?php echo $selected_mapping_id == $site['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($site['site_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <span class="badge" style="background: #f1f5f9; color: #475569;"><?php echo htmlspecialchars($sites[0]['site_name'] ?? 'Main Site'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 4px; font-size: 0.75rem;">
                            <div style="width: 10px; height: 10px; border-radius: 50%; background: #10b981;"></div> Scanned
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px; font-size: 0.75rem;">
                            <div style="width: 10px; height: 10px; border-radius: 50%; background: #94a3b8;"></div> Pending
                        </div>
                        <a href="client_patrol_history.php" style="font-size: 0.85rem; color: var(--primary); text-decoration: none; font-weight: 500; margin-left: 10px;">Full History →</a>
                    </div>
                </div>

                <div class="visual-container" id="visualContainer">
                    <svg class="visual-svg" id="visualSvg"></svg>
                    <!-- Checkpoints will be injected here -->
                </div>
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

    <script>
        const checkpoints = <?php echo json_encode($checkpoints); ?>;
        const container = document.getElementById('visualContainer');
        const svg = document.getElementById('visualSvg');

        function renderMap() {
            if (!checkpoints.length) {
                container.innerHTML = '<div style="height:100%; display:flex; align-items:center; justify-content:center; color:#6b7280; font-style:italic;">No checkpoints configured for this site.</div>';
                return;
            }

            // Clear previous map objects (keep SVG)
            const pins = container.querySelectorAll('.checkpoint-pin');
            pins.forEach(p => p.remove());
            svg.innerHTML = '';

            let pathD = '';

            checkpoints.forEach((cp, index) => {
                const x = cp.visual_pos_x || (50 + index * 60);
                const y = cp.visual_pos_y || (50 + index * 40);

                // Create Pin
                const pin = document.createElement('div');
                pin.className = 'checkpoint-pin ' + (cp.is_zero_checkpoint == 1 ? 'status-start' : (cp.last_scan_today ? 'status-scanned' : 'status-pending'));
                pin.style.left = x + 'px';
                pin.style.top = y + 'px';
                pin.innerHTML = `<span>${index + 1}</span><div class="pin-label">${cp.name} ${cp.last_scan_today ? '<br><small>✅ ' + new Date(cp.last_scan_today).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) + '</small>' : ''}</div>`;
                container.appendChild(pin);

                // Build Path
                if (index === 0) {
                    pathD = `M ${x + 18} ${y + 18}`;
                } else {
                    pathD += ` L ${x + 18} ${y + 18}`;
                }
            });

            // Draw Path Line
            const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path.setAttribute("d", pathD);
            path.setAttribute("class", "visual-path");
            path.id = "mainPath";
            svg.appendChild(path);

            // Flowing Arrow
            if (checkpoints.length > 1) {
                const arrow = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
                arrow.setAttribute("points", "0,-6 10,0 0,6");
                arrow.setAttribute("class", "flowing-arrow");
                arrow.style.offsetPath = `path('${pathD}')`;
                svg.appendChild(arrow);
            }
        }

        renderMap();

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
