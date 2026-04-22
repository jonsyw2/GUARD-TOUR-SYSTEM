<?php
// api/cleanup_retention.php
// This script purges old patrol history and images based on admin settings.
// Can be run as a cron job or manually from the admin panel.

require_once '../db_config.php';

// Security: If not CLI and not manual trigger from admin, reject.
// In a real environment, you might use a secret key for the cron job.
$is_cli = (PHP_SAPI === 'cli');
$is_manual = (isset($_GET['manual']) && $_GET['manual'] == '1');

if (!$is_cli && !$is_manual) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

// Fetch settings
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$retention_history = (int)($settings['retention_days_history'] ?? 0);
$retention_images = (int)($settings['retention_days_images'] ?? 0);

$history_deleted = 0;
$images_purged = 0;

// 1. Cleanup History (Records and associated files)
if ($retention_history > 0) {
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$retention_history days"));
    
    // Find records to delete to also delete their files
    $find_sql = "SELECT photo_path, justification_photo_path FROM scans WHERE scan_time < '$cutoff_date'";
    $find_res = $conn->query($find_sql);
    
    if ($find_res) {
        while ($row = $find_res->fetch_assoc()) {
            // Delete local files if they exist
            foreach (['photo_path', 'justification_photo_path'] as $col) {
                if (!empty($row[$col]) && strpos($row[$col], 'http') === false) {
                    $file_path = '../' . $row[$col];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                        $images_purged++;
                    }
                }
            }
        }
    }
    
    // Delete the database records
    $del_sql = "DELETE FROM scans WHERE scan_time < '$cutoff_date'";
    if ($conn->query($del_sql)) {
        $history_deleted = $conn->affected_rows;
    }
}

// 2. Cleanup Images only (Keep records but purge files)
// This applies to records that are NOT deleted by the history retention policy
// but ARE older than the image retention policy.
if ($retention_images > 0) {
    $cutoff_date_images = date('Y-m-d H:i:s', strtotime("-$retention_images days"));
    
    // Find records that still have images and are past the image cutoff
    $find_img_sql = "SELECT id, photo_path, justification_photo_path FROM scans 
                     WHERE scan_time < '$cutoff_date_images' 
                     AND (photo_path IS NOT NULL OR justification_photo_path IS NOT NULL)";
    $find_img_res = $conn->query($find_img_sql);
    
    if ($find_img_res) {
        while ($row = $find_img_res->fetch_assoc()) {
            $updated = false;
            $id = $row['id'];
            
            foreach (['photo_path', 'justification_photo_path'] as $col) {
                if (!empty($row[$col])) {
                    // If local file, delete it
                    if (strpos($row[$col], 'http') === false) {
                        $file_path = '../' . $row[$col];
                        if (file_exists($file_path)) {
                            @unlink($file_path);
                        }
                    }
                    $images_purged++;
                    $updated = true;
                }
            }
            
            if ($updated) {
                $conn->query("UPDATE scans SET photo_path = NULL, justification_photo_path = NULL WHERE id = $id");
            }
        }
    }
}

$response = [
    'status' => 'success',
    'history_deleted' => $history_deleted,
    'images_purged' => $images_purged,
    'timestamp' => date('Y-m-d H:i:s')
];

// Update last run time
$conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_cleanup_run', '" . time() . "') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

if ($is_cli) {
    echo "Retention Cleanup Report:\n";
    echo " - History records deleted: $history_deleted\n";
    echo " - Images purged/unlinked: $images_purged\n";
    echo " - Cutoff History: " . ($retention_history > 0 ? "$retention_history days" : "Infinite") . "\n";
    echo " - Cutoff Images: " . ($retention_images > 0 ? "$retention_images days" : "Infinite") . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($response);
}
?>
