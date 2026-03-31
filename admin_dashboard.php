<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch statistics
$total_users_result = $conn->query("SELECT COUNT(*) as count FROM guards");
$total_users = $total_users_result ? $total_users_result->fetch_assoc()['count'] : 0;

$agencies_count_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_level = 'agency'");
$agencies_count = $agencies_count_result ? $agencies_count_result->fetch_assoc()['count'] : 0;

$clients_count_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_level = 'client'");
$clients_count = $clients_count_result ? $clients_count_result->fetch_assoc()['count'] : 0;

// Fetch lists
$agencies_list = $conn->query("SELECT id, username FROM users WHERE user_level = 'agency' ORDER BY id DESC LIMIT 10");
$clients_list = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY id DESC LIMIT 10");

// Fetch 5 most recent login logs
$recent_logins = $conn->query("SELECT username, ip_address, status, timestamp FROM login_logs ORDER BY timestamp DESC LIMIT 5");
?>
<?php
$page_title = 'Admin Dashboard';
$header_title = 'Dashboard Overview';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="contentArea">
            <style>
                .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 40px; }
                .stat-card { background: white; padding: 32px; border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); position: relative; transition: all 0.3s ease; cursor: pointer; text-decoration: none; display: block; color: inherit; }
                a.stat-card { color: inherit; text-decoration: none; }
                .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
                .stat-label { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; display: block; }
                .stat-value { font-size: 2.5rem; font-weight: 800; color: var(--text-main); letter-spacing: -1px; }
                .stat-icon { position: absolute; top: 32px; right: 32px; font-size: 2rem; opacity: 0.2; }
                
                .lists-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 32px; }
                @media (max-width: 1024px) { .lists-grid { grid-template-columns: 1fr; } }
            </style>

            <div class="stats-grid">
                <a href="admin_security_guards.php" class="stat-card">
                    <span class="stat-label">Total Guards</span>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <div class="stat-icon">👥</div>
                </a>
                <a href="agency_maintenance.php?tab=assignments" class="stat-card">
                    <span class="stat-label">Security Agencies</span>
                    <div class="stat-value"><?php echo $agencies_count; ?></div>
                    <div class="stat-icon">🛡️</div>
                </a>
                <a href="admin_total_clients.php" class="stat-card">
                    <span class="stat-label">Total Clients</span>
                    <div class="stat-value"><?php echo $clients_count; ?></div>
                    <div class="stat-icon">💼</div>
                </a>
            </div>

            <div class="lists-grid">
                <div class="card">
                    <div class="card-header"><h3>Recent Agencies</h3></div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Agency Name</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($agencies_list && $agencies_list->num_rows > 0): ?>
                                    <?php while($row = $agencies_list->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="text-muted">#<?php echo $row['id']; ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                            <td><span class="status-badge status-success">Active</span></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="empty-state">No agencies found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Recent Clients</h3></div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Client Name</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($clients_list && $clients_list->num_rows > 0): ?>
                                    <?php while($row = $clients_list->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="text-muted">#<?php echo $row['id']; ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                            <td><span class="status-badge status-success">Active</span></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="empty-state">No clients found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Login Logs Section -->
            <div class="card" style="margin-top: 32px;">
                <div class="card-header"><h3>Recent Login Activity</h3></div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>IP Address</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_logins && $recent_logins->num_rows > 0): ?>
                                <?php while($log = $recent_logins->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($log['username']); ?></strong></td>
                                        <td style="color: #64748b; font-family: monospace;"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($log['timestamp'])); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $log['status'] === 'SUCCESS' ? 'status-success' : 'status-danger'; ?>">
                                                <?php echo htmlspecialchars($log['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="empty-state">No recent login activity found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

<?php include 'admin_layout/footer.php'; ?>
