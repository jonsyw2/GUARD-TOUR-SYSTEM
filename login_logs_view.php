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
<?php
$page_title = 'Login Logs';
$header_title = 'System Login Logs';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="content-area">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3>Recent Authentication Attempts</h3>
                    <div class="d-flex gap-2">
                        <span class="status-badge status-success"><?php echo $logs_result->num_rows; ?> Entries Found</span>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Identity Attempted</th>
                                <th>Network Address (IP)</th>
                                <th>Access Status</th>
                                <th>Device / Browser (User Agent)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($logs_result && $logs_result->num_rows > 0): ?>
                                <?php while($row = $logs_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex flex-direction-column">
                                                <span style="font-weight: 600;"><?php echo date('M d, Y', strtotime($row['timestamp'])); ?></span>
                                                <span class="text-muted" style="font-size: 0.8rem;"><?php echo date('h:i A', strtotime($row['timestamp'])); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div style="width: 32px; height: 32px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--primary); font-size: 0.8rem;">
                                                    <?php echo strtoupper(substr($row['username'], 0, 1)); ?>
                                                </div>
                                                <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; color: var(--primary); font-weight: 500;">
                                                <?php echo htmlspecialchars($row['ip_address']); ?>
                                            </code>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo strtolower($row['status']) === 'success' ? 'status-success' : 'status-danger'; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="user-agent-pill" title="<?php echo htmlspecialchars($row['user_agent']); ?>">
                                                <?php echo htmlspecialchars($row['user_agent']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="empty-state">No authentication logs recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <style>
            .user-agent-pill {
                display: block;
                max-width: 250px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-size: 0.8rem;
                color: var(--text-muted);
                background: #f8fafc;
                padding: 4px 10px;
                border-radius: 6px;
                border: 1px solid var(--border);
            }
            .status-danger {
                background: #fee2e2;
                color: #b91c1c;
            }
        </style>
    </main>

<?php include 'admin_layout/footer.php'; ?>
