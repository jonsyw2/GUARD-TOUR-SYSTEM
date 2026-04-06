<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;
$message = '';
$message_type = '';

// Helper function for safer migrations
if (!function_exists('addColumnSafely')) {
    function addColumnSafely($conn, $table, $column, $definition, $after = '') {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($res && $res->num_rows == 0) {
            $afterClause = $after ? " AFTER `$after`" : "";
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition $afterClause");
        }
    }
}

// Auto-Migration: Add new columns if they don't exist
addColumnSafely($conn, 'guards', 'gender', 'VARCHAR(20)', 'name');
addColumnSafely($conn, 'guards', 'police_clearance_no', 'VARCHAR(50)', 'lesp_expiry');
addColumnSafely($conn, 'guards', 'nbi_no', 'VARCHAR(50)', 'police_clearance_no');
addColumnSafely($conn, 'guards', 'contact_no', 'VARCHAR(20)', 'nbi_no');

$message = '';
$message_type = '';
$show_key_modal = false;
$show_limit_modal = false;
$show_status_modal = false;
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
    $address = $conn->real_escape_string($_POST['address']);
    $gender = $conn->real_escape_string($_POST['gender']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $police_clearance_no = $conn->real_escape_string($_POST['police_clearance_no']);
    $nbi_no = $conn->real_escape_string($_POST['nbi_no']);
    $lesp_no = $conn->real_escape_string($_POST['lesp_no']);
    $lesp_expiry = $conn->real_escape_string($_POST['lesp_expiry']);
    $client_mapping_id = isset($_POST['assign_to_client']) ? (int)$_POST['assign_to_client'] : null;
    
    // Format Full Name: Last, First Middle
    $fullname = $last_name . ", " . $first_name . " " . $middle_name;
    $fullname = trim($fullname);
    
    $can_create = true;
    if ($client_mapping_id) {
        // Check Client's Guard Limit
        $limit_sql = "
            SELECT ac.guard_limit, 
                   (SELECT COUNT(*) FROM guard_assignments WHERE agency_client_id = ac.id) as current_guards
            FROM agency_clients ac 
            WHERE ac.id = $client_mapping_id
        ";
        $limit_res = $conn->query($limit_sql);
        if ($limit_res && $row = $limit_res->fetch_assoc()) {
            $max_guards = (int)$row['guard_limit'];
            $current_guards = (int)$row['current_guards'];
            
            if ($max_guards > 0 && $current_guards >= $max_guards) {
                $message = "Creation failed: This client site has reached its limit of $max_guards guards.";
                $message_type = "error";
                $show_limit_modal = true;
                $can_create = false;
            }
        }
    }

    if ($can_create) {
        // Generate Unique 6-character Key
        $unique_key = generateUniqueGuardKey($conn);
        $hashed_password = password_hash($unique_key, PASSWORD_DEFAULT);
        
        $conn->begin_transaction();
        try {
            // 1. Create User
            $conn->query("INSERT INTO users (username, password, user_level) VALUES ('$unique_key', '$hashed_password', 'guard')");
            $user_id = $conn->insert_id;
            
            // 2. Create Guard entry
            $conn->query("INSERT INTO guards (user_id, agency_id, name, gender, address, contact_no, police_clearance_no, nbi_no, lesp_no, lesp_expiry) 
                         VALUES ($user_id, $agency_id, '$fullname', '$gender', '$address', '$contact_no', '$police_clearance_no', '$nbi_no', '$lesp_no', '$lesp_expiry')");
            $guard_id = $conn->insert_id;

            // 3. Handle Auto-assignment
            if ($client_mapping_id) {
                $conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $client_mapping_id)");
            }
            
            $conn->commit();
            $_SESSION['guard_created_key'] = $unique_key;
            header("Location: manage_guards.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error creating guard: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle updating Guard
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_guard'])) {
    $guard_id = (int)$_POST['guard_id'];
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $middle_name = $conn->real_escape_string($_POST['middle_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $address = $conn->real_escape_string($_POST['address']);
    $gender = $conn->real_escape_string($_POST['gender']);
    $contact_no = $conn->real_escape_string($_POST['contact_no']);
    $police_clearance_no = $conn->real_escape_string($_POST['police_clearance_no']);
    $nbi_no = $conn->real_escape_string($_POST['nbi_no']);
    $lesp_no = $conn->real_escape_string($_POST['lesp_no']);
    $lesp_expiry = $conn->real_escape_string($_POST['lesp_expiry']);
    $client_mapping_id = isset($_POST['client_mapping_id']) ? (int)$_POST['client_mapping_id'] : null;
    
    $fullname = trim($last_name . ", " . $first_name . " " . $middle_name);
    
    $conn->begin_transaction();
    try {
        // Update Guard Details
        $conn->query("UPDATE guards SET 
                        name = '$fullname', 
                        gender = '$gender',
                        address = '$address', 
                        contact_no = '$contact_no',
                        police_clearance_no = '$police_clearance_no',
                        nbi_no = '$nbi_no',
                        lesp_no = '$lesp_no', 
                        lesp_expiry = '$lesp_expiry' 
                      WHERE id = $guard_id");
        
        // Handle Assignment Update
        if ($client_mapping_id) {
            // Check if already assigned
            $check = $conn->query("SELECT id FROM guard_assignments WHERE guard_id = $guard_id");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE guard_assignments SET agency_client_id = $client_mapping_id WHERE guard_id = $guard_id");
            } else {
                $conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $client_mapping_id)");
            }
        } else {
            // Remove assignment if "No Assignment" selected
            $conn->query("DELETE FROM guard_assignments WHERE guard_id = $guard_id");
        }
        
        $conn->commit();
        $message = "Guard details and assignment updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error updating guard: " . $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
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
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting guard: " . $e->getMessage();
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Handle Unassigning Guard from Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['unassign_guard'])) {
    $assignment_id = (int)$_POST['assignment_id'];
    if ($conn->query("DELETE FROM guard_assignments WHERE id = $assignment_id")) {
        $message = "Guard unassigned successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error unassigning guard: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle changing guard client assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_assignment'])) {
    $guard_id = (int)$_POST['guard_id'];
    $client_mapping_id = isset($_POST['client_mapping_id']) ? (int)$_POST['client_mapping_id'] : 0;
    $conn->begin_transaction();
    try {
        if ($client_mapping_id) {
            $check = $conn->query("SELECT id FROM guard_assignments WHERE guard_id = $guard_id");
            if ($check && $check->num_rows > 0) {
                $conn->query("UPDATE guard_assignments SET agency_client_id = $client_mapping_id WHERE guard_id = $guard_id");
            } else {
                $conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $client_mapping_id)");
            }
        } else {
            $conn->query("DELETE FROM guard_assignments WHERE guard_id = $guard_id");
        }
        $conn->commit();
        $message = "Assignment updated successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error updating assignment: " . $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Assigning Guard to Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_guard'])) {
    $guard_id = (int)$_POST['guard_id'];
    $mapping_id = (int)$_POST['agency_client_id'];
    
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
            $show_limit_modal = true;
            $can_assign = false;
        }
    }

    if ($can_assign) {
        // Check if already assigned
        $check_assigned = $conn->query("SELECT id FROM guard_assignments WHERE guard_id = $guard_id AND agency_client_id = $mapping_id");
        if ($check_assigned && $check_assigned->num_rows > 0) {
            $message = "This guard is already assigned to this client.";
            $message_type = "error";
        } else {
            if ($conn->query("INSERT INTO guard_assignments (guard_id, agency_client_id) VALUES ($guard_id, $mapping_id)")) {
                $message = "Guard assigned to client successfully!";
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

// Fetch Guards created by this agency with current assignment info
$guards_sql = "SELECT g.id, g.name, g.gender, g.contact_no, g.police_clearance_no, g.nbi_no, u.username, g.created_at, g.address, g.lesp_no, g.lesp_expiry, g.profile_photo,
                      GROUP_CONCAT(ac.id SEPARATOR ',') as mapping_ids,
                      GROUP_CONCAT(cu.username SEPARATOR ', ') as client_names
               FROM guards g 
               JOIN users u ON g.user_id = u.id 
               LEFT JOIN guard_assignments ga ON g.id = ga.guard_id
               LEFT JOIN agency_clients ac ON ga.agency_client_id = ac.id
               LEFT JOIN users cu ON ac.client_id = cu.id
               WHERE g.agency_id = $agency_id 
               GROUP BY g.id
               ORDER BY g.id ASC";
$guards_res = $conn->query($guards_sql);

// Fetch assigned clients for the dropdown
$clients_sql = "SELECT ac.id as mapping_id, u.username as client_name FROM agency_clients ac JOIN users u ON ac.client_id = u.id WHERE ac.agency_id = $agency_id";
$clients_res = $conn->query($clients_sql);
$clients_data = [];
if ($clients_res) {
    while($row = $clients_res->fetch_assoc()) $clients_data[] = $row;
}

// Calculate total allowed guards: SUM(guard_limit + 2) for each client site assigned to this agency
$limit_sql = "SELECT COALESCE(SUM(guard_limit + 2), 0) as total_allowed FROM agency_clients WHERE agency_id = $agency_id";
$limit_res = $conn->query($limit_sql);
$total_guard_limit = 0;
if ($limit_res) {
    $limit_row = $limit_res->fetch_assoc();
    $total_guard_limit = (int)($limit_row['total_allowed'] ?? 0);
}

// Count current guards in this agency
$guard_count_res = $conn->query("SELECT COUNT(*) as total FROM guards WHERE agency_id = $agency_id");
$current_guard_count = 0;
if ($guard_count_res) {
    $current_guard_count = (int)$guard_count_res->fetch_assoc()['total'];
}
$guard_limit_reached = ($total_guard_limit > 0 && $current_guard_count >= $total_guard_limit);
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

            <li><a href="manage_guards.php" class="nav-link active">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
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
        <header class="topbar">
            <h2>Personnel & Assignment Management</h2>
        </header>

        <div class="content-area">

            <!-- Active Guards Table Header with Add Button -->


            <!-- Active Guards Table -->
            <div class="card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin:0;">Active Guards Personnel</h3>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-size: 0.8rem; color: #6b7280;">
                            <?php echo $current_guard_count; ?> / <?php echo $total_guard_limit > 0 ? $total_guard_limit : '∞'; ?> guards
                        </span>
                        <?php if ($guard_limit_reached): ?>
                            <button class="btn" style="width:auto; padding: 8px 20px; font-size: 0.9rem; background: #d1d5db; color: #9ca3af; cursor: not-allowed;" disabled title="Guard limit reached">+ Add Guard</button>
                        <?php else: ?>
                            <button class="btn" style="width:auto; padding: 8px 20px; font-size: 0.9rem;" onclick="document.getElementById('addGuardModal').classList.add('show')">+ Add Guard</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Guard Name</th>
                                <th>Access Key</th>
                                <th>Assigned Client</th>
                                <th>Date Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($guards_res && $guards_res->num_rows > 0): ?>
                                <?php while($row = $guards_res->fetch_assoc()): 
                                    $parts = explode(", ", $row['name']);
                                    $last = $parts[0] ?? '';
                                    $fm = $parts[1] ?? '';
                                    $fm_parts = explode(" ", $fm);
                                    $first = $fm_parts[0] ?? '';
                                    $middle = $fm_parts[1] ?? '';
                                ?>
                                    <tr onclick="openViewClientModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['client_names'] ?? ''); ?>', '<?php echo $row['mapping_ids'] ?? ''; ?>')" style="cursor: pointer;">
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <?php
                                                $photo_url = $row['profile_photo'] ?? '';
                                                if ($photo_url && strpos($photo_url, 'http') !== 0) {
                                                    $photo_url = 'https://guardtour.ccbisphils.com/' . $photo_url;
                                                }
                                                if ($photo_url): ?>
                                                    <img src="<?php echo htmlspecialchars($photo_url); ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                                <?php else:
                                                    $initials = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', explode(' ', trim($row['name'])))));
                                                    $initials = substr($initials, 0, 2);
                                                ?>
                                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; color: #3b82f6; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($initials); ?></div>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                            </div>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($row['username']); ?></code></td>
                                        <td>
                                            <?php if ($row['client_names']): ?>
                                                <?php $names = explode(', ', $row['client_names']); ?>
                                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                    <?php foreach($names as $name): ?>
                                                        <span class="badge" style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem;"><?php echo htmlspecialchars($name); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 0.8rem;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <div style="display: flex; gap: 6px;">
                                                <button onclick="event.stopPropagation(); openEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($last); ?>', '<?php echo addslashes($first); ?>', '<?php echo addslashes($middle); ?>', '<?php echo addslashes($row['gender'] ?? ''); ?>', '<?php echo addslashes($row['address']); ?>', '<?php echo addslashes($row['contact_no'] ?? ''); ?>', '<?php echo addslashes($row['police_clearance_no'] ?? ''); ?>', '<?php echo addslashes($row['nbi_no'] ?? ''); ?>', '<?php echo addslashes($row['lesp_no']); ?>', '<?php echo $row['lesp_expiry']; ?>', '<?php echo $row['mapping_ids'] ?? ''; ?>')" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #3b82f6; width: auto;">Edit</button>
                                                <button onclick="event.stopPropagation(); openDeleteModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>')" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: #ef4444; width: auto;">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="empty-state">No guards registered yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </main>

    <!-- Add Guard Modal -->
    <div id="addGuardModal" class="modal">
        <div class="modal-content" style="max-width: 680px; text-align: left; padding: 32px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px;">
                <h3 style="margin: 0; font-size: 1.2rem;">Register New Guard</h3>
                <button type="button" onclick="document.getElementById('addGuardModal').classList.remove('show')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
            </div>
            <form action="manage_guards.php" method="POST">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" placeholder="e.g. John" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name (Optional)</label>
                        <input type="text" name="middle_name" class="form-control" placeholder="e.g. Quincey">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" placeholder="e.g. Doe" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Permanent Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Full residential address" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-control" required>
                            <option value="" disabled selected>Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact No.</label>
                        <input type="text" name="contact_no" class="form-control" placeholder="09XXXXXXXXX" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Police Clearance No.</label>
                        <input type="text" name="police_clearance_no" class="form-control" placeholder="P.C. Number" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">NBI No.</label>
                        <input type="text" name="nbi_no" class="form-control" placeholder="NBI Number" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">LESP No.</label>
                        <input type="text" name="lesp_no" class="form-control" placeholder="License Number" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">LESP Expiry Date</label>
                        <input type="date" name="lesp_expiry" class="form-control" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <p style="font-size: 0.8rem; color: #6b7280; margin-bottom: 0;">An access key will be automatically generated upon account creation.</p>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end;">
                    <button type="button" onclick="document.getElementById('addGuardModal').classList.remove('show')" class="btn" style="background: #f1f5f9; color: #475569; width: auto; padding: 10px 24px;">Cancel</button>
                    <button type="submit" name="create_guard" class="btn" style="width: auto; padding: 10px 24px;">Create Guard Account</button>
                </div>
            </form>
        </div>
    </div>


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

    <!-- Status Process Modal (Generic) -->
    <div id="statusModal" class="modal <?php echo $show_status_modal ? 'show' : ''; ?>">
        <div class="modal-content" style="text-align: center; max-width: 400px;">
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

    <!-- Edit Guard Modal -->
    <div id="editGuardModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <h3 style="margin-bottom: 20px;">Edit Guard Details</h3>
            <form action="manage_guards.php" method="POST">
                <input type="hidden" name="guard_id" id="edit_guard_id">
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                    </div>
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" id="edit_middle_name" class="form-control">
                    </div>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Permanent Address</label>
                    <textarea name="address" id="edit_address" class="form-control" rows="2" required></textarea>
                </div>
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Gender</label>
                        <select name="gender" id="edit_gender" class="form-control" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Contact No.</label>
                        <input type="text" name="contact_no" id="edit_contact_no" class="form-control" required>
                    </div>
                </div>
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">Police Clearance No.</label>
                        <input type="text" name="police_clearance_no" id="edit_police_clearance_no" class="form-control" required>
                    </div>
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">NBI No.</label>
                        <input type="text" name="nbi_no" id="edit_nbi_no" class="form-control" required>
                    </div>
                </div>
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">LESP No.</label>
                        <input type="text" name="lesp_no" id="edit_lesp_no" class="form-control" required>
                    </div>
                    <div class="form-group" style="text-align: left;">
                        <label class="form-label">LESP Expiry Date</label>
                        <input type="date" name="lesp_expiry" id="edit_lesp_expiry" class="form-control" required>
                    </div>
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

    <!-- Unassign Confirmation Modal -->
    <div id="unassignModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3 style="margin-bottom: 10px;">Confirm Unassignment</h3>
            <p style="color: #6b7280; margin-bottom: 24px;">Are you sure you want to unassign <strong id="unassign_guard_name"></strong> from <strong id="unassign_client_name"></strong>?</p>
            <form action="manage_guards.php" method="POST">
                <input type="hidden" name="assignment_id" id="unassign_id">
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn" style="background: #f3f4f6; color: #374151;" onclick="closeModal('unassignModal')">Cancel</button>
                    <button type="submit" name="unassign_guard" class="btn" style="background: #ef4444;">Unassign Guard</button>
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
            return; // Tabs removed
        }

        // Auto-switch to appropriate tab if form was submitted
        // Tabs removed

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function openViewClientModal(guardId, guardName, clientNames, currentMappingId) {
            document.getElementById('viewClientGuardName').textContent = guardName;
            document.getElementById('vcm_guard_id').value = guardId;
            const list = document.getElementById('viewClientList');
            list.innerHTML = '';
            if (clientNames && clientNames.trim() !== '') {
                clientNames.split(', ').forEach(function(name) {
                    const span = document.createElement('span');
                    span.textContent = name.trim();
                    span.style.cssText = 'background:#d1fae5;color:#065f46;padding:6px 14px;border-radius:20px;font-size:0.85rem;font-weight:600;';
                    list.appendChild(span);
                });
            } else {
                list.innerHTML = '<span style="color:#9ca3af;font-style:italic;">No client assigned</span>';
            }
            // Pre-select the current mapping in the dropdown
            const select = document.getElementById('vcm_client_select');
            select.value = currentMappingId || '';
            document.getElementById('viewClientModal').classList.add('show');
        }

        function openEditModal(id, last, first, middle) {
            document.getElementById('edit_guard_id').value = id;
            document.getElementById('edit_last_name').value = last;
            document.getElementById('edit_first_name').value = first;
            document.getElementById('edit_middle_name').value = middle;
            document.getElementById('edit_guardModal').classList.add('show');
        }
        
        // Correction: Fixed modal ID naming consistency
        function openEditModal(id, last, first, middle, gender, address, contact, p_clearance, nbi, lesp_no, lesp_expiry, client_id) {
            document.getElementById('edit_guard_id').value = id;
            document.getElementById('edit_last_name').value = last;
            document.getElementById('edit_first_name').value = first;
            document.getElementById('edit_middle_name').value = middle;
            document.getElementById('edit_gender').value = gender;
            document.getElementById('edit_address').value = address;
            document.getElementById('edit_contact_no').value = contact;
            document.getElementById('edit_police_clearance_no').value = p_clearance;
            document.getElementById('edit_nbi_no').value = nbi;
            document.getElementById('edit_lesp_no').value = lesp_no;
            document.getElementById('edit_lesp_expiry').value = lesp_expiry;
            document.getElementById('editGuardModal').classList.add('show');
        }

        function openDeleteModal(id, name) {
            document.getElementById('delete_guard_id').value = id;
            document.getElementById('delete_guard_name').textContent = name;
            document.getElementById('deleteGuardModal').classList.add('show');
        }

        function openUnassignModal(id, guard, client) {
            document.getElementById('unassign_id').value = id;
            document.getElementById('unassign_guard_name').textContent = guard;
            document.getElementById('unassign_client_name').textContent = client;
            document.getElementById('unassignModal').classList.add('show');
        }

        window.onclick = function(event) {
            const logoutModal = document.getElementById('logoutModal');
            const successModal = document.getElementById('successKeyModal');
            const statusModal = document.getElementById('statusModal');
            const editModal = document.getElementById('editGuardModal');
            const deleteModal = document.getElementById('deleteGuardModal');
            const unassignModal = document.getElementById('unassignModal');
            
            if (event.target == logoutModal) logoutModal.classList.remove('show');
            if (event.target == successModal) successModal.classList.remove('show');
            if (event.target == statusModal) statusModal.classList.remove('show');
            if (event.target == editModal) editModal.classList.remove('show');
            if (event.target == deleteModal) deleteModal.classList.remove('show');
            if (event.target == unassignModal) unassignModal.classList.remove('show');
            const viewClientModal = document.getElementById('viewClientModal');
            if (event.target == viewClientModal) viewClientModal.classList.remove('show');
        }
    </script>

    <!-- View Assigned Client Modal -->
    <div id="viewClientModal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 14px;">
                <h3 id="viewClientGuardName" style="margin: 0; font-size: 1.1rem;"></h3>
                <button type="button" onclick="closeModal('viewClientModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
            </div>
            <form action="manage_guards.php" method="POST">
                <input type="hidden" name="guard_id" id="vcm_guard_id">
                <div style="margin-bottom: 8px; font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">Current Assignment</div>
                <div id="viewClientList" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; min-height: 30px; align-items: center;"></div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Change Assigned Client</label>
                    <select name="client_mapping_id" id="vcm_client_select" class="form-control">
                        <option value="">-- No Assignment --</option>
                        <?php foreach($clients_data as $client): ?>
                            <option value="<?php echo $client['mapping_id']; ?>"><?php echo htmlspecialchars($client['client_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end;">
                    <button type="button" onclick="closeModal('viewClientModal')" class="btn" style="width: auto; padding: 8px 20px; background: #f1f5f9; color: #374151;">Cancel</button>
                    <button type="submit" name="change_assignment" class="btn" style="width: auto; padding: 8px 20px;">Save</button>
                </div>
            </form>
        </div>
    </div>
    <!-- VERSION: 2.1 -->
</body>
</html>
