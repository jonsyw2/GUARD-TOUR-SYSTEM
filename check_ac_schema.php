<?php
require_once 'db_config.php';
$res = $conn->query("DESCRIBE agency_clients");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
unlink(__FILE__);
?>
