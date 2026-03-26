<?php
require_once 'db_config.php';
$table = 'agency_clients';
$result = $conn->query("DESCRIBE $table");
if ($result) {
    echo "Columns in $table:\n";
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error describing $table: " . $conn->error . "\n";
}

$result = $conn->query("SHOW TABLES");
echo "\nAll tables:\n";
while ($row = $result->fetch_array()) {
    echo $row[0] . "\n";
}
?>
