<?php
require_once 'db_config.php';
$res = $conn->query("DESCRIBE agency_clients");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'] . " (" . $row['Type'] . ")";
}
file_put_contents('schema_dump.txt', implode("\n", $cols));
?>
