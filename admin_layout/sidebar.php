<?php
/**
 * Global Sidebar Component
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <div class="sidebar-header">ADMIN DASHBOARD</div>
    <ul class="nav-links">
        <li>
            <a href="admin_dashboard.php" class="nav-link <?php echo $current_page == 'admin_dashboard.php' ? 'active' : ''; ?>">
                <span>📊</span> Dashboard
            </a>
        </li>
        <li>
            <a href="admin_security_guards.php" class="nav-link <?php echo $current_page == 'admin_security_guards.php' ? 'active' : ''; ?>">
                <span>🛡️</span> Security Guards
            </a>
        </li>
        <li>
            <a href="agency_maintenance.php" class="nav-link <?php echo $current_page == 'agency_maintenance.php' ? 'active' : ''; ?>">
                <span>🛠️</span> Agency Maintenance
            </a>
        </li>
        <li>
            <a href="admin_clients.php" class="nav-link <?php echo $current_page == 'admin_clients.php' ? 'active' : ''; ?>">
                <span>👥</span> Client Management
            </a>
        </li>
        <li>
            <a href="manage_limits.php" class="nav-link <?php echo $current_page == 'manage_limits.php' ? 'active' : ''; ?>">
                <span>⚙️</span> QR Limit Control
            </a>
        </li>
        <li>
            <a href="login_logs_view.php" class="nav-link <?php echo $current_page == 'login_logs_view.php' ? 'active' : ''; ?>">
                <span>📋</span> Login Logs
            </a>
        </li>
    </ul>
    <div class="sidebar-footer">
        <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Log Out</a>
    </div>
</aside>
