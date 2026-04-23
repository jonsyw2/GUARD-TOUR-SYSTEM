<?php
$conn = new mysqli('127.0.0.1', 'root', '', 'ojt');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

echo "--- AGENCY CLIENTS ---\n";
$res = $conn->query("SELECT id, client_id, agency_id, site_name, company_name, qr_limit FROM agency_clients WHERE site_name LIKE '%Main Factory%' OR company_name LIKE '%Acme Corp%'");
$mapping_ids = [];
while($row = $res->fetch_assoc()) {
    print_r($row);
    $mapping_ids[] = $row['id'];
}

if (empty($mapping_ids)) {
    echo "No mapping found.\n";
    exit;
}

foreach ($mapping_ids as $m_id) {
    echo "\n--- CHECKPOINTS FOR MAPPING $m_id ---\n";
    $res = $conn->query("SELECT id, name, checkpoint_code, is_zero_checkpoint, is_end_checkpoint FROM checkpoints WHERE agency_client_id = $m_id");
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }

    echo "\n--- TOUR ASSIGNMENTS FOR MAPPING $m_id ---\n";
    $res = $conn->query("SELECT ta.checkpoint_id, cp.name, ta.sort_order FROM tour_assignments ta JOIN checkpoints cp ON ta.checkpoint_id = cp.id WHERE ta.agency_client_id = $m_id ORDER BY ta.sort_order ASC");
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
}
?>
