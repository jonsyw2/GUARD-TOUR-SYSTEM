<?php
require_once 'auth_check.php';

echo "<h2>Diagnostic Information</h2>";
echo "<p><strong>Current User ID (Session):</strong> " . ($_SESSION['user_id'] ?? 'Not set') . "</p>";
echo "<p><strong>Current Username:</strong> " . ($_SESSION['username'] ?? 'Not set') . "</p>";

// 1. Check user record in users table
$user_id = $_SESSION['user_id'] ?? 0;
$user_res = $conn->query("SELECT * FROM users WHERE id = $user_id");
if ($user_res && $u = $user_res->fetch_assoc()) {
    echo "<h3>1. User Record Found:</h3>";
    echo "<pre>"; print_r($u); echo "</pre>";
} else {
    echo "<h3>1. User Record NOT FOUND in `users` table for ID $user_id.</h3>";
}

// 2. Check for ANY user named client1 (just in case ID changed)
$username = $_SESSION['username'] ?? '';
$u_check = $conn->query("SELECT * FROM users WHERE username = '$username'");
if ($u_check && $uc = $u_check->fetch_assoc()) {
    echo "<h3>2. User Record by Username '$username':</h3>";
    echo "<pre>"; print_r($uc); echo "</pre>";
    $real_id = $uc['id'];
} else {
    echo "<h3>2. NO USER found with username '$username'.</h3>";
    $real_id = 0;
}

// 3. Check agency_clients mapping
echo "<h3>3. Checking `agency_clients` mappings:</h3>";
$q = "SELECT * FROM agency_clients";
$res = $conn->query($q);
if ($res) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>Agency ID</th><th>Client ID</th><th>Company Name</th></tr>";
    while ($row = $res->fetch_assoc()) {
        $style = ($row['client_id'] == $user_id) ? "style='background-color:#dcfce7; font-weight:bold;'" : "";
        echo "<tr $style>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['agency_id']}</td>";
        echo "<td>{$row['client_id']}</td>";
        echo "<td>" . ($row['company_name'] ?: '---') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>Error querying `agency_clients`: " . $conn->error . "</p>";
}

// 4. Case-sensitivity test
echo "<h3>4. Case-sensitivity Test:</h3>";
$tables = ['agency_clients', 'Agency_Clients', 'AGENCY_CLIENTS'];
foreach ($tables as $t) {
    $test = $conn->query("SELECT COUNT(*) FROM $t");
    if ($test) {
        echo "<p style='color:green;'>Table `$t` EXISTS.</p>";
    } else {
        echo "<p style='color:red;'>Table `$t` DOES NOT exist.</p>";
    }
}
?>
