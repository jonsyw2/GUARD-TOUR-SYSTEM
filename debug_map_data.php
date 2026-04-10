<?php
require_once 'db_config.php';
$conn = new mysqli($host, $user, $password, $dbname);

echo "<h1>Debug Map Data</h1>";

$mapping_id = $_GET['mapping_id'] ?? 6;
$tour_session_id = $_GET['tour_session_id'] ?? '';

echo "Mapping ID: $mapping_id<br>";
echo "Tour Session ID: $tour_session_id<br><hr>";

// Checkpoints for this site
$cp_res = $conn->query("SELECT id, name, visual_pos_x, visual_pos_y, agency_client_id FROM checkpoints WHERE agency_client_id = $mapping_id");
echo "<h3>Checkpoints for Site $mapping_id:</h3>";
if ($cp_res && $cp_res->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>X</th><th>Y</th></tr>";
    while($row = $cp_res->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['visual_pos_x']}</td><td>{$row['visual_pos_y']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "No checkpoints found for site $mapping_id.<br>";
}

// Scans for this tour
if ($tour_session_id) {
    echo "<h3>Scans for Tour $tour_session_id:</h3>";
    $s_res = $conn->query("SELECT id, checkpoint_id, status, scan_time FROM scans WHERE tour_session_id = '$tour_session_id'");
    if ($s_res && $s_res->num_rows > 0) {
        echo "<table border='1'><tr><th>ID</th><th>CP ID</th><th>Status</th><th>Time</th></tr>";
        while($row = $s_res->fetch_assoc()) {
            echo "<tr><td>{$row['id']}</td><td>{$row['checkpoint_id']}</td><td>{$row['status']}</td><td>{$row['scan_time']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "No scans found for this tour session.<br>";
    }
}
?>
