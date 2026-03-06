<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;
$message = '';
$message_type = '';

// Handle Company Details Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_details'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $company_name = $conn->real_escape_string($_POST['company_name']);
    
    // Handle File Upload
    $logo_path = null;
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
        $target_dir = "uploads/logos/";
        $file_ext = strtolower(pathinfo($_FILES["company_logo"]["name"], PATHINFO_EXTENSION));
        $new_filename = "logo_" . $mapping_id . "_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_filename;
        
        // Basic validation
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $valid_extensions) && $_FILES["company_logo"]["size"] < 2000000) {
            if (move_uploaded_file($_FILES["company_logo"]["tmp_name"], $target_file)) {
                $logo_path = $target_file;
                
                // Optional: Delete old logo if exists
                $old_logo_res = $conn->query("SELECT company_logo FROM agency_clients WHERE id = $mapping_id");
                if ($old_logo_res && $old_res = $old_logo_res->fetch_assoc()) {
                    if ($old_res['company_logo'] && file_exists($old_res['company_logo'])) {
                        unlink($old_res['company_logo']);
                    }
                }
            }
        }
    }
    
    $update_logo_sql = $logo_path ? ", company_logo = '$logo_path'" : "";
    $sql = "UPDATE agency_clients SET company_name = '$company_name' $update_logo_sql WHERE id = $mapping_id AND agency_id = $agency_id";
    
    if ($conn->query($sql)) {
        $message = "Client details updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating details: " . $conn->error;
        $message_type = "error";
    }
}

// Handle Guard Assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_guard'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $guard_id = (int)$_POST['guard_id'];
    
    // Check if already assigned
    $check = $conn->query("SELECT id FROM guard_assignments WHERE guard_id = $guard_id AND agency_client_id = $mapping_id");
    if ($check && $check->num_rows > 0) {
        $message = "Guard is already assigned to this client.";
        $message_type = "error";
    } else {
        // The user has moved guard limits to the Agency level. 
        // Guards are already limit-checked during creation in manage_guards.php.
        // We can now allow assignments without a per-site limit.
        if ($conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $mapping_id)")) {
            $message = "Guard assigned successfully!";
            $message_type = "success";
        } else {
            $message = "Error assigning guard: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Fetch Assigned Clients
$clients_sql = "
    SELECT ac.id as mapping_id, u.username as client_username, ac.company_name, ac.company_logo, a.guard_limit,
           (SELECT COUNT(*) FROM guard_assignments WHERE agency_client_id = ac.id) as current_guards
    FROM agency_clients ac
    JOIN users u ON ac.client_id = u.id
    JOIN users a ON ac.agency_id = a.id
    WHERE ac.agency_id = $agency_id
    ORDER BY u.username ASC
";
$clients_res = $conn->query($clients_sql);

// Fetch All Guards for this agency for the modals
$guards_sql = "SELECT id, name FROM guards WHERE agency_id = $agency_id ORDER BY name ASC";
$guards_res = $conn->query($guards_sql);
$all_guards = [];
if ($guards_res) {
    while($g = $guards_res->fetch_assoc()) $all_guards[] = $g;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management - Agency Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; }
        
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; border-radius: 4px; cursor: pointer; border: 1px solid transparent; font-weight: 500; }
        .btn-primary { background: #10b981; color: white; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f9fafb; }

        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 500px; position: relative; }
        .modal.show { display: flex; }
        
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link active">Client Management</a></li>
            <li><a href="manage_qrs.php" class="nav-link">Manage QRs</a></li>
            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
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
            <h2>Client Profile Management</h2>
        </header>

        <div class="content-area">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <div class="card">
                <h3 class="card-header">Assigned Clients</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Account Name</th>
                            <th>Company Details</th>
                            <th>Guards Assigned</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($clients_res && $clients_res->num_rows > 0): ?>
                            <?php while($row = $clients_res->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['client_username']); ?></strong></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <?php if ($row['company_logo']): ?>
                                                <img src="<?php echo htmlspecialchars($row['company_logo']); ?>" alt="Logo" style="width: 40px; height: 40px; object-fit: contain; border-radius: 4px; border: 1px solid #e5e7eb;">
                                            <?php else: ?>
                                                <div style="width: 40px; height: 40px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; border-radius: 4px; color: #9ca3af; font-size: 0.75rem;">No Logo</div>
                                            <?php endif; ?>
                                            <div>
                                                <div style="font-size: 0.9rem; font-weight: 600;"><?php echo $row['company_name'] ?: '<span style="color:#9ca3af; font-weight:400; font-style:italic;">No company name set</span>'; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: <?php echo $row['current_guards'] >= $row['guard_limit'] ? '#ef4444' : '#10b981'; ?>">
                                            <?php echo $row['current_guards']; ?> / <?php echo $row['guard_limit']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <button class="btn-sm btn-outline" onclick="openDetailsModal(<?php echo $row['mapping_id']; ?>, '<?php echo addslashes($row['company_name']); ?>', '<?php echo addslashes($row['company_logo']); ?>')">Edit Details</button>
                                        <button class="btn-sm btn-primary" onclick="openGuardModal(<?php echo $row['mapping_id']; ?>, '<?php echo addslashes($row['client_username']); ?>')">Add Guard</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding: 40px; color:#6b7280;">No clients assigned by admin yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Edit Client Details</h3>
            <form action="agency_client_management.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="mapping_id" id="details_mapping_id">
                <div class="form-group">
                    <label class="form-label">Company Name</label>
                    <input type="text" name="company_name" id="details_company_name" class="form-control" placeholder="e.g. Acme Corp" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Company Logo (Photo)</label>
                    <input type="file" name="company_logo" id="details_company_logo" class="form-control" accept="image/*">
                    <small style="color: #6b7280; font-size: 0.75rem;">Upload a photo of the client logo.</small>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn-sm btn-outline" style="flex:1;" onclick="closeModal('detailsModal')">Cancel</button>
                    <button type="submit" name="update_details" class="btn-sm btn-primary" style="flex:1;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Guard Modal -->
    <div id="guardModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 10px;">Assign Guard</h3>
            <p id="guard_client_name" style="color: #6b7280; font-size: 0.9rem; margin-bottom: 20px;"></p>
            <form action="agency_client_management.php" method="POST">
                <input type="hidden" name="mapping_id" id="guard_mapping_id">
                <div class="form-group">
                    <label class="form-label">Select Internal Guard</label>
                    <select name="guard_id" class="form-control" required>
                        <option value="" disabled selected>-- Select Guard --</option>
                        <?php foreach($all_guards as $g): ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button" class="btn-sm btn-outline" style="flex:1;" onclick="closeModal('guardModal')">Cancel</button>
                    <button type="submit" name="assign_guard" class="btn-sm btn-primary" style="flex:1;">Confirm Assignment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn-sm btn-outline" style="flex:1;" onclick="closeModal('logoutModal')">Cancel</button>
                <a href="logout.php" class="btn-sm btn-primary" style="flex:1; background: #ef4444; border:none; text-decoration:none; display:flex; align-items:center; justify-content:center;">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function openDetailsModal(id, name) {
            document.getElementById('details_mapping_id').value = id;
            document.getElementById('details_company_name').value = name;
            document.getElementById('detailsModal').classList.add('show');
        }

        function openGuardModal(id, clientUname) {
            document.getElementById('guard_mapping_id').value = id;
            document.getElementById('guard_client_name').innerText = "Assigning personnel to site: " + clientUname;
            document.getElementById('guardModal').classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }
    </script>
</body>
</html>
