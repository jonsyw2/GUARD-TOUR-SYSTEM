<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;
$message = '';
$message_type = '';
$show_limit_modal = false;
$show_status_modal = false;

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

// Removed generateUniqueSupervisorKey function as it is no longer used.

// Handle Creating Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_supervisor'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = $conn->real_escape_string($_POST['middle_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    
    $fullname = trim($last_name . ", " . $first_name . " " . $middle_name);
    
    // Check if username already exists
    $user_check = $conn->query("SELECT id FROM users WHERE username = '$username'");
    if ($user_check && $user_check->num_rows > 0) {
        $message = "Creation failed: Username already exists.";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
        $assigned_clients = $_POST['assigned_clients'] ?? [];
        $can_create = true;
        
        if (!empty($assigned_clients)) {
            foreach ($assigned_clients as $client_id) {
                $client_id = (int)$client_id;
                // A client can only have ONE supervisor assigned (supervisor_id column in agency_clients)
                $limit_sql = "SELECT supervisor_limit, supervisor_id FROM agency_clients WHERE agency_id = $agency_id AND client_id = $client_id";
                $res = $conn->query($limit_sql);
                if ($res && $row = $res->fetch_assoc()) {
                    $max = (int)$row['supervisor_limit'];
                    $is_assigned = !empty($row['supervisor_id']);
                    if ($max > 0 && $is_assigned) {
                        $message = "Creation failed: One or more selected clients already have an assigned supervisor.";
                        $message_type = "error";
                        $show_limit_modal = true;
                        $can_create = false;
                        break;
                    }
                }
            }
        }

        if ($can_create) {
            $conn->begin_transaction();
            try {
                // 1. Create User
                $conn->query("INSERT INTO users (username, password, user_level) VALUES ('$username', '$hashed_password', 'supervisor')");
                $user_id = $conn->insert_id;
                
                $conn->query("INSERT INTO supervisors (user_id, agency_id, name, contact_no) VALUES ($user_id, $agency_id, '$fullname', '$contact_no')");
                $new_supervisor_id = $conn->insert_id;

                // 3. Assign to Clients
                if (!empty($assigned_clients)) {
                    foreach ($assigned_clients as $client_id) {
                        $client_id = (int)$client_id;
                        $conn->query("UPDATE agency_clients SET supervisor_id = $new_supervisor_id WHERE agency_id = $agency_id AND client_id = $client_id");
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
}

// Handle Editing Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_supervisor'])) {
    $sup_id = (int)$_POST['supervisor_id'];
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $name = $conn->real_escape_string($_POST['name']);
    $contact = $conn->real_escape_string($_POST['contact_no']);
    
    $fetch_user = $conn->query("SELECT user_id FROM supervisors WHERE id = $sup_id");
    if ($fetch_user && $fetch_user->num_rows > 0) {
        $user_id = $fetch_user->fetch_assoc()['user_id'];
        
        // Update user account
        $pw_sql = "";
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $pw_sql = ", password = '$hashed'";
        }
        
        $conn->query("UPDATE users SET username = '$username' $pw_sql WHERE id = $user_id");
        
        if ($conn->query("UPDATE supervisors SET name = '$name', contact_no = '$contact' WHERE id = $sup_id AND agency_id = $agency_id")) {
            $message = "Supervisor updated successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } else {
            $message = "Error updating supervisor: " . $conn->error;
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle Deleting Supervisor
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_supervisor'])) {
    $sup_id = (int)$_POST['supervisor_id'];
    $res = $conn->query("SELECT user_id FROM supervisors WHERE id = $sup_id AND agency_id = $agency_id");
    if ($res && $res->num_rows > 0) {
        $user_id = $res->fetch_assoc()['user_id'];
        $conn->begin_transaction();
        try {
            // Clear site assignments first
            $conn->query("UPDATE agency_clients SET supervisor_id = NULL WHERE supervisor_id = $sup_id AND agency_id = $agency_id");
            
            $conn->query("DELETE FROM supervisors WHERE id = $sup_id");
            $conn->query("DELETE FROM users WHERE id = $user_id");
            $conn->commit();
            $message = "Supervisor account deleted successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting supervisor: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Fetch Supervisors for this agency
$supervisors_res = $conn->query("
    SELECT s.*, u.username 
    FROM supervisors s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.agency_id = $agency_id 
    ORDER BY s.created_at DESC
");



$generated_key = '';
if (isset($_SESSION['supervisor_created_key'])) {
    $generated_key = $_SESSION['supervisor_created_key'];
    unset($_SESSION['supervisor_created_key']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Supervisors - Agency Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }

        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; flex-shrink: 0; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .grid { display: grid; grid-template-columns: 350px 1fr; gap: 24px; align-items: start; }
        
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
        .btn { padding: 10px 18px; background-color: #10b981; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; width: 100%; transition: background 0.2s; }
        .btn:hover { background-color: #059669; }
        .btn-secondary { background: #6366f1; }
        .btn-secondary:hover { background: #4f46e5; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>
            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>

        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Supervisor Management</h2>
            <div class="user-info">
                <span class="badge" style="background: #e0f2fe; color: #0369a1;">AGENCY CONTROL</span>
            </div>
        </header>

        <div class="content-area">
            <?php if ($message): ?>
                <div style="padding: 16px; border-radius: 8px; margin-bottom: 24px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#065f46' : '#991b1b'; ?>; border: 1px solid <?php echo $message_type === 'success' ? '#34d399' : '#f87171'; ?>;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="grid">
                <div class="card">
                    <h3 class="card-header">Add New Supervisor</h3>
                    <form action="manage_supervisors.php" method="POST">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required placeholder="Account username">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required placeholder="••••••••">
                        </div>
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Middle Name (Optional)</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_no" class="form-control" placeholder="09XXXXXXXXX">
                        </div>
                        <button type="submit" name="create_supervisor" class="btn">Create Account</button>
                    </form>
                </div>

                <div class="card">
                    <h3 class="card-header">Existing Supervisors</h3>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Contact</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($supervisors_res && $supervisors_res->num_rows > 0): ?>
                                    <?php while($row = $supervisors_res->fetch_assoc()): ?>
                                        <tr onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['contact_no']); ?>', '<?php echo addslashes($row['username']); ?>')" style="cursor: pointer;">
                                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                            <td><code><?php echo htmlspecialchars($row['username']); ?></code></td>
                                            <td><?php echo htmlspecialchars($row['contact_no'] ?: '---'); ?></td>
                                            <td>
                                                <button onclick="event.stopPropagation(); openDeleteModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>')" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.8rem; width: auto;">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" style="text-align: center; color: #6b7280; padding: 40px;">No supervisors registered yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>


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
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Edit Supervisor</h3>
            <form action="manage_supervisors.php" method="POST">
                <input type="hidden" name="supervisor_id" id="edit_id">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" id="edit_username" class="form-control" required>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">New Password (Leave blank to keep current)</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••">
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_no" id="edit_contact" class="form-control">
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151;" onclick="document.getElementById('editModal').classList.remove('show')">Cancel</button>
                    <button type="submit" name="update_supervisor" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3>Confirm Deletion</h3>
            <p style="color: #6b7280; margin: 12px 0 24px;">Are you sure you want to delete <strong id="delete_name"></strong>? This will permanentely remove their access.</p>
            <form action="manage_supervisors.php" method="POST">
                <input type="hidden" name="supervisor_id" id="delete_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151;" onclick="document.getElementById('deleteModal').classList.remove('show')">Cancel</button>
                    <button type="submit" name="delete_supervisor" class="btn btn-danger">Delete Supervisor</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Ready to Logout?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn" style="background: #f3f4f6; color: #374151;" onclick="document.getElementById('logoutModal').classList.remove('show')">Cancel</button>
                <a href="logout.php" class="btn btn-danger" style="text-decoration: none;">Logout</a>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(id, name, contact, username) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_contact').value = contact;
            document.getElementById('edit_username').value = username;
            document.getElementById('editModal').classList.add('show');
        }

        function openDeleteModal(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').innerText = name;
            document.getElementById('deleteModal').classList.add('show');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
    </script>
</body>
</html>
