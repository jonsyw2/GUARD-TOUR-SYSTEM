<?php
require_once 'db_config.php';

echo "--- Debug Info ---\n";

// Check users
$users = $conn->query("SELECT id, username, user_level FROM users WHERE user_level = 'client'");
echo "Clients in 'users' table:\n";
while ($u = $users->fetch_assoc()) {
    echo "ID: {$u['id']}, Username: {$u['username']}\n";
    
    // Check mappings for this client
    $mappings = $conn->query("SELECT * FROM agency_clients WHERE client_id = {$u['id']}");
    if ($mappings->num_rows > 0) {
        while ($m = $mappings->fetch_assoc()) {
            echo "  - Mapping ID: {$m['id']}, Agency ID: {$m['agency_id']}, Site: {$m['site_name']}\n";
        }
    } else {
        echo "  - NO MAPPINGS FOUND\n";
    }
}

echo "\n--- agency_clients table dump (first 20) ---\n";
$all_mappings = $conn->query("SELECT * FROM agency_clients LIMIT 20");
while ($m = $all_mappings->fetch_assoc()) {
    echo "ID: {$m['id']}, Agency: {$m['agency_id']}, Client: {$m['client_id']}, Site: {$m['site_name']}\n";
}
?>
