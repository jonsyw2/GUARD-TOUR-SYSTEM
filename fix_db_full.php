<?php
require_once 'db_config.php';

// Fix scans table: Ensure id is PRIMARY KEY and AUTO_INCREMENT
$res = $conn->query("SHOW CREATE TABLE scans");
if ($res) {
    $row = $res->fetch_assoc();
    $create_table = $row['Create Table'];
    if (strpos($create_table, 'PRIMARY KEY') === false) {
        // Need to add primary key and auto_increment
        // First, drop the column if it's messy, or just modify it
        if ($conn->query("ALTER TABLE scans MODIFY id INT AUTO_INCREMENT PRIMARY KEY")) {
            echo "Modified scans.id to PRIMARY KEY AUTO_INCREMENT.\n";
        } else {
            echo "Error modifying scans.id: " . $conn->error . "\n";
        }
    } else {
        echo "scans.id already has PRIMARY KEY.\n";
    }
}

// Add tour_session_id if missing (double-check from before)
function addColumnSafely($conn, $table, $column, $definition, $after = '') {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res && $res->num_rows == 0) {
        $afterClause = $after ? " AFTER `$after`" : "";
        if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition $afterClause")) {
            echo "Added column $column to $table.\n";
        } else {
            echo "Error adding column $column to $table: " . $conn->error . "\n";
        }
    } else {
        echo "Column $column already exists in $table.\n";
    }
}

addColumnSafely($conn, 'scans', 'tour_session_id', 'VARCHAR(100)', 'justification_photo_path');
addColumnSafely($conn, 'scans', 'shift', "VARCHAR(50) DEFAULT 'Day Shift'", 'tour_session_id');

echo "Database fix complete.\n";
?>
