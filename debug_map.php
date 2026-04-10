<?php
// error_reporting(E_ALL); ini_set('display_errors', 1);
require_once 'db_config.php';

echo "<h2>Sentinel Tour Diagnostic Tool</h2>";

// $conn is already created in db_config.php
if (!isset($conn) || $conn->connect_error) {
    die("Connection failed: Database connection not initialized in db_config.php");
}

$mapping_id = isset($_GET['mapping_id']) ? (int)$_GET['mapping_id'] : 6;

echo "<h3>1. Checking Site ID: $mapping_id</h3>";
$site_res = $conn->query("SELECT id, site_name, company_name FROM agency_clients WHERE id = $mapping_id");
if ($site_res && $site_res->num_rows > 0) {
    $site = $site_res->fetch_assoc();
    echo "✅ Site Found: <b>" . $site['site_name'] . "</b> (Company: " . $site['company_name'] . ")<br>";
} else {
    echo "❌ Site ID $mapping_id NOT FOUND in agency_clients table.<br>";
}

echo "<h3>2. Checking Checkpoints for Site $mapping_id</h3>";
$cp_res = $conn->query("SELECT COUNT(*) as count FROM checkpoints WHERE agency_client_id = $mapping_id");
$cp_data = $cp_res->fetch_assoc();
echo "Total Checkpoints in Database: <b>" . $cp_data['count'] . "</b><br>";

if ($cp_data['count'] > 0) {
    echo "<h4>First 5 Checkpoints:</h4><ul>";
    $list_res = $conn->query("SELECT id, name, visual_pos_x, visual_pos_y FROM checkpoints WHERE agency_client_id = $mapping_id LIMIT 5");
    while($row = $list_res->fetch_assoc()) {
        $pos = "(" . $row['visual_pos_x'] . ", " . $row['visual_pos_y'] . ")";
        echo "<li>ID: " . $row['id'] . " - " . $row['name'] . " - Visual Position: $pos</li>";
    }
    echo "</ul>";
}

echo "<h3>3. Available Sites (Mappings)</h3><ul>";
$all_sites = $conn->query("SELECT id, site_name FROM agency_clients LIMIT 20");
while($s = $all_sites->fetch_assoc()) {
    echo "<li>ID: <b>" . $s['id'] . "</b> - " . $s['site_name'] . " <a href='?mapping_id=" . $s['id'] . "'>[Check This Site]</a></li>";
}
echo "</ul>";

$conn->close();
?>
