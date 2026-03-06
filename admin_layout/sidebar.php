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
            <div class="nav-link <?php echo in_array($current_page, ['agency_maintenance.php', 'client_maintenance.php']) ? 'active' : ''; ?> has-submenu" onclick="toggleSubmenu('maintenanceMenu', this)">
                <span>🛠️</span> <span>Maintenance</span>
            </div>
            <ul class="submenu <?php echo in_array($current_page, ['agency_maintenance.php', 'client_maintenance.php']) ? 'open' : ''; ?>" id="maintenanceMenu">
                <li><a href="agency_maintenance.php" class="submenu-link <?php echo $current_page == 'agency_maintenance.php' ? 'active' : ''; ?>">Agency Management</a></li>
                <li><a href="client_maintenance.php" class="submenu-link <?php echo $current_page == 'client_maintenance.php' ? 'active' : ''; ?>">Client Directory</a></li>
            </ul>
        </li>
        <li>
            <a href="manage_limits.php" class="nav-link <?php echo $current_page == 'manage_limits.php' ? 'active' : ''; ?>">
                <span>⚙️</span> QR Limits
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
