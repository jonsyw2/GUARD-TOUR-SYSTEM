<?php
include 'db_config.php';
$res = $conn->query("DESCRIBE agency_clients");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . PHP_EOL;
}
?>
