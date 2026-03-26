<?php
require 'db_config.php';
$res = $conn->query('SELECT * FROM agency_clients');
if (!$res) {
    echo "ERROR: " . $conn->error . "\n";
} else if ($res->num_rows == 0) {
    echo "TABLE agency_clients IS EMPTY\n";
} else {
    while($row = $res->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Agency: " . $row['agency_id'] . " | Client: " . $row['client_id'] . " | Site: " . $row['site_name'] . "\n";
    }
}
?>
