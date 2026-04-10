<?php
require_once 'db_config.php';
$conn = new mysqli($host, $user, $password, $dbname);

$mapping_id = 8;
$res = $conn->query("SELECT id, name, visual_pos_x, visual_pos_y, agency_client_id FROM checkpoints WHERE agency_client_id = $mapping_id");

echo "<h3>Checkpoints for Site $mapping_id</h3>";
if ($res && $res->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>X</th><th>Y</th></tr>";
    while($row = $res->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['visual_pos_x']}</td><td>{$row['visual_pos_y']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "No checkpoints found for mapping_id $mapping_id";
}

$tour_id = 'Patrol Tour Cycle started at 03:05 PM'; // Example from screenshot
// Let's see some scans
$s_res = $conn->query("SELECT s.id, s.checkpoint_id, c.name FROM scans s JOIN checkpoints c ON s.checkpoint_id = c.id LIMIT 5");
echo "<h3>Sample Scans for debugging:</h3>";
while($row = $s_res->fetch_assoc()) {
    print_r($row); echo "<br>";
}
?>
