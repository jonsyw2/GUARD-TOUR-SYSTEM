<?php
session_start();
include 'db_config.php';

if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';

// Handle Add User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $new_username = $conn->real_escape_string($_POST['new_username']);
    $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $user_level = $conn->real_escape_string($_POST['user_level']);
    
    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$new_username'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Username already exists! Please choose another.";
        $message_type = "error";
    } else {
        $sql = "INSERT INTO users (username, password, user_level) VALUES ('$new_username', '$password', '$user_level')";
        if ($conn->query($sql) === TRUE) {
            $message = "User created successfully!";
            $message_type = "success";
        } else {
            $message = "Error creating user: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Handle Delete User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $delete_id = (int)$_POST['delete_id'];
    
    // Prevent admin from deleting themselves
    if ($delete_id == $_SESSION['user_id']) {
        $message = "You cannot delete your own account.";
        $message_type = "error";
    } else {
        $del_sql = "DELETE FROM users WHERE id = $delete_id";
        if ($conn->query($del_sql) === TRUE) {
            $message = "User deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting user: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Fetch all users
$users_sql = "SELECT id, username, user_level FROM users ORDER BY user_level ASC, username ASC";
$users_result = $conn->query($users_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Maintenance - Admin Dashboard</title>
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

        .grid-container { display: grid; grid-template-columns: 350px 1fr; gap: 24px; align-items: start; }
        @media (max-width: 900px) { .grid-container { grid-template-columns: 1fr; } }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-sm: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #3b82f6; }
        .btn { padding: 10px 18px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: background-color 0.2s; width: 100%; font-size: 1rem; }
        .btn:hover { background-color: #2563eb; }
        .btn-danger { background-color: white; color: #ef4444; border: 1px solid #ef4444; width: auto; padding: 6px 12px; font-size: 0.85rem;}
        .btn-danger:hover { background-color: #fee2e2; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: middle;}
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }

        .role-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .role-admin { background-color: #e0e7ff; color: #4f46e5; }
        .role-agency { background-color: #d1fae5; color: #10b981; }
        .role-client { background-color: #f3f4f6; color: #4b5563; }
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
                    <li><a href="agency_maintenance.php" class="submenu-link">Agency Maintenance</a></li>
                    <li><a href="users_maintenance.php" class="submenu-link active">User Maintenance</a></li>
                </ul>
            </li>
            <li><a href="manage_limits.php" class="nav-link">QR Limits</a></li>
            <li><a href="#" class="nav-link">Reports</a></li>
            <li><a href="#" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>User Maintenance</h2>
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
                <!-- Add User Form -->
                <div class="card">
                    <h3 class="card-header">Create New User</h3>
                    <form action="users_maintenance.php" method="POST">
                        <div class="form-group">
                            <label class="form-label" for="new_username">User Name</label>
                            <input type="text" id="new_username" name="new_username" class="form-control" required placeholder="Enter username">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required placeholder="Assign password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="user_level">Role / User Level</label>
                            <select id="user_level" name="user_level" class="form-control" required>
                                <option value="" disabled selected>-- Select Role --</option>
                                <option value="admin">Admin</option>
                                <option value="agency">Agency</option>
                                <option value="client">Client</option>
                            </select>
                        </div>
                        <button type="submit" name="add_user" class="btn">Create User</button>
                    </form>
                </div>

                <!-- Users List -->
                <div class="card">
                    <h3 class="card-header">All Users Directory</h3>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users_result && $users_result->num_rows > 0): ?>
                                    <?php while($row = $users_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                            <td>
                                                <span class="role-badge role-<?php echo htmlspecialchars($row['user_level']); ?>">
                                                    <?php echo htmlspecialchars($row['user_level']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <!-- Cannot delete if the user is the one currently logged in -->
                                                <?php if($row['id'] != ($_SESSION['user_id'] ?? null) && $row['username'] != $_SESSION['username']): ?>
                                                    <form action="users_maintenance.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');">
                                                        <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="empty-state">No users found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        function toggleSubmenu(menuId, element) {
            const menu = document.getElementById(menuId);
            const caret = element.querySelector('.caret');
            menu.classList.toggle('open');
            caret.classList.toggle('open');
        }
    </script>
</body>
</html>
