<?php
require_once 'db_config.php';

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

// Fixed missing columns
addColumnSafely($conn, 'scans', 'tour_session_id', 'VARCHAR(100)', 'justification_photo_path');
addColumnSafely($conn, 'scans', 'shift', "VARCHAR(50) DEFAULT 'Day Shift'", 'tour_session_id');

echo "Migration complete.\n";
?>
