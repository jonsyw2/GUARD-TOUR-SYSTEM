<?php
require_once 'db_config.php';
$res = $conn->query("SHOW FULL COLUMNS FROM agency_clients");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'] . " | " . $row['Type'] . " | " . $row['Key'] . " | " . $row['Extra'];
}
file_put_contents('schema_dump_full.txt', implode("\n", $cols));
?>
