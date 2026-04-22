<?php
require_once 'db_config.php';

$sql = "CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql)) {
    echo "Table system_settings created or already exists.\n";
    
    // Insert default values if not present
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retention_days_history', '0')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retention_days_images', '0')");
    echo "Default settings initialized.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}
?>
