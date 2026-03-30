<?php
require_once 'db_config.php';
$res = $conn->query("SELECT tour_session_id, scan_time, guard_id FROM scans ORDER BY id DESC LIMIT 10");
$rows = [];
while($row = $res->fetch_assoc()) $rows[] = $row;
header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT);
?>
