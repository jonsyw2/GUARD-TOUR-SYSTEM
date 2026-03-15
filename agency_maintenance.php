<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Ensure limits columns exist in users table
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS qr_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS guard_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS inspector_qr_limit INT DEFAULT 0");

$message = '';
$message_type = '';
$show_status_modal = false;

// Handle updating agency limits
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_agency_limits'])) {
    $agency_id = (int)$_POST['agency_id'];
    $qr_limit = (int)$_POST['qr_limit'];
    $guard_limit = (int)$_POST['guard_limit'];
    $inspector_qr_limit = (int)$_POST['inspector_qr_limit'];

    if ($conn->query("UPDATE users SET qr_limit = $qr_limit, guard_limit = $guard_limit, inspector_qr_limit = $inspector_qr_limit WHERE id = $agency_id")) {
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
    $inspector_qr_limit = (int)$_POST['inspector_qr_limit'];
    
    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$agency_name'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $message = "Agency username already exists!";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $sql = "INSERT INTO users (username, password, user_level, qr_limit, guard_limit, inspector_qr_limit) 
                VALUES ('$agency_name', '$password', 'agency', $qr_limit, $guard_limit, $inspector_qr_limit)";
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
    } else {
        $message = "Please select both an agency and a client.";
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Add and Assign Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_assign_client'])) {
    $agency_id = (int)$_POST['agency_id'];
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
        // Start transaction
        $conn->begin_transaction();
        try {
            // Insert new client
            $sql_user = "INSERT INTO users (username, password, user_level) VALUES ('$client_username', '$password', 'client')";
            if (!$conn->query($sql_user)) {
                throw new Exception("Error creating client user: " . $conn->error);
            }
            $client_id = $conn->insert_id;

            // Assign to agency
            $sql_assign = "INSERT INTO agency_clients (agency_id, client_id) VALUES ($agency_id, $client_id)";
            if (!$conn->query($sql_assign)) {
                throw new Exception("Error assigning client to agency: " . $conn->error);
            }

            $conn->commit();
            $message = "Client created and assigned successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle Add Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_client'])) {
    $new_username = $conn->real_escape_string($_POST['new_username']);
    $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $checkSql = "SELECT id FROM users WHERE username = '$new_username'";
    $result = $conn->query($checkSql);
    if ($result->num_rows > 0) {
        $message = "Client username already exists!";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        $sql = "INSERT INTO users (username, password, user_level) VALUES ('$new_username', '$password', 'client')";
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

// Fetch all agencies for dropdowns
$agencies_result = $conn->query("SELECT id, username, qr_limit, guard_limit, inspector_qr_limit FROM users WHERE user_level = 'agency' ORDER BY username ASC");

// Fetch all clients
$clients_directory = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch all clients for dropdowns
$clients_result = $conn->query("SELECT id, username FROM users WHERE user_level = 'client' ORDER BY username ASC");

// Fetch agency-client mappings
$mapping_sql = "
    SELECT ac.id, a.username AS agency_name, c.username AS client_name, ac.created_at, 
           a.qr_limit, a.guard_limit, a.inspector_qr_limit
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    ORDER BY a.username ASC, c.username ASC
";
$mappings_result = $conn->query($mapping_sql);

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
                            <div class="form-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;">
                                <div class="form-group"><label class="form-label">QR Limit</label><input type="number" name="qr_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Guard Limit</label><input type="number" name="guard_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Inspector QR</label><input type="number" name="inspector_qr_limit" class="form-control" value="0" min="0"></div>
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
                                    <tr onclick="openAgencyEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['username']); ?>', <?php echo $row['qr_limit']; ?>, <?php echo $row['guard_limit']; ?>, <?php echo $row['inspector_qr_limit']; ?>)">
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px;">
                                                QR: <?php echo $row['qr_limit']; ?> | 
                                                Guards: <?php echo $row['guard_limit']; ?> | 
                                                Insp: <?php echo $row['inspector_qr_limit']; ?>
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
                            <button type="button" class="toggle-btn" onclick="toggleAssignForm('new', this)">Add & Assign New</button>
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

                        <!-- Form: Add & Assign New -->
                        <div id="form-assign-new" style="display: none;">
                            <form action="agency_maintenance.php" method="POST" autocomplete="off">
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
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Client Userame</label>
                                        <input type="text" name="client_username" class="form-control" required placeholder="Account username">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Password</label>
                                        <input type="password" name="client_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                                    </div>
                                </div>
                                <button type="submit" name="add_assign_client" class="btn btn-primary" style="margin-top: 20px;">Create Account & Assign</button>
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

        function openAgencyEditModal(id, name, qr, guard, insp) {
            document.getElementById('edit_agency_id').value = id;
            document.getElementById('edit_agency_name').value = name;
            document.getElementById('edit_qr_limit').value = qr;
            document.getElementById('edit_guard_limit').value = guard;
            document.getElementById('edit_inspector_qr_limit').value = insp;
            document.getElementById('editAgencyModal').classList.add('show');
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
                <div class="form-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;">
                    <div class="form-group" style="text-align: left;"><label class="form-label">QR Limit</label><input type="number" name="qr_limit" id="edit_qr_limit" class="form-control" min="0"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Guard Limit</label><input type="number" name="guard_limit" id="edit_guard_limit" class="form-control" min="0"></div>
                    <div class="form-group" style="text-align: left;"><label class="form-label">Inspector</label><input type="number" name="inspector_qr_limit" id="edit_inspector_qr_limit" class="form-control" min="0"></div>
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
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
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

<?php include 'admin_layout/footer.php'; ?>
</html>
