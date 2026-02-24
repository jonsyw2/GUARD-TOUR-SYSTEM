<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch login logs ordered by latest first
$logs_sql = "SELECT id, username, ip_address, user_agent, status, timestamp FROM login_logs ORDER BY timestamp DESC LIMIT 500";
$logs_result = $conn->query($logs_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Logs - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        
        /* Submenu Styles */
        .has-submenu { cursor: pointer; justify-content: space-between; }
        .submenu { display: none; background-color: #0f172a; list-style: none; padding-left: 0; }
        .submenu.open { display: block; }
        .submenu-link { padding: 12px 24px 12px 48px; display: block; color: #9ca3af; text-decoration: none; font-size: 0.95rem; transition: all 0.2s; }
        .submenu-link:hover, .submenu-link.active { color: #fff; background-color: #1f2937; }
        .caret { transition: transform 0.2s; font-size: 0.8rem; }
        .caret.open { transform: rotate(180deg); }

        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

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
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; }
        .btn-confirm:hover { background: #be123c; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #e0e7ff; color: #4f46e5; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1400px; margin: 0 auto; width: 100%; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: middle; font-size: 0.9rem;}
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .status-success { background-color: #d1fae5; color: #10b981; }
        .status-failed { background-color: #fee2e2; color: #ef4444; }
        
        .user-agent { font-size: 0.8rem; color: #6b7280; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block;}
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Admin Panel
        </div>
        <ul class="nav-links">
            <li><a href="admin_dashboard.php" class="nav-link">Dashboard</a></li>
            <li>
                <div class="nav-link has-submenu" onclick="toggleSubmenu('maintenanceMenu', this)">
                    <span>Maintenance</span>
                    <span class="caret">&#9660;</span>
                </div>
                <ul class="submenu" id="maintenanceMenu">
                    <li><a href="agency_maintenance.php" class="submenu-link">Agency Maintenance</a></li>
                    <li><a href="users_maintenance.php" class="submenu-link">User Maintenance</a></li>
                </ul>
            </li>
            <li><a href="manage_limits.php" class="nav-link">QR Limits</a></li>
            <li><a href="login_logs_view.php" class="nav-link active">Login Logs</a></li>
            <li><a href="#" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Authentication Security Logs</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin'; ?></strong></span>
                <span class="badge">admin</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <div class="card">
                <h3 class="card-header">Recent Login Activity</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Timestamp</th>
                                <th>Username Attempted</th>
                                <th>IP Address</th>
                                <th>Status</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($logs_result && $logs_result->num_rows > 0): ?>
                                <?php while($row = $logs_result->fetch_assoc()): ?>
                                    <tr>
                                        <td style="color:#9ca3af;">#<?php echo $row['id']; ?></td>
                                        <td><strong><?php echo date('M d, Y H:i:s', strtotime($row['timestamp'])); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                                        <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td><span class="user-agent" title="<?php echo htmlspecialchars($row['user_agent']); ?>">
                                            <?php echo htmlspecialchars($row['user_agent']); ?>
                                        </span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">No login activity recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
        function toggleSubmenu(menuId, element) {
            const menu = document.getElementById(menuId);
            const caret = element.querySelector('.caret');
            menu.classList.toggle('open');
            caret.classList.toggle('open');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>
