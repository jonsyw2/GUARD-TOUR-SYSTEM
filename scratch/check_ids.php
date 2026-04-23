<?php
require_once 'db_config.php';
$res = $conn->query("SELECT id, client_id, qr_limit FROM agency_clients WHERE id IN (12, 13)");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
