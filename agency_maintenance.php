<?php
require_once 'auth_check.php';

// AJAX Handler for Agency Quick Links
if (isset($_GET['ajax_agency_data'])) {
    $agency_id = isset($_GET['agency_id']) ? (int)$_GET['agency_id'] : 0;
    $type = $_GET['type'] ?? 'clients';
    $response = [];

    if ($type === 'clients') {
        $sql = "SELECT ac.id, c.username as name, ac.client_limit, ac.qr_limit, ac.guard_limit, ac.inspector_limit FROM agency_clients ac JOIN users c ON ac.client_id = c.id WHERE ac.agency_id = $agency_id ORDER BY c.username ASC";
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) $response[] = $r;
    } elseif ($type === 'update_client_limits') {
        $mapping_id = (int)$_GET['mapping_id'];
        $add_client = (int)($_GET['add_client'] ?? 0);
        $add_qr = (int)($_GET['add_qr'] ?? 0);
        $add_guards = (int)($_GET['add_guards'] ?? 0);
        $add_inspectors = (int)($_GET['add_inspectors'] ?? 0);

        $conn->begin_transaction();
        try {
            // 1. Get the agency_id from this mapping to update the agency pool as well
            $agency_res = $conn->query("SELECT agency_id FROM agency_clients WHERE id = $mapping_id");
            if (!$agency_res || $agency_res->num_rows === 0) {
                throw new Exception("Mapping ID $mapping_id not found.");
            }
            $target_agency_id = (int)$agency_res->fetch_assoc()['agency_id'];

            // 2. Update Client Site Limits (Specific Mapping)
            $sqlSite = "UPDATE agency_clients SET 
                    client_limit = COALESCE(client_limit, 0) + $add_client,
                    qr_limit = COALESCE(qr_limit, 0) + $add_qr, 
                    guard_limit = COALESCE(guard_limit, 0) + $add_guards, 
                    inspector_limit = COALESCE(inspector_limit, 0) + $add_inspectors 
                    WHERE id = $mapping_id";
            $conn->query($sqlSite);

            // 3. Sync with Agency Pooled Limits (Users Table)
            $sqlAgency = "UPDATE users SET 
                    client_limit = COALESCE(client_limit, 0) + $add_client,
                    qr_limit = COALESCE(qr_limit, 0) + $add_qr, 
                    guard_limit = COALESCE(guard_limit, 0) + $add_guards, 
                    inspector_limit = COALESCE(inspector_limit, 0) + $add_inspectors 
                    WHERE id = $target_agency_id";
            $conn->query($sqlAgency);

            $conn->commit();
            $response = ['success' => true, 'message' => 'Limits updated successfully!'];
        } catch (Exception $e) {
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    } elseif ($type === 'qr') {
        $sql = "SELECT ac.site_name, c.username as client_name, (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id) as qr_count FROM agency_clients ac JOIN users c ON ac.client_id = c.id WHERE ac.agency_id = $agency_id ORDER BY c.username ASC";
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) $response[] = $r;
    } elseif ($type === 'guards') {
        $sql = "SELECT g.name, GROUP_CONCAT(c.username SEPARATOR ', ') as assigned_clients FROM guards g LEFT JOIN guard_assignments ga ON g.id = ga.guard_id LEFT JOIN agency_clients ac ON ga.agency_client_id = ac.id LEFT JOIN users c ON ac.client_id = c.id WHERE g.agency_id = $agency_id GROUP BY g.id ORDER BY g.name ASC";
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) $response[] = $r;
    } elseif ($type === 'inspectors') {
        $sql = "SELECT id, name FROM inspectors WHERE agency_id = $agency_id ORDER BY name ASC";
        $res = $conn->query($sql);
        while ($r = $res->fetch_assoc()) $response[] = $r;
    } elseif ($type === 'inspector_info') {
        $inspector_id = (int)($_GET['inspector_id'] ?? 0);
        $sql = "SELECT i.*, u.username, i.created_at as registered_at, (SELECT COUNT(*) FROM inspector_assignments WHERE inspector_id = i.id) as assignments_count FROM inspectors i JOIN users u ON i.user_id = u.id WHERE i.id = $inspector_id";
        $res = $conn->query($sql);
        if ($res) {
            if ($res->num_rows > 0) {
                $response = $res->fetch_assoc();
            } else {
                header('HTTP/1.1 404 Not Found');
                $response = ['success' => false, 'message' => "Inspector ID $inspector_id not found in records."];
            }
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            $response = ['success' => false, 'message' => "Database Error: " . $conn->error];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Ensure limits and status columns exist in users table
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS qr_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS guard_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS inspector_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS supervisor_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS client_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_person VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_no VARCHAR(20) DEFAULT NULL");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS agency_name VARCHAR(255) DEFAULT NULL");

// Sync existing agency_name for legacy agency accounts
$conn->query("UPDATE users SET agency_name = username WHERE user_level = 'agency' AND (agency_name IS NULL OR agency_name = '')");

// Migration: Add limit columns to agency_clients if they don't exist
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS client_limit INT DEFAULT 0");
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS qr_limit INT DEFAULT 0");
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS guard_limit INT DEFAULT 0");
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS inspector_limit INT DEFAULT 0");
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS supervisor_limit INT DEFAULT 0");
// Auto-Migration: Add supervisor_id to agency_clients if missing
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS supervisor_id INT DEFAULT NULL");

// Auto-Migration: Create supervisors table
$conn->query("
    CREATE TABLE IF NOT EXISTS supervisors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        agency_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        contact_no VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (agency_id)
    ) ENGINE=InnoDB
");

// Auto-Migration: Create inspectors and assignments if missing (essential for Quick Links)
$conn->query("CREATE TABLE IF NOT EXISTS inspectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    agency_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (agency_id)
) ENGINE=InnoDB");

$conn->query("CREATE TABLE IF NOT EXISTS inspector_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspector_id INT NOT NULL,
    agency_client_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (inspector_id),
    INDEX (agency_client_id)
) ENGINE=InnoDB");

// Limits columns handled by ALTER TABLE ... IF NOT EXISTS

// Removed generateUniqueSupervisorKeyAdmin function as it is no longer used.

$message = '';
$message_type = '';
$show_status_modal = false;

// Handle updating agency details
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_agency_details'])) {
    $agency_id = (int)$_POST['agency_id'];
    $agency_name = $conn->real_escape_string($_POST['agency_name']);
    $address = $conn->real_escape_string($_POST['address']);
    $contact_person = $conn->real_escape_string($_POST['contact_person']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $status = $conn->real_escape_string($_POST['status'] ?? 'active');
    $client_limit = (int)($_POST['client_limit'] ?? 0);

    $sql = "UPDATE users SET 
            agency_name = '$agency_name',
            address = '$address',
            contact_person = '$contact_person',
            contact_no = '$contact_no',
            status = '$status',
            client_limit = $client_limit
            WHERE id = $agency_id";

    if ($conn->query($sql)) {
        $message = "Agency details updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error updating agency: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}



// Handle Add Agency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_agency'])) {
    $agency_name = $conn->real_escape_string($_POST['agency_name']);
    $username = $conn->real_escape_string($_POST['agency_username']);
    $password = password_hash($_POST['agency_password'], PASSWORD_DEFAULT);
    $client_limit = (int)$_POST['client_limit'];
    $address = $conn->real_escape_string($_POST['address']);
    $contact_person = $conn->real_escape_string($_POST['contact_person'] ?? '');
    $contact_no = $conn->real_escape_string($_POST['contact_no'] ?? '');
    
    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$username'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Agency username already exists!";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $sql = "INSERT INTO users (username, agency_name, password, user_level, client_limit, address, contact_person, contact_no) 
                VALUES ('$username', '$agency_name', '$password', 'agency', $client_limit, '$address', '$contact_person', '$contact_no')";
        if ($conn->query($sql) === TRUE) {
            $message = "Agency added successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } else {
            $message = "Error adding agency: " . $conn->error;
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle Delete Agency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_agency_action'])) {
    $agency_id = (int)$_POST['agency_id'];
    
    // 1. Check if it's an agency
    $check = $conn->query("SELECT id FROM users WHERE id = $agency_id AND user_level = 'agency'");
    if ($check && $check->num_rows > 0) {
        $conn->begin_transaction();
        try {
            // 2. Remove client assignments
            $conn->query("DELETE FROM agency_clients WHERE agency_id = $agency_id");
            
            // 3. Remove supervisors belonging to this agency
            // First get their user IDs to remove from users table
            $sups = $conn->query("SELECT user_id FROM supervisors WHERE agency_id = $agency_id");
            while($s = $sups->fetch_assoc()) {
                $uid = $s['user_id'];
                $conn->query("DELETE FROM users WHERE id = $uid");
            }
            $conn->query("DELETE FROM supervisors WHERE agency_id = $agency_id");

            // 4. Remove the agency user
            $conn->query("DELETE FROM users WHERE id = $agency_id");

            $conn->commit();
            $message = "Agency and all related assignments/supervisors deleted successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting agency: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    } else {
        $message = "Invalid agency ID.";
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Add Client

// Handle Add Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_client'])) {
    $client_username = $conn->real_escape_string($_POST['client_username']);
    $password = password_hash($_POST['client_password'], PASSWORD_DEFAULT);

    // Check if client username exists
    $checkSql = "SELECT id FROM users WHERE username = '$client_username'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Client username already exists!";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        if ($conn->query("INSERT INTO users (username, password, user_level) VALUES ('$client_username', '$password', 'client')")) {
            $message = "Client created successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } else {
            $message = "Error creating client: " . $conn->error;
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}


// Handle Delete Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_client'])) {
    $delete_id = (int)$_POST['delete_id'];
    if ($delete_id == $_SESSION['user_id']) {
        $message = "You cannot delete your own account.";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        if ($conn->query("DELETE FROM users WHERE id = $delete_id AND user_level = 'client'") === TRUE) {
            $message = "Client deleted successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } else {
            $message = "Error deleting client: " . $conn->error;
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle Add Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_supervisor'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $agency_id = $_POST['agency_id'];
    if ($agency_id === 'all') {
        $agency_id = 0;
    } else {
        $agency_id = (int)$agency_id;
    }
    $fullname = $conn->real_escape_string($_POST['fullname']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $assigned_clients = $_POST['assigned_clients'] ?? [];

    // Check if username already exists
    $user_check = $conn->query("SELECT id FROM users WHERE username = '$username'");
    if ($user_check && $user_check->num_rows > 0) {
        $message = "Creation failed: Username already exists.";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $conn->begin_transaction();
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // 1. Create User
            if (!$conn->query("INSERT INTO users (username, password, user_level) VALUES ('$username', '$hashed_password', 'supervisor')")) {
                throw new Exception("Error creating supervisor user: " . $conn->error);
            }
            $user_id = $conn->insert_id;

            // 2. Create Supervisor entry
            if (!$conn->query("INSERT INTO supervisors (user_id, agency_id, name, contact_no) VALUES ($user_id, $agency_id, '$fullname', '$contact_no')")) {
                throw new Exception("Error creating supervisor entry: " . $conn->error);
            }
            $supervisor_id = $conn->insert_id;

            // 3. Assign Clients
            if (!empty($assigned_clients)) {
                foreach ($assigned_clients as $client_id) {
                    $client_id = (int)$client_id;
                    $conn->query("UPDATE agency_clients SET supervisor_id = $supervisor_id WHERE (agency_id = $agency_id OR $agency_id = 0) AND client_id = $client_id");
                }
            }

            $conn->commit();
            $message = "Supervisor account created successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error creating supervisor: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle Update Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_supervisor'])) {
    $supervisor_id = (int)$_POST['edit_supervisor_id'];
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $agency_id = $_POST['agency_id'];
    if ($agency_id === 'all') {
        $agency_id = 0;
    } else {
        $agency_id = (int)$agency_id;
    }

    $fullname = $conn->real_escape_string($_POST['fullname']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $assigned_clients = $_POST['assigned_clients'] ?? [];

    $conn->begin_transaction();
    try {
        $fetch_user = $conn->query("SELECT user_id FROM supervisors WHERE id = $supervisor_id");
        if ($fetch_user && $fetch_user->num_rows > 0) {
            $user_id = $fetch_user->fetch_assoc()['user_id'];
            
            // Update user account
            $pw_sql = "";
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pw_sql = ", password = '$hashed'";
            }
            $conn->query("UPDATE users SET username = '$username' $pw_sql WHERE id = $user_id");

            // 1. Update Supervisor entry
            $conn->query("UPDATE supervisors SET name = '$fullname', contact_no = '$contact_no', agency_id = $agency_id WHERE id = $supervisor_id");

            // 2. Clear old assignments for this supervisor
            $conn->query("UPDATE agency_clients SET supervisor_id = NULL WHERE supervisor_id = $supervisor_id");

            // 3. Re-assign Clients
            if (!empty($assigned_clients)) {
                foreach ($assigned_clients as $client_id) {
                    $client_id = (int)$client_id;
                    $conn->query("UPDATE agency_clients SET supervisor_id = $supervisor_id WHERE (agency_id = $agency_id OR $agency_id = 0) AND client_id = $client_id");
                }
            }

            $conn->commit();
            $message = "Supervisor updated successfully!";
            $message_type = "success";
            $show_status_modal = true;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Delete Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_supervisor_action'])) {
    $supervisor_id = (int)$_POST['delete_supervisor_id'];
    
    $res = $conn->query("SELECT s.user_id, u.status, u.user_level FROM supervisors s JOIN users u ON s.user_id = u.id WHERE s.id = $supervisor_id");
    if ($res && $res->num_rows > 0) {
        $user = $res->fetch_assoc();
        
        // Check Account Status
        if (($user['status'] ?? 'active') === 'suspended' && $user['user_level'] === 'agency') {
            echo "<script>alert('Your account has been suspended. Please contact the administrator.'); window.location.href='login.php';</script>";
            exit();
        }
        $user_id = $user['user_id'];
        $conn->begin_transaction();
        try {
            // Clear assignments
            $conn->query("UPDATE agency_clients SET supervisor_id = NULL WHERE supervisor_id = $supervisor_id");
            // Delete supervisor and user
            $conn->query("DELETE FROM supervisors WHERE id = $supervisor_id");
            $conn->query("DELETE FROM users WHERE id = $user_id");
            $conn->commit();
            $message = "Supervisor account deleted successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting supervisor: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Fetch all agencies for dropdowns
$agencies_result = $conn->query("SELECT id, username, agency_name, qr_limit, guard_limit, inspector_limit, supervisor_limit, client_limit, address, contact_person, contact_no, status FROM users WHERE user_level = 'agency' ORDER BY agency_name ASC, username ASC");

// Fetch all clients
$clients_directory = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch all clients for dropdowns
$clients_result = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch all supervisors for User Accounts tab
$supervisors_sql = "
    SELECT s.*, u.username, 
           CASE WHEN s.agency_id = 0 THEN 'All Agencies' ELSE a.username END as agency_name,
           GROUP_CONCAT(c.username SEPARATOR ', ') as assigned_clients,
           GROUP_CONCAT(ac.client_id SEPARATOR ',') as assigned_client_ids
    FROM supervisors s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN users a ON s.agency_id = a.id
    LEFT JOIN agency_clients ac ON s.id = ac.supervisor_id
    LEFT JOIN users c ON ac.client_id = c.id
    GROUP BY s.id
    ORDER BY s.name ASC
";
$supervisors_result = $conn->query($supervisors_sql);

// Fetch all agency clients for JS mapping (for dynamic client selection)
$agency_clients_raw = $conn->query("
    SELECT ac.agency_id, ac.client_id, c.username as client_name 
    FROM agency_clients ac 
    JOIN users c ON ac.client_id = c.id
");
$agency_clients_map = [];
while ($ac_row = $agency_clients_raw->fetch_assoc()) {
    $agency_clients_map[$ac_row['agency_id']][] = [
        'id' => $ac_row['client_id'],
        'name' => $ac_row['client_name']
    ];
}
$agency_clients_json = json_encode($agency_clients_map);

// NEW: Fetch all registered personnel for the typeable dropdown
$personnel_sql = "
    (SELECT name, agency_id FROM guards)
    UNION
    (SELECT name, agency_id FROM inspectors)
    ORDER BY name ASC
";
$personnel_res = $conn->query($personnel_sql);
$personnel_map = [];
while ($p = $personnel_res->fetch_assoc()) {
    $personnel_map[$p['agency_id']][] = $p['name'];
}
$personnel_json = json_encode($personnel_map);

?>
<?php
$page_title = 'Users Maintenance';
$header_title = 'Users Maintenance';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="content-area">

            <div class="tab-nav">
                <button class="tab-btn active" onclick="switchTab('tab-agencies', this)">Agencies</button>
                <button class="tab-btn" onclick="switchTab('tab-user-accounts', this)">User Accounts</button>
            </div>

            <style>
                .tab-nav { display: flex; gap: 8px; margin-bottom: 32px; background: #e2e8f0; padding: 6px; border-radius: var(--radius-lg); width: fit-content; }
                .tab-btn { padding: 10px 24px; border: none; background: transparent; color: var(--text-muted); font-weight: 600; cursor: pointer; border-radius: var(--radius-md); transition: all 0.2s; }
                .tab-btn.active { background: var(--card-bg); color: var(--primary); box-shadow: var(--shadow); }
                .tab-pane { display: none; animation: fadeIn 0.4s ease; }
                .tab-pane.active { display: block; }
                @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
                .form-toggle { display: grid; grid-template-columns: 1fr 1fr; background: #f1f5f9; padding: 5px; border-radius: 10px; margin-bottom: 30px; }
                .toggle-btn { padding: 10px; border: none; background: transparent; border-radius: 8px; cursor: pointer; font-weight: 600; color: var(--text-muted); transition: all 0.2s; text-align: center; }
                .toggle-btn.active { background: white; color: var(--primary); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
                .limit-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #f1f5f9; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; color: var(--secondary); }
                .unassign-link { color: var(--danger); font-weight: 600; text-decoration: none; font-size: 0.85rem; padding: 6px 12px; border-radius: 6px; transition: all 0.2s; }
                .unassign-link:hover { background: #fee2e2; }

                tbody tr { cursor: pointer; transition: background 0.2s; }
                tbody tr:hover { background-color: #f8fafc !important; }

                tbody tr { cursor: pointer; transition: background 0.2s; }
                tbody tr:hover { background-color: #f8fafc !important; }

                /* ── agency badge ── */
                .badge-agency { display: inline-block; background: #ede9fe; color: #6d28d9;
                                padding: 3px 10px; border-radius: 20px; font-size: 0.78rem;
                                font-weight: 600; }
                /* ── client badges ── */
                .badge-client { display: inline-block; background: #d1fae5; color: #065f46;
                                padding: 3px 10px; border-radius: 20px; font-size: 0.78rem;
                                font-weight: 600; margin: 2px 2px 2px 0; }
                .badge-none   { color: #9ca3af; font-size: 0.82rem; font-style: italic; }
            </style>

            <!-- TAB: AGENCIES -->
            <div id="tab-agencies" class="tab-pane active">

                <div class="card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin:0;">Registered Agencies</h3>
                        <button class="btn btn-primary" style="width:auto; padding: 8px 20px; font-size: 0.9rem;" onclick="document.getElementById('addAgencyModal').classList.add('show')">+ Add Agency</button>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Agency Name</th>
                                    <th>Account Username</th>
                                    <th>Status</th>
                                    <th>Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $agencies_result->data_seek(0);
                                if ($agencies_result->num_rows > 0): 
                                    while($row = $agencies_result->fetch_assoc()): ?>
                                        <tr onclick="openQuickLinksModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['agency_name'] ?: $row['username']); ?>', <?php echo $row['client_limit']; ?>, '<?php echo addslashes($row['address'] ?? ''); ?>', '<?php echo addslashes($row['contact_person'] ?? ''); ?>', '<?php echo addslashes($row['contact_no'] ?? ''); ?>', '<?php echo $row['status'] ?? 'active'; ?>', <?php echo htmlspecialchars(json_encode($row)); ?>)">
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['agency_name'] ?: $row['username']); ?></strong>
                                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px;">
                                                    Client Limit: <?php echo $row['client_limit']; ?>
                                                </div>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($row['username']); ?></code></td>
                                            <td>
                                                <?php if (($row['status'] ?? 'active') === 'suspended'): ?>
                                                    <span style="color: #ef4444; font-weight: 600;">● Suspended</span>
                                                <?php else: ?>
                                                    <span style="color: var(--success); font-weight: 600;">● Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="display: flex; gap: 8px;">
                                                <button class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem; width: auto;" onclick='event.stopPropagation(); openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)'>Edit</button>
                                                <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #fee2e2; color: #991b1b; border: none; width: auto;" onclick="event.stopPropagation(); openDeleteModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['agency_name'] ?: $row['username']); ?>')">Delete</button>
                                            </td>
                                        </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="empty-state">No agencies registered yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            </div>

            <!-- TAB: USER ACCOUNTS -->
            <div id="tab-user-accounts" class="tab-pane">
                <div class="card" style="max-width: 600px;">
                    <div class="card-header"><h3>Add New User (Supervisor)</h3></div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <div class="form-group">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <label class="form-label" style="margin-bottom: 0;">Full Name (Registered Personnel)</label>
                                    <span style="font-size: 0.7rem; color: #64748b;">Type name or select from list</span>
                                </div>
                                <input type="text" name="fullname" id="account_fullname" class="form-control" required placeholder="Select agency to see personnel..." list="personnel_list">
                                <datalist id="personnel_list"></datalist>
                            </div>
                            <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control" required placeholder="Account username">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required placeholder="••••••••">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="contact_no" class="form-control" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Agency</label>
                                <select name="agency_id" id="account_agency_id" class="form-control" required onchange="handleAgencyChange(this.value, 'account_clients_container')">
                                    <option value="" disabled selected>Select Agency</option>
                                    <option value="all" style="font-weight: bold; color: var(--primary);">All Agency (Global Access)</option>
                                    <?php 
                                    $agencies_result->data_seek(0);
                                    while($a = $agencies_result->fetch_assoc()) echo "<option value='{$a['id']}'>".htmlspecialchars($a['username'])."</option>";
                                    ?>
                                </select>
                            </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <label class="form-label" style="margin-bottom: 0;">Assign to Clients</label>
                                    <label style="font-size: 0.75rem; color: var(--primary); font-weight: 600; cursor: pointer;">
                                        <input type="checkbox" id="select_all_clients" onclick="toggleSelectAllClients('account_clients_container', this.checked)"> Select All
                                    </label>
                                </div>
                                <div id="account_clients_container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-height: 150px; overflow-y: auto; padding: 10px; border: 1.5px solid var(--border); border-radius: 10px; background: #fbfcfd;">
                                    <span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">Select an agency first...</span>
                                </div>
                            <button type="submit" name="add_supervisor" class="btn btn-primary" style="margin-top: 10px;">Create Supervisor Account</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Supervisor Accounts</h3></div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Agency</th>
                                    <th>Assigned Clients</th>
                                    <th>Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($supervisors_result && $supervisors_result->num_rows > 0): 
                                    while($sup = $supervisors_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($sup['name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($sup['username']); ?></code></td>
                                        <td><span class="badge-agency"><?php echo htmlspecialchars($sup['agency_name']); ?></span></td>
                                        <td>
                                            <?php if ($sup['assigned_clients']): ?>
                                                <?php foreach (explode(', ', $sup['assigned_clients']) as $cn): ?>
                                                    <span class="badge-client"><?php echo htmlspecialchars($cn); ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="badge-none">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="display: flex; gap: 8px;">
                                            <button type="button" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem; width: auto;" onclick='openSupervisorEditModal(<?php echo json_encode($sup); ?>)'>Edit</button>
                                            <button type="button" class="unassign-link" style="border:none; background:none; cursor:pointer;" onclick="openSupervisorDeleteModal(<?php echo $sup['id']; ?>, '<?php echo addslashes($sup['name']); ?>')">Delete</button>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="empty-state">No supervisor accounts found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- MODALS -->

    <!-- Add New Agency Modal -->
    <div id="addAgencyModal" class="modal">
        <div class="modal-content" style="max-width: 560px; text-align: left; padding: 32px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px;">
                <h3 style="margin: 0; font-size: 1.2rem;">Add New Agency</h3>
                <button type="button" onclick="closeModal('addAgencyModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
            </div>
            <form action="agency_maintenance.php" method="POST" autocomplete="off">
                <div class="form-group">
                    <label class="form-label">Agency Name</label>
                    <input type="text" name="agency_name" class="form-control" required placeholder="Ex: Shield Security" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">Account Username</label>
                    <input type="text" name="agency_username" class="form-control" required placeholder="shield_admin" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">Account Password</label>
                    <input type="password" name="agency_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label">Agency Address</label>
                    <textarea name="address" class="form-control" placeholder="Full business address" rows="2"></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 4px;">
                    <div class="form-group"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" placeholder="Full Name"></div>
                    <div class="form-group"><label class="form-label">Contact No.</label><input type="text" name="contact_no" class="form-control" placeholder="09XXXXXXXXX"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Client Account Limit</label>
                    <input type="number" name="client_limit" class="form-control" value="0" min="0">
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end;">
                    <button type="button" onclick="closeModal('addAgencyModal')" class="btn" style="background: #f1f5f9; color: #475569; width: auto; padding: 10px 24px;">Cancel</button>
                    <button type="submit" name="add_agency" class="btn btn-primary" style="width: auto; padding: 10px 24px;">Create Agency Profile</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Process Modal -->
    <div id="statusModal" class="modal <?php echo $show_status_modal ? 'show' : ''; ?>">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                <?php echo $message_type === 'success' ? '✓' : '!'; ?>
            </div>
            <h3 style="margin-bottom: 10px;"><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
            <p style="color: #6b7280; margin-bottom: 24px;"><?php echo $message; ?></p>
            <button class="btn btn-primary" onclick="closeModal('statusModal')">Done</button>
        </div>
    </div>

    <!-- Agency Quick Links Modal -->
    <div id="agencyQuickLinksModal" class="modal">
        <div class="modal-content" style="max-width: 600px; text-align: left; padding: 0;">
            <div style="padding: 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 id="ql_agency_name" style="margin: 0; color: #1e293b; font-size: 1.25rem;">Agency Details</h3>
                <button type="button" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8;" onclick="closeModal('agencyQuickLinksModal')">&times;</button>
            </div>

            <div style="padding: 12px 16px; background: #ffffff; border-bottom: 1px solid #f1f5f9;">
                <div style="position: relative; display: flex; align-items: center;">
                    <span style="position: absolute; left: 12px; color: #94a3b8;">🔍</span>
                    <input type="text" id="ql_search_input" placeholder="Search" 
                           style="width: 100%; padding: 10px 10px 10px 35px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem; outline: none; transition: border-color 0.2s;"
                           oninput="filterQuickLinkData()">
                </div>
            </div>
            
            <div style="padding: 16px; background: #ffffff;">
                <div class="ql-nav" style="display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 8px;">
                    <button class="ql-tab active" onclick="loadQuickLinkTab('clients')">Clients</button>
                    <button class="ql-tab" onclick="loadQuickLinkTab('qr')">QR Usage</button>
                    <button class="ql-tab" onclick="loadQuickLinkTab('guards')">Guards</button>
                    <button class="ql-tab" onclick="loadQuickLinkTab('inspectors')">Inspectors</button>
                </div>
                
                <div id="ql_content_area" style="min-height: 250px; max-height: 400px; overflow-y: auto; padding: 4px;">
                    <!-- Content injected via AJAX -->
                    <div class="ql-loader" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 250px; color: #94a3b8;">
                        <span style="font-size: 2rem; animation: spin 1s linear infinite;">↻</span>
                        <p style="margin-top: 10px; font-weight: 500;">Fetching data...</p>
                    </div>
                </div>
            </div>

            <div style="padding: 16px; text-align: right; border-top: 1px solid #f1f5f9;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; width: auto;" onclick="closeModal('agencyQuickLinksModal')">Close</button>
            </div>
        </div>
    </div>

    <style>
        .ql-tab { padding: 10px 18px; border: 1px solid #e2e8f0; background: white; border-radius: 8px; font-weight: 600; font-size: 0.85rem; color: #64748b; cursor: pointer; white-space: nowrap; transition: all 0.2s; }
        .ql-tab:hover { background: #f8fafc; color: var(--primary); border-color: var(--primary-light); }
        .ql-tab.active { background: var(--primary); color: white; border-color: var(--primary); }
        
        .ql-data-list { list-style: none; padding: 0; margin: 0; }
        .ql-data-item { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .ql-data-item:last-child { border-bottom: none; }
        .ql-label { font-weight: 600; color: #334155; }
        .ql-value { font-size: 0.9rem; color: #64748b; }
        
        /* Incremental Controls */
        .ql-adj-container { background: #f8fafc; border-radius: 8px; padding: 12px; margin-top: 8px; border: 1px solid #e2e8f0; display: none; }
        .ql-adj-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .ql-adj-row:last-child { margin-bottom: 0; }
        .ql-adj-label { font-size: 0.8rem; font-weight: 600; color: #64748b; flex: 1; }
        .ql-adj-ctrl { display: flex; align-items: center; gap: 4px; }
        .btn-adj { width: 24px; height: 24px; border-radius: 4px; border: 1px solid #cbd5e1; background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #334155; }
        .btn-adj:hover { background: #f1f5f9; }
        .ql-adj-input { width: 45px; text-align: center; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px 0; font-size: 0.85rem; font-weight: 700; }
        .ql-adj-input::-webkit-inner-spin-button { display: none; }
        .btn-save-adj { width: 100%; margin-top: 10px; padding: 6px; background: var(--primary); color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 0.8rem; cursor: pointer; }
        
        @keyframes spin { from {transform: rotate(0deg);} to {transform: rotate(360deg);} }
    </style>

    <!-- Agency Delete Confirmation Modal -->
    <div id="deleteAgencyModal" class="modal">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3>Delete Agency?</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Are you sure you want to delete <strong id="delete_agency_display_name"></strong>? This will also remove all their client assignments and supervisor accounts.</p>
            <form action="" method="POST">
                <input type="hidden" name="agency_id" id="delete_agency_id">
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button type="button" class="btn" style="background: #e2e8f0; color: #475569;" onclick="closeModal('deleteAgencyModal')">Cancel</button>
                    <button type="submit" name="delete_agency_action" class="btn" style="background: #ef4444; color: white;">Delete Permanently</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Agency Modal -->
    <div id="editAgencyModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Edit Agency Details</h3>
            <form action="" method="POST">
                <input type="hidden" name="agency_id" id="edit_agency_id">
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Agency Name</label>
                        <input type="text" name="agency_name" id="edit_agency_name_display" class="form-control" required>
                    </div>
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Account Username (Read-only)</label>
                        <input type="text" id="edit_agency_username" class="form-control" disabled>
                    </div>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Agency Address</label>
                    <textarea name="address" id="edit_agency_address" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                    <div class="form-group" style="text-align: left;"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="edit_agency_contact_person" class="form-control"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Contact No.</label><input type="text" name="contact_no" id="edit_agency_contact_no" class="form-control"></div>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Client Limit</label>
                    <input type="number" name="client_limit" id="edit_agency_client_limit" class="form-control" min="0" placeholder="0 = unlimited">
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Account Status</label>
                    <select name="status" id="edit_agency_status" class="form-control">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('editAgencyModal')">Cancel</button>
                    <button type="submit" name="update_agency_details" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Supervisor Modal -->
    <div id="editSupervisorModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <h3 style="margin-bottom: 20px;">Edit Supervisor Account</h3>
            <form action="" method="POST">
                <input type="hidden" name="edit_supervisor_id" id="edit_supervisor_id">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="fullname" id="edit_sup_fullname" class="form-control" required list="personnel_list">
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_no" id="edit_sup_contact" class="form-control">
                </div>
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" id="edit_sup_username" class="form-control" required>
                    </div>
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">New Password (Optional)</label>
                        <input type="password" name="password" class="form-control" placeholder="••••••••">
                    </div>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Agency</label>
                    <select name="agency_id" id="edit_sup_agency_id" class="form-control" required onchange="handleAgencyChange(this.value, 'edit_sup_clients_container')">
                        <option value="all" style="font-weight: bold; color: var(--primary);">All Agency (Global Access)</option>
                        <?php 
                        $agencies_result->data_seek(0);
                        while($a = $agencies_result->fetch_assoc()) echo "<option value='{$a['id']}'>".htmlspecialchars($a['username'])."</option>";
                        ?>
                    </select>
                </div>
                <div class="form-group" style="text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label class="form-label" style="margin-bottom:0;">Assigned Clients</label>
                        <label style="font-size: 0.75rem; color: var(--primary); font-weight: 600; cursor: pointer;">
                            <input type="checkbox" id="edit_select_all_clients" onclick="toggleSelectAllClients('edit_sup_clients_container', this.checked)"> Select All
                        </label>
                    </div>
                    <div id="edit_sup_clients_container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-height: 150px; overflow-y: auto; padding: 10px; border: 1.5px solid var(--border); border-radius: 10px; background: #fbfcfd;">
                    </div>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('editSupervisorModal')">Cancel</button>
                    <button type="submit" name="update_supervisor" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Supervisor Modal -->
    <div id="deleteSupervisorModal" class="modal">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3>Delete Supervisor Account?</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Are you sure you want to delete <strong id="delete_supervisor_name"></strong>? This will remove their access permanently.</p>
            <form action="" method="POST">
                <input type="hidden" name="delete_supervisor_id" id="delete_supervisor_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('deleteSupervisorModal')">Cancel</button>
                    <button type="submit" name="delete_supervisor_action" class="btn" style="background: #ef4444; color: white; flex: 1;">Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            if (btn) btn.classList.add('active');
            
            // Update URL query parameter
            const tabName = tabId.replace('tab-', '');
            const newurl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?tab=' + tabName;
            window.history.replaceState({path:newurl}, '', newurl);
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function openDeleteModal(id, name) {
            document.getElementById('delete_agency_id').value = id;
            document.getElementById('delete_agency_display_name').innerText = name;
            document.getElementById('deleteAgencyModal').classList.add('show');
        }

        function openEditModal(agency) {
            document.getElementById('edit_agency_id').value = agency.id;
            document.getElementById('edit_agency_name_display').value = agency.agency_name || agency.username;
            document.getElementById('edit_agency_username').value = agency.username;
            document.getElementById('edit_agency_address').value = agency.address;
            document.getElementById('edit_agency_contact_person').value = agency.contact_person;
            document.getElementById('edit_agency_contact_no').value = agency.contact_no;
            document.getElementById('edit_agency_status').value = agency.status;
            document.getElementById('edit_agency_client_limit').value = agency.client_limit || 0;
            document.getElementById('editAgencyModal').classList.add('show');
        }

        function openSupervisorEditModal(sup) {
            document.getElementById('edit_supervisor_id').value = sup.id;
            document.getElementById('edit_sup_fullname').value = sup.name;
            document.getElementById('edit_sup_username').value = sup.username;
            document.getElementById('edit_sup_contact').value = sup.contact_no;
            
            const agencyId = sup.agency_id == 0 ? 'all' : sup.agency_id;
            document.getElementById('edit_sup_agency_id').value = agencyId;
            
            filterPersonnelByAgency(agencyId);
            
            const selectedIds = sup.assigned_client_ids ? sup.assigned_client_ids.split(',') : [];
            filterClientsByAgency(agencyId, 'edit_sup_clients_container', selectedIds);
            
            document.getElementById('editSupervisorModal').classList.add('show');
        }

        function openSupervisorDeleteModal(id, name) {
            document.getElementById('delete_supervisor_id').value = id;
            document.getElementById('delete_supervisor_name').textContent = name;
            document.getElementById('deleteSupervisorModal').classList.add('show');
        }

        // Configuration and Maps
        const agencyClientsMap = <?php echo $agency_clients_json; ?>;
        const personnelMap = <?php echo $personnel_json; ?>;

        function handleAgencyChange(agencyId, containerId) {
            filterClientsByAgency(agencyId, containerId);
            filterPersonnelByAgency(agencyId);
        }

        function filterPersonnelByAgency(agencyId) {
            const datalist = document.getElementById('personnel_list');
            datalist.innerHTML = '';
            
            let names = [];
            if (agencyId === 'all') {
                Object.values(personnelMap).forEach(list => { names = names.concat(list); });
            } else if (personnelMap[agencyId]) {
                names = personnelMap[agencyId];
            }

            const uniqueNames = [...new Set(names)].sort();
            uniqueNames.forEach(name => {
                const option = document.createElement('option');
                option.value = name;
                datalist.appendChild(option);
            });
        }

        function toggleSelectAllClients(containerId, isChecked) {
            const container = document.getElementById(containerId);
            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = isChecked);
        }

        function filterClientsByAgency(agencyId, containerId, selectedIds = []) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            
            if (document.getElementById('select_all_clients')) document.getElementById('select_all_clients').checked = false;

            let clients = [];
            if (agencyId === 'all') {
                Object.values(agencyClientsMap).forEach(list => { clients = clients.concat(list); });
            } else if (agencyClientsMap[agencyId]) {
                clients = agencyClientsMap[agencyId];
            }

            if (clients.length === 0) {
                container.innerHTML = '<span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">No clients found...</span>';
                return;
            }

            const seenIds = new Set();
            const uniqueClients = clients.filter(c => {
                if (seenIds.has(c.id)) return false;
                seenIds.add(c.id);
                return true;
            });

            uniqueClients.forEach(client => {
                const isChecked = selectedIds.includes(client.id.toString()) || selectedIds.includes(parseInt(client.id));
                const div = document.createElement('div');
                div.style.display = 'flex';
                div.style.alignItems = 'center';
                div.style.gap = '8px';
                div.innerHTML = `
                    <input type="checkbox" name="assigned_clients[]" value="${client.id}" id="client_${containerId}_${client.id}" ${isChecked ? 'checked' : ''}>
                    <label for="client_${containerId}_${client.id}" style="font-size: 0.85rem; cursor: pointer;">${client.name}</label>
                `;
                container.appendChild(div);
            });
        }

        // Initialize state on DOM load
        window.addEventListener('DOMContentLoaded', () => {
            // URL Tab Selection
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab) {
                const tabPane = document.getElementById('tab-' + tab);
                if (tabPane) {
                    // Find the corresponding button
                    let btnIndex = 1;
                    if (tab === 'user-accounts') btnIndex = 2;
                    
                    const btn = document.querySelector(`.tab-btn:nth-child(${btnIndex})`);
                    switchTab('tab-' + tab, btn);
                }
            }

            // Supervisor Form Init
            const agencySelect = document.getElementById('account_agency_id');
            if (agencySelect && agencySelect.value) {
                filterPersonnelByAgency(agencySelect.value);
                filterClientsByAgency(agencySelect.value, 'account_clients_container');
            }
        });
        // --- Agency Quick Links ---
        let qlAgencyFullData = null;
        function openQuickLinksModal(agencyId, agencyName, clientLimit, address, contactPerson, contactNo, status, fullData) {
            qlAgencyData = {
                id: agencyId,
                name: agencyName,
                limit: clientLimit,
                address: address,
                person: contactPerson,
                no: contactNo,
                status: status
            };
            qlAgencyFullData = fullData;
            document.getElementById('ql_agency_name').innerText = agencyName;
            document.getElementById('ql_search_input').value = ''; // Reset search
            document.getElementById('agencyQuickLinksModal').classList.add('show');
            loadQuickLinkTab('clients');
        }

        function openEditFromQuickLinks() {
            if (!qlAgencyFullData) return;
            closeModal('agencyQuickLinksModal');
            openEditModal(qlAgencyFullData);
        }

        async function loadQuickLinkTab(tab) {
            if (!qlAgencyData) return;
            // Reset search input on tab switch
            document.getElementById('ql_search_input').value = '';
            
            // Update UI Active Tab
            document.querySelectorAll('.ql-tab').forEach(t => {
                const label = t.innerText.toLowerCase();
                t.classList.toggle('active', label === tab.toLowerCase() || (label === 'qr usage' && tab === 'qr'));
            });

            const contentArea = document.getElementById('ql_content_area');
            contentArea.innerHTML = `<div class="ql-loader" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 250px; color: #94a3b8;"><span style="font-size: 2rem; animation: spin 1s linear infinite;">↻</span><p style="margin-top: 10px; font-weight: 500;">Fetching data...</p></div>`;

            try {
                const response = await fetch(`agency_maintenance.php?ajax_agency_data=1&agency_id=${qlAgencyData.id}&type=${tab}`);
                const data = await response.json();
                renderQuickLinkData(tab, data);
            } catch (err) {
                contentArea.innerHTML = `<p style="padding: 20px; text-align: center; color: #ef4444;">Error loading data. Please try again.</p>`;
            }
        }

        function renderQuickLinkData(type, data) {
            const container = document.getElementById('ql_content_area');
            if (data.length === 0 && type !== 'inspector_info') {
                container.innerHTML = `<p style="padding: 40px; text-align: center; color: #94a3b8; font-style: italic;">No records found.</p>`;
                return;
            }

            let html = '<ul class="ql-data-list">';
            if (type === 'clients') {
                data.forEach(item => {
                    html += `
                        <li class="ql-data-item" style="flex-direction: column; align-items: stretch; gap: 4px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                <span class="ql-label">${item.name}</span>
                                <button class="btn-sm" style="width: auto; padding: 4px 12px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight:700;" onclick="toggleAdjForm(${item.id})">Add Limits +</button>
                            </div>
                            <div id="adj_form_${item.id}" class="ql-adj-container">
                                <p style="font-size: 0.7rem; color: #94a3b8; margin: 0 0 10px 0; font-weight:600; text-transform:uppercase;">Adjust Incremental Limits</p>

                                <div class="ql-adj-row">
                                    <span class="ql-adj-label">QR Checkpoints</span>
                                    <div class="ql-adj-ctrl">
                                        <button class="btn-adj" onclick="adjVal(${item.id}, 'qr', -1)">-</button>
                                        <input type="number" id="adj_qr_${item.id}" class="ql-adj-input" value="0">
                                        <button class="btn-adj" onclick="adjVal(${item.id}, 'qr', 1)">+</button>
                                    </div>
                                </div>
                                <div class="ql-adj-row">
                                    <span class="ql-adj-label">Security Guards</span>
                                    <div class="ql-adj-ctrl">
                                        <button class="btn-adj" onclick="adjVal(${item.id}, 'guard', -1)">-</button>
                                        <input type="number" id="adj_guard_${item.id}" class="ql-adj-input" value="0">
                                        <button class="btn-adj" onclick="adjVal(${item.id}, 'guard', 1)">+</button>
                                    </div>
                                </div>
                                <div class="ql-adj-row">
                                    <span class="ql-adj-label">Inspectors</span>
                                    <div class="ql-adj-ctrl">
                                        <button class="btn-adj" onclick="adjVal(${item.id}, 'insp', -1)">-</button>
                                        <input type="number" id="adj_insp_${item.id}" class="ql-adj-input" value="0">
                                        <button class="btn-adj" onclick="adjVal(${item.id}, 'insp', 1)">+</button>
                                    </div>
                                </div>
                                <button class="btn-save-adj" onclick="saveAdj(${item.id})">Save</button>
                            </div>
                        </li>`;
                });
            } else if (type === 'qr') {
                html += `<li class="ql-data-item" style="background: #f8fafc; font-weight: 700;"><span class="ql-label">Client Site</span><span class="ql-value" style="font-weight:700; color: #1e293b;">Total QRs</span></li>`;
                data.forEach(item => {
                    html += `<li class="ql-data-item"><span class="ql-label">${item.client_name}</span><span class="ql-value" style="background:#e0f2fe; color:#0369a1; padding:2px 10px; border-radius:12px; font-weight:700;">${item.qr_count}</span></li>`;
                });
            } else if (type === 'guards') {
                html += `<li class="ql-data-item" style="background: #f8fafc; font-weight: 700;"><span class="ql-label">Guard Name</span><span class="ql-value" style="font-weight:700; color: #1e293b;">Assigned To</span></li>`;
                let i = 1;
                data.forEach(item => {
                    html += `<li class="ql-data-item">
                                <span class="ql-label"><span style="color:#94a3b8; margin-right:8px; font-weight:400;">${i++}.</span>${item.name}</span>
                                <span class="ql-value" style="font-size:0.8rem; text-align:right;">${item.assigned_clients || '<span style="color:#cbd5e1;">None</span>'}</span>
                             </li>`;
                });
            } else if (type === 'inspectors') {
                data.forEach(item => {
                    html += `<li class="ql-data-item" style="cursor:pointer;" onclick="loadInspectorDetail(${item.id})">
                                <span class="ql-label">${item.name}</span>
                                <span style="color:var(--primary); font-size: 0.8rem;">View Info →</span>
                             </li>`;
                });
            } else if (type === 'inspector_info') {
                const regDate = (data && data.registered_at) ? new Date(data.registered_at) : null;
                const formattedDate = (regDate && !isNaN(regDate)) ? regDate.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'Not Available';
                
                html = `
                    <div style="padding: 20px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <button onclick="loadQuickLinkTab('inspectors')" style="background:none; border:none; color:var(--primary); cursor:pointer; font-weight:600; padding:0; margin-bottom: 16px;">← Back to List</button>
                        <h4 style="margin: 0 0 10px 0; font-size: 1.1rem; color: #1e293b;">${data.name || 'Unknown Inspector'}</h4>
                        <div style="display: grid; gap: 12px; margin-top: 15px;">
                            <div style="display:flex; justify-content:space-between; font-size:0.9rem;">
                                <span style="color:#64748b;">Access Key / Username:</span>
                                <code style="background:#e2e8f0; padding:2px 6px; border-radius:4px; font-weight:700;">${data.username || 'N/A'}</code>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:0.9rem;">
                                <span style="color:#64748b;">Registration Date:</span>
                                <span style="font-weight:600;">${formattedDate}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:0.9rem;">
                                <span style="color:#64748b;">Assigned Clients:</span>
                                <span style="font-weight:600;">${data.assignments_count || 0} clients</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            html += (type === 'inspector_info') ? '' : '</ul>';
            container.innerHTML = html;
        }

        async function loadInspectorDetail(id) {
            // Reset search when going into detail view
            document.getElementById('ql_search_input').value = '';
            
            const contentArea = document.getElementById('ql_content_area');
            contentArea.innerHTML = `<div class="ql-loader" style="height:250px; display:flex; justify-content:center; align-items:center;"><span style="font-size: 2rem; animation: spin 1s linear infinite;">↻</span></div>`;
            try {
                const response = await fetch(`agency_maintenance.php?ajax_agency_data=1&agency_id=${qlAgencyData.id}&type=inspector_info&inspector_id=${id}`);
                const data = await response.json();
                
                if (data && data.id) {
                    renderQuickLinkData('inspector_info', data);
                } else {
                    const msg = data.message || 'Inspector record is incomplete or missing.';
                    contentArea.innerHTML = `<p style="padding: 20px; text-align: center; color: #ef4444;">${msg}</p><button onclick="loadQuickLinkTab('inspectors')" class="btn-sm" style="margin: 0 auto; display: block;">Back to List</button>`;
                }
            } catch (err) {
                console.error("Inspector Load Error:", err);
                contentArea.innerHTML = `<p style="padding: 20px; text-align: center; color: #ef4444;">Could not load details. There might be a connection issue or a setup error.</p><button onclick="loadQuickLinkTab('inspectors')" class="btn-sm" style="margin: 0 auto; display: block;">Back to List</button>`;
            }
        }

        // --- Incremental Limit Logic ---
        function toggleAdjForm(id) {
            const form = document.getElementById(`adj_form_${id}`);
            const isVisible = form.style.display === 'block';
            document.querySelectorAll('.ql-adj-container').forEach(f => f.style.display = 'none'); // Hide others
            form.style.display = isVisible ? 'none' : 'block';
        }

        function adjVal(id, prefix, delta) {
            const input = document.getElementById(`adj_${prefix}_${id}`);
            const current = parseInt(input.value) || 0;
            input.value = current + delta;
        }

        async function saveAdj(id) {
            const addClient = document.getElementById(`adj_client_${id}`) ? document.getElementById(`adj_client_${id}`).value : 0;
            const addQr = document.getElementById(`adj_qr_${id}`) ? document.getElementById(`adj_qr_${id}`).value : 0;
            const addGuard = document.getElementById(`adj_guard_${id}`) ? document.getElementById(`adj_guard_${id}`).value : 0;
            const addInsp = document.getElementById(`adj_insp_${id}`) ? document.getElementById(`adj_insp_${id}`).value : 0;

            try {
                const response = await fetch(`agency_maintenance.php?ajax_agency_data=1&type=update_client_limits&mapping_id=${id}&add_client=${addClient}&add_qr=${addQr}&add_guards=${addGuard}&add_inspectors=${addInsp}`);
                const data = await response.json();
                if (data.success) {
                    alert('Limits updated successfully!');
                    loadQuickLinkTab('clients'); 
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('An unexpected error occurred.');
            }
        }

        // --- Live Search Filtering ---
        function filterQuickLinkData() {
            const query = document.getElementById('ql_search_input').value.toLowerCase().trim();
            const items = document.querySelectorAll('.ql-data-item');

            items.forEach(item => {
                // Don't filter header rows (e.g. in QR tab)
                if (item.style.fontWeight === '700' || item.style.background === 'rgb(248, 250, 252)' || item.style.background === '#f8fafc') {
                    item.style.display = 'flex';
                    return;
                }

                const label = item.querySelector('.ql-label');
                if (!label) return;

                const text = label.innerText.toLowerCase();
                if (text.includes(query)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>

<?php include 'admin_layout/footer.php'; ?>
</html>
