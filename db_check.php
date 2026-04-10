<?php
require_once 'db_config.php'; // Assuming db_config.php exists in GUARD-TOUR-SYSTEM

$output = "--- WEB PORTAL DIAGNOSTIC ---\n";

// Check Site 17
$res = $conn->query("SELECT id, site_name, agency_id, client_id FROM agency_clients WHERE id = 17");
if ($row = $res->fetch_assoc()) {
    $output .= "Site 17: Name={$row['site_name']}, Agency={$row['agency_id']}, Client={$row['client_id']}\n";
    
    // Check checkpoints
    $cpRes = $conn->query("SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = 17");
    $cpRow = $cpRes->fetch_row();
    $output .= "  Checkpoints for Site 17: {$cpRow[0]}\n";
} else {
    $output .= "Site 17 NOT FOUND!\n";
}

// Check other sites for this agency
if (isset($row['agency_id'])) {
    $aid = $row['agency_id'];
    $out .= "Other Sites for Agency $aid:\n";
    $res = $conn->query("SELECT id, site_name FROM agency_clients WHERE agency_id = $aid");
    while($r = $res->fetch_assoc()) {
        $cRes = $conn->query("SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = {$r['id']}");
        $cRow = $cRes->fetch_row();
        $output .= " - [{$r['id']}] {$r['site_name']} (Checkpoints: {$cRow[0]})\n";
    }
}

file_put_contents('web_db_dump.txt', $output);
?>
