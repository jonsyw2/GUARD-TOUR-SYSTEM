<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch statistics
$total_users_result = $conn->query("SELECT COUNT(*) as count FROM users");
$total_users = $total_users_result ? $total_users_result->fetch_assoc()['count'] : 0;

$agencies_count_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_level = 'agency'");
$agencies_count = $agencies_count_result ? $agencies_count_result->fetch_assoc()['count'] : 0;

$clients_count_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_level = 'client'");
$clients_count = $clients_count_result ? $clients_count_result->fetch_assoc()['count'] : 0;

// Fetch lists
$agencies_list = $conn->query("SELECT id, username FROM users WHERE user_level = 'agency' ORDER BY username ASC LIMIT 10");
$clients_list = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            display: flex;
            height: 100vh;
            background-color: #f3f4f6;
            color: #1f2937;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background-color: #111827;
            color: #fff;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 24px 20px;
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            border-bottom: 1px solid #374151;
            letter-spacing: 0.5px;
            color: #f9fafb;
        }

        .nav-links {
            list-style: none;
            flex: 1;
            padding-top: 15px;
        }

        .nav-link {
            padding: 15px 24px;
            display: flex;
            align-items: center;
            color: #9ca3af;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s, color 0.2s, border-color 0.2s;
            border-left: 4px solid transparent;
        }

        .nav-link:hover, .nav-link.active {
            background-color: #1f2937;
            color: #fff;
            border-left-color: #3b82f6;
        }

        /* Submenu Styles */
        .has-submenu {
            cursor: pointer;
            justify-content: space-between;
        }
        
        .submenu {
            display: none;
            background-color: #0f172a;
            list-style: none;
            padding-left: 0;
        }
        
        .submenu.open {
            display: block;
        }

        .submenu-link {
            padding: 12px 24px 12px 48px;
            display: block;
            color: #9ca3af;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .submenu-link:hover, .submenu-link.active {
            color: #fff;
            background-color: #1f2937;
        }

        .caret {
            transition: transform 0.2s;
            font-size: 0.8rem;
        }

        .caret.open {
            transform: rotate(180deg);
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid #374151;
        }

        .logout-btn {
            display: block;
            text-align: center;
            padding: 12px;
            background-color: #ef4444;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background-color: #dc2626;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .topbar {
            background: white;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .topbar h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .badge {
            background: #e0e7ff;
            color: #4f46e5;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(17, 24, 39, 0.7);
            z-index: 50;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.show { display: flex; }
        .modal-content {
            background: white;
            padding: 32px;
            border-radius: 12px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            text-align: center;
            animation: modalFadeIn 0.3s ease-out forwards;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-icon {
            width: 48px; height: 48px;
            background: #ffe4e6;
            color: #e11d48;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.5rem;
        }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal {
            flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem;
            cursor: pointer; transition: all 0.2s; border: none;
        }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; }
        .btn-confirm:hover { background: #be123c; }

        .content-area {
            padding: 32px;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #3b82f6;
            border-radius: 12px 0 0 12px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .card:hover::before {
            opacity: 1;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -5px rgba(0, 0, 0, 0.05);
        }

        .card h3 {
            color: #6b7280;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 12px;
        }

        .card .value {
            font-size: 2.25rem;
            font-weight: 700;
            color: #111827;
        }

        .lists-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 768px) { .lists-container { grid-template-columns: 1fr; } }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Admin Panel
        </div>
        <ul class="nav-links">
            <li><a href="admin_dashboard.php" class="nav-link active">Dashboard</a></li>
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
            <li><a href="login_logs_view.php" class="nav-link">Login Logs</a></li>
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
            <h2>Overview</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin'; ?></strong></span>
                <span class="badge">admin</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <div class="dashboard-cards">
                <div class="card">
                    <h3>Total Users</h3>
                    <div class="value"><?php echo $total_users; ?></div>
                </div>
                <div class="card">
                    <h3>Total Agencies</h3>
                    <div class="value"><?php echo $agencies_count; ?></div>
                </div>
                <div class="card">
                    <h3>Total Clients</h3>
                    <div class="value"><?php echo $clients_count; ?></div>
                </div>
            </div>
            
            <div class="lists-container">
                <div class="card">
                    <h3 style="color: #111827; text-transform: none; font-size: 1.25rem; margin-bottom: 8px;">Agencies List</h3>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Agency Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($agencies_list && $agencies_list->num_rows > 0): ?>
                                    <?php while($row = $agencies_list->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="empty-state">No agencies found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3 style="color: #111827; text-transform: none; font-size: 1.25rem; margin-bottom: 8px;">Clients List</h3>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Client Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($clients_list && $clients_list->num_rows > 0): ?>
                                    <?php while($row = $clients_list->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="empty-state">No clients found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
