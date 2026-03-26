<?php
require_once 'db_config.php';

echo "<h2>Checking Scans Data</h2>";
$sql = "SELECT id, scan_time, status, photo_path, justification_photo_path FROM scans ORDER BY scan_time DESC LIMIT 50";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Scan Time</th><th>Status</th><th>Photo Path</th><th>Justification Photo Path</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['scan_time'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . ($row['photo_path'] ? "✅ HAS PHOTO" : "❌ EMPTY") . " (" . htmlspecialchars($row['photo_path']) . ")</td>";
        echo "<td>" . ($row['justification_photo_path'] ? "✅ HAS JUSTIFICATION" : "❌ EMPTY") . " (" . htmlspecialchars($row['justification_photo_path']) . ")</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No scans found or query error.";
}
?>
