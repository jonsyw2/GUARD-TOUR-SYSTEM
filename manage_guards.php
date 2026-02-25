<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;

$message = '';
$message_type = '';

// Handle creating Guard
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_guard'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $fullname = $conn->real_escape_string($_POST['fullname']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Check if username already exists
    $check_uname = $conn->query("SELECT id FROM users WHERE username = '$username'");
    if ($check_uname && $check_uname->num_rows > 0) {
        $message = "Error: Username '$username' already exists.";
        $message_type = "error";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Create User
            $conn->query("INSERT INTO users (username, password, user_level) VALUES ('$username', '$password', 'guard')");
            $user_id = $conn->insert_id;
            
            // 2. Create Guard entry
            $conn->query("INSERT INTO guards (user_id, agency_id, name) VALUES ($user_id, $agency_id, '$fullname')");
            
            $conn->commit();
            $message = "Guard account for '$fullname' created successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error creating guard: " . $e->getMessage();
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
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_qrs.php" class="nav-link">Manage QRs</a></li>
            <li><a href="manage_guards.php" class="nav-link active">Manage Guards</a></li>
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
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <div class="grid-container">
                <!-- Create Guard Card -->
                <div class="card">
                    <h3 class="card-header">Register New Guard</h3>
                    <form action="manage_guards.php" method="POST">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="fullname" class="form-control" placeholder="e.g. John Doe" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Username (for login)</label>
                            <input type="text" name="username" class="form-control" placeholder="e.g. jdoe_guard" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <button type="submit" name="create_guard" class="btn">Create Account</button>
                    </form>
                </div>

                <!-- Assign Guard Card -->
                <div class="card">
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
                                        <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?> (<?php echo htmlspecialchars($g['username']); ?>)</option>
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

            <!-- List Guards -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 class="card-header">Active Guards</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $guards_res->data_seek(0);
                        if ($guards_res->num_rows > 0): ?>
                            <?php while($row = $guards_res->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
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
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>
