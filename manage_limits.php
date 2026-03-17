<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';
$show_status_modal = false;

// Handle updating limit
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_limit'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $new_limit = (int)$_POST['qr_limit'];
    $override = isset($_POST['qr_override']) ? 1 : 0;
    $disabled = isset($_POST['is_disabled']) ? 1 : 0;

    $update_sql = "UPDATE agency_clients SET qr_limit = $new_limit, qr_override = $override, is_disabled = $disabled WHERE id = $mapping_id";
    
    if ($conn->query($update_sql) === TRUE) {
        $message = "QR Configuration updated successfully.";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error updating configuration: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Fetch agency-client mappings with checkpoint counts
$mapping_sql = "
    SELECT 
        ac.id, 
        a.username AS agency_name, 
        c.username AS client_name, 
        ac.qr_limit, 
        ac.qr_override, 
        ac.is_disabled,
        (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id AND (is_zero_checkpoint = 0 OR is_zero_checkpoint IS NULL) AND (is_end_checkpoint = 0 OR is_end_checkpoint IS NULL)) as current_qrs
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    ORDER BY a.username ASC, c.username ASC
";
$mappings_result = $conn->query($mapping_sql);

?>
<?php
$page_title = 'QR Checkpoint Limits';
$header_title = 'QR Checkpoint Configurations';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="content-area">

            <div class="card">
                <div class="card-header"><h3>Active QR Boundary Configurations</h3></div>
                <div class="card-body">
                    <p class="text-muted mb-4">Manage the maximum allowed QR checkpoints per assigned client, grant temporary overrides, or suspend QR creation entirely from this panel.</p>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Security Agency</th>
                                    <th>Assigned Client</th>
                                    <th>Current Usage</th>
                                    <th>Status</th>
                                    <th>Configuration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($mappings_result && $mappings_result->num_rows > 0): ?>
                                    <?php while($row = $mappings_result->fetch_assoc()): ?>
                                        <?php 
                                            $usage_percent = ($row['qr_limit'] > 0) ? ($row['current_qrs'] / $row['qr_limit']) * 100 : 0;
                                            $status_class = 'status-success';
                                            $status_text = 'ACTIVE';
                                            
                                            if ($row['is_disabled']) {
                                                $status_class = 'status-danger';
                                                $status_text = 'DISABLED';
                                            } else if ($row['qr_override']) {
                                                $status_class = 'status-warning';
                                                $status_text = 'OVERRIDE ON';
                                            } else if ($usage_percent >= 100) {
                                                $status_class = 'status-danger';
                                                $status_text = 'LIMIT REACHED';
                                            } else if ($usage_percent >= 80) {
                                                $status_class = 'status-warning';
                                                $status_text = 'NEAR LIMIT';
                                            }
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['agency_name']); ?></strong></td>
                                            <td><strong><?php echo htmlspecialchars($row['client_name']); ?></strong></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="limit-pill"><?php echo $row['current_qrs']; ?> / <?php echo $row['qr_limit']; ?> QRs</span>
                                                    <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; min-width: 80px;">
                                                        <div style="width: <?php echo min(100, $usage_percent); ?>%; height: 100%; background: <?php echo $usage_percent >= 90 ? 'var(--danger)' : ($usage_percent >= 70 ? 'var(--warning)' : 'var(--success)'); ?>; border-radius: 3px;"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                            </td>
                                            <td>
                                                <form action="manage_limits.php" method="POST" style="display: flex; gap: 12px; align-items: flex-end;">
                                                    <input type="hidden" name="mapping_id" value="<?php echo $row['id']; ?>">
                                                    
                                                    <div class="form-group" style="margin-bottom: 0;">
                                                        <label class="form-label" style="font-size: 0.75rem;">Limit</label>
                                                        <input type="number" name="qr_limit" class="form-control" value="<?php echo $row['qr_limit']; ?>" min="0" required style="padding: 8px 12px; width: 80px;">
                                                    </div>
                                                    
                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                        <label class="d-flex gap-2 align-items-center" style="font-size: 0.8rem; cursor: pointer; color: var(--text-muted);">
                                                            <input type="checkbox" name="qr_override" <?php echo $row['qr_override'] ? 'checked' : ''; ?> style="accent-color: var(--primary);"> Override
                                                        </label>
                                                        <label class="d-flex gap-2 align-items-center" style="font-size: 0.8rem; cursor: pointer; color: var(--text-muted);">
                                                            <input type="checkbox" name="is_disabled" <?php echo $row['is_disabled'] ? 'checked' : ''; ?> style="accent-color: var(--primary);"> Disable
                                                        </label>
                                                    </div>

                                                    <button type="submit" name="update_limit" class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem; width: auto;">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="empty-state">No client-agency mappings found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>

        <!-- Status Process Modal (Generic) -->
        <div id="statusModal" class="modal <?php echo $show_status_modal ? 'show' : ''; ?>">
            <div class="modal-content">
                <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                    <?php echo $message_type === 'success' ? '✓' : '!'; ?>
                </div>
                <h3 style="margin-bottom: 10px;"><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
                <p style="color: #6b7280; margin-bottom: 24px;"><?php echo $message; ?></p>
                <button class="btn btn-primary" style="width: 100%; border: none;" onclick="closeModal('statusModal')">Done</button>
            </div>
        </div>

        <style>
            /* Modal Styles */
            .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
            .modal.show { display: flex; }
            .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        </style>

        <script>
            function closeModal(modalId) {
                document.getElementById(modalId).classList.remove('show');
            }
        </script>
    </main>

<?php include 'admin_layout/footer.php'; ?>
