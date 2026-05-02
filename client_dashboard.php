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

        /* Responsive Design */
        .mobile-toggle { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-main); padding: 8px; }
        .sidebar-close { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; position: absolute; top: 20px; right: 20px; }

        @media (max-width: 1024px) {
            body { padding: 0; gap: 0; }
            .sidebar { position: fixed; left: -250px; top: 0; bottom: 0; z-index: 1001; transition: left 0.3s ease; box-shadow: 10px 0 20px rgba(0,0,0,0.2); }
            .sidebar.show { left: 0; }
            .sidebar-close { display: block; }
            .main-content { border-radius: 0; border: none; }
            .topbar { padding: 16px 20px; }
            .mobile-toggle { display: block; }
            .content-area { padding: 24px 16px; }
            .stats-row { grid-template-columns: 1fr; gap: 16px; }
        }

        @media (max-width: 640px) {
            .topbar h2 { font-size: 1.1rem; }
            .topbar div span:first-child { display: none; }
            .modal-content { max-width: 90%; padding: 24px; }
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; backdrop-filter: blur(2px); }
        .sidebar-overlay.show { display: block; }
    </style>
    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                const newOverlay = document.createElement('div');
                newOverlay.className = 'sidebar-overlay';
                newOverlay.onclick = toggleSidebar;
                document.body.appendChild(newOverlay);
            }
            sidebar.classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }
    </script>
</head>
<body>

    <aside class="sidebar">
        <button class="sidebar-close" onclick="toggleSidebar()">✕</button>
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
            <a href="#" class="btn-change-password" onclick="document.getElementById('changePasswordModal').classList.add('show'); return false;" style="display: block; text-align: center; padding: 12px; background-color: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin-bottom: 12px; transition: background 0.3s;">Change Password</a>
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                <h2>Security Overview</h2>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 0.9rem;">Welcome, <strong><?php echo htmlspecialchars($_SESSION['company_name'] ?? $_SESSION['username']); ?></strong></span>
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

    <!-- Change Password Modal -->
    <div class="modal-overlay" id="changePasswordModal">
        <div class="modal-content" style="text-align: left;">
            <h3 class="modal-title" style="text-align: center;">Change Password</h3>
            <p class="modal-text" style="text-align: center;">Update your account password securely.</p>
            
            <div id="pwd_alert" style="display: none; padding: 10px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; text-align: center;"></div>

            <form id="changePasswordForm">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #374151;">Current Password</label>
                    <input type="password" id="current_password" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #374151;">New Password</label>
                    <input type="password" id="new_password" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                </div>
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #374151;">Confirm New Password</label>
                    <input type="password" id="confirm_password" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-cancel" onclick="document.getElementById('changePasswordModal').classList.remove('show');">Cancel</button>
                    <button type="submit" class="btn-modal btn-confirm" style="background: var(--primary);" id="btnSubmitPassword">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            const pwdModal = document.getElementById('changePasswordModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
            if (event.target == pwdModal) {
                pwdModal.classList.remove('show');
            }
        }

        document.getElementById('changePasswordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitPassword');
            const alertBox = document.getElementById('pwd_alert');
            
            const current_password = document.getElementById('current_password').value;
            const new_password = document.getElementById('new_password').value;
            const confirm_password = document.getElementById('confirm_password').value;

            if (new_password !== confirm_password) {
                alertBox.style.display = 'block';
                alertBox.style.backgroundColor = '#fee2e2';
                alertBox.style.color = '#dc2626';
                alertBox.innerText = 'New password and confirm password do not match.';
                return;
            }

            btn.disabled = true;
            btn.innerText = 'Updating...';
            alertBox.style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('current_password', current_password);
                formData.append('new_password', new_password);
                formData.append('confirm_password', confirm_password);

                const response = await fetch('api/change_password.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                alertBox.style.display = 'block';
                if (result.status === 'success') {
                    alertBox.style.backgroundColor = '#dcfce7';
                    alertBox.style.color = '#16a34a';
                    alertBox.innerText = result.message;
                    document.getElementById('changePasswordForm').reset();
                    setTimeout(() => {
                        document.getElementById('changePasswordModal').classList.remove('show');
                        alertBox.style.display = 'none';
                    }, 2000);
                } else {
                    alertBox.style.backgroundColor = '#fee2e2';
                    alertBox.style.color = '#dc2626';
                    alertBox.innerText = result.message;
                }
            } catch (error) {
                alertBox.style.display = 'block';
                alertBox.style.backgroundColor = '#fee2e2';
                alertBox.style.color = '#dc2626';
                alertBox.innerText = 'An error occurred. Please try again.';
            } finally {
                btn.disabled = false;
                btn.innerText = 'Update Password';
            }
        });
    </script>
</body>
</html>
