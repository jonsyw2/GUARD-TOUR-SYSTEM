<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';

// Auto-create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retention_days_history', '0')");
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retention_days_images', '0')");

// Handle Settings Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_settings'])) {
    $history_days = (int)$_POST['retention_days_history'];
    $image_days = (int)$_POST['retention_days_images'];

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('retention_days_history', ?), ('retention_days_images', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    
    // We need to run them separately if the above doesn't work well with multiple values in this specific driver
    $success = true;
    $res1 = $conn->query("UPDATE system_settings SET setting_value = '$history_days' WHERE setting_key = 'retention_days_history'");
    $res2 = $conn->query("UPDATE system_settings SET setting_value = '$image_days' WHERE setting_key = 'retention_days_images'");

    if ($res1 && $res2) {
        $message = "Retention settings updated successfully.";
        $message_type = "success";
    } else {
        $message = "Error updating settings: " . $conn->error;
        $message_type = "error";
    }
}

// Fetch Current Settings
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$history_val = $settings['retention_days_history'] ?? 0;
$image_val = $settings['retention_days_images'] ?? 0;
$last_run = $settings['last_cleanup_run'] ?? 0;
$last_run_text = $last_run ? date('M d, Y h:i A', $last_run) : 'Never';

// Fetch Statistics
$total_scans = $conn->query("SELECT COUNT(*) FROM scans")->fetch_row()[0] ?? 0;
$total_images = $conn->query("SELECT COUNT(*) FROM scans WHERE photo_path IS NOT NULL OR justification_photo_path IS NOT NULL")->fetch_row()[0] ?? 0;
$old_scans = $conn->query("SELECT COUNT(*) FROM scans WHERE scan_time < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_row()[0] ?? 0;

?>
<?php
$page_title = 'Data Retention';
$header_title = 'Data Retention Management';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

<main class="main-content">
    <?php include 'admin_layout/topbar.php'; ?>

    <div class="content-area">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✅' : '❌'; ?></span>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-bottom: 32px;">
            <div class="card" style="margin-bottom: 0;">
                <div class="card-body" style="padding: 24px;">
                    <div class="text-muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">Total Patrol Logs</div>
                    <div style="font-size: 1.8rem; font-weight: 800;"><?php echo number_format($total_scans); ?></div>
                </div>
            </div>
            <div class="card" style="margin-bottom: 0;">
                <div class="card-body" style="padding: 24px;">
                    <div class="text-muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">Logs with Images</div>
                    <div style="font-size: 1.8rem; font-weight: 800;"><?php echo number_format($total_images); ?></div>
                </div>
            </div>
            <div class="card" style="margin-bottom: 0;">
                <div class="card-body" style="padding: 24px;">
                    <div class="text-muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">Logs > 30 Days Old</div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: var(--danger);"><?php echo number_format($old_scans); ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Retention Configuration</h3>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 24px;">Configure how long the system should retain patrol data. Records older than these limits will be automatically purged to optimize performance and storage.</p>
                
                <form action="admin_retention.php" method="POST" class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Patrol History Retention (Days)</label>
                        <input type="number" name="retention_days_history" class="form-control" value="<?php echo $history_val; ?>" min="0" required>
                        <p style="font-size: 0.75rem; color: #94a3b8; mt-2">Number of days to keep patrol logs. Set to <strong>0</strong> to keep forever.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Patrol Images Retention (Days)</label>
                        <input type="number" name="retention_days_images" class="form-control" value="<?php echo $image_val; ?>" min="0" required>
                        <p style="font-size: 0.75rem; color: #94a3b8; mt-2">Number of days to keep actual image files. Set to <strong>0</strong> to keep forever.</p>
                    </div>

                    <div style="grid-column: span 2; display: flex; justify-content: flex-end; gap: 16px; border-top: 1px solid var(--border); padding-top: 24px; margin-top: 8px;">
                        <button type="button" class="btn btn-danger" style="width: auto;" onclick="confirmCleanup()">
                            <span>🗑️</span> Run Cleanup Now
                        </button>
                        <button type="submit" name="save_settings" class="btn btn-primary" style="width: auto; padding-left: 40px; padding-right: 40px;">
                            Save Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</main>

<!-- Confirmation Modal -->
<div id="cleanupModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-icon">⚠️</div>
        <h3 style="margin-bottom: 12px;">Confirm Manual Cleanup</h3>
        <p class="text-muted" style="margin-bottom: 24px;">This will immediately delete all patrol history and images older than your current settings. This action <strong>cannot be undone</strong>.</p>
        <div style="display: flex; gap: 12px;">
            <button class="btn btn-secondary" style="flex: 1; background: #f1f5f9; color: #475569;" onclick="closeCleanupModal()">Cancel</button>
            <button class="btn btn-primary" style="flex: 1; background: var(--danger);" onclick="runCleanup()">Yes, Purge Data</button>
        </div>
    </div>
</div>

<script>
function confirmCleanup() {
    document.getElementById('cleanupModal').classList.add('show');
}
function closeCleanupModal() {
    document.getElementById('cleanupModal').classList.remove('show');
}
async function runCleanup() {
    const btn = event.target;
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = 'Purging...';
    
    try {
        const response = await fetch('api/cleanup_retention.php?manual=1');
        const res = await response.json();
        
        if (res.status === 'success') {
            alert('Cleanup completed!\nHistory deleted: ' + res.history_deleted + '\nImages purged: ' + res.images_purged);
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    } catch (err) {
        alert('Network Error: Could not reach cleanup script.');
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
        closeCleanupModal();
    }
}
</script>

<?php include 'admin_layout/footer.php'; ?>
