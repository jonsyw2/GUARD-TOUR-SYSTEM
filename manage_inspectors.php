<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;

// Ensure inspectors and assignments table exists
$conn->query("CREATE TABLE IF NOT EXISTS inspectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    agency_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS inspector_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspector_id INT NOT NULL,
    agency_client_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inspector_id) REFERENCES inspectors(id) ON DELETE CASCADE,
    FOREIGN KEY (agency_client_id) REFERENCES agency_clients(id) ON DELETE CASCADE
)");

$conn->query("ALTER TABLE inspectors ADD COLUMN IF NOT EXISTS contact_no VARCHAR(20) DEFAULT NULL");

$message = '';
$message_type = '';
$show_key_modal = false;
$show_limit_modal = false;
$show_status_modal = false;
$generated_key = '';

if (isset($_SESSION['inspector_created_key'])) {
    $generated_key = $_SESSION['inspector_created_key'];
    $show_key_modal = true;
    unset($_SESSION['inspector_created_key']);
}

// Function to generate unique 6-character alphanumeric key
function generateUniqueInspectorKey($conn) {
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

// Handle creating Inspector
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_inspector'])) {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = $conn->real_escape_string($_POST['middle_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    
    $contact_no = $conn->real_escape_string($_POST['contact_no'] ?? '');
    
    $fullname = trim($last_name . ", " . $first_name . " " . $middle_name);
    
    // Single dropdown: assigned_client is one value or empty
    $assigned_client = isset($_POST['assigned_client']) && $_POST['assigned_client'] !== '' ? (int)$_POST['assigned_client'] : null;
    $assigned_clients = $assigned_client ? [$assigned_client] : [];
    $can_create = true;
    
    if (!empty($assigned_clients)) {
        foreach ($assigned_clients as $client_id) {
            $client_id = (int)$client_id;
            $limit_sql = "
                SELECT ac.inspector_limit, 
                       (SELECT COUNT(*) FROM inspector_assignments WHERE agency_client_id = ac.id) as current_inspectors
                FROM agency_clients ac 
                WHERE ac.id = $client_id
            ";
            $res = $conn->query($limit_sql);
            if ($res && $row = $res->fetch_assoc()) {
                $max = (int)$row['inspector_limit'];
                $current = (int)$row['current_inspectors'];
                if ($max > 0 && $current >= $max) {
                    $message = "Creation failed: One or more selected clients have reached their inspector limit.";
                    $message_type = "error";
                    $show_limit_modal = true;
                    $can_create = false;
                    break;
                }
            }
        }
    }

    if ($can_create) {
        $unique_key = generateUniqueInspectorKey($conn);
        $hashed_password = password_hash($unique_key, PASSWORD_DEFAULT);
        
        $conn->begin_transaction();
        try {
            $conn->query("INSERT INTO users (username, password, user_level) VALUES ('$unique_key', '$hashed_password', 'inspector')");
            $user_id = $conn->insert_id;
            $conn->query("INSERT INTO inspectors (user_id, agency_id, name, contact_no) VALUES ($user_id, $agency_id, '$fullname', '$contact_no')");
            $new_inspector_id = $conn->insert_id;
            
            // Assign to Clients
            foreach ($assigned_clients as $client_id) {
                $client_id = (int)$client_id;
                $conn->query("INSERT INTO inspector_assignments (inspector_id, agency_client_id) VALUES ($new_inspector_id, $client_id)");
            }

            $conn->commit();
            $_SESSION['inspector_created_key'] = $unique_key;
            header("Location: manage_inspectors.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error creating inspector: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle updating Inspector
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_inspector'])) {
    $inspector_id = (int)$_POST['inspector_id'];
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = $conn->real_escape_string($_POST['middle_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $contact_no = $conn->real_escape_string($_POST['contact_no'] ?? '');
    
    $assigned_client = isset($_POST['assigned_client']) && $_POST['assigned_client'] !== '' ? (int)$_POST['assigned_client'] : null;

    $fullname = trim($last_name . ", " . $first_name . " " . $middle_name);
    
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE inspectors SET name = '$fullname', contact_no = '$contact_no' WHERE id = $inspector_id");
        $conn->query("DELETE FROM inspector_assignments WHERE inspector_id = $inspector_id");
        if ($assigned_client) {
            $conn->query("INSERT INTO inspector_assignments (inspector_id, agency_client_id) VALUES ($inspector_id, $assigned_client)");
        }
        $conn->commit();
        $message = "Inspector details updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error updating inspector: " . $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle deleting Inspector
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_inspector'])) {
    $inspector_id = (int)$_POST['inspector_id'];
    $res = $conn->query("SELECT user_id FROM inspectors WHERE id = $inspector_id");
    if ($res && $res->num_rows > 0) {
        $user_id = $res->fetch_assoc()['user_id'];
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM inspectors WHERE id = $inspector_id");
            $conn->query("DELETE FROM users WHERE id = $user_id");
            $conn->commit();
            $message = "Inspector account deleted successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting inspector: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Fetch Agency Clients for assignment
$clients_res = $conn->query("SELECT id, company_name, (SELECT username FROM users WHERE id = client_id) as client_username FROM agency_clients WHERE agency_id = $agency_id ORDER BY company_name ASC");
$all_clients = [];
if ($clients_res) {
    while($c = $clients_res->fetch_assoc()) $all_clients[] = $c;
}

// Fetch Inspectors created by this agency with assigned clients
$inspectors_sql = "
    SELECT i.id, i.name, i.contact_no, u.username, i.created_at,
           GROUP_CONCAT(COALESCE(ac.company_name, cu.username) SEPARATOR ', ') as assigned_clients,
           MAX(ia.agency_client_id) as client_id
    FROM inspectors i 
    JOIN users u ON i.user_id = u.id 
    LEFT JOIN inspector_assignments ia ON i.id = ia.inspector_id
    LEFT JOIN agency_clients ac ON ia.agency_client_id = ac.id
    LEFT JOIN users cu ON ac.client_id = cu.id
    WHERE i.agency_id = $agency_id 
    GROUP BY i.id
    ORDER BY i.id ASC
";
$inspectors_res = $conn->query($inspectors_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inspectors - Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #6366f1; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: 0.2s; }
        .btn-primary { background-color: #6366f1; color: white; width: 100%; }
        .btn-primary:hover { background-color: #4f46e5; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 450px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>

            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link active">Manage Inspectors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>

        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar"><h2>Inspector Management</h2></header>
        <div class="content-area">
            
            <div style="display: grid; grid-template-columns: 400px 1fr; gap: 24px;">
                <div class="card">
                    <h3 class="card-header">Register New Inspector</h3>
                    <form action="manage_inspectors.php" method="POST">
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" placeholder="e.g. Smith" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" placeholder="e.g. Alice" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Middle Name (Optional)</label>
                            <input type="text" name="middle_name" class="form-control" placeholder="e.g. Marie">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact No.</label>
                            <input type="text" name="contact_no" class="form-control" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Assign to Client (Optional)</label>
                            <select name="assigned_client" class="form-control">
                                <option value="">-- No Direct Assignment --</option>
                                <?php foreach ($all_clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['company_name'] ?: $client['client_username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p style="font-size: 0.8rem; color: #6b7280; margin-bottom: 20px;">An access key will be automatically generated upon account creation.</p>
                        <button type="submit" name="create_inspector" class="btn btn-primary">Create Inspector Account</button>
                    </form>
                </div>

                <div class="card">
                    <h3 class="card-header">Active Inspectors</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Assigned Clients</th>
                                <th>Access Key</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($inspectors_res->num_rows > 0): ?>
                                <?php while($row = $inspectors_res->fetch_assoc()): 
                                    $parts = explode(',', $row['name']);
                                    $last = trim($parts[0] ?? '');
                                    $first_mid = trim($parts[1] ?? '');
                                    $first_parts = explode(' ', $first_mid);
                                    $first = trim($first_parts[0] ?? '');
                                    unset($first_parts[0]);
                                    $middle = trim(implode(' ', $first_parts));
                                ?>
                                    <tr onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($last); ?>', '<?php echo addslashes($first); ?>', '<?php echo addslashes($middle); ?>', '<?php echo addslashes($row['contact_no'] ?? ''); ?>', '<?php echo $row['client_id'] ?? ''; ?>')" style="cursor: pointer;">
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td>
                                            <?php if ($row['assigned_clients']): ?>
                                                <div style="font-size: 0.8rem; color: #4b5563;"><?php echo htmlspecialchars($row['assigned_clients']); ?></div>
                                            <?php else: ?>
                                                <span style="font-size: 0.8rem; color: #9ca3af; font-style: italic;">No clients assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($row['username']); ?></code></td>
                                        <td>
                                            <button onclick="event.stopPropagation(); openDeleteModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>')" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #ef4444; color: white;">Delete</button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align: center; font-style: italic; color: #6b7280;">No inspectors found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Status Process Modal (Generic) -->
        <div id="statusModal" class="modal <?php echo $show_status_modal ? 'show' : ''; ?>">
            <div class="modal-content" style="max-width: 400px;">
                <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                    <?php echo $message_type === 'success' ? '✓' : '!'; ?>
                </div>
                <h3 style="margin-bottom: 10px;"><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
                <p style="color: #6b7280; margin-bottom: 24px;"><?php echo $message; ?></p>
                <button class="btn btn-primary" onclick="closeModal('statusModal')">Done</button>
            </div>
        </div>
    </main>

    <!-- Success Key Modal -->
    <div id="successKeyModal" class="modal <?php echo $show_key_modal ? 'show' : ''; ?>">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #d1fae5; color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">✓</div>
            <h3 style="margin-bottom: 10px;">Inspector Account Created!</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Provide this unique access key to the inspector.</p>
            <div style="background: #f9fafb; padding: 20px; border-radius: 12px; border: 2px dashed #d1d5db; margin-bottom: 24px;">
                <span style="font-size: 3rem; font-weight: 800; color: #111827; letter-spacing: 2px; font-family: monospace;"><?php echo htmlspecialchars($generated_key); ?></span>
            </div>
            <button class="btn btn-primary" onclick="closeModal('successKeyModal')">Done</button>
        </div>
    </div>

    <!-- Limit Reached Modal -->
    <div id="limitModal" class="modal <?php echo $show_limit_modal ? 'show' : ''; ?>">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3 style="margin-bottom: 10px;">Limit Reached</h3>
            <p style="color: #6b7280; margin-bottom: 24px;"><?php echo $message; ?></p>
            <button class="btn btn-primary" style="background: #111827;" onclick="closeModal('limitModal')">Understand</button>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editInspectorModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Edit Inspector Details</h3>
            <form action="manage_inspectors.php" method="POST">
                <input type="hidden" name="inspector_id" id="edit_inspector_id">
                <div class="form-group" style="text-align: left;"><label class="form-label">Last Name</label><input type="text" name="last_name" id="edit_last_name" class="form-control" required></div>
                <div class="form-group" style="text-align: left;"><label class="form-label">First Name</label><input type="text" name="first_name" id="edit_first_name" class="form-control" required></div>
                <div class="form-group" style="text-align: left;"><label class="form-label">Middle Name</label><input type="text" name="middle_name" id="edit_middle_name" class="form-control"></div>
                <div class="form-group" style="text-align: left;"><label class="form-label">Contact No.</label><input type="text" name="contact_no" id="edit_contact_no" class="form-control" placeholder="09XXXXXXXXX"></div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Assign to Client (Optional)</label>
                    <select name="assigned_client" id="edit_assigned_client" class="form-control">
                        <option value="">-- No Direct Assignment --</option>
                        <?php foreach ($all_clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['company_name'] ?: $client['client_username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('editInspectorModal')">Cancel</button>
                    <button type="submit" name="update_inspector" class="btn btn-primary" style="flex: 1;">Update Details</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteInspectorModal" class="modal">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3>Confirm Deletion</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Delete <strong id="delete_inspector_name"></strong>?</p>
            <form action="manage_inspectors.php" method="POST">
                <input type="hidden" name="inspector_id" id="delete_inspector_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('deleteInspectorModal')">Cancel</button>
                    <button type="submit" name="delete_inspector" class="btn" style="background: #ef4444; color: white; flex: 1;">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('logoutModal')">Cancel</button>
                <a href="logout.php" class="btn" style="background: #ef4444; color: white; text-decoration: none; flex: 1; display: flex; align-items: center; justify-content: center;">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }
        function openEditModal(id, last, first, middle, contact, client_id) {
            document.getElementById('edit_inspector_id').value = id;
            document.getElementById('edit_last_name').value = last;
            document.getElementById('edit_first_name').value = first;
            document.getElementById('edit_middle_name').value = middle;
            document.getElementById('edit_contact_no').value = contact;
            document.getElementById('edit_assigned_client').value = client_id;
            document.getElementById('editInspectorModal').classList.add('show');
        }
        function openDeleteModal(id, name) {
            document.getElementById('delete_inspector_id').value = id;
            document.getElementById('delete_inspector_name').textContent = name;
            document.getElementById('deleteInspectorModal').classList.add('show');
        }
        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) e.target.classList.remove('show');
        }
    </script>
    <!-- VERSION: 2.1 -->
</body>
</html>
