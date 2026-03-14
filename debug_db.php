<?php
require_once 'db_config.php';

function checkTable($conn, $table) {
    echo "--- Checking Table: $table ---\n";
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    if ($res->num_rows == 0) {
        echo "Table '$table' DOES NOT EXIST.\n";
        return;
    }
    $res = $conn->query("DESCRIBE $table");
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    echo "\n";
}

header('Content-Type: text/plain');
checkTable($conn, 'agency_clients');
checkTable($conn, 'shifts');
checkTable($conn, 'checkpoints');
checkTable($conn, 'tour_assignments');
checkTable($conn, 'guards');
checkTable($conn, 'incidents');
checkTable($conn, 'users');

// unlink(__FILE__);
?>
