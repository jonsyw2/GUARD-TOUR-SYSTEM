<?php
include 'db_config.php';

// Add photo_path if not exists
$conn->query("ALTER TABLE inspector_scans ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) DEFAULT NULL AFTER remarks");
// Add checkpoint_id if not exists
$conn->query("ALTER TABLE inspector_scans ADD COLUMN IF NOT EXISTS checkpoint_id INT DEFAULT NULL AFTER agency_client_id");

// Also add INDEX for faster lookup
$conn->query("ALTER TABLE inspector_scans ADD INDEX IF NOT EXISTS (checkpoint_id)");

echo "Migration Successful!";
?>
