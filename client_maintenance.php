<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';
$show_status_modal = false;

// Handle Add Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_client'])) {
    $new_username = $conn->real_escape_string($_POST['new_username']);
    $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $user_level = 'client';
    
    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$new_username'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Username already exists! Please choose another.";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $sql = "INSERT INTO users (username, password, user_level) VALUES ('$new_username', '$password', '$user_level')";
        if ($conn->query($sql) === TRUE) {
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
    
    // Prevent admin from deleting themselves
    if ($delete_id == $_SESSION['user_id']) {
        $message = "You cannot delete your own account.";
        $message_type = "error";
    } else {
        $del_sql = "DELETE FROM users WHERE id = $delete_id AND user_level = 'client'";
        if ($conn->query($del_sql) === TRUE) {
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

// Fetch all clients
$users_sql = "SELECT id, username, user_level FROM users WHERE user_level = 'client' ORDER BY username ASC";
$users_result = $conn->query($users_sql);

?>
<?php
$page_title = 'Client Maintenance';
$header_title = 'Client Directory Management';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="content-area">

            <div class="form-grid">
                <!-- Add User Form -->
                <div class="card">
                    <div class="card-header"><h3>Create New Client Account</h3></div>
                    <div class="card-body">
                        <form action="client_maintenance.php" method="POST">
                            <div class="form-group">
                                <label class="form-label">Client Username</label>
                                <input type="text" name="new_username" class="form-control" required placeholder="Enter client username" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Secure Password</label>
                                <input type="password" name="new_password" class="form-control" required placeholder="Assign initial password" autocomplete="new-password">
                            </div>
                            <button type="submit" name="add_client" class="btn btn-primary">Create Client Profile</button>
                        </form>
                    </div>
                </div>

                <!-- Users List -->
                <div class="card">
                    <div class="card-header"><h3>Active Clients Directory</h3></div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Client Username</th>
                                    <th>Access level</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users_result && $users_result->num_rows > 0): ?>
                                    <?php while($row = $users_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="text-muted">#<?php echo $row['id']; ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                            <td>
                                                <span class="status-badge status-warning">
                                                    <?php echo htmlspecialchars($row['user_level']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if($row['id'] != ($_SESSION['user_id'] ?? null) && $row['username'] != $_SESSION['username']): ?>
                                                    <form action="client_maintenance.php" method="POST" onsubmit="return confirm('Permanently delete this client?');">
                                                        <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="delete_client" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.8rem;">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="empty-state">No clients found in the directory.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
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

        <style>
            .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
            .modal.show { display: flex; }
            .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        </style>

        <script>
            function closeModal(id) { document.getElementById(id).classList.remove('show'); }
        </script>
    </main>

<?php include 'admin_layout/footer.php'; ?>
