<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;
$message = '';
$message_type = '';
$show_status_modal = false;


// Handle Company Details Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_details'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $company_name = $conn->real_escape_string($_POST['company_name']);
    $company_address = $conn->real_escape_string($_POST['company_address']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $email_address = $conn->real_escape_string($_POST['email_address']);
    $website_link = $conn->real_escape_string($_POST['website_link']);
    $contact_person = $conn->real_escape_string($_POST['contact_person']);
    $contact_person_position = $conn->real_escape_string($_POST['contact_person_position']);
    $contact_person_no = $conn->real_escape_string($_POST['contact_person_no']);
    $qr_limit = (int)$_POST['qr_limit'];
    $guard_limit = (int)$_POST['guard_limit'];
    $inspector_limit = (int)$_POST['inspector_limit'];
    $supervisor_limit = (int)$_POST['supervisor_limit'];
    
    // Handle File Upload
    $logo_path = null;
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
        $target_dir = "uploads/logos/";
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
        $file_ext = strtolower(pathinfo($_FILES["company_logo"]["name"], PATHINFO_EXTENSION));
        $new_filename = "logo_" . $mapping_id . "_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_filename;
        
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $valid_extensions) && $_FILES["company_logo"]["size"] < 2000000) {
            if (move_uploaded_file($_FILES["company_logo"]["tmp_name"], $target_file)) {
                $logo_path = $target_file;
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
    $sql = "UPDATE agency_clients SET 
            company_name = '$company_name',
            company_address = '$company_address',
            contact_no = '$contact_no',
            email_address = '$email_address',
            website_link = '$website_link',
            contact_person = '$contact_person',
            contact_person_position = '$contact_person_position',
            contact_person_no = '$contact_person_no',
            qr_limit = $qr_limit,
            guard_limit = $guard_limit,
            inspector_limit = $inspector_limit,
            supervisor_limit = $supervisor_limit
            $update_logo_sql 
            WHERE id = $mapping_id AND agency_id = $agency_id";
    
    if ($conn->query($sql)) {
        $message = "Client details updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error updating details: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Add Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_client'])) {
    $client_username = $conn->real_escape_string($_POST['client_username']);
    $password = password_hash($_POST['client_password'], PASSWORD_DEFAULT);
    $company_name = $conn->real_escape_string($_POST['company_name']);
    $company_address = $conn->real_escape_string($_POST['company_address']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $email_address = $conn->real_escape_string($_POST['email_address']);
    $website_link = $conn->real_escape_string($_POST['website_link']);
    $contact_person = $conn->real_escape_string($_POST['contact_person']);
    $contact_person_position = $conn->real_escape_string($_POST['contact_person_position']);
    $contact_person_no = $conn->real_escape_string($_POST['contact_person_no']);
    $qr_limit = (int)$_POST['qr_limit'];
    $guard_limit = (int)$_POST['guard_limit'];
    $inspector_limit = (int)$_POST['inspector_limit'];
    $supervisor_limit = (int)$_POST['supervisor_limit'];

    $conn->begin_transaction();
    try {
        // 1. Check if username exists
        $user_check = $conn->query("SELECT id FROM users WHERE username = '$client_username'");
        if ($user_check && $user_check->num_rows > 0) {
            throw new Exception("Username '$client_username' is already taken.");
        }

        // 2. Check Agency Limit
        $limit_res = $conn->query("SELECT client_limit FROM users WHERE id = $agency_id");
        $client_limit = 0;
        if ($limit_res && $limit_res->num_rows > 0) {
            $client_limit = (int)$limit_res->fetch_assoc()['client_limit'];
        }

        $count_res = $conn->query("SELECT COUNT(DISTINCT client_id) as current_clients FROM agency_clients WHERE agency_id = $agency_id");
        $current_clients = (int)$count_res->fetch_assoc()['current_clients'];

        if ($client_limit > 0 && $current_clients >= $client_limit) {
            throw new Exception("You have reached your maximum client limit ($client_limit). Contact Admin to increase it.");
        }

        // 3. Create User Account
        if (!$conn->query("INSERT INTO users (username, password, user_level) VALUES ('$client_username', '$password', 'client')")) {
            throw new Exception("Error creating user: " . $conn->error);
        }
        $new_client_id = $conn->insert_id;

        // 4. Create Agency-Client Assignment and Profile
        if (!$conn->query("INSERT INTO agency_clients (agency_id, client_id, company_name, company_address, contact_no, email_address, website_link, contact_person, contact_person_position, contact_person_no, qr_limit, guard_limit, inspector_limit, supervisor_limit) 
                           VALUES ($agency_id, $new_client_id, '$company_name', '$company_address', '$contact_no', '$email_address', '$website_link', '$contact_person', '$contact_person_position', '$contact_person_no', $qr_limit, $guard_limit, $inspector_limit, $supervisor_limit)")) {
            throw new Exception("Error creating client profile: " . $conn->error);
        }
        $mapping_id = $conn->insert_id;

        // 5. Handle Logo Upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
            $target_dir = "uploads/logos/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
            $file_ext = strtolower(pathinfo($_FILES["company_logo"]["name"], PATHINFO_EXTENSION));
            $new_filename = "logo_" . $mapping_id . "_" . time() . "." . $file_ext;
            $target_file = $target_dir . $new_filename;
            
            $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($file_ext, $valid_extensions) && $_FILES["company_logo"]["size"] < 2000000) {
                if (move_uploaded_file($_FILES["company_logo"]["tmp_name"], $target_file)) {
                    $conn->query("UPDATE agency_clients SET company_logo = '$target_file' WHERE id = $mapping_id");
                }
            }
        }

        $conn->commit();
        $message = "Client account and profile created successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
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
        $show_status_modal = true;
    } else {
    // Check Client's Guard Limit
    $limit_sql = "
        SELECT ac.guard_limit, 
               (SELECT COUNT(*) FROM guard_assignments WHERE agency_client_id = ac.id) as current_guards
        FROM agency_clients ac 
        WHERE ac.id = $mapping_id
    ";
    $limit_res = $conn->query($limit_sql);
    $can_assign = true;
    if ($limit_res && $row = $limit_res->fetch_assoc()) {
        $max_guards = (int)$row['guard_limit'];
        $current_guards = (int)$row['current_guards'];
        
        if ($max_guards > 0 && $current_guards >= $max_guards) {
            $message = "Assignment failed: This client site has reached its limit of $max_guards guards.";
            $message_type = "error";
            $show_status_modal = true;
            $can_assign = false;
        }
    }

    if ($can_assign) {
        if ($conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $mapping_id)")) {
            $message = "Guard assigned successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } else {
            $message = "Error assigning guard: " . $conn->error;
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}
}

// Fetch Client Limit and Current Count for Headers (after potential POST updates)
$limit_res = $conn->query("SELECT client_limit FROM users WHERE id = $agency_id");
$client_limit = 0;
if ($limit_res && $row = $limit_res->fetch_assoc()) {
    $client_limit = (int)$row['client_limit'];
}

$count_res = $conn->query("SELECT COUNT(DISTINCT client_id) as current_clients FROM agency_clients WHERE agency_id = $agency_id");
$current_clients = (int)$count_res->fetch_assoc()['current_clients'];

$display_title = "Assigned Clients";
$clients = [];

// Fetch Assigned Clients
$clients_sql = "
    SELECT ac.id as mapping_id, u.username as client_username, ac.company_name, ac.company_logo, 
           ac.qr_limit, ac.guard_limit, ac.inspector_limit, ac.supervisor_limit,
           ac.company_address, ac.contact_no, ac.email_address, ac.website_link,
           ac.contact_person, ac.contact_person_position, ac.contact_person_no,
           (SELECT COUNT(*) FROM guard_assignments WHERE agency_client_id = ac.id) as current_guards,
           (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id AND is_zero_checkpoint = 0) as qr_count,
           (
               SELECT GROUP_CONCAT(g.name SEPARATOR ' | ')
               FROM guard_assignments ga
               JOIN guards g ON ga.guard_id = g.id
               WHERE ga.agency_client_id = ac.id
           ) as guard_names
    FROM agency_clients ac
    JOIN users u ON ac.client_id = u.id
    JOIN users a ON ac.agency_id = a.id
    WHERE ac.agency_id = $agency_id
    ORDER BY u.username ASC
";
$clients_res = $conn->query($clients_sql);
if ($clients_res) {
    while($row = $clients_res->fetch_assoc()) {
        $clients[] = $row;
    }
}

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
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }

        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
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
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .card-header { font-size: 1.125rem; font-weight: 600; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        tbody tr { cursor: pointer; transition: background 0.2s; }
        tbody tr:hover { background-color: #f8fafc; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; }
        
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; border-radius: 4px; cursor: pointer; border: 1px solid transparent; font-weight: 500; }
        .btn-primary { background: #10b981; color: white; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f9fafb; }

        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 500px; position: relative; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); max-height: 90vh; overflow-y: auto; }
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
            <li><a href="manage_supervisors.php" class="nav-link">Manage Supervisors</a></li>

            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
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

            <!-- Registered Clients Section -->
            <div class="card">
                <div class="card-header">
                    <h3 style="margin: 0; border: none;"><?php echo $display_title; ?></h3>
                    <?php if ($current_clients < $client_limit): ?>
                        <button class="btn-sm btn-primary" onclick="openAddClientModal()">+ Add New Client</button>
                    <?php endif; ?>
                </div>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client Account</th>
                                <th>Company Details</th>
                                <th>System Limits (QR/G/I/S)</th>
                                <th>Guards Assigned</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                    <tbody>
                         <?php 
                            for ($i = 1; $i <= $client_limit; $i++): 
                                $row = isset($clients[$i-1]) ? $clients[$i-1] : null;
                                if ($row):
                         ?>
                                <tr onclick="openSummaryModal('<?php echo addslashes($row['company_name'] ?: $row['client_username']); ?>', '<?php echo $row['qr_count']; ?>', '<?php echo addslashes($row['guard_names'] ?? ''); ?>', '<?php echo addslashes($row['contact_person'] ?? ''); ?>', '<?php echo addslashes($row['contact_person_no'] ?? ''); ?>', '<?php echo addslashes($row['email_address'] ?? ''); ?>', '<?php echo addslashes($row['company_address'] ?? ''); ?>')">
                                    <td><?php echo $i; ?></td>
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
                                        <div style="font-size: 0.85rem; display: flex; flex-direction: column; gap: 4px;">
                                            <div>QR: <span style="font-weight:600;"><?php echo $row['qr_count']; ?> / <?php echo $row['qr_limit']; ?></span></div>
                                            <div>Guard: <span style="font-weight:600;"><?php echo $row['current_guards']; ?> / <?php echo $row['guard_limit']; ?></span></div>
                                            <div>Insp: <span style="font-weight:600;"><?php echo $row['inspector_limit']; ?></span> | Supr: <span style="font-weight:600;"><?php echo $row['supervisor_limit']; ?></span></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: <?php echo $row['current_guards'] >= $row['guard_limit'] ? '#ef4444' : '#10b981'; ?>">
                                            Active: <?php echo $row['current_guards']; ?>
                                        </span>
                                    </td>
                                     <td style="text-align: right;">
                                        <div style="display: flex; gap: 8px; justify-content: flex-end;" onclick="event.stopPropagation()">
                                             <button class="btn-sm btn-outline" onclick="openDetailsModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">Edit Details</button>
                                            <button class="btn-sm btn-primary" onclick="openGuardModal(<?php echo $row['mapping_id']; ?>, '<?php echo addslashes($row['client_username']); ?>')">Assign Guard</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr style="cursor: default; background: transparent;">
                                    <td><?php echo $i; ?></td>
                                    <td colspan="4" style="color: #94a3b8; font-style: italic;">Available Client slot</td>
                                    <td style="text-align: right;">
                                        <button class="btn-sm btn-link" style="color: #10b981; font-weight: 600; background: none; border: none; cursor: pointer; text-decoration: underline;" onclick="openAddClientModal()">Add Client</button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>

    <!-- Modal: Add New Client -->
    <div id="addClientModal" class="modal">
        <div class="modal-content" style="max-width: 800px; text-align: left;">
            <div class="card-header" style="padding-top: 0;">
                <h3 style="margin: 0; border: none;">Create New Client Account</h3>
                <button type="button" class="btn-sm btn-outline" onclick="closeModal('addClientModal')" style="border:none; font-size: 1.5rem; line-height: 1;">&times;</button>
            </div>
                    <form action="agency_client_management.php" method="POST" enctype="multipart/form-data" autocomplete="off">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Client Username</label>
                                <input type="text" name="client_username" class="form-control" placeholder="Account login username" required autocomplete="none">
                            </div>

                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Account Password</label>
                                <input type="password" name="client_password" class="form-control" placeholder="••••••••" required autocomplete="new-password">
                            </div>

                            <div class="form-group" style="grid-column: span 2; border-top: 2px solid #f3f4f6; padding-top: 24px; margin-top: 8px;">
                                <label class="form-label" style="font-weight: 700; color: #111827;">COMPANY PROFILE DETAILS</label>
                            </div>

                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Company Name</label>
                                <input type="text" name="company_name" class="form-control" placeholder="e.g. Acme Corp" required>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Company Address</label>
                                <textarea name="company_address" class="form-control" placeholder="Full Business Address" rows="3"></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Contact No</label>
                                <input type="text" name="contact_no" class="form-control" placeholder="Company Phone">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email_address" class="form-control" placeholder="contact@company.com">
                            </div>

                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Website/Social Link (Optional)</label>
                                <input type="text" name="website_link" class="form-control" placeholder="FB, Viber, or Website URL">
                            </div>

                            <div style="grid-column: span 2; margin-top: 16px; border-top: 1px solid #f3f4f6; padding-top: 24px; margin-bottom: 8px;">
                                <h4 style="font-size: 0.95rem; font-weight: 700; color: #111827; text-transform: uppercase;">Contact Person Details</h4>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Contact Person</label>
                                <input type="text" name="contact_person" class="form-control" placeholder="Full Name">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Position</label>
                                <input type="text" name="contact_person_position" class="form-control" placeholder="Job Title">
                            </div>

                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Contact No (Contact Person)</label>
                                <input type="text" name="contact_person_no" class="form-control" placeholder="Personal or Office Phone">
                            </div>

                            <div class="form-group" style="grid-column: span 2; border-top: 2px solid #f3f4f6; padding-top: 24px; margin-top: 8px;">
                                <label class="form-label" style="font-weight: 700; color: var(--primary);">CLIENT SYSTEM LIMITS</label>
                            </div>

                            <div class="form-grid" style="grid-column: span 2; display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                                <div class="form-group"><label class="form-label">QR Limit</label><input type="number" name="qr_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Guard Limit</label><input type="number" name="guard_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Inspector Limit</label><input type="number" name="inspector_limit" class="form-control" value="0" min="0"></div>
                                <div class="form-group"><label class="form-label">Supervisor Limit</label><input type="number" name="supervisor_limit" class="form-control" value="0" min="0"></div>
                            </div>
                        </div>

                        <div style="margin-top: 40px; border-top: 1px solid #e5e7eb; padding-top: 24px; display: flex; gap: 12px; justify-content: flex-end;">
                            <button type="button" class="btn-sm btn-outline" onclick="closeModal('addClientModal')" style="padding: 12px 24px;">Cancel</button>
                            <button type="submit" name="add_client" class="btn-sm btn-primary" style="padding: 12px 40px; font-size: 1rem; border-radius: 8px;">Create & Register Client</button>
                        </div>
                    </form>
        </div>
    </div>
        </div>
    </main>

    <!-- Site Summary Modal -->
    <div id="summaryModal" class="modal">
        <div class="modal-content" style="text-align: left;">
            <h3 id="summary_title" style="margin-bottom: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">Site Summary</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                <div style="background: #f0fdf4; padding: 16px; border-radius: 12px; border: 1px solid #bbf7d0;">
                    <div style="color: #166534; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Total QR Checkpoints</div>
                    <div id="summary_qr_count" style="font-size: 1.5rem; font-weight: 800; color: #14532d;">0</div>
                </div>
                <div style="background: #eff6ff; padding: 16px; border-radius: 12px; border: 1px solid #bfdbfe;">
                    <div style="color: #1e40af; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Personnel Count</div>
                    <div id="summary_guard_count" style="font-size: 1.5rem; font-weight: 800; color: #1e3a8a;">0</div>
                </div>
            </div>

            <div style="background: #f9fafb; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #6b7280; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.05em;">Client Details</div>
                <div style="display: grid; gap: 12px;">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <span style="font-size: 1.1rem; filter: grayscale(1);">👤</span>
                        <div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">Contact Person</div>
                            <div id="summary_contact_person" style="font-size: 0.95rem; font-weight: 600; color: #374151;">---</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <span style="font-size: 1.1rem; filter: grayscale(1);">📞</span>
                        <div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">Phone Number</div>
                            <div id="summary_contact_no" style="font-size: 0.95rem; font-weight: 600; color: #374151;">---</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <span style="font-size: 1.1rem; filter: grayscale(1);">✉️</span>
                        <div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">Email Address</div>
                            <div id="summary_email" style="font-size: 0.95rem; font-weight: 600; color: #374151;">---</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <span style="font-size: 1.1rem; filter: grayscale(1);">📍</span>
                        <div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">Company Address</div>
                            <div id="summary_address" style="font-size: 0.9rem; font-weight: 500; color: #4b5563; line-height: 1.4;">---</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="background: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #6b7280; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.05em;">Assigned Personnel</div>
                <div id="summary_guards_list" style="font-size: 0.95rem; color: #4b5563; font-weight: 500; line-height: 1.7;">
                    <!-- Guards list injected here -->
                </div>
            </div>

            <div style="margin-top: 32px; display: flex; gap: 12px;">
                <button type="button" class="btn-sm btn-outline" style="flex:1; padding: 12px;" onclick="closeModal('summaryModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content" style="max-width: 600px; text-align: left;">
            <h3 style="margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">Edit Client Details</h3>
            <form action="agency_client_management.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="mapping_id" id="details_mapping_id">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" id="details_company_name" class="form-control" placeholder="e.g. Acme Corp" required>
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Company Address</label>
                        <textarea name="company_address" id="details_company_address" class="form-control" placeholder="Full Business Address" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contact No</label>
                        <input type="text" name="contact_no" id="details_contact_no" class="form-control" placeholder="Company Phone">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email_address" id="details_email_address" class="form-control" placeholder="contact@company.com">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Website/Social Link (Optional)</label>
                        <input type="text" name="website_link" id="details_website_link" class="form-control" placeholder="FB, Viber, or Website URL">
                    </div>

                    <div style="grid-column: span 2; margin-top: 8px; border-top: 1px solid #f3f4f6; padding-top: 16px; margin-bottom: 16px;">
                        <h4 style="font-size: 0.9rem; font-weight: 700; color: #4b5563; text-transform: uppercase; letter-spacing: 0.5px;">Contact Person Details</h4>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="details_contact_person" class="form-control" placeholder="Full Name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" name="contact_person_position" id="details_contact_person_position" class="form-control" placeholder="Job Title">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Contact No (Contact Person)</label>
                        <input type="text" name="contact_person_no" id="details_contact_person_no" class="form-control" placeholder="Personal or Office Phone">
                    </div>

                    <div class="form-group" style="grid-column: span 2; margin-top: 8px; border-top: 1px solid #f3f4f6; padding-top: 16px;">
                        <label class="form-label" style="font-weight: 700; color: var(--primary);">SYSTEM LIMITS</label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">QR Limit</label>
                        <input type="number" name="qr_limit" id="details_qr_limit" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Guard Limit</label>
                        <input type="number" name="guard_limit" id="details_guard_limit" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Inspector Limit</label>
                        <input type="number" name="inspector_limit" id="details_inspector_limit" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Supervisor Limit</label>
                        <input type="number" name="supervisor_limit" id="details_supervisor_limit" class="form-control" min="0">
                    </div>

                    <div class="form-group" style="grid-column: span 2; margin-top: 8px; border-top: 1px solid #f3f4f6; padding-top: 16px;">
                        <label class="form-label">Company Logo (Photo)</label>
                        <input type="file" name="company_logo" id="details_company_logo" class="form-control" accept="image/*">
                        <small style="color: #6b7280; font-size: 0.75rem;">Upload a photo of the client logo.</small>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 32px;">
                    <button type="button" class="btn-sm btn-outline" style="flex:1; padding: 12px;" onclick="closeModal('detailsModal')">Cancel</button>
                    <button type="submit" name="update_details" class="btn-sm btn-primary" style="flex:1; padding: 12px;">Save Changes</button>
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

    <!-- Status Process Modal (Generic) -->
    <div id="statusModal" class="modal <?php echo $show_status_modal ? 'show' : ''; ?>">
        <div class="modal-content" style="max-width: 400px;">
            <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                <?php echo $message_type === 'success' ? '✓' : '!'; ?>
            </div>
            <h3 style="margin-bottom: 10px;"><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
            <p style="color: #6b7280; margin-bottom: 24px;"><?php echo $message; ?></p>
            <button class="btn-sm btn-primary" style="width: 100%; padding: 10px;" onclick="closeModal('statusModal')">Done</button>
        </div>
    </div>

    <script>

        function openSummaryModal(clientName, qrCount, guardNames, contactPerson, contactNo, email, address) {
            document.getElementById('summary_title').innerText = "Site Summary: " + clientName;
            document.getElementById('summary_qr_count').innerText = qrCount;
            
            document.getElementById('summary_contact_person').innerText = contactPerson || "Not Set";
            document.getElementById('summary_contact_no').innerText = contactNo || "Not Set";
            document.getElementById('summary_email').innerText = email || "Not Set";
            document.getElementById('summary_address').innerText = address || "Not Set";
            
            const listDiv = document.getElementById('summary_guards_list');
            if (guardNames && guardNames.trim() !== '') {
                const names = guardNames.split(' | ');
                document.getElementById('summary_guard_count').innerText = names.length;
                listDiv.innerHTML = names.map(n => `<div style="padding: 6px 0; border-bottom: 1px dotted #e5e7eb; display: flex; align-items: center; gap: 8px;"><span style="color: #10b981;">•</span> ${n}</div>`).join('');
            } else {
                document.getElementById('summary_guard_count').innerText = "0";
                listDiv.innerHTML = '<span style="color: #9ca3af; font-style: italic; font-size: 0.9rem;">No guards assigned to this site.</span>';
            }
            document.getElementById('summaryModal').classList.add('show');
        }

        function openDetailsModal(data) {
            document.getElementById('details_mapping_id').value = data.mapping_id;
            document.getElementById('details_company_name').value = data.company_name || '';
            document.getElementById('details_company_address').value = data.company_address || '';
            document.getElementById('details_contact_no').value = data.contact_no || '';
            document.getElementById('details_email_address').value = data.email_address || '';
            document.getElementById('details_website_link').value = data.website_link || '';
            document.getElementById('details_contact_person').value = data.contact_person || '';
            document.getElementById('details_contact_person_position').value = data.contact_person_position || '';
            document.getElementById('details_contact_person_no').value = data.contact_person_no || '';
            document.getElementById('details_qr_limit').value = data.qr_limit || 0;
            document.getElementById('details_guard_limit').value = data.guard_limit || 0;
            document.getElementById('details_inspector_limit').value = data.inspector_limit || 0;
            document.getElementById('details_supervisor_limit').value = data.supervisor_limit || 0;
            
            document.getElementById('detailsModal').classList.add('show');
        }

        function openAddClientModal() {
            document.getElementById('addClientModal').classList.add('show');
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
