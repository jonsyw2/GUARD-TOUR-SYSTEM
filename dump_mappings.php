<?php
require_once 'db_config.php';
$mapping_sql = "
    SELECT ac.id, a.username AS agency_name, c.username AS client_name, ac.created_at
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    ORDER BY a.username ASC, c.username ASC
";
$res = $conn->query($mapping_sql);
$rows = [];
while($row = $res->fetch_assoc()) {
    $rows[] = "ID: " . $row['id'] . " | Agency: " . $row['agency_name'] . " | Client: " . $row['client_name'];
}
file_put_contents('mapping_dump.txt', implode("\n", $rows));
?>
