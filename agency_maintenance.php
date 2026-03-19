<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Ensure limits columns exist in users table
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS qr_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS guard_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS inspector_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS supervisor_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS client_limit INT DEFAULT 0");

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

// Limits columns handled by ALTER TABLE ... IF NOT EXISTS

// Function to generate unique 6-character alphanumeric key (access key)
function generateUniqueSupervisorKeyAdmin($conn) {
    $chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    do {
        $key = "";
        for ($i = 0; $i < 6; $i++) {
            $key .= $chars[rand(0, strlen($chars) - 1)];
        }
        $check = $conn->query("SELECT id FROM users WHERE username = '$key'");
    } while ($check && $check->num_rows > 0);
    return $key;
}

$message = '';
$message_type = '';
$show_status_modal = false;

// Handle updating agency limits
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_agency_limits'])) {
    $agency_id = (int)$_POST['agency_id'];
    $qr_limit = (int)$_POST['qr_limit'];
    $guard_limit = (int)$_POST['guard_limit'];
    $inspector_limit = (int)$_POST['inspector_limit'];
    $supervisor_limit = (int)$_POST['supervisor_limit'];
    $client_limit = (int)$_POST['client_limit'];

    if ($conn->query("UPDATE users SET qr_limit = $qr_limit, guard_limit = $guard_limit, inspector_limit = $inspector_limit, supervisor_limit = $supervisor_limit, client_limit = $client_limit WHERE id = $agency_id")) {
        $message = "Agency limits updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error updating agency: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Unassign Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['unassign_client_action'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    if ($conn->query("DELETE FROM agency_clients WHERE id = $mapping_id")) {
        $message = "Client unassigned successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error unassigning client: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Add Agency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_agency'])) {
    $agency_name = $conn->real_escape_string($_POST['agency_name']);
    $password = password_hash($_POST['agency_password'], PASSWORD_DEFAULT);
    $qr_limit = (int)$_POST['qr_limit'];
    $guard_limit = (int)$_POST['guard_limit'];
    $inspector_limit = (int)$_POST['inspector_limit'];
    $supervisor_limit = (int)$_POST['supervisor_limit'];
    $client_limit = (int)$_POST['client_limit'];
    
    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$agency_name'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Agency username already exists!";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $sql = "INSERT INTO users (username, password, user_level, qr_limit, guard_limit, inspector_limit, supervisor_limit, client_limit) 
                VALUES ('$agency_name', '$password', 'agency', $qr_limit, $guard_limit, $inspector_limit, $supervisor_limit, $client_limit)";
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

// Handle Assign Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_client'])) {
    $agency_id = (int)$_POST['agency_id'];
    $client_id = (int)$_POST['client_id'];

    if ($agency_id && $client_id) {
        // Check if assignment already exists
        $check_assignment = "SELECT id FROM agency_clients WHERE agency_id = $agency_id AND client_id = $client_id";
        $assignment_result = $conn->query($check_assignment);
        
        if ($assignment_result->num_rows > 0) {
            $message = "This client is already assigned to this agency!";
            $message_type = "error";
            $show_status_modal = true;
        } else {
            // Check Client Limit
            $limit_res = $conn->query("SELECT client_limit FROM users WHERE id = $agency_id");
            $client_limit = 0;
            if ($limit_res && $limit_res->num_rows > 0) {
                $client_limit = (int)$limit_res->fetch_assoc()['client_limit'];
            }

            $count_res = $conn->query("SELECT COUNT(*) as current_clients FROM agency_clients WHERE agency_id = $agency_id");
            $current_clients = (int)$count_res->fetch_assoc()['current_clients'];

            if ($client_limit > 0 && $current_clients >= $client_limit) {
                $message = "This agency has reached its maximum client limit ($client_limit).";
                $message_type = "error";
                $show_status_modal = true;
            } else {
                $sql = "INSERT INTO agency_clients (agency_id, client_id) VALUES ($agency_id, $client_id)";
                if ($conn->query($sql) === TRUE) {
                    $message = "Client assigned to agency successfully!";
                    $message_type = "success";
                    $show_status_modal = true;
                } else {
                    $message = "Error assigning client: " . $conn->error;
                    $message_type = "error";
                    $show_status_modal = true;
                }
            }
        }
    } else {
        $message = "Please select both an agency and a client.";
        $message_type = "error";
        $show_status_modal = true;
    }
}

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
        // Generate Unique Access Key
        $unique_key = generateUniqueSupervisorKeyAdmin($conn);
        $hashed_password = password_hash($unique_key, PASSWORD_DEFAULT);

        // 1. Create User
        if (!$conn->query("INSERT INTO users (username, password, user_level) VALUES ('$unique_key', '$hashed_password', 'supervisor')")) {
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
                $conn->query("UPDATE agency_clients SET supervisor_id = $supervisor_id WHERE agency_id = $agency_id AND client_id = $client_id");
            }
        }

        $conn->commit();
        $message = "Supervisor created successfully! Access Key: <strong>$unique_key</strong>";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Update Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_supervisor'])) {
    $supervisor_id = (int)$_POST['edit_supervisor_id'];
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
        // 1. Update Supervisor entry
        $conn->query("UPDATE supervisors SET name = '$fullname', contact_no = '$contact_no', agency_id = $agency_id WHERE id = $supervisor_id");

        // 2. Clear old assignments for this supervisor
        $conn->query("UPDATE agency_clients SET supervisor_id = NULL WHERE supervisor_id = $supervisor_id");

        // 3. Re-assign Clients
        if (!empty($assigned_clients)) {
            foreach ($assigned_clients as $client_id) {
                $client_id = (int)$client_id;
                $conn->query("UPDATE agency_clients SET supervisor_id = $supervisor_id WHERE agency_id = $agency_id AND client_id = $client_id");
            }
        }

        $conn->commit();
        $message = "Supervisor updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
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
    
    $res = $conn->query("SELECT user_id FROM supervisors WHERE id = $supervisor_id");
    if ($res && $res->num_rows > 0) {
        $user_id = $res->fetch_assoc()['user_id'];
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
$agencies_result = $conn->query("SELECT id, username, qr_limit, guard_limit, inspector_limit, supervisor_limit, client_limit FROM users WHERE user_level = 'agency' ORDER BY username ASC");

// Fetch all clients
$clients_directory = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch all clients for dropdowns
$clients_result = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch agency-client mappings
$mapping_sql = "
    SELECT ac.id, a.username AS agency_name, c.username AS client_name, ac.created_at, 
           a.qr_limit, a.guard_limit, a.inspector_limit, a.supervisor_limit, a.client_limit
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    ORDER BY a.username ASC, c.username ASC
";
$mappings_result = $conn->query($mapping_sql);

// Fetch all supervisors for User Accounts tab
$supervisors_sql = "
    SELECT s.*, u.username as access_key, 
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
                <button class="tab-btn" onclick="switchTab('tab-assignments', this)">Client Assignments</button>
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
                <div class="card" style="max-width: 600px;">
                    <div class="card-header"><h3>Add New Agency</h3></div>
                    <div class="card-body">
                        <form action="agency_maintenance.php" method="POST" autocomplete="off">
                            <div class="form-group">
                                <label class="form-label">Agency Name</label>
                                <input type="text" name="agency_name" class="form-control" required placeholder="Ex: Shield Security" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Security Password</label>
                                <input type="password" name="agency_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                            </div>
                            <div class="form-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 20px;">
                                <div class="form-group"><label class="form-label">QR Limit</label><input type="number" name="qr_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Guard Limit</label><input type="number" name="guard_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Inspector</label><input type="number" name="inspector_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Supervisor</label><input type="number" name="supervisor_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Client Limit</label><input type="number" name="client_limit" class="form-control" value="0" min="0"></div>
                            </div>
                            <button type="submit" name="add_agency" class="btn btn-primary">Create Agency Profile</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Registered Agencies</h3></div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Agency Username</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $agencies_result->data_seek(0);
                                if ($agencies_result->num_rows > 0): 
                                    while($row = $agencies_result->fetch_assoc()): ?>
                                    <tr onclick="openAgencyEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['username']); ?>', <?php echo $row['qr_limit']; ?>, <?php echo $row['guard_limit']; ?>, <?php echo $row['inspector_limit']; ?>, <?php echo $row['supervisor_limit']; ?>, <?php echo $row['client_limit']; ?>)">
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px;">
                                                QR: <?php echo $row['qr_limit']; ?> | 
                                                Guards: <?php echo $row['guard_limit']; ?> | 
                                                Insp: <?php echo $row['inspector_limit']; ?> | 
                                                Supr: <?php echo $row['supervisor_limit']; ?> |
                                                Clnt: <?php echo $row['client_limit']; ?>
                                            </div>
                                        </td>
                                        <td><span style="color: var(--success); font-weight: 600;">● Active</span></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="3" class="empty-state">No agencies registered yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB: ASSIGNMENTS -->
            <div id="tab-assignments" class="tab-pane">
                <div class="card" style="max-width: 800px;">
                    <div class="card-header"><h3>Client Assignment Tool</h3></div>
                    <div class="card-body">
                        <div class="form-toggle">
                            <button type="button" class="toggle-btn active" onclick="toggleAssignForm('existing', this)">Assign Existing Client</button>
                            <button type="button" class="toggle-btn" onclick="toggleAssignForm('new', this)">Add New Client</button>
                        </div>

                        <!-- Form: Assign Existing -->
                        <div id="form-assign-existing">
                            <form action="agency_maintenance.php" method="POST">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Target Agency</label>
                                        <select name="agency_id" class="form-control" required>
                                            <option value="" disabled selected>Select Agency</option>
                                            <?php 
                                            $agencies_result->data_seek(0);
                                            while($a = $agencies_result->fetch_assoc()) echo "<option value='{$a['id']}'>".htmlspecialchars($a['username'])."</option>";
                                            ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Available Client</label>
                                        <select name="client_id" class="form-control" required>
                                            <option value="" disabled selected>Select Client</option>
                                            <?php 
                                            $clients_result->data_seek(0);
                                            while($c = $clients_result->fetch_assoc()) echo "<option value='{$c['id']}'>".htmlspecialchars($c['username'])."</option>";
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" name="assign_client" class="btn btn-primary" style="margin-top: 20px;">Finalize Assignment</button>
                            </form>
                        </div>

                        <!-- Form: Add New Client -->
                        <div id="form-assign-new" style="display: none;">
                            <form action="agency_maintenance.php" method="POST" autocomplete="off">
                                <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div class="form-group">
                                        <label class="form-label">Client Username</label>
                                        <input type="text" name="client_username" class="form-control" required placeholder="Account username">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Password</label>
                                        <input type="password" name="client_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                                    </div>
                                </div>
                                <button type="submit" name="add_client" class="btn btn-primary" style="margin-top: 20px;">Create Client Account</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Current Business Assignments</h3></div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Agency</th>
                                    <th>Assigned Client</th>
                                    <th>Setup Date</th>
                                    <th>Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($mappings_result && $mappings_result->num_rows > 0): 
                                    while($row = $mappings_result->fetch_assoc()): ?>
                                    <tr onclick="openUnassignModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['agency_name']); ?>', '<?php echo addslashes($row['client_name']); ?>')">
                                        <td><strong><?php echo htmlspecialchars($row['agency_name']); ?></strong></td>
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($row['client_name']); ?></div>
                                        </td>
                                        <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></span></td>
                                        <td><button type="button" class="unassign-link" style="border:none; background:none; cursor:pointer;">Unassign</button></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="empty-state">No business mappings found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB: USER ACCOUNTS -->
            <div id="tab-user-accounts" class="tab-pane">
                <div class="card" style="max-width: 600px;">
                    <div class="card-header"><h3>Add New User (Supervisor)</h3></div>
                    <div class="card-body">
                        <form action="agency_maintenance.php" method="POST">
                            <div class="form-group">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <label class="form-label" style="margin-bottom: 0;">Full Name (Registered Personnel)</label>
                                    <span style="font-size: 0.7rem; color: #64748b;">Type name or select from list</span>
                                </div>
                                <input type="text" name="fullname" id="account_fullname" class="form-control" required placeholder="Select agency to see personnel..." list="personnel_list">
                                <datalist id="personnel_list"></datalist>
                                <div style="margin-top: 8px; font-size: 0.75rem; color: #64748b; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                    <strong>Login Tip:</strong> Supervisors use their <strong>Access Key</strong> as both Username and Password at <a href="login.php" style="color: var(--primary); font-weight: 600;">login.php</a>.
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
                                    <th>Access Key</th>
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
                                        <td><code><?php echo htmlspecialchars($sup['access_key']); ?></code></td>
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

        <!-- Status Process Modal (Generic) -->
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
    </main>

    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            if (btn) btn.classList.add('active');
        }

        function toggleAssignForm(type, btn) {
            document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            if(type === 'existing') {
                document.getElementById('form-assign-existing').style.display = 'block';
                document.getElementById('form-assign-new').style.display = 'none';
            } else {
                document.getElementById('form-assign-existing').style.display = 'none';
                document.getElementById('form-assign-new').style.display = 'block';
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Handle tab selection via URL parameter
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab === 'assignments') {
                const btn = document.querySelector('.tab-btn:nth-child(2)');
                switchTab('tab-assignments', btn);
            }
        });

        function openAgencyEditModal(id, name, qr, guard, insp, supervisor, client) {
            document.getElementById('edit_agency_id').value = id;
            document.getElementById('edit_agency_name').value = name;
            document.getElementById('edit_qr_limit').value = qr;
            document.getElementById('edit_guard_limit').value = guard;
            document.getElementById('edit_inspector_limit').value = insp;
            document.getElementById('edit_supervisor_limit').value = supervisor;
            document.getElementById('edit_client_limit').value = client;
            document.getElementById('editAgencyModal').classList.add('show');
        }

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
                // Collect all names from all agencies
                Object.values(personnelMap).forEach(list => {
                    names = names.concat(list);
                });
            } else if (personnelMap[agencyId]) {
                names = personnelMap[agencyId];
            }

            // Deduplicate and sort
            const uniqueNames = [...new Set(names)].sort();
            
            uniqueNames.forEach(name => {
                const option = document.createElement('option');
                option.value = name;
                datalist.appendChild(option);
            });
        }

        // Initialize lists on page load
        window.addEventListener('DOMContentLoaded', () => {
            const agencyId = document.getElementById('account_agency_id').value;
            if (agencyId) {
                filterPersonnelByAgency(agencyId);
                filterClientsByAgency(agencyId, 'account_clients_container');
            }
        });

        function toggleSelectAllClients(containerId, isChecked) {
            const container = document.getElementById(containerId);
            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = isChecked);
        }

        function filterClientsByAgency(agencyId, containerId, selectedIds = []) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            
            document.getElementById('select_all_clients').checked = false;

            let clients = [];
            if (agencyId === 'all') {
                Object.values(agencyClientsMap).forEach(list => {
                    clients = clients.concat(list);
                });
            } else if (agencyClientsMap[agencyId]) {
                clients = agencyClientsMap[agencyId];
            }

            if (clients.length === 0) {
                container.innerHTML = '<span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">No clients found for this selection...</span>';
                return;
            }

            // Deduplicate clients by ID (important for 'all' selection)
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

        function openSupervisorEditModal(sup) {
            document.getElementById('edit_supervisor_id').value = sup.id;
            document.getElementById('edit_sup_fullname').value = sup.name;
            document.getElementById('edit_sup_contact').value = sup.contact_no;
            
            const agencyId = sup.agency_id == 0 ? 'all' : sup.agency_id;
            document.getElementById('edit_sup_agency_id').value = agencyId;
            
            // Ensure personnel list is updated for the edit modal as well
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

        function openUnassignModal(id, agency, client) {
            document.getElementById('unassign_mapping_id').value = id;
            document.getElementById('unassign_agency_name').textContent = agency;
            document.getElementById('unassign_client_name').textContent = client;
            document.getElementById('unassignModal').classList.add('show');
        }
    </script>

    <!-- Edit Agency Modal -->
    <div id="editAgencyModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Edit Agency Limits</h3>
            <form action="agency_maintenance.php" method="POST">
                <input type="hidden" name="agency_id" id="edit_agency_id">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Agency Name</label>
                    <input type="text" id="edit_agency_name" class="form-control" disabled>
                </div>
                <div class="form-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px;">
                    <div class="form-group" style="text-align: left;"><label class="form-label">QR Limit</label><input type="number" name="qr_limit" id="edit_qr_limit" class="form-control" min="0"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Guard Limit</label><input type="number" name="guard_limit" id="edit_guard_limit" class="form-control" min="0"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Inspector</label><input type="number" name="inspector_limit" id="edit_inspector_limit" class="form-control" min="0"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Supervisor</label><input type="number" name="supervisor_limit" id="edit_supervisor_limit" class="form-control" min="0"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Client Limit</label><input type="number" name="client_limit" id="edit_client_limit" class="form-control" min="0"></div>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('editAgencyModal')">Cancel</button>
                    <button type="submit" name="update_agency_limits" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unassign Modal -->
    <div id="unassignModal" class="modal">
        <div class="modal-content">
            <div class="modal-icon">!</div>
            <h3>Confirm Unassignment</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Unassign <strong id="unassign_client_name"></strong> from <strong id="unassign_agency_name"></strong>?</p>
            <form action="agency_maintenance.php" method="POST">
                <input type="hidden" name="mapping_id" id="unassign_mapping_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('unassignModal')">Cancel</button>
                    <button type="submit" name="unassign_client_action" class="btn" style="background: #ef4444; color: white; flex: 1;">Unassign</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Supervisor Modal -->
    <div id="editSupervisorModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <h3 style="margin-bottom: 20px;">Edit Supervisor Account</h3>
            <form action="agency_maintenance.php" method="POST">
                <input type="hidden" name="edit_supervisor_id" id="edit_supervisor_id">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="fullname" id="edit_sup_fullname" class="form-control" required list="personnel_list">
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_no" id="edit_sup_contact" class="form-control">
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
            <div class="modal-icon">!</div>
            <h3>Delete Supervisor Account?</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Are you sure you want to delete <strong id="delete_supervisor_name"></strong>? This will remove their access permanently.</p>
            <form action="agency_maintenance.php" method="POST">
                <input type="hidden" name="delete_supervisor_id" id="delete_supervisor_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('deleteSupervisorModal')">Cancel</button>
                    <button type="submit" name="delete_supervisor_action" class="btn" style="background: #ef4444; color: white; flex: 1;">Delete Account</button>
                </div>
            </form>
        </div>
    </div>

<?php include 'admin_layout/footer.php'; ?>
</html>
