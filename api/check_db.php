<?php
require_once '../db_config.php';
header('Content-Type: application/json');

$mapping_id = $_GET['mapping_id'] ?? 6;

$conn = new mysqli($host, $user, $password, $dbname);

$res = [];

// 1. Check Site
$site = $conn->query("SELECT * FROM agency_clients WHERE id = $mapping_id")->fetch_assoc();
$res['site_info'] = $site;

// 2. Count Checkpoints for this site
$cp_count = $conn->query("SELECT COUNT(*) as count FROM checkpoints WHERE agency_client_id = $mapping_id")->fetch_assoc();
$res['checkpoint_count'] = $cp_count['count'];

// 3. Sample Checkpoints
$cps = [];
$cp_res = $conn->query("SELECT id, name, agency_client_id, visual_pos_x, visual_pos_y FROM checkpoints WHERE agency_client_id = $mapping_id LIMIT 5");
while($row = $cp_res->fetch_assoc()) $cps[] = $row;
$res['sample_checkpoints'] = $cps;

// 4. Check Scans for Site 6
$scan_count = $conn->query("SELECT COUNT(*) as count FROM scans s JOIN checkpoints c ON s.checkpoint_id = c.id WHERE c.agency_client_id = $mapping_id")->fetch_assoc();
$res['scan_count'] = $scan_count['count'];

echo json_encode($res, JSON_PRETTY_PRINT);
?>
