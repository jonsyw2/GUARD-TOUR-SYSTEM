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
    $new_qr_limit = (int)$_POST['qr_limit'];
    $new_site_limit = (int)$_POST['site_limit'];
    $override = isset($_POST['qr_override']) ? 1 : 0;
    $disabled = isset($_POST['is_disabled']) ? 1 : 0;
    $visual_locked = isset($_POST['is_visual_locked']) ? 1 : 0;

    // Sync limits across ALL mappings for this client under this agency
    $update_sql = "UPDATE agency_clients SET 
                   qr_limit = $new_qr_limit,
                   client_limit = $new_site_limit, 
                   qr_override = $override, 
                   is_disabled = $disabled,
                   is_visual_locked = $visual_locked
                   WHERE client_id = (SELECT client_id FROM (SELECT client_id FROM agency_clients WHERE id = $mapping_id) as t)";
    
    if ($conn->query($update_sql) === TRUE) {
        $message = "Configuration updated successfully.";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error updating configuration: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Fetch agency-client mappings with pooled checkpoint counts, grouped by organization
$mapping_sql = "
    SELECT 
        c.id as client_id,
        a.id as agency_id,
        a.username as agency_username, 
        a.agency_name,
        c.username AS client_username, 
        ac.company_name,
        ac.qr_limit, 
        ac.client_limit,
        ac.qr_override, 
        ac.is_disabled,
        ac.is_patrol_locked,
        ac.is_visual_locked,
        COUNT(ac.id) as site_count,
        GROUP_CONCAT(ac.id) as all_mapping_ids,
        (SELECT COUNT(*) FROM checkpoints WHERE is_zero_checkpoint = 0 AND agency_client_id IN (SELECT id FROM agency_clients WHERE client_id = c.id)) as total_qrs
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    GROUP BY c.id, a.id
    ORDER BY COALESCE(NULLIF(a.agency_name, ''), a.username) ASC, COALESCE(NULLIF(ac.company_name, ''), c.username) ASC
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
                                            // The usage is now pooled across sites
                                            $usage_percent = ($row['qr_limit'] > 0) ? ($row['total_qrs'] / $row['qr_limit']) * 100 : 0;
                                            $status_class = 'status-success';
                                            $status_text = 'ACTIVE';
                                            if ($row['is_disabled']) {
                                                $status_class = 'status-danger';
                                                $status_text = 'SUSPENDED';
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
                                            <td data-label="Security Agency">
                                                <div class="fw-bold"><?php echo htmlspecialchars($row['agency_name'] ?: $row['agency_username']); ?></div>
                                                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 400;">@<?php echo htmlspecialchars($row['agency_username']); ?></div>
                                            </td>
                                            <td data-label="Assigned Client">
                                                <div class="fw-bold"><?php echo htmlspecialchars($row['company_name'] ?: $row['client_username']); ?></div>
                                                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 400;">@<?php echo htmlspecialchars($row['client_username']); ?></div>
                                            </td>
                                            <td data-label="Current Usage">
                                                <div style="display: flex; flex-direction: column; gap: 6px; min-width: 140px;">
                                                    <span class="limit-pill" style="white-space: nowrap; font-weight: 700; color: #1e293b; background: #f1f5f9; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; border: 1px solid #e2e8f0; align-self: flex-start;">
                                                        <?php echo $row['total_qrs']; ?> / <?php echo $row['qr_limit']; ?> QRs
                                                    </span>
                                                    <div style="width: 100%; height: 6px; background: #f1f5f9; border-radius: 3px; border: 1px solid #e2e8f0; overflow: hidden;">
                                                        <div style="width: <?php echo min(100, $usage_percent); ?>%; height: 100%; background: <?php echo $usage_percent >= 90 ? '#ef4444' : ($usage_percent >= 70 ? '#f59e0b' : '#10b981'); ?>; transition: width 0.3s ease;"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                            </td>
                                            <td data-label="Configuration">
                                                <form action="manage_limits.php" method="POST" style="display: flex; gap: 12px; align-items: flex-end;">
                                                    <?php 
                                                        $mids = explode(',', $row['all_mapping_ids']);
                                                        $first_mid = $mids[0];
                                                    ?>
                                                    <input type="hidden" name="mapping_id" value="<?php echo $first_mid; ?>">
                                                    
                                                    <div class="form-group" style="margin-bottom: 0;">
                                                        <label class="form-label" style="font-size: 0.75rem;">QR Limit</label>
                                                        <input type="number" name="qr_limit" class="form-control" value="<?php echo $row['qr_limit']; ?>" min="0" required style="padding: 8px 12px; width: 65px;">
                                                    </div>
 
                                                    <div class="form-group" style="margin-bottom: 0;">
                                                        <label class="form-label" style="font-size: 0.75rem;">Site Limit</label>
                                                        <input type="number" name="site_limit" class="form-control" value="<?php echo $row['client_limit']; ?>" min="0" required style="padding: 8px 12px; width: 65px;">
                                                    </div>
                                                    
                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                        <label class="d-flex gap-2 align-items-center" style="font-size: 0.8rem; cursor: pointer; color: var(--text-muted);" title="Allow exceeding current QR limit">
                                                            <input type="checkbox" name="qr_override" <?php echo $row['qr_override'] ? 'checked' : ''; ?> style="accent-color: var(--primary);"> Override
                                                        </label>
                                                        <label class="d-flex gap-2 align-items-center" style="font-size: 0.8rem; cursor: pointer; color: var(--text-muted);" title="Disable QR scanning activities for this site">
                                                            <input type="checkbox" name="is_disabled" <?php echo $row['is_disabled'] ? 'checked' : ''; ?> style="accent-color: var(--primary);"> Disable
                                                        </label>
                                                    </div>
 
                                                    <div style="display: flex; flex-direction: column; gap: 4px; border-left: 1px solid #e2e8f0; padding-left: 12px;">
                                                        <label class="d-flex gap-2 align-items-center" style="font-size: 0.8rem; cursor: pointer; color: var(--text-muted);" title="Lock/Unlock Checkpoint Map locations">
                                                            <input type="checkbox" name="is_visual_locked" <?php echo $row['is_visual_locked'] ? 'checked' : ''; ?> style="accent-color: #f59e0b;"> Visual Lock
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
        
        <style>
            @media (max-width: 1024px) {
                .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            }
            @media (max-width: 768px) {
                tr { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 20px; padding: 16px; background: white; box-shadow: var(--shadow); }
                td { display: block; padding: 12px 0; border: none !important; width: 100% !important; border-bottom: 1px solid #f1f5f9 !important; }
                td:last-child { border-bottom: none !important; }
                td::before { content: attr(data-label); display: block; font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
                thead { display: none; }
                td[data-label="Configuration"] form { flex-direction: column !important; align-items: stretch !important; gap: 16px !important; }
                .form-control { width: 100% !important; }
                .btn { width: 100% !important; }
                .d-flex.gap-2 { justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
                div[style*="border-left"] { border-left: none !important; padding-left: 0 !important; }
            }
        </style>
    </main>

<?php include 'admin_layout/footer.php'; ?>
