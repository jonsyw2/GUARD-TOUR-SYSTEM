<?php
require 'db_config.php';
$res = $conn->query('DESCRIBE checkpoints');
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
?>
