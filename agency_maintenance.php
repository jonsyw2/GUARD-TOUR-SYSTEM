<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';

// Handle Add Agency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_agency'])) {
    $agency_name = $conn->real_escape_string($_POST['agency_name']);
    $password = password_hash($_POST['agency_password'], PASSWORD_DEFAULT);
    
    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$agency_name'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Agency username already exists!";
        $message_type = "error";
    } else {
        $sql = "INSERT INTO users (username, password, user_level) VALUES ('$agency_name', '$password', 'agency')";
        if ($conn->query($sql) === TRUE) {
            $message = "Agency added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding agency: " . $conn->error;
            $message_type = "error";
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
        } else {
            $sql = "INSERT INTO agency_clients (agency_id, client_id) VALUES ($agency_id, $client_id)";
            if ($conn->query($sql) === TRUE) {
                $message = "Client assigned to agency successfully!";
                $message_type = "success";
            } else {
                $message = "Error assigning client: " . $conn->error;
                $message_type = "error";
            }
        }
    } else {
        $message = "Please select both an agency and a client.";
        $message_type = "error";
    }
}

// Fetch all agencies for dropdowns
$agencies_result = $conn->query("SELECT id, username FROM users WHERE user_level = 'agency' ORDER BY username ASC");

// Fetch all clients for dropdowns
$clients_result = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch agency-client mappings
$mapping_sql = "
    SELECT ac.id, a.username AS agency_name, c.username AS client_name, ac.created_at
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    ORDER BY a.username ASC, c.username ASC
";
$mappings_result = $conn->query($mapping_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agency Maintenance - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        
        /* Submenu Styles */
        .has-submenu { cursor: pointer; justify-content: space-between; }
        .submenu { display: none; background-color: #0f172a; list-style: none; padding-left: 0; }
        .submenu.open { display: block; }
        .submenu-link { padding: 12px 24px 12px 48px; display: block; color: #9ca3af; text-decoration: none; font-size: 0.95rem; transition: all 0.2s; }
        .submenu-link:hover, .submenu-link.active { color: #fff; background-color: #1f2937; }
        .caret { transition: transform 0.2s; font-size: 0.8rem; }
        .caret.open { transform: rotate(180deg); }

        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; }
        .btn-confirm:hover { background: #be123c; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #e0e7ff; color: #4f46e5; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; display: flex; align-items: center; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; align-items: start;}
        @media (max-width: 768px) { .grid-container { grid-template-columns: 1fr; } }
        
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-sm: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-size: 0.95rem; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: #3b82f6; ring: 2px solid #93c5fd; }
        .btn { padding: 10px 18px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: background-color 0.2s; width: 100%; font-size: 1rem; }
        .btn:hover { background-color: #2563eb; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Admin Panel
        </div>
        <ul class="nav-links">
            <li><a href="admin_dashboard.php" class="nav-link">Dashboard</a></li>
            <li>
                <div class="nav-link has-submenu" onclick="toggleSubmenu('maintenanceMenu', this)">
                    <span>Maintenance</span>
                    <span class="caret open">&#9660;</span>
                </div>
                <ul class="submenu open" id="maintenanceMenu">
                    <li><a href="agency_maintenance.php" class="submenu-link active">Agency Maintenance</a></li>
                    <li><a href="users_maintenance.php" class="submenu-link">User Maintenance</a></li>
                </ul>
            </li>
            <li><a href="manage_limits.php" class="nav-link">QR Limits</a></li>
            <li><a href="login_logs_view.php" class="nav-link">Login Logs</a></li>
            <li><a href="#" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Agency Maintenance</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin'; ?></strong></span>
                <span class="badge">admin</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="grid-container">
                <!-- Add Agency Form -->
                <div class="card">
                    <h3 class="card-header">Add New Agency</h3>
                    <form action="agency_maintenance.php" method="POST">
                        <div class="form-group">
                            <label class="form-label" for="agency_name">Agency Name</label>
                            <input type="text" id="agency_name" name="agency_name" class="form-control" required placeholder="Enter agency name">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="agency_password">Password</label>
                            <input type="password" id="agency_password" name="agency_password" class="form-control" required placeholder="Assign a password">
                        </div>
                        <button type="submit" name="add_agency" class="btn">Create Agency</button>
                    </form>
                </div>

                <!-- Assign Client Form -->
                <div class="card">
                    <h3 class="card-header">Assign Client to Agency</h3>
                    <form action="agency_maintenance.php" method="POST">
                        <div class="form-group">
                            <label class="form-label" for="agency_id">Select Agency</label>
                            <select id="agency_id" name="agency_id" class="form-control" required>
                                <option value="" disabled selected>-- Choose Agency --</option>
                                <?php 
                                if($agencies_result && $agencies_result->num_rows > 0) {
                                    $agencies_result->data_seek(0);
                                    while($agency = $agencies_result->fetch_assoc()) {
                                        echo '<option value="'.$agency['id'].'">'.htmlspecialchars($agency['username']).'</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="client_id">Select Client</label>
                            <select id="client_id" name="client_id" class="form-control" required>
                                <option value="" disabled selected>-- Choose Client --</option>
                                <?php 
                                if($clients_result && $clients_result->num_rows > 0) {
                                    $clients_result->data_seek(0);
                                    while($client = $clients_result->fetch_assoc()) {
                                        echo '<option value="'.$client['id'].'">'.htmlspecialchars($client['username']).'</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <button type="submit" name="assign_client" class="btn">Assign Client</button>
                    </form>
                </div>
            </div>

            <!-- List of Assignments -->
            <div class="card">
                <h3 class="card-header">Agency & Client Assignments</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Agency Name</th>
                                <th>Assigned Client</th>
                                <th>Assignment Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mappings_result && $mappings_result->num_rows > 0): ?>
                                <?php while($row = $mappings_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['agency_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <a href="#" style="color: #ef4444; font-size: 0.875rem; text-decoration: none; font-weight: 500;">Unassign</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="empty-state">No clients have been assigned to any agencies yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </div>
            <h3 class="modal-title">Ready to Leave?</h3>
            <p class="modal-text">Select "Log Out" below if you are ready to end your current dashboard session.</p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" class="btn-modal btn-confirm">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function toggleSubmenu(menuId, element) {
            const menu = document.getElementById(menuId);
            const caret = element.querySelector('.caret');
            menu.classList.toggle('open');
            caret.classList.toggle('open');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>
