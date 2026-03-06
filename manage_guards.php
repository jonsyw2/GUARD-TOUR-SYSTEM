<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;

$message = '';
$message_type = '';
$show_key_modal = false;
$show_limit_modal = false;
$generated_key = '';

if (isset($_SESSION['guard_created_key'])) {
    $generated_key = $_SESSION['guard_created_key'];
    $show_key_modal = true;
    unset($_SESSION['guard_created_key']);
}

// Function to generate unique 6-character alphanumeric key
function generateUniqueGuardKey($conn) {
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

// Handle creating Guard
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_guard'])) {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = $conn->real_escape_string($_POST['middle_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    
    // Format Full Name: Last, First Middle
    $fullname = $last_name . ", " . $first_name . " " . $middle_name;
    $fullname = trim($fullname);
    
    // Check Agency Guard Limit
    $limit_check = $conn->query("SELECT guard_limit FROM users WHERE id = $agency_id");
    $current_count_check = $conn->query("SELECT COUNT(*) as count FROM guards WHERE agency_id = $agency_id");
    
    if ($limit_check && $current_count_check) {
        $max_guards = $limit_check->fetch_assoc()['guard_limit'];
        $current_guards = $current_count_check->fetch_assoc()['count'];
        
        if ($max_guards > 0 && $current_guards >= $max_guards) {
            $message = "Creation failed: Your agency has reached its maximum limit of $max_guards guards.";
            $message_type = "error";
            $show_limit_modal = true;
        } else {
            // Generate Unique 6-character Key
            $unique_key = generateUniqueGuardKey($conn);
            $hashed_password = password_hash($unique_key, PASSWORD_DEFAULT);
            
            $conn->begin_transaction();
            try {
                // 1. Create User (Username is the unique key)
                $conn->query("INSERT INTO users (username, password, user_level) VALUES ('$unique_key', '$hashed_password', 'guard')");
                $user_id = $conn->insert_id;
                
                // 2. Create Guard entry
                $conn->query("INSERT INTO guards (user_id, agency_id, name) VALUES ($user_id, $agency_id, '$fullname')");
                
                $conn->commit();
                $_SESSION['guard_created_key'] = $unique_key;
                header("Location: manage_guards.php");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error creating guard: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Handle updating Guard
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_guard'])) {
    $guard_id = (int)$_POST['guard_id'];
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = $conn->real_escape_string($_POST['middle_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    
    $fullname = trim($last_name . ", " . $first_name . " " . $middle_name);
    
    if ($conn->query("UPDATE guards SET name = '$fullname' WHERE id = $guard_id")) {
        $message = "Guard details updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating guard: " . $conn->error;
        $message_type = "error";
    }
}

// Handle deleting Guard
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_guard'])) {
    $guard_id = (int)$_POST['guard_id'];
    
    // Get user_id first
    $res = $conn->query("SELECT user_id FROM guards WHERE id = $guard_id");
    if ($res && $res->num_rows > 0) {
        $user_id = $res->fetch_assoc()['user_id'];
        
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM guard_assignments WHERE guard_id = $guard_id");
            $conn->query("DELETE FROM guards WHERE id = $guard_id");
            $conn->query("DELETE FROM users WHERE id = $user_id");
            $conn->commit();
            $message = "Guard account deleted successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting guard: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Handle Assigning Guard to Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_guard'])) {
    $guard_id = (int)$_POST['guard_id'];
    $mapping_id = (int)$_POST['agency_client_id'];
    
    // Check if already assigned
    $check_assigned = $conn->query("SELECT id FROM guard_assignments WHERE guard_id = $guard_id AND agency_client_id = $mapping_id");
    if ($check_assigned && $check_assigned->num_rows > 0) {
        $message = "This guard is already assigned to this client.";
        $message_type = "error";
    } else {
        // Check Agency Guard Limit (Ensuring we don't exceed global pool if assignments are the constraint)
        // Note: The user wants limits at the agency level. If assignments are still per-site, 
        // they might still want a per-site subset, but the request was to move them TO the agency.
        // For now, I'll allow unlimited assignments per site as long as the agency owns the guards,
        // unless you want to keep per-site limits (in which case they'd be managed by the agency).
        
        if ($conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $mapping_id)")) {
            $message = "Guard assigned to client successfully!";
            $message_type = "success";
        } else {
            $message = "Error assigning guard: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Fetch Guards created by this agency
$guards_sql = "SELECT g.id, g.name, u.username, g.created_at FROM guards g JOIN users u ON g.user_id = u.id WHERE g.agency_id = $agency_id ORDER BY g.created_at DESC";
$guards_res = $conn->query($guards_sql);

// Fetch assigned clients for the dropdown
$clients_sql = "
    SELECT ac.id as mapping_id, u.username as client_name 
    FROM agency_clients ac 
    JOIN users u ON ac.client_id = u.id 
    WHERE ac.agency_id = $agency_id 
    ORDER BY u.username ASC
";
$clients_res = $conn->query($clients_sql);
$clients_data = [];
if ($clients_res) {
    while($row = $clients_res->fetch_assoc()) $clients_data[] = $row;
}

// Fetch current assignments for the table
$assignments_sql = "
    SELECT ga.id, g.name as guard_name, u.username as client_name, ga.assigned_at
    FROM guard_assignments ga
    JOIN guards g ON ga.guard_id = g.id
    JOIN agency_clients ac ON ga.agency_client_id = ac.id
    JOIN users u ON ac.client_id = u.id
    WHERE g.agency_id = $agency_id
    ORDER BY ga.assigned_at DESC
";
$assignments_res = $conn->query($assignments_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Guards - Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Reusing common styles from manage_qrs.php */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px;}
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
        .btn { padding: 10px 18px; background-color: #10b981; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; width: 100%; }
        .btn:hover { background-color: #059669; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show, .modal.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }

        /* Centered Modal Specifics */
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(4px); }

        /* Tab Styles */
        .tabs-container { margin-bottom: 32px; }
        .tabs-nav { display: flex; gap: 0; border-bottom: 1px solid #e5e7eb; margin-bottom: 24px; background: white; border-radius: 8px 8px 0 0; overflow: hidden; }
        .tab-btn { flex: 1; padding: 16px; border: none; background: #f9fafb; cursor: pointer; font-weight: 600; color: #6b7280; font-size: 0.95rem; transition: all 0.2s; border-bottom: 2px solid transparent; }
        .tab-btn:hover { background: #f3f4f6; color: #111827; }
        .tab-btn.active { background: white; color: #10b981; border-bottom-color: #10b981; }
        .tab-content { display: none; width: 100%; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>
            <li><a href="manage_qrs.php" class="nav-link">Manage QRs</a></li>
            <li><a href="manage_guards.php" class="nav-link active">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Personnel & Assignment Management</h2>
        </header>

        <div class="content-area">
            <?php if ($message && !$show_limit_modal): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="tabs-container">
                <div class="tabs-nav">
                    <button class="tab-btn active" onclick="switchTab('registerTab', this)">Register New Guard</button>
                    <button class="tab-btn" onclick="switchTab('assignTab', this)">Assign Guard to Client</button>
                </div>

                <!-- Register Tab Content -->
                <div id="registerTab" class="tab-content active">
                    <div class="card" style="max-width: 600px; margin: 0 auto;">
                        <h3 class="card-header">Register New Guard</h3>
                        <form action="manage_guards.php" method="POST">
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" placeholder="e.g. Doe" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" placeholder="e.g. John" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Middle Name (Optional)</label>
                                <input type="text" name="middle_name" class="form-control" placeholder="e.g. Quincey">
                            </div>
                            <p style="font-size: 0.8rem; color: #6b7280; margin-bottom: 20px;">
                                An access key will be automatically generated upon account creation.
                            </p>
                            <button type="submit" name="create_guard" class="btn">Create Account</button>
                        </form>
                    </div>
                </div>

                <!-- Assign Tab Content -->
                <div id="assignTab" class="tab-content">
                    <div class="card" style="max-width: 600px; margin: 0 auto;">
                        <h3 class="card-header">Assign Guard to Client</h3>
                        <?php if ($guards_res->num_rows == 0): ?>
                            <p class="empty-state">No guards created yet.</p>
                        <?php elseif (empty($clients_data)): ?>
                            <p class="empty-state">No clients assigned to your agency.</p>
                        <?php else: ?>
                            <form action="manage_guards.php" method="POST">
                                <div class="form-group">
                                    <label class="form-label">Select Guard</label>
                                    <select name="guard_id" class="form-control" required>
                                        <option value="" disabled selected>-- Choose Guard --</option>
                                        <?php 
                                        $guards_res->data_seek(0);
                                        while($g = $guards_res->fetch_assoc()): ?>
                                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?> [<?php echo htmlspecialchars($g['username']); ?>]</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Select Client</label>
                                    <select name="agency_client_id" class="form-control" required>
                                        <option value="" disabled selected>-- Choose Client --</option>
                                        <?php foreach($clients_data as $client): ?>
                                            <option value="<?php echo $client['mapping_id']; ?>"><?php echo htmlspecialchars($client['client_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="assign_guard" class="btn">Assign to Site</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- List Guards -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 class="card-header">Active Guards</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Access Key</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $guards_res->data_seek(0);
                        if ($guards_res->num_rows > 0): ?>
                            <?php while($row = $guards_res->fetch_assoc()): 
                                // Parse name for editing: "Last, First Middle"
                                $full_name_raw = $row['name'];
                                $parts = explode(',', $full_name_raw);
                                $last = trim($parts[0] ?? '');
                                $first_mid = trim($parts[1] ?? '');
                                $first_parts = explode(' ', $first_mid);
                                $first = trim($first_parts[0] ?? '');
                                unset($first_parts[0]);
                                $middle = trim(implode(' ', $first_parts));
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td><code><?php echo htmlspecialchars($row['username']); ?></code></td>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <button onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($last); ?>', '<?php echo addslashes($first); ?>', '<?php echo addslashes($middle); ?>')" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #6366f1;">Edit</button>
                                            <button onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>')" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #ef4444;">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="empty-state">No personnel records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- List Assignments -->
            <div class="card">
                <h3 class="card-header">Client Assignments</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Guard Name</th>
                            <th>Client / Site</th>
                            <th>Assigned Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($assignments_res && $assignments_res->num_rows > 0): ?>
                            <?php while($row = $assignments_res->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['guard_name']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['client_name']); ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($row['assigned_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="empty-state">No active client assignments.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Success Key Modal -->
    <div id="successKeyModal" class="modal <?php echo $show_key_modal ? 'show' : ''; ?>">
        <div class="modal-content" style="text-align: center; max-width: 450px;">
            <div style="width: 60px; height: 60px; background: #d1fae5; color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">✓</div>
            <h3 style="margin-bottom: 10px; font-size: 1.5rem;">Account Created!</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Please provide this unique access key to the guard for logging into the portal.</p>
            
            <div style="background: #f9fafb; padding: 20px; border-radius: 12px; border: 2px dashed #d1d5db; margin-bottom: 24px;">
                <span style="display: block; font-size: 0.8rem; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">Access Key</span>
                <span style="font-size: 3rem; font-weight: 800; color: #111827; letter-spacing: 2px; font-family: monospace;" id="displayKey"><?php echo htmlspecialchars($generated_key); ?></span>
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

    <!-- Edit Guard Modal -->
    <div id="editGuardModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <h3 style="margin-bottom: 20px;">Edit Guard Details</h3>
            <form action="manage_guards.php" method="POST">
                <input type="hidden" name="guard_id" id="edit_guard_id">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Middle Name (Optional)</label>
                    <input type="text" name="middle_name" id="edit_middle_name" class="form-control">
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151;" onclick="closeModal('editGuardModal')">Cancel</button>
                    <button type="submit" name="update_guard" class="btn">Update Details</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteGuardModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3 style="margin-bottom: 10px;">Confirm Deletion</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Are you sure you want to delete <strong id="delete_guard_name"></strong>? This will permanently remove their access and assignments.</p>
            <form action="manage_guards.php" method="POST">
                <input type="hidden" name="guard_id" id="delete_guard_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151;" onclick="closeModal('deleteGuardModal')">Cancel</button>
                    <button type="submit" name="delete_guard" class="btn" style="background: #ef4444;">Delete Guard</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn" style="background: #f3f4f6; color: #374151;" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" class="btn" style="background: #ef4444; text-decoration: none;">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        // Auto-switch to appropriate tab if form was submitted
        <?php if (isset($_POST['assign_guard'])): ?>
        switchTab('assignTab', document.querySelectorAll('.tab-btn')[1]);
        <?php endif; ?>

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function openEditModal(id, last, first, middle) {
            document.getElementById('edit_guard_id').value = id;
            document.getElementById('edit_last_name').value = last;
            document.getElementById('edit_first_name').value = first;
            document.getElementById('edit_middle_name').value = middle;
            document.getElementById('edit_guardModal').classList.add('show');
        }
        
        // Correction: Fixed modal ID naming consistency
        function openEditModal(id, last, first, middle) {
            document.getElementById('edit_guard_id').value = id;
            document.getElementById('edit_last_name').value = last;
            document.getElementById('edit_first_name').value = first;
            document.getElementById('edit_middle_name').value = middle;
            document.getElementById('editGuardModal').classList.add('show');
        }

        function openDeleteModal(id, name) {
            document.getElementById('delete_guard_id').value = id;
            document.getElementById('delete_guard_name').textContent = name;
            document.getElementById('deleteGuardModal').classList.add('show');
        }

        window.onclick = function(event) {
            const logoutModal = document.getElementById('logoutModal');
            const successModal = document.getElementById('successKeyModal');
            const editModal = document.getElementById('editGuardModal');
            const deleteModal = document.getElementById('deleteGuardModal');
            
            if (event.target == logoutModal) logoutModal.classList.remove('show');
            if (event.target == successModal) successModal.classList.remove('show');
            if (event.target == editModal) editModal.classList.remove('show');
            if (event.target == deleteModal) deleteModal.classList.remove('show');
        }
    </script>
</body>
</html>
