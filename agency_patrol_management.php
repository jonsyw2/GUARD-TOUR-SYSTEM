<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}

$agency_id = $_SESSION['user_id'] ?? null;

// Fallback logic if user_id isn't in session, fetch it based on username
if (!$agency_id && isset($_SESSION['username'])) {
    $uname = $conn->real_escape_string($_SESSION['username']);
    $res = $conn->query("SELECT id FROM users WHERE username = '$uname'");
    if ($res && $res->num_rows > 0) {
        $agency_id = $res->fetch_assoc()['id'];
        $_SESSION['user_id'] = $agency_id;
    }
}

$message = $_SESSION['status_message'] ?? '';
$message_type = $_SESSION['status_type'] ?? '';
$show_status_modal = !empty($message);
unset($_SESSION['status_message'], $_SESSION['status_type']);

function setStatusMessage($msg, $type = 'success') {
    $_SESSION['status_message'] = $msg;
    $_SESSION['status_type'] = $type;
}

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

// Auto-Migration: Ensure required tables and columns exist for Patrol Management
addColumnSafely($conn, 'agency_clients', 'site_name', 'VARCHAR(255)', 'client_id');
addColumnSafely($conn, 'agency_clients', 'is_patrol_locked', 'TINYINT(1) DEFAULT 0', 'site_name');
addColumnSafely($conn, 'agency_clients', 'shift_type', "VARCHAR(50) DEFAULT 'Day Shift'", 'is_patrol_locked');
addColumnSafely($conn, 'agency_clients', 'is_visual_locked', 'TINYINT(1) DEFAULT 0', 'shift_type');
addColumnSafely($conn, 'agency_clients', 'visual_saved',   'TINYINT(1) DEFAULT 0', 'is_visual_locked');
addColumnSafely($conn, 'agency_clients', 'is_sequence_fixed', 'TINYINT(1) DEFAULT 0', 'visual_saved');
addColumnSafely($conn, 'agency_clients', 'sequence_change_request', "ENUM('none', 'pending', 'approved') DEFAULT 'none'", 'is_sequence_fixed');


$conn->query("ALTER TABLE checkpoints ADD COLUMN IF NOT EXISTS visual_pos_x INT DEFAULT 0, ADD COLUMN IF NOT EXISTS visual_pos_y INT DEFAULT 0");
addColumnSafely($conn, 'checkpoints', 'is_zero_checkpoint', 'TINYINT(1) DEFAULT 0');
addColumnSafely($conn, 'checkpoints', 'checkpoint_code', 'VARCHAR(50)', 'name');
addColumnSafely($conn, 'tour_assignments', 'shift_name', 'VARCHAR(50)', 'duration_minutes');

// Scans Table Migrations
addColumnSafely($conn, 'scans', 'tour_session_id', 'VARCHAR(100)', 'justification_photo_path');
addColumnSafely($conn, 'scans', 'shift', "VARCHAR(50) DEFAULT 'Day Shift'", 'tour_session_id');

$conn->query("CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_client_id INT NOT NULL,
    shift_name VARCHAR(50) NOT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS tour_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_client_id INT NOT NULL,
    checkpoint_id INT NOT NULL,
    sort_order INT NOT NULL,
    interval_minutes INT DEFAULT 0,
    duration_minutes INT DEFAULT 0,
    shift_name VARCHAR(50)
)");

// AJAX Handler for fetching checkpoints & shifts (Modified to support Visual Designer)
if (isset($_GET['ajax_checkpoints']) && isset($_GET['mapping_id'])) {
    $mapping_id = (int)$_GET['mapping_id'];
    $date_filter = "";
    if (!empty($_GET['date'])) {
        $filter_date = $conn->real_escape_string($_GET['date']);
        $date_filter = " AND DATE(scan_time) = '$filter_date'";
    }
    
    // Checkpoints (Including visual position and filtered status)
    // ORDER: Starting Point first, then regular by sort_order, then End Point last
    $cp_res = $conn->query("
        SELECT cp.id, cp.name, cp.visual_pos_x, cp.visual_pos_y, cp.is_zero_checkpoint,
        (SELECT status FROM scans WHERE checkpoint_id = cp.id $date_filter ORDER BY scan_time DESC LIMIT 1) as latest_status,
        IFNULL(ta.sort_order, 9999) as sort_order_val
        FROM checkpoints cp
        LEFT JOIN tour_assignments ta ON cp.id = ta.checkpoint_id AND ta.agency_client_id = $mapping_id
        WHERE cp.agency_client_id = $mapping_id
        ORDER BY 
            CASE cp.is_zero_checkpoint WHEN 1 THEN 0 WHEN 0 THEN 1 WHEN 2 THEN 2 ELSE 3 END ASC,
            sort_order_val ASC,
            cp.id ASC
    ");
    
    $checkpoints = [];
    if ($cp_res) {
        while ($row = $cp_res->fetch_assoc()) {
            $row['isStart'] = ((int)$row['is_zero_checkpoint'] === 1);
            $row['isEnd']   = ((int)$row['is_zero_checkpoint'] === 2);
            $checkpoints[] = $row;
        }
    }
    
    // Shifts
    $shift_res = $conn->query("SELECT id, shift_name, start_time, end_time FROM shifts WHERE agency_client_id = $mapping_id ORDER BY id ASC");
    $shifts = [];
    if ($shift_res) {
        while ($row = $shift_res->fetch_assoc()) {
            $shifts[] = $row;
        }
    }
    
    // Check if visual is locked
    $lock_res = $conn->query("SELECT is_visual_locked FROM agency_clients WHERE id = $mapping_id LIMIT 1");
    $is_visual_locked = 0;
    if ($lock_res && $row = $lock_res->fetch_assoc()) {
        $is_visual_locked = (int)$row['is_visual_locked'];
    }
    
    header('Content-Type: application/json');
    echo json_encode(['checkpoints' => $checkpoints, 'shifts' => $shifts, 'is_visual_locked' => $is_visual_locked]);
    exit();
}

// AJAX Handler for saving checkpoint position
if (isset($_POST['ajax_save_position']) && isset($_POST['cp_id'])) {
    $cp_id = (int)$_POST['cp_id'];
    $x = (int)($_POST['x'] ?? 0);
    $y = (int)($_POST['y'] ?? 0);
    
    // Security: Verify checkpoint belongs to an agency site
    $stmt = $conn->prepare("
        UPDATE checkpoints cp
        JOIN agency_clients ac ON cp.agency_client_id = ac.id
        SET cp.visual_pos_x = ?, cp.visual_pos_y = ?
        WHERE cp.id = ? AND ac.agency_id = ?
    ");
    $stmt->bind_param("iiii", $x, $y, $cp_id, $agency_id);
    $stmt->execute();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}

// AJAX Handler for locking visual map
if (isset($_POST['ajax_lock_visual']) && isset($_POST['mapping_id'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    
    $stmt = $conn->prepare("UPDATE agency_clients SET is_visual_locked = 1, visual_saved = 1 WHERE id = ? AND agency_id = ?");
    $stmt->bind_param("ii", $mapping_id, $agency_id);
    $stmt->execute();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}

// Handle Delete Site (with full history wipe and 1-site minimum)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_site'])) {
    $mapping_id = (int)$_POST['mapping_id'];

    // Security: Verify site belongs to this agency
    $verify_sql = "SELECT client_id, site_name FROM agency_clients WHERE id = $mapping_id AND agency_id = $agency_id";
    $verify_res = $conn->query($verify_sql);

    if ($verify_res && $row = $verify_res->fetch_assoc()) {
        $client_id = $row['client_id'];
        $site_name = $row['site_name'];

        // Rule: Client must have at least one location
        $count_res = $conn->query("SELECT COUNT(*) as site_count FROM agency_clients WHERE client_id = $client_id");
        $site_count = $count_res->fetch_assoc()['site_count'];

        if ($site_count <= 1) {
            setStatusMessage("Deletion blocked: Every client must have at least one site location.", "error");
            header("Location: agency_patrol_management.php?tab=qr&mapping_id=$mapping_id");
            exit();
        } else {
            $conn->begin_transaction();
            try {
                // Wipe historical data and configuration
                $conn->query("DELETE FROM scans WHERE checkpoint_id IN (SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id)");
                $conn->query("DELETE FROM incidents WHERE agency_client_id = $mapping_id");
                $conn->query("DELETE FROM inspector_scans WHERE agency_client_id = $mapping_id");
                $conn->query("DELETE FROM guard_assignments WHERE agency_client_id = $mapping_id");
                $conn->query("DELETE FROM inspector_assignments WHERE agency_client_id = $mapping_id");
                $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mapping_id");
                $conn->query("DELETE FROM shifts WHERE agency_client_id = $mapping_id");
                $conn->query("DELETE FROM checkpoints WHERE agency_client_id = $mapping_id");
                
                // Finally delete the site mapping
                $conn->query("DELETE FROM agency_clients WHERE id = $mapping_id");

                $conn->commit();
                setStatusMessage("Site '$site_name' and all its historical records have been permanently removed.");
                header("Location: agency_patrol_management.php?tab=qr");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                setStatusMessage("Deletion failed: " . $e->getMessage(), "error");
                header("Location: agency_patrol_management.php?tab=qr&mapping_id=$mapping_id");
                exit();
            }
        }
    } else {
        setStatusMessage("Unauthorized deletion attempt.", "error");
        header("Location: agency_patrol_management.php?tab=qr");
        exit();
    }
}

// AJAX Handler for deleting a checkpoint
if (isset($_POST['ajax_delete_checkpoint']) && isset($_POST['cp_id'])) {
    $cp_id = (int)$_POST['cp_id'];
    
    // Security: Verify checkpoint belongs to an agency site for this specific agency
    $stmt = $conn->prepare("
        DELETE cp FROM checkpoints cp
        JOIN agency_clients ac ON cp.agency_client_id = ac.id
        WHERE cp.id = ? AND ac.agency_id = ? AND cp.is_zero_checkpoint = 0
    ");
    $stmt->bind_param("ii", $cp_id, $agency_id);
    $stmt->execute();
    
    // Also delete any assignments referencing this checkpoint
    $conn->query("DELETE FROM tour_assignments WHERE checkpoint_id = $cp_id");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}

// AJAX Handler: Update Site Name
if (isset($_POST['ajax_update_site_name']) && isset($_POST['mapping_id'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $new_name = $conn->real_escape_string($_POST['site_name'] ?? '');
    
    $stmt = $conn->prepare("UPDATE agency_clients SET site_name = ? WHERE id = ? AND agency_id = ?");
    $stmt->bind_param("sii", $new_name, $mapping_id, $agency_id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit();
}



// AJAX Handler: Sync Shifts
if (isset($_POST['ajax_sync_shifts']) && isset($_POST['mapping_id'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $shifts = $_POST['shifts'] ?? [];
    $starts = $_POST['shift_starts'] ?? [];
    $ends = $_POST['shift_ends'] ?? [];
    
    // Security check
    $verify = $conn->query("SELECT id FROM agency_clients WHERE id = $mapping_id AND agency_id = $agency_id");
    if (!$verify || $verify->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM shifts WHERE agency_client_id = $mapping_id");
        foreach ($shifts as $i => $s_name) {
            $name = trim($conn->real_escape_string($s_name));
            $s_start = $conn->real_escape_string($starts[$i] ?? '');
            $s_end = $conn->real_escape_string($ends[$i] ?? '');
            
            if (!empty($name)) {
                $stmt = $conn->prepare("INSERT INTO shifts (agency_client_id, shift_name, start_time, end_time) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $mapping_id, $name, $s_start, $s_end);
                $stmt->execute();
            }
        }
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

$message = '';
$message_type = '';
$show_status_modal = false;

// Handle Save Patrol
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_patrol'])) {
    $mapping_id = (int)$_POST['mapping_id'];

    // ── Gate: require visual layout to be saved first ──────────────────────
    $vs_check = $conn->query("SELECT visual_saved FROM agency_clients WHERE id = $mapping_id AND agency_id = $agency_id LIMIT 1");
    $vs_row   = $vs_check ? $vs_check->fetch_assoc() : null;
    if (!$vs_row || !(int)$vs_row['visual_saved']) {
        setStatusMessage("Please open the Visual Map Designer and save the layout before configuring patrol patterns.", "error");
        header("Location: agency_patrol_management.php?tab=patrol&mapping_id=$mapping_id");
        exit();
    } else {

    $checkpoint_ids = $_POST['checkpoint_ids'] ?? [];
    $intervals = $_POST['intervals'] ?? [];
    $durations = $_POST['durations'] ?? [];
    $assignment_shifts = $_POST['assignment_shifts'] ?? [];
    $is_locked = isset($_POST['is_patrol_locked']) ? 1 : 0;

    // Resolve system checkpoint IDs (Start=1, End=2) to exclude them from the sortable middle
    $system_cp_res = $conn->query("SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint != 0");
    $system_cp_ids = [];
    while ($r = $system_cp_res->fetch_assoc()) {
        $system_cp_ids[] = (int)$r['id'];
    }
    
    $conn->begin_transaction();
        try {
            // Update Lock Status
            $stmt = $conn->prepare("UPDATE agency_clients SET is_patrol_locked = ? WHERE id = ? AND agency_id = ?");
            $stmt->bind_param("iii", $is_locked, $mapping_id, $agency_id);
            $stmt->execute();

            // Clear existing and save new assignments
            $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mapping_id");
            
            for ($i = 0; $i < count($checkpoint_ids); $i++) {
                $final_checkpoint_ids[] = (int)$checkpoint_ids[$i];
                $final_intervals[] = (int)($intervals[$i] ?? 0);
                $final_durations[] = (int)($durations[$i] ?? 0);
                $final_shifts[] = $conn->real_escape_string($assignment_shifts[$i] ?? '');
            }

            for ($i = 0; $i < count($final_checkpoint_ids); $i++) {
                $cp_id = $final_checkpoint_ids[$i];
                $interval = $final_intervals[$i];
                $duration = $final_durations[$i];
                $as_shift = $final_shifts[$i];
                $order = $i + 1;
                $stmt_ins = $conn->prepare("INSERT INTO tour_assignments (agency_client_id, checkpoint_id, sort_order, interval_minutes, duration_minutes, shift_name) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_ins->bind_param("iiiiis", $mapping_id, $cp_id, $order, $interval, $duration, $as_shift);
                $stmt_ins->execute();
            }
            $conn->commit();
            setStatusMessage("Patrol configuration saved successfully!");
            header("Location: agency_patrol_management.php?tab=patrol&mapping_id=$mapping_id");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            setStatusMessage("Error saving patrol: " . $e->getMessage(), "error");
            header("Location: agency_patrol_management.php?tab=patrol&mapping_id=$mapping_id");
            exit();
        }
    } // end visual_saved gate
}

// Handle Updating Client QR Limits (Site Name & Checkpoints)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_limit'])) {
    $mapping_id = (int)$_POST['agency_client_id'];
    $site_name = $conn->real_escape_string($_POST['site_name']);
    $checkpoints = $_POST['checkpoints'] ?? [];
    
    $verify_sql = "SELECT id FROM agency_clients WHERE id = $mapping_id AND agency_id = $agency_id";
    $verify_res = $conn->query($verify_sql);
    
    // Get site-specific QR limit
    $limit_check_sql = "SELECT qr_limit FROM agency_clients WHERE id = $mapping_id";
    $limit_check_res = $conn->query($limit_check_sql);
    $qr_limit = 0;
    if ($limit_check_res && $row = $limit_check_res->fetch_assoc()) {
        $qr_limit = (int)$row['qr_limit'];
    }
    
    if ($verify_res && $verify_res->num_rows > 0) {
        $site_usage = count($checkpoints);
        if ($site_usage > $qr_limit) { 
            $err_msg = "Error: Total checkpoints for this site ({$site_usage}) would exceed the limited capacity of {$qr_limit}.";
            if (isset($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $err_msg]);
                exit();
            }
            $message = $err_msg;
            $message_type = "error";
            $show_status_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                $update_sql = "UPDATE agency_clients SET site_name = '$site_name' WHERE id = $mapping_id";
                $conn->query($update_sql);

                // Handle checkpoints update (ID-based)
                $submitted_checkpoint_ids = $_POST['checkpoint_ids'] ?? [];
                $submitted_checkpoint_names = $_POST['checkpoints'] ?? [];
                
                $remaining_ids = [];
                for ($i = 0; $i < count($submitted_checkpoint_names); $i++) {
                    $cp_name = trim($submitted_checkpoint_names[$i]);
                    $cp_id = isset($submitted_checkpoint_ids[$i]) ? (int)$submitted_checkpoint_ids[$i] : 0;
                    
                    if (empty($cp_name)) continue;

                    if ($cp_id > 0) {
                        // Update existing by ID
                        $stmt = $conn->prepare("UPDATE checkpoints SET name = ? WHERE id = ? AND agency_client_id = ?");
                        $stmt->bind_param("sii", $cp_name, $cp_id, $mapping_id);
                        $stmt->execute();
                        $remaining_ids[] = $cp_id;
                    } else {
                        // Insert new
                        $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                        $stmt = $conn->prepare("INSERT INTO checkpoints (agency_client_id, name, checkpoint_code) VALUES (?, ?, ?)");
                        $stmt->bind_param("iss", $mapping_id, $cp_name, $code);
                        $stmt->execute();
                        $remaining_ids[] = $conn->insert_id;
                    }
                }

                // Deletion logic: Exclude Starting point
                $remaining_ids_str = empty($remaining_ids) ? "0" : implode(',', $remaining_ids);
                $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mapping_id AND checkpoint_id NOT IN ($remaining_ids_str) AND checkpoint_id IN (SELECT id FROM checkpoints WHERE is_zero_checkpoint = 0)");
                $conn->query("DELETE FROM checkpoints WHERE agency_client_id = $mapping_id AND id NOT IN ($remaining_ids_str) AND is_zero_checkpoint = 0");

                // Auto-create Starting Point if missing
                $start_check = $conn->query("SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1");
                if ($start_check && $start_check->num_rows == 0) {
                    $code = "START-" . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                    $conn->query("INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, is_zero_checkpoint) VALUES ($mapping_id, 'Starting Point', '$code', 1)");
                }

                // Handle shifts update
                $site_shifts = $_POST['shifts'] ?? [];
                $shift_starts = $_POST['shift_starts'] ?? [];
                $shift_ends = $_POST['shift_ends'] ?? [];
                
                // Validate 24-hour Limit
                $total_minutes = 0;
                foreach ($site_shifts as $i => $s_name) {
                    $start = $shift_starts[$i] ?? '';
                    $end = $shift_ends[$i] ?? '';
                    if (empty($start) || empty($end)) continue;

                    $s_time = strtotime($start);
                    $e_time = strtotime($end);
                    if ($s_time === false || $e_time === false) continue;

                    if ($e_time > $s_time) {
                        $diff = ($e_time - $s_time) / 60;
                    } else if ($e_time < $s_time) {
                        $diff = ((strtotime("23:59:59") - $s_time) + ($e_time - strtotime("00:00:00")) + 1) / 60;
                    } else {
                        $diff = 1440; // Exactly 24 hours if start == end
                    }
                    $total_minutes += $diff;
                }

                if ($total_minutes > 1440) {
                    $hours = round($total_minutes / 60, 1);
                    throw new Exception("Total shift duration cannot exceed 24 hours (Currently: {$hours} hours).");
                }

                $conn->query("DELETE FROM shifts WHERE agency_client_id = $mapping_id");
                
                foreach ($site_shifts as $i => $s_name) {
                    $s_name = trim($conn->real_escape_string($s_name));
                    $s_start = $conn->real_escape_string($shift_starts[$i] ?? '');
                    $s_end = $conn->real_escape_string($shift_ends[$i] ?? '');
                    
                    if (!empty($s_name)) {
                        $stmt = $conn->prepare("INSERT INTO shifts (agency_client_id, shift_name, start_time, end_time) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("isss", $mapping_id, $s_name, $s_start, $s_end);
                        $stmt->execute();
                    }
                }

                $conn->commit();
                
                if (isset($_POST['is_ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Site configuration and checkpoints updated successfully!']);
                    exit();
                }

                $message = "Site configuration and checkpoints updated successfully!";
                $message_type = "success";
                $show_status_modal = true;
            } catch (Exception $e) {
                $conn->rollback();
                
                if (isset($_POST['is_ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                    exit();
                }

                $message = "Error updating configuration: " . $e->getMessage();
                $message_type = "error";
                $show_status_modal = true;
            }
        }
    } else {
        $message = "Invalid client selection.";
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Add New Site (On-the-fly)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_new_site'])) {
    $parent_mapping_id = (int)$_POST['parent_mapping_id'];
    $new_site_name = $conn->real_escape_string($_POST['new_site_name']);

    try {
        // Fetch parent info
        $parent_res = $conn->query("SELECT client_id, company_name, client_limit, qr_limit FROM agency_clients WHERE id = $parent_mapping_id AND agency_id = $agency_id");
        if (!$parent_res || $parent_res->num_rows == 0) throw new Exception("Parent client not found.");
        
        $p = $parent_res->fetch_assoc();
        $target_client_id = $p['client_id'];
        $comp_name = $p['company_name'];
        $s_limit = (int)$p['client_limit'];
        $qr_limit = (int)$p['qr_limit'];

        // Check current count
        $count_res = $conn->query("SELECT COUNT(*) as current_sites FROM agency_clients WHERE client_id = $target_client_id AND agency_id = $agency_id");
        $current_sites = (int)$count_res->fetch_assoc()['current_sites'];

        if ($s_limit > 0 && $current_sites >= $s_limit) {
            throw new Exception("Cannot add more sites. This client has reached its limit of $s_limit site(s).");
        }

        // Insert new site mapping
        // We COPY the organizational settings, including the pooled QR limit
        $ins_sql = "INSERT INTO agency_clients (agency_id, client_id, company_name, site_name, qr_limit, client_limit) 
                    VALUES ($agency_id, $target_client_id, '$comp_name', '$new_site_name', $qr_limit, $s_limit)";
        
        if ($conn->query($ins_sql)) {
            $last_id = $conn->insert_id;
            header("Location: agency_patrol_management.php?tab=qr&mapping_id=$last_id");
            exit();
        } else {
            throw new Exception("Database error: " . $conn->error);
        }

    } catch (Exception $e) {
        setStatusMessage($e->getMessage(), "error");
        header("Location: agency_patrol_management.php?tab=qr&mapping_id=$parent_mapping_id");
        exit();
    }
}

// Fetch assigned clients for the dropdown (Patrol Tab) and QR Management Tab
$clients_sql = "
    SELECT 
        ac.id as mapping_id, 
        ac.client_id,
        u.username AS client_name, 
        ac.site_name, 
        ac.is_patrol_locked,
        ac.shift_type,
        ac.company_name,
        ac.qr_limit, 
        ac.guard_limit,
        ac.inspector_limit,
        ac.client_limit,
        ac.qr_override, 
        ac.is_disabled,
        ac.visual_saved,
        (SELECT COUNT(*) FROM checkpoints WHERE is_zero_checkpoint = 0 AND agency_client_id = ac.id) as site_used_qrs,
        (SELECT COUNT(*) FROM checkpoints WHERE is_zero_checkpoint = 0 AND agency_client_id IN (SELECT id FROM agency_clients WHERE client_id = ac.client_id)) as total_used_qrs,
        (SELECT COUNT(*) FROM guard_assignments WHERE agency_client_id = ac.id) as site_used_guards,
        (SELECT COUNT(*) FROM guard_assignments WHERE agency_client_id IN (SELECT id FROM agency_clients WHERE client_id = ac.client_id)) as total_used_guards,
        (SELECT COUNT(*) FROM inspector_assignments WHERE agency_client_id = ac.id) as site_used_inspectors,
        (SELECT COUNT(*) FROM inspector_assignments WHERE agency_client_id IN (SELECT id FROM agency_clients WHERE client_id = ac.client_id)) as total_used_inspectors,
        (SELECT COUNT(*) FROM agency_clients WHERE client_id = ac.client_id) as site_count
    FROM agency_clients ac 
    JOIN users u ON ac.client_id = u.id 
    JOIN users ag_u ON ac.agency_id = ag_u.id
    WHERE ac.agency_id = $agency_id 
    ORDER BY COALESCE(ac.company_name, u.username) ASC, ac.site_name ASC";
$clients_res = $conn->query($clients_sql);
$clients_data = [];
while ($row = $clients_res->fetch_assoc()) {
    $clients_data[] = $row;
}

// Group by organization for the two-step selector
$orgs_data = [];
foreach ($clients_data as $c) {
    if (!isset($orgs_data[$c['client_id']])) {
        $orgs_data[$c['client_id']] = [
            'id' => $c['client_id'],
            'name' => $c['company_name'] ?: $c['client_name'],
            'client_limit' => (int)$c['client_limit'],
            'first_mapping_id' => $c['mapping_id']
        ];
    }
}

$selected_mapping_id = isset($_GET['mapping_id']) ? (int)$_GET['mapping_id'] : (isset($_POST['mapping_id']) ? (int)$_POST['mapping_id'] : (isset($_POST['agency_client_id']) ? (int)$_POST['agency_client_id'] : null));

$selected_client = null;
if ($selected_mapping_id) {
    foreach ($clients_data as $client) {
        if ($client['mapping_id'] == $selected_mapping_id) {
            $selected_client = $client;
            break;
        }
    }
}

// Fetch checkpoints for selected client (Patrol Tab)
$available_checkpoints = [];
$current_assignments = [];
$starting_point = null;

if ($selected_mapping_id) {
    $start_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $selected_mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
    if ($start_res && $start_res->num_rows > 0) {
        $starting_point = $start_res->fetch_assoc();
    } else {
        // Auto-create Starting Point if missing
        $code = "START-" . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        $conn->query("INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, is_zero_checkpoint) VALUES ($selected_mapping_id, 'Starting Point', '$code', 1)");
        $start_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $selected_mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
        $starting_point = $start_res->fetch_assoc();
    }

    // Auto-create End Point if missing
    $end_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $selected_mapping_id AND is_zero_checkpoint = 2 LIMIT 1");
    if ($end_res && $end_res->num_rows > 0) {
        $ending_point = $end_res->fetch_assoc();
    } else {
        $code = "END-" . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        $conn->query("INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, is_zero_checkpoint) VALUES ($selected_mapping_id, 'End Point', '$code', 2)");
        $end_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $selected_mapping_id AND is_zero_checkpoint = 2 LIMIT 1");
        $ending_point = $end_res->fetch_assoc();
    }

    $cp_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $selected_mapping_id AND is_zero_checkpoint = 0 ORDER BY name ASC");
    while ($row = $cp_res->fetch_assoc()) {
        $available_checkpoints[] = $row;
    }

    $assign_res = $conn->query("
        SELECT ta.checkpoint_id, ta.interval_minutes, ta.duration_minutes, ta.shift_name, cp.name 
        FROM tour_assignments ta 
        JOIN checkpoints cp ON ta.checkpoint_id = cp.id 
        WHERE ta.agency_client_id = $selected_mapping_id 
          AND cp.is_zero_checkpoint = 0
        ORDER BY ta.sort_order ASC
    ");
    while ($row = $assign_res->fetch_assoc()) {
        $current_assignments[] = $row;
    }

    // Fetch shifts for selected client (Patrol Tab)
    $client_shifts = [];
    $shift_query = $conn->query("SELECT shift_name, start_time, end_time FROM shifts WHERE agency_client_id = $selected_mapping_id ORDER BY id ASC");
    if ($shift_query) {
        while ($s_row = $shift_query->fetch_assoc()) {
            $s_display = $s_row['shift_name'];
            if ($s_row['start_time'] && $s_row['end_time']) {
                $s_start = date("H:i", strtotime($s_row['start_time']));
                $s_end = date("H:i", strtotime($s_row['end_time']));
                $s_display .= " ($s_start - $s_end)";
            }
            $client_shifts[] = $s_display;
        }
    }
}

// Fetch checkpoints for table view (Patrol Management Tab)
$checkpoints_result = null;
if ($selected_mapping_id) {
    $checkpoints_sql = "
        SELECT cp.id, cp.name, cp.checkpoint_code, cp.is_zero_checkpoint, cp.created_at, c.username as client_name, ac.site_name, ac.company_name
        FROM checkpoints cp
        JOIN agency_clients ac ON cp.agency_client_id = ac.id
        JOIN users c ON ac.client_id = c.id
        WHERE ac.id = $selected_mapping_id
        ORDER BY CASE cp.is_zero_checkpoint WHEN 1 THEN 0 WHEN 0 THEN 1 WHEN 2 THEN 2 ELSE 3 END ASC, cp.created_at DESC
    ";
    $checkpoints_result = $conn->query($checkpoints_sql);
}

// Determine active tab
$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'patrol') ? 'patrol' : 'qr';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <title>Patrol Management - Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="js/modal_system.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0; gap: 0; overflow-x: hidden; }

        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; transition: margin-left 0.3s ease; width: 100%; }
        .topbar { background: white; padding: 15px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; gap: 10px; }
        .topbar h2 { font-size: 1.15rem; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .badge { background: #d1fae5; color: #10b981; padding: 2px 8px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        
        .hamburger { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #374151; padding: 5px; z-index: 1001; }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        
        /* Tabs styling */
        .tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 0px; }
        .tab-btn { padding: 12px 24px; font-weight: 600; color: #6b7280; background: none; border: none; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s; font-size: 1rem; }
        .tab-btn:hover { color: #374151; }
        .tab-btn.active { color: #10b981; border-bottom-color: #10b981; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }

        /* Form elements */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #10b981; }
        
        .btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 1rem; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }

        /* Patrol Patterns Tab Styles */
        .tour-list-container { margin-top: 20px; counter-reset: tour-counter -1; }
        .tour-list { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
        .tour-item { position: relative; display: flex; align-items: center; gap: 15px; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 10px; transition: all 0.2s; }
        .tour-item:hover { border-color: #3b82f6; background: #fff; }
        .tour-item::before {
            counter-increment: tour-counter;
            content: counter(tour-counter);
            font-weight: 700;
            color: #64748b;
            font-size: 1.1rem;
            width: 20px;
            text-align: right;
            margin-right: -5px;
        }
        .tour-item-fixed::before { content: none !important; counter-increment: none !important; width: 0 !important; margin: 0 !important; }
        .tour-item.sortable-ghost { opacity: 0.4; }
        .handle { cursor: grab; color: #94a3b8; font-size: 1.2rem; display: flex; align-items: center; }
        .checkpoint-name { flex: 1; font-weight: 600; color: #1e293b; }
        
        .setting-inputs { display: flex; gap: 15px; align-items: center; }
        .input-group { display: flex; align-items: center; gap: 8px; background: #fff; padding: 4px 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .input-group input { width: 50px; border: none; text-align: center; font-weight: 600; outline: none; }
        .input-group label { font-size: 0.75rem; color: #64748b; font-weight: 500; }

        .remove-btn { color: #ef4444; background: none; border: none; cursor: pointer; padding: 5px; border-radius: 5px; transition: background 0.2s; }
        .remove-btn:hover { background: #fee2e2; }

        .lock-container { display: flex; align-items: center; gap: 10px; padding: 15px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; margin-bottom: 20px; }
        .lock-container input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; }
        .lock-label { font-weight: 600; color: #9a3412; cursor: pointer; }

        /* QR Management Tab Styles */
        .grid-container { display: grid; grid-template-columns: 1.6fr 1.1fr; gap: 24px; margin-bottom: 32px;}
        @media (max-width: 768px) { .grid-container { grid-template-columns: 1fr; } }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }

        .client-status-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 12px; }
        .progress-bar { width: 100%; height: 8px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden; margin-top: 8px;}
        .progress-fill { height: 100%; background-color: #10b981; transition: width 0.3s; }
        .progress-fill.warning { background-color: #f59e0b; }
        .progress-fill.danger { background-color: #ef4444; }

        .qr-display { padding: 20px; text-align: center; display: flex; flex-direction: column; align-items: center; }
        .qr-img { margin: 0 auto; display: flex; justify-content: center; align-items: center; width: 100%; }
        .qr-img canvas, .qr-img img { margin: 0 auto; }
        .code-display { font-family: monospace; font-size: 1.5rem; font-weight: bold; margin-top: 10px; width: 100%; text-align: center; }

        /* Print styles */
        @media print {
            .sidebar, .topbar, .tabs, .card:not(.print-card), .btn:not(.no-print) { display: none !important; }
            body { background: white; margin: 0; padding: 0; display: block !important; }
            .main-content { overflow: visible !important; display: block !important; }
            .content-area { padding: 0; margin: 0; max-width: 100%; display: block !important; }
            .modal-overlay { 
                position: static !important; 
                display: block !important; 
                background: none !important; 
                backdrop-filter: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .modal-content.print-card { 
                box-shadow: none !important; 
                border: none !important; 
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 auto !important;
                padding: 40px 0 !important;
                transform: none !important;
                animation: none !important;
            }
            .qr-display {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
            }
            .qr-img canvas, .qr-img img {
                margin: 0 auto !important;
            }
        }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; animation: modalFadeIn 0.3s ease-out forwards;}
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: inline-block;}
        .btn-confirm:hover { background: #be123c; }
        .modal-actions { display: flex; gap: 12px; margin-top: 20px; }

        /* Visual Designer Styles */
        .btn-visual {
            background-color: #6366f1;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-visual:hover { background-color: #4f46e5; }
        
        .visual-container {
            width: 100%;
            height: 400px;
            background-color: #f8fafc;
            background-image: 
                linear-gradient(rgba(203, 213, 225, 0.2) 1.5px, transparent 1.5px),
                linear-gradient(90deg, rgba(203, 213, 225, 0.2) 1.5px, transparent 1.5px);
            background-size: 30px 30px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            margin-top: 15px;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
        }
        .checkpoint-circle {
            width: 44px;
            height: 44px;
            border-radius: 50% 50% 50% 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.1rem;
            position: absolute;
            cursor: move;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            user-select: none;
            border: 3px solid #ffffff;
            /* Pin shape pointing DOWN (standard map pin) */
            transform: rotate(-45deg);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .checkpoint-circle:hover {
            transform: rotate(-45deg) scale(1.1);
            z-index: 100 !important;
        }
        .checkpoint-circle > span {
            /* Un-rotate the content so it is upright */
            transform: rotate(45deg);
            display: block;
        }
        .checkpoint-circle.start {
            background: linear-gradient(135deg, #10b981, #111827);
            color: white;
            z-index: 10;
        }
        .checkpoint-circle.end {
            background: linear-gradient(135deg, #ea580c, #111827);
            color: white;
            z-index: 10;
        }
        .checkpoint-circle.regular {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #ffffff;
            border-color: #ffffff;
        }
        .checkpoint-circle .label {
            position: absolute;
            bottom: -45px;
            white-space: nowrap;
            font-size: 0.85rem;
            color: #111827;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.95);
            padding: 2px 10px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            /* Reset the parent rotation for the label */
            transform: rotate(45deg);
            transform-origin: center;
            border: 1px solid #e2e8f0;
        }

        /* Static Status Colors & Animations */
        .cp-status-on-time { 
            background: linear-gradient(135deg, #10b981, #059669) !important; 
            color: white !important; 
        }
        .cp-status-late { 
            background: linear-gradient(135deg, #ef4444, #dc2626) !important; 
            color: white !important; 
        }
        .cp-status-none { 
            background: linear-gradient(135deg, #94a3b8, #64748b) !important; 
            color: white !important; 
        }

        .path-status-on-time {
            stroke: #10b981 !important;
            animation: pulse-line-on-time 2s infinite;
        }

        .path-status-late {
            stroke: #ef4444 !important;
            animation: pulse-line-late 2s infinite;
        }

        @keyframes pulse-line-on-time {
            0% { filter: drop-shadow(0 0 2px rgba(16, 185, 129, 0.4)); opacity: 0.7; }
            50% { filter: drop-shadow(0 0 8px rgba(16, 185, 129, 1)); opacity: 1; }
            100% { filter: drop-shadow(0 0 2px rgba(16, 185, 129, 0.4)); opacity: 0.7; }
        }

        @keyframes pulse-line-late {
            0% { filter: drop-shadow(0 0 2px rgba(239, 68, 68, 0.4)); opacity: 0.7; }
            50% { filter: drop-shadow(0 0 8px rgba(239, 68, 68, 1)); opacity: 1; }
            100% { filter: drop-shadow(0 0 2px rgba(239, 68, 68, 0.4)); opacity: 0.7; }
        }

        .download-mode .checkpoint-circle { animation: none !important; box-shadow: none !important; }

        .visual-world {
            position: absolute;
            top: 0;
            left: 0;
            width: 3000px;
            height: 3000px;
            transform-origin: 0 0;
            will-change: transform;
            /* Allow lines to be seen outside world bounds (fixes clipping) */
            overflow: visible;
        }

        .visual-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 3000px;
            height: 3000px;
            pointer-events: none;
            z-index: 1;
            overflow: visible;
        }

        /* Floating Controls */
        .visual-controls {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 1000;
        }
        .ctrl-btn {
            width: 38px;
            height: 38px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: #1e293b;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.2s;
            backdrop-filter: blur(4px);
        }
        .ctrl-btn:hover { background: #ffffff; color: #6366f1; transform: translateY(-1px); }
        .ctrl-btn:active { transform: translateY(0); scale: 0.95; }
        .ctrl-btn.fit { font-size: 0.9rem; }

        .visual-path {
            stroke: #6366f1;
            stroke-width: 3;
            stroke-linecap: round;
            fill: none;
            stroke-dasharray: 8 4;
            filter: drop-shadow(0 0 2px rgba(99, 102, 241, 0.5));
        }
        .static-arrow {
            fill: #6366f1;
        }
        .flowing-arrow {
            fill: #4f46e5;
            offset-anchor: center;
            offset-path: none;
            animation: flow 4s linear infinite;
        }
        @keyframes flow {
            from { offset-distance: 0%; }
            to { offset-distance: 100%; }
        }

        /* Snapshot Mode: Static arrows, no animations */
        .download-mode .flowing-arrow { display: none !important; }
        .download-mode .static-arrow { display: block !important; }
        .download-mode .visual-path { transition: none !important; }
        .download-mode .checkpoint-circle { animation: none !important; box-shadow: none !important; }

        .static-arrow {
            display: none;
            fill: #6366f1;
        }

        @keyframes flow-arrow {
            from { offset-distance: 0%; }
            to { offset-distance: 100%; }
        }
        
        .modal-content.large { max-width: 900px; }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; }
        .close-modal-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280; }
        .close-modal-btn:hover { color: #1f2937; }

        /* Modern Responsive Scaling Classes */
        .shift-input-row, .checkpoint-input-row {
            display: flex; gap: 10px; align-items: center; margin-bottom: 8px;
        }
        
        @media (max-width: 1024px) {
            .sidebar { position: fixed; left: -250px; height: 100%; z-index: 1000; }
            .sidebar.show { left: 0; }
            .hamburger { display: block; }
            .sidebar-overlay.show { display: block; }
            .content-area { padding: 16px; }
            .topbar { padding: 15px; }
        }

        @media (max-width: 768px) {
            .topbar h2 { font-size: 1rem; }
            .user-info span:first-child { display: none; }
            .card { padding: 20px; }
            .content-area { padding: 12px; }
            
            /* Aggressive Scaling for Inputs */
            .shift-input-row, .setting-inputs { 
                gap: 6px; 
                font-size: 0.85rem;
            }
            .form-control { padding: 8px 10px; font-size: 0.85rem; }
            .input-group { padding: 2px 6px; gap: 4px; }
            .input-group input { width: 35px; font-size: 0.85rem; }
            .btn { padding: 8px 14px; font-size: 0.85rem; }
            .tab-btn { padding: 10px 16px; font-size: 0.85rem; }
            
            .shift-input-row span { font-size: 0.65rem !important; }
            .shift-input-row input[type="time"] { padding: 6px 4px !important; font-size: 0.8rem; }
        }

        @media (max-width: 480px) {
            .shift-input-row { 
                gap: 4px; 
            }
            .shift-input-row > div:first-child { flex: 1.5 !important; min-width: 60px; }
            .shift-input-row span { display: none; } /* Hide "FROM"/"TO" text to save space */
            .input-group label { display: none; } /* Hide labels in patrol items */
            .checkpoint-name { font-size: 0.8rem; }
            .setting-inputs { gap: 4px; }
            .input-group { min-width: 45px; }
            .handle { font-size: 1rem; }
        }
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
            <li><a href="agency_patrol_management.php" class="nav-link active">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    <main class="main-content">
        <header class="topbar">
            <button class="hamburger" onclick="toggleSidebar()">☰</button>
            <h2>Patrol & QR Management</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                <span class="badge">AGENCY</span>
            </div>
        </header>

        <div class="content-area">
            
            <div class="tabs">
                <button class="tab-btn <?php echo $active_tab === 'qr' ? 'active' : ''; ?>" onclick="switchTab('qr')">QR Checkpoints</button>
                <button class="tab-btn <?php echo $active_tab === 'patrol' ? 'active' : ''; ?>" onclick="switchTab('patrol')">Patrol Patterns</button>
            </div>

            <!-- PATROL PATTERNS TAB -->
            <div id="tab-patrol" class="tab-pane <?php echo $active_tab === 'patrol' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">Configure Site Patrol Pattern</div>
                    
                    <?php if ($selected_mapping_id && $selected_client): ?>
                        <div style="display: flex; align-items: center; gap: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 18px; margin-bottom: 4px;">
                            <span style="font-size: 1.2rem;">📍</span>
                            <div>
                                <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600;">Viewing Patrol Pattern For</div>
                                <div style="font-weight: 700; color: #111827; font-size: 0.95rem;">
                                    <?php echo htmlspecialchars($selected_client['company_name'] ?: $selected_client['client_name']); ?>
                                    <?php if ($selected_client['site_name']): ?>
                                        <span style="font-weight: 400; color: #6b7280;"> — <?php echo htmlspecialchars($selected_client['site_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="background: #fef9c3; border: 1px solid #fde68a; border-radius: 10px; padding: 16px 20px; text-align: center; color: #78350f;">
                            <strong>No site selected.</strong> Please go to <a href="#" onclick="switchTab('qr'); return false;" style="color: #10b981; font-weight: 600;">QR Checkpoints</a>, choose a client and site, and then switch back here.
                        </div>
                    <?php endif; ?>

                    <?php if ($selected_mapping_id): ?>
                        <form action="agency_patrol_management.php" method="POST">
                            <input type="hidden" name="mapping_id" value="<?php echo $selected_mapping_id; ?>">
                            
                            <div class="lock-container">
                                <input type="checkbox" name="is_patrol_locked" id="lockPatrol" <?php echo ($selected_client['is_patrol_locked'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="lockPatrol" class="lock-label">Lock configuration for client (Agency Exclusive Mode)</label>
                            </div>

                            <?php if (!($selected_client['visual_saved'] ?? 0)): ?>
                            <div style="background: #fef3c7; border: 1.5px solid #fcd34d; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; display: flex; align-items: center; gap: 14px;">
                                <span style="font-size: 1.5rem;">🗺️</span>
                                <div>
                                    <div style="font-weight: 700; color: #92400e; font-size: 0.95rem;">Visual Layout Required</div>
                                    <div style="font-size: 0.82rem; color: #78350f; margin-top: 2px;">Please open the <strong>Visual Map Designer</strong> above, position your checkpoints, and click <strong>Save &amp; Lock Layout</strong> before configuring patrol patterns.</div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="tour-list-container">
                                <?php if ($starting_point): 
                                    $start_shift = $selected_client['shift_type'] ?? '';
                                    $start_interval = 1;
                                    $start_duration = 1;
                                    foreach($current_assignments as $as) {
                                        if($as['checkpoint_id'] == $starting_point['id']) {
                                            $start_shift = $as['shift_name'];
                                            $start_interval = $as['interval_minutes'] ?: 1;
                                            $start_duration = $as['duration_minutes'] ?: 1;
                                            break;
                                        }
                                    }
                                ?>
                                    <div style="display: flex; justify-content: flex-end; margin-bottom: 8px;">
                                        <button type="button" class="btn-visual" onclick="openVisualDesigner()" title="Open Visual Map Designer">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                            Visual
                                        </button>
                                    </div>
                                    <div class="tour-list" style="margin-bottom: 0px; border-bottom: none; border-bottom-left-radius: 0; border-bottom-right-radius: 0; padding-bottom: 0;">
                                        <div class="tour-item tour-item-fixed" style="cursor: default; background: #e0f2fe; border-color: #bae6fd; margin-bottom: 0;">
                                            <span class="handle" style="visibility: hidden; cursor: default;">☰</span>
                                            <span class="checkpoint-name"><?php echo htmlspecialchars($starting_point['name']); ?></span>
                                            <input type="hidden" name="checkpoint_ids[]" value="<?php echo $starting_point['id']; ?>">
                                            <div class="setting-inputs">
                                                <div class="input-group">
                                                    <label>Interval</label>
                                                    <input type="number" name="intervals[]" value="<?php echo $start_interval; ?>" min="0">
                                                    <label>min</label>
                                                </div>
                                                <div class="input-group">
                                                    <label>Duration</label>
                                                    <input type="number" name="durations[]" value="<?php echo $start_duration; ?>" min="0">
                                                    <label>min</label>
                                                </div>
                                                <input type="hidden" name="assignment_shifts[]" value="<?php echo htmlspecialchars($start_shift ?: 'Day Shift'); ?>">
                                            </div>
                                            <button type="button" class="remove-btn" style="visibility: hidden;">&times;</button>
                                        </div>
                                    </div>
                                <?php endif; ?>



                                <div id="tour-list" class="tour-list" style="<?php echo $starting_point ? 'margin-top: 0; border-top-left-radius: 0; border-top-right-radius: 0;' : ''; ?>">
                                    <?php foreach ($current_assignments as $item): ?>
                                        <?php if ($starting_point && $item['checkpoint_id'] == $starting_point['id']): 
                                            continue; 
                                        endif; ?>
                                        <div class="tour-item">
                                            <span class="handle">☰</span>
                                            <span class="checkpoint-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                            <input type="hidden" name="checkpoint_ids[]" value="<?php echo $item['checkpoint_id']; ?>">
                                            <div class="setting-inputs">
                                                <div class="input-group">
                                                    <label>Interval</label>
                                                    <input type="number" name="intervals[]" value="<?php echo $item['interval_minutes'] ?: 1; ?>" min="1">
                                                    <label>min</label>
                                                </div>
                                                <div class="input-group">
                                                    <label>Duration</label>
                                                    <input type="number" name="durations[]" value="<?php echo $item['duration_minutes'] ?: 1; ?>" min="1">
                                                    <label>min</label>
                                                </div>
                                                <input type="hidden" name="assignment_shifts[]" value="<?php echo htmlspecialchars($item['shift_name'] ?? 'Day Shift'); ?>">
                                            </div>
                                            <button type="button" class="remove-btn" onclick="removeCheckpoint(this, '<?php echo $item['checkpoint_id']; ?>', '<?php echo addslashes($item['name']); ?>')">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php
                            // End Point row — always pinned last
                            if (isset($ending_point)):
                                $end_shift = '';
                                $end_interval = 1;
                                $end_duration = 1;
                                foreach($current_assignments as $as) {
                                    if($as['checkpoint_id'] == $ending_point['id']) {
                                        $end_shift = $as['shift_name'];
                                        $end_interval = $as['interval_minutes'] ?: 1;
                                        $end_duration = $as['duration_minutes'] ?: 1;
                                        break;
                                    }
                                }
                            ?>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0 0 8px 8px; border-top: none; padding: 0 15px 15px 15px; margin-top: -5px;">
                                <div class="tour-item tour-item-fixed" style="cursor: default; background: #fff1f2; border-color: #fecdd3; margin-bottom: 0; counter-reset: tour-counter;">
                                    <span class="handle" style="visibility: hidden; cursor: default;">☰</span>
                                    <span class="checkpoint-name" style="color: #be123c; font-weight: 700;"><?php echo htmlspecialchars($ending_point['name']); ?></span>
                                    <input type="hidden" name="checkpoint_ids[]" value="<?php echo $ending_point['id']; ?>">
                                    <div class="setting-inputs">
                                        <div class="input-group">
                                            <label>Interval</label>
                                            <input type="number" name="intervals[]" value="<?php echo $end_interval; ?>" min="0">
                                            <label>min</label>
                                        </div>
                                        <div class="input-group">
                                            <label>Duration</label>
                                            <input type="number" name="durations[]" value="<?php echo $end_duration; ?>" min="0">
                                            <label>min</label>
                                        </div>
                                        <input type="hidden" name="assignment_shifts[]" value="<?php echo htmlspecialchars($end_shift ?: 'Day Shift'); ?>">
                                    </div>
                                    <button type="button" class="remove-btn" style="visibility: hidden;">&times;</button>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($selected_client['visual_saved'] ?? 0): ?>
                            <div style="display: flex; gap: 10px; margin-top: 20px;">
                                <select id="checkpoint-select" class="form-control" style="flex: 1;">
                                    <option value="">-- Add Checkpoint to Pattern --</option>
                                    <?php 
                                    $assigned_ids = array_column($current_assignments, 'checkpoint_id');
                                    foreach ($available_checkpoints as $cp): 
                                        if (in_array($cp['id'], $assigned_ids)) continue;
                                    ?>
                                        <option value="<?php echo $cp['id']; ?>"><?php echo htmlspecialchars($cp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-primary" onclick="addCheckpoint()">Add</button>
                            </div>

                            <button type="submit" name="save_patrol" class="btn btn-success" style="width: 100%; margin-top:20px;">Save Patrol Configuration</button>
                            <?php else: ?>
                            <div style="display: flex; gap: 10px; margin-top: 20px; opacity: 0.45; pointer-events: none; cursor: not-allowed;">
                                <select class="form-control" style="flex: 1;" disabled>
                                    <option>-- Add Checkpoint to Pattern --</option>
                                </select>
                                <button type="button" class="btn btn-primary" disabled>Add</button>
                            </div>
                            <button type="button" class="btn btn-success" style="width: 100%; margin-top:20px; opacity: 0.45; cursor: not-allowed;" disabled>Save Patrol Configuration</button>
                            <?php endif; ?>
                        </form>


                    <?php else: ?>
                        <p style="text-align: center; color: #64748b; padding: 40px;">Select a client site above to manage its patrol pattern.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- QR MANAGEMENT TAB -->
            <div id="tab-qr" class="tab-pane <?php echo $active_tab === 'qr' ? 'active' : ''; ?>">
                <div class="grid-container">
                    <!-- Update Site Form -->
                    <div class="card">
                        <h3 class="card-header">Set Client Site Config</h3>
                        <?php if (empty($clients_data)): ?>
                            <p style="color: #6b7280;">You have not been assigned any clients yet.</p>
                        <?php else: ?>
                            <form action="agency_patrol_management.php?tab=qr" method="POST" id="qrLimitForm" onsubmit="handleQrConfigSubmit(event)">
                                <div class="form-group">
                                    <label class="form-label" for="client_org_id">Select Client</label>
                                    <select id="client_org_id" class="form-control" onchange="onOrganizationChange()">
                                        <option value="" disabled selected>-- Choose Client --</option>
                                        <?php foreach($orgs_data as $org): ?>
                                            <option value="<?php echo $org['id']; ?>" <?php echo ((isset($selected_client['client_id']) && $selected_client['client_id'] == $org['id']) || (isset($preselect_org_id) && $preselect_org_id == $org['id'])) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($org['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="site_selection_container" style="display: none; margin-bottom: 24px; padding: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; animation: slideDown 0.3s ease-out;">
                                    <div class="form-group">
                                        <label class="form-label" for="qr_agency_client_id">Select Site</label>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <select id="qr_agency_client_id" name="agency_client_id" class="form-control" required onchange="updateLimitForm()" style="flex: 1;">
                                                <option value="" disabled selected>-- Choose Site --</option>
                                            </select>
                                            <button type="button" id="deleteSiteBtn" class="btn btn-danger" style="display: none; padding: 10px; background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; min-width: 44px; height: 44px; justify-content: center; align-items: center;" title="Remove Site" onclick="confirmDeleteSite()">
                                                <span style="font-size: 1.2rem;">🗑️</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div id="add_site_section" style="margin-top: 20px; padding-top: 15px; border-top: 1px dashed #86efac;">
                                        <label class="form-label">Add New Site</label>
                                        <div style="display: flex; gap: 10px;">
                                            <input type="text" id="new_site_name_input" class="form-control" placeholder="Enter Site Name (e.g. East Warehouse)">
                                            <button type="button" id="btn_add_site" class="btn btn-primary" onclick="addNewSite()" style="white-space: nowrap;">Add Site</button>
                                        </div>
                                        <p id="site_limit_warning" style="display: none; color: #ef4444; font-size: 0.8rem; margin-top: 8px; font-weight: 600;"></p>
                                    </div>
                                </div>

                                <div id="site_config_details" style="display: none; border-top: 2px solid #e5e7eb; padding-top: 20px; margin-top: 20px;">

                                <style>
                                    @keyframes slideDown {
                                        from { opacity: 0; transform: translateY(-10px); }
                                        to { opacity: 1; transform: translateY(0); }
                                    }
                                </style>
                                <div class="form-group">
                                    <label class="form-label" for="site_name">Site Name / Description</label>
                                    <input type="text" id="site_name" name="site_name" class="form-control" placeholder="e.g. Main Factory, West Wing" required onchange="markQrConfigDirty()">
                                </div>
                                <div class="form-group" style="margin-top: 24px;">
                                    <label class="form-label">Checkpoints</label>
                                    <div id="checkpoints-container" style="display: flex; flex-direction: column; gap: 10px;">
                                        <!-- Checkpoint inputs populated here -->
                                    </div>
                                    <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                                        <button type="button" class="btn" id="add-checkpoint-btn" style="background:#f3f4f6; color:#374151;" onclick="addCheckpointInput()">+ Add Checkpoint</button>
                                        <small id="qr-limit-text" style="color: #6b7280; font-weight: 500;"></small>
                                    </div>
                                </div>
                                
                                <div class="form-group" style="margin-top: 24px;">
                                    <label class="form-label">Site Shifts</label>
                                    <div id="shifts-container" style="display: flex; flex-direction: column; gap: 10px;">
                                        <!-- Shift inputs populated here -->
                                    </div>
                                    <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                                         <button type="button" class="btn" style="background:#f3f4f6; color:#374151;" onclick="addShiftInput()">+ Add Shift</button>
                                         <span id="shift-total-duration" style="font-size: 0.85rem; color: #64748b; font-weight: 600;">Total Duration: 0h / 24h</span>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 32px; height: 50px; font-weight: 600; font-size: 1rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);">Save Configuration</button>
                                </div> <!-- end site_config_details -->
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Client Usage Stats -->
                    <div class="card">
                        <h3 class="card-header">Client Limits & Usage</h3>
                        <div id="usage-sidebar-content" class="card-body">
                            <div style="text-align: center; padding: 20px; color: #64748b;">
                                <div style="font-size: 2rem; margin-bottom: 10px;">📊</div>
                                <p style="font-size: 0.85rem;">Select a client and site to view usage statistics.</p>
                            </div>
                        </div>
                    </div>
                </div>


            </div>

        </div>

        <!-- Status Modal -->
        <div id="statusModal" class="modal-overlay <?php echo $show_status_modal ? 'show' : ''; ?>">
            <div class="modal-content">
                <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                    <?php echo $message_type === 'success' ? '✓' : '!'; ?>
                </div>
                <h3><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
                <p style="color: #6b7280; margin-bottom: 24px; margin-top: 10px;"><?php echo htmlspecialchars($message); ?></p>
                <button class="btn btn-success" style="width: 100%;" onclick="closeModal('statusModal')">Done</button>
            </div>
        </div>

        <!-- Alert Modal for JS Validation -->
        <div id="alertModal" class="modal-overlay">
            <div class="modal-content">
                <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                    !
                </div>
                <h3>Notice</h3>
                <p id="alertModalText" style="color: #6b7280; margin-bottom: 24px; margin-top: 10px;"></p>
                <button class="btn btn-success" style="width: 100%;" onclick="closeAlertModal()">OK</button>
            </div>
        </div>

        <!-- Logout Modal -->
        <div class="modal-overlay" id="logoutModal">
            <div class="modal-content">
                <h3 style="margin-bottom: 10px;">Ready to Leave?</h3>
                <p style="color: #6b7280; font-size: 0.95rem; margin-bottom: 20px;">Select "Log Out" below if you are ready to end your current session.</p>
                <div class="modal-actions">
                    <button class="btn-modal btn-cancel" onclick="closeModal('logoutModal')">Cancel</button>
                    <a href="logout.php" class="btn-modal btn-confirm">Log Out</a>
                </div>
            </div>
        </div>

        <!-- Print QR Modal -->
        <div class="modal-overlay" id="printQRModal">
            <div class="modal-content print-card" style="max-width: 500px;">
                <h3 style="margin-bottom: 15px;" class="no-print">Checkpoint QR Code</h3>
                <div class="qr-display" id="qrContainer">
                    <h2 id="printSiteName" style="margin-bottom: 20px; color: #111827; font-size: 2rem;"></h2>
                    <div id="qrcode" class="qr-img" style="margin-bottom: 20px;"></div>
                    <div class="code-display" id="codeLabel" style="font-size: 1.8rem; letter-spacing: 2px;"></div>
                </div>
                <div class="modal-actions no-print">
                    <button class="btn-modal btn-cancel" onclick="closeModal('printQRModal')">Close</button>
                    <button class="btn-modal" style="background: #111827; color: white;" onclick="downloadQR()">Download QR</button>
                </div>
            </div>
        </div>

        <!-- Visual Designer Modal -->
        <div id="visualDesignerModal" class="modal-overlay">
            <div class="modal-content large">
                <div class="card-header card-header-flex" style="border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; padding-bottom: 12px;">
                    <h3 class="modal-title" style="margin-bottom: 0; font-size: 1.25rem;">Visual Patrol Map</h3>
                    <button type="button" class="close-modal-btn" onclick="closeVisualDesigner()">&times;</button>
                </div>
                <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px; text-align: left;">
                    Draggable overview of checkpoints. <strong>Green (S)</strong> is Start, <strong>Orange (E)</strong> is End, and <strong>White</strong> are checkpoints.
                </p>
                <div id="visual-canvas" class="visual-container">
                    <div class="visual-controls">
                        <button class="ctrl-btn" onclick="zoomMap(1.2)" title="Zoom In">+</button>
                        <button class="ctrl-btn" onclick="zoomMap(0.8)" title="Zoom Out">−</button>
                        <button class="ctrl-btn fit" onclick="fitMapToScreen()" title="Fit to Screen">FIT</button>
                    </div>
                    <div id="visual-world" class="visual-world" oncontextmenu="return false;">
                        <svg id="visual-svg" class="visual-svg">
                            <defs>
                                <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                                    <polygon points="0 0, 10 3.5, 0 7" fill="#6366f1" />
                                </marker>
                            </defs>
                        </svg>
                    </div>
                </div>
                <div class="modal-actions" style="margin-top: 20px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button id="saveVisualBtn" class="btn-modal btn-success" style="max-width: 200px;" onclick="saveVisualLayout()">Save Layout</button>
                    <button class="btn-modal" style="background: #0ea5e9; color: white; max-width: 200px;" onclick="downloadVisualMap()">Download Map (PDF)</button>
                    <button class="btn-modal btn-cancel" style="max-width: 200px;" onclick="closeVisualDesigner()">Close View</button>
                </div>
            </div>
        </div>

    </main>

    <script src="js/modal_system.js"></script>
    <script>
        async function confirmDeleteSite() {
            const select = document.getElementById('qr_agency_client_id');
            const sid = select.value;
            if (!sid) return;
            
            const siteName = select.options[select.selectedIndex].text;
            
            const confirmed = await CustomModal.confirm(
                `Are you sure you want to permanently remove ${siteName}? This will wipe all associated checkpoints, shifts, assignments, and historical reports for this location. You cannot delete the last site for a client.`,
                'Delete Site & Wipe History?'
            );

            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'agency_patrol_management.php';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'mapping_id';
                idInput.value = sid;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'delete_site';
                actionInput.value = '1';
                
                form.appendChild(idInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }


        const clientsData = <?php echo json_encode($clients_data); ?>;
        const orgsData = <?php echo json_encode(array_values($orgs_data)); ?>;
        const currentShifts = <?php echo json_encode($client_shifts ?? []); ?>;
        let elementToFocusAfterAlert = null;
        let isQrConfigDirty = false;

        function markQrConfigDirty() {
            if (!isQrConfigDirty) {
                console.log('QR Configuration marked as DIRTY');
                isQrConfigDirty = true;
            }
        }

        async function checkQrConfigClean() {
            if (isQrConfigDirty) {
                const stay = await CustomModal.confirm(
                    "You have unsaved changes in QR & Checkpoints. Save first?",
                    "Unsaved Changes"
                );
                if (stay) {
                    return false; // User wants to stay and save
                }
                // User clicked "Cancel" (in user's words) or chooses to discard/proceed
                isQrConfigDirty = false; 
            }
            return true;
        }
        
        // Sidebar Toggle Logic
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }

        // Initialize Sortable for drag-and-drop reordering
        document.addEventListener('DOMContentLoaded', function() {
            const tourList = document.getElementById('tour-list');
            if (tourList) {
                new Sortable(tourList, {
                    handle: '.handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost'
                });
            }
        });

        async function switchTab(tabId) {
            // Guard: If leaving QR tab with unsaved changes
            const currentTab = document.querySelector('.tab-pane.active').id.replace('tab-', '');
            if (currentTab === 'qr' && tabId !== 'qr') {
                const canProceed = await checkQrConfigClean();
                if (!canProceed) return;
            }

            if (tabId === 'patrol') {
                // When switching to patrol, do a full reload carrying the selected mapping_id
                // so PHP can render the correct patrol pattern server-side.
                const siteSelect = document.getElementById('qr_agency_client_id');
                const mappingId = siteSelect ? siteSelect.value : '';
                const url = new URL(window.location);
                url.searchParams.set('tab', 'patrol');
                if (mappingId) url.searchParams.set('mapping_id', mappingId);
                else url.searchParams.delete('mapping_id');
                window.location.href = url.toString();
                return;
            }

            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            const matchingBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => btn.innerText.toLowerCase().includes(tabId));
            if (matchingBtn) matchingBtn.classList.add('active');

            // Update panes
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            const pane = document.getElementById('tab-' + tabId);
            if (pane) pane.classList.add('active');
            
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        }

        function onOrganizationChange(skipUpdateLimit = false) {
            const orgSelect = document.getElementById('client_org_id');
            const siteSelect = document.getElementById('qr_agency_client_id');
            const siteContainer = document.getElementById('site_selection_container');
            const deleteBtn = document.getElementById('deleteSiteBtn');
            
            if (deleteBtn) deleteBtn.style.display = 'none';
            const btnAddSite = document.getElementById('btn_add_site');
            const limitWarning = document.getElementById('site_limit_warning');
            const nameInput = document.getElementById('new_site_name_input');
            
            const orgId = parseInt(orgSelect.value);
            if (!orgId) {
                siteContainer.style.display = 'none';
                return;
            }

            const org = orgsData.find(o => String(o.id) === String(orgId));
            if (!org) {
                console.warn('Organization not found in orgsData:', orgId);
                return;
            }

            const sites = clientsData.filter(c => String(c.client_id) === String(orgId));
            
            // Populate sites
            siteSelect.innerHTML = '<option value="" disabled selected>-- Choose Site --</option>';
            sites.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.mapping_id;
                opt.text = s.site_name || 'Primary Site';
                siteSelect.add(opt);
            });

            // Auto-select if only one site exists
            if (sites.length === 1) {
                siteSelect.value = sites[0].mapping_id;
            }
            
            if (!skipUpdateLimit) updateLimitForm();

            // Check limits
            const limit = parseInt(org.client_limit) || 0;
            const currentCount = sites.length;
            
            if (limit > 0 && currentCount >= limit) {
                btnAddSite.disabled = true;
                btnAddSite.style.opacity = '0.5';
                limitWarning.innerText = `Limit reached (${currentCount}/${limit} sites). Contact Admin to increase limit.`;
                limitWarning.style.display = 'block';
                nameInput.disabled = true;
            } else {
                btnAddSite.disabled = false;
                btnAddSite.style.opacity = '1';
                limitWarning.style.display = 'none';
                nameInput.disabled = false;
            }

            siteContainer.style.display = 'block';
        }

        async function addNewSite() {
            const orgSelect = document.getElementById('client_org_id');
            const nameInput = document.getElementById('new_site_name_input');
            const siteName = nameInput.value.trim();
            const orgId = orgSelect.value;
            
            if (!orgId || !siteName) {
                showAlert('Please select an organization and enter a site name.');
                return;
            }

            const org = orgsData.find(o => String(o.id) === String(orgId));
            const parentMappingId = org.first_mapping_id;

            const confirmed = await CustomModal.confirm(`Are you sure you want to add the site "${siteName}" to this organization?`);
            if (!confirmed) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'agency_patrol_management.php?tab=qr';
            
            const input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'add_new_site';
            input1.value = '1';
            form.appendChild(input1);
            
            const input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'parent_mapping_id';
            input2.value = parentMappingId;
            form.appendChild(input2);
            
            const input3 = document.createElement('input');
            input3.type = 'hidden';
            input3.name = 'new_site_name';
            input3.value = siteName;
            form.appendChild(input3);
            
            document.body.appendChild(form);
            form.submit();
        }

        // Handle URL mapping_id or PHP-injected mapping_id on load
        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const mid = urlParams.get('mapping_id') || '<?php echo $selected_mapping_id ?? ""; ?>';
            if (mid) {
                const client = clientsData.find(c => String(c.mapping_id) === String(mid));
                if (client) {
                    const orgSelect = document.getElementById('client_org_id');
                    if (orgSelect) {
                        orgSelect.value = client.client_id;
                        onOrganizationChange(true); // Skip updateLimitForm as we call it explicitly below
                        
                        const siteSelect = document.getElementById('qr_agency_client_id');
                        if (siteSelect) {
                            siteSelect.value = mid;
                            updateLimitForm();
                        }
                    }
                }
            } else {
                // Check if any organization is pre-selected
                const orgSelect = document.getElementById('client_org_id');
                if (orgSelect && orgSelect.value) {
                    onOrganizationChange();
                }
            }
        });

        function updateUsageSidebar(mappingId) {
            const container = document.getElementById('usage-sidebar-content');
            if (!container) return;

            if (!mappingId) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #64748b;">
                        <div style="font-size: 2rem; margin-bottom: 10px;">📊</div>
                        <p style="font-size: 0.85rem;">Select a client and site to view usage statistics.</p>
                    </div>
                `;
                return;
            }

            const client = clientsData.find(c => String(c.mapping_id) === String(mappingId));
            if (!client) return;

            // QR Calculations
            const qrLimit = parseInt(client.qr_limit) || 0;
            const qrSiteUsed = parseInt(client.site_used_qrs) || 0;
            const qrPercent = qrLimit > 0 ? (qrSiteUsed / qrLimit) * 100 : 0;
            
            // Guard Calculations (Pooled across org, but showing relevant to this client context)
            const grdLimit = parseInt(client.guard_limit) || 0;
            const grdTotalUsed = parseInt(client.total_used_guards) || 0;
            const grdPercent = grdLimit > 0 ? (grdTotalUsed / grdLimit) * 100 : 0;

            // Inspector Calculations (Pooled)
            const inspLimit = parseInt(client.inspector_limit) || 0;
            const inspTotalUsed = parseInt(client.total_used_inspectors) || 0;
            const inspPercent = inspLimit > 0 ? (inspTotalUsed / inspLimit) * 100 : 0;

            const getFillColor = (p) => p > 90 ? '#ef4444' : (p > 75 ? '#f59e0b' : '#10b981');

            container.innerHTML = `
                <div style="margin-bottom: 24px; padding-bottom: 20px;">
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem; margin-bottom: 18px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 8px;">
                        ${client.site_name || 'Primary Site'}
                    </div>
                    
                    <!-- QR Usage -->
                    <div style="margin-bottom: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px;">
                             <span style="font-size: 0.72rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">QR Checkpoints</span>
                             <span style="font-size: 0.85rem; color: #1e293b; font-weight: 700;">${qrSiteUsed} / ${qrLimit}</span>
                        </div>
                        <div class="progress-bar" style="height: 6px; background: #f1f5f9; border-radius: 3px; overflow: hidden; margin-bottom: 4px;">
                            <div style="width: ${Math.min(100, qrPercent)}%; height: 100%; background: ${getFillColor(qrPercent)}; border-radius: 3px; transition: width 0.3s ease;"></div>
                        </div>
                    </div>

                    ${client.is_disabled == 1 ? '<div style="margin-top: 15px; padding: 10px; background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; color: #ef4444; font-size: 0.75rem; font-weight: 700; text-align: center; text-transform: uppercase; letter-spacing: 0.05em;">Account Suspended</div>' : ''}
                    ${client.qr_override == 1 ? '<div style="margin-top:12px; font-size: 0.65rem; color: #10b981; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">✓ Admin Override Active</div>' : ''}
                </div>
            `;
        }

        async function updateLimitForm() {
            const select = document.getElementById('qr_agency_client_id');
            const mappingId = parseInt(select.value);
            const siteDetails = document.getElementById('site_config_details');
            const deleteBtn = document.getElementById('deleteSiteBtn');

            if (!mappingId) {
                if (siteDetails) siteDetails.style.display = 'none';
                if (deleteBtn) deleteBtn.style.display = 'none';
                updateUsageSidebar(null);
                return;
            }
            
            updateUsageSidebar(mappingId);
            if (deleteBtn) deleteBtn.style.display = 'flex';
            if (siteDetails) siteDetails.style.display = 'block';

            const siteNameInput = document.getElementById('site_name');
            const container = document.getElementById('checkpoints-container');
            const shiftContainer = document.getElementById('shifts-container');
            const limitText = document.getElementById('qr-limit-text');
            
            // Clear all previous data immediately
            siteNameInput.value = '';
            container.innerHTML = '';
            shiftContainer.innerHTML = '';
            limitText.textContent = '';
            isQrConfigDirty = false; // Reset when switching sites

            // Store current mappingId to handle race conditions
            select.dataset.currentLoading = mappingId;

            const client = clientsData.find(c => parseInt(c.mapping_id) === mappingId);
            if (client) {
                siteNameInput.value = client.site_name || '';
                
                // Set limit text (0 means zero, not unlimited)
                const limit = parseInt(client.qr_limit || 0);
                const used = parseInt(client.site_used_qrs || 0);
                const remaining = Math.max(0, limit - used);
                
                limitText.textContent = `Available: ${remaining} of ${limit}`;
                limitText.dataset.limit = limit;
                
                // Update Limit Text & Button State
                updateQrLimitUI();

                // Fetch existing checkpoints and shifts
                try {
                    const response = await fetch(`agency_patrol_management.php?ajax_checkpoints=1&mapping_id=${mappingId}`);
                    const data = await response.json();
                    
                    // Race condition check: only proceed if this was the latest request
                    if (select.dataset.currentLoading !== String(mappingId)) return;

                    if (data.checkpoints && data.checkpoints.length > 0) {
                        data.checkpoints.forEach(cp => {
                            if (cp.isStart || cp.isEnd) return; // Skip Starting Point and End Point
                            addCheckpointInput(cp.name, cp.id, true); // true = skipDirty
                        });
                    } else {
                        addCheckpointInput('', '', true); // Add at least one empty row, skipDirty
                    }

                    if (data.shifts && data.shifts.length > 0) {
                        data.shifts.forEach(sh => {
                            addShiftInput(sh.shift_name, sh.id, sh.start_time, sh.end_time, true); // true = skipDirty
                        });
                    }
                } catch (e) {
                    console.error('Error fetching site data:', e);
                    addCheckpointInput();
                    addShiftInput();
                }
            }
        }

        function calculateTotalShiftMinutes() {
            const starts = document.querySelectorAll('input[name="shift_starts[]"]');
            const ends = document.querySelectorAll('input[name="shift_ends[]"]');
            let total = 0;

            for (let i = 0; i < starts.length; i++) {
                const s = starts[i].value;
                const e = ends[i].value;
                if (!s || !e) continue;

                const [sH, sM] = s.split(':').map(Number);
                const [eH, eM] = e.split(':').map(Number);
                const sTotal = sH * 60 + sM;
                const eTotal = eH * 60 + eM;

                if (eTotal > sTotal) {
                    total += (eTotal - sTotal);
                } else if (eTotal < sTotal) {
                    total += (1440 - sTotal + eTotal);
                } else {
                    total += 1440; // 24h shift
                }
            }
            return total;
        }

        function updateDurationDisplay(skipDirty = false) {
            const total = calculateTotalShiftMinutes();
            const hours = (total / 60).toFixed(1);
            const display = document.getElementById('shift-total-duration');
            if (display) {
                display.innerText = `Total Duration: ${hours}h / 24h`;
                display.style.color = total > 1440 ? '#ef4444' : '#64748b';
            }
            if (!skipDirty) markQrConfigDirty();
        }

        function addShiftInput(value = '', id = '', startTime = '', endTime = '', skipDirty = false) {
            const container = document.getElementById('shifts-container');
            
            // If adding a new empty row (Manual user click)
            if (value === '') {
                const total = calculateTotalShiftMinutes();
                if (total >= 1440) {
                    showAlert("You have already allocated 24 hours of shifts. Please adjust or remove an existing shift before adding a new one.");
                    return;
                }
            }

            const row = document.createElement('div');
            row.className = 'shift-input-row';
            
            // Format time strings HH:MM:SS -> HH:MM for HTML5 time picker
            const formattedStart = startTime ? startTime.substring(0, 5) : '';
            const formattedEnd = endTime ? endTime.substring(0, 5) : '';

            row.innerHTML = `
                <div style="flex: 1.2; min-width: 70px;">
                    <input type="text" name="shifts[]" class="form-control" placeholder="e.g. Morning Shift" value="${value.replace(/"/g, '&quot;')}" required>
                </div>
                <div style="flex: 1; display: flex; align-items: center; gap: 4px; min-width: 125px; flex-shrink: 0;">
                    <span style="font-size: 0.7rem; color: #64748b; font-weight: 700;">FROM</span>
                    <input type="time" name="shift_starts[]" class="form-control" value="${formattedStart}" style="padding: 8px 4px; font-size: 0.85rem;" onchange="updateDurationDisplay()">
                </div>
                <div style="flex: 1; display: flex; align-items: center; gap: 4px; min-width: 125px; flex-shrink: 0;">
                    <span style="font-size: 0.7rem; color: #64748b; font-weight: 700;">TO</span>
                    <input type="time" name="shift_ends[]" class="form-control" value="${formattedEnd}" style="padding: 8px 4px; font-size: 0.85rem;" onchange="updateDurationDisplay()">
                </div>
                <button type="button" class="btn btn-danger" style="background:#ef4444; color:white; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; border-radius: 8px; border: none; flex-shrink: 0;" onclick="this.parentElement.remove(); updateDurationDisplay();">✕</button>
            `;
            container.appendChild(row);
            updateDurationDisplay(skipDirty);
        }

        async function addCheckpointInput(value = '', id = '', skipDirty = false) {
            const container = document.getElementById('checkpoints-container');
            const select = document.getElementById('qr_agency_client_id');
            const mappingId = select.value;
            
            // If adding a new empty input (User Manual Click)
            if (value === '') {
                if (!mappingId) return;

                const existingInputs = container.querySelectorAll('input[name="checkpoints[]"]');
                for (let i = 0; i < existingInputs.length; i++) {
                    if (existingInputs[i].value.trim() === '') {
                        showAlert('Please fill out the existing checkpoint field before adding another one.', existingInputs[i]);
                        return;
                    }
                }

                const limitText = document.getElementById('qr-limit-text');
                const currentInputs = container.querySelectorAll('.cp-input-row').length;
                
                if (limitText.dataset.limit !== 'unlimited') {
                    const limit = parseInt(limitText.dataset.limit || 0);
                    if (limit > 0 && currentInputs >= limit) {
                        showAlert(`Cannot add more checkpoints. The limit for this client is ${limit}.`);
                        return;
                    }
                }


            }

            const row = document.createElement('div');
            row.className = 'cp-input-row';
            row.style.display = 'flex';
            row.style.gap = '10px';
            
            // If the value is the database default, treat it as empty for UI (to show placeholder)
            const displayValue = (value === 'New Checkpoint') ? '' : value;
            
            row.innerHTML = `
                <input type="hidden" name="checkpoint_ids[]" value="${id}">
                <input type="text" name="checkpoints[]" class="form-control" placeholder="New Checkpoint" value="${displayValue.replace(/"/g, '&quot;')}" required onchange="markQrConfigDirty()">
                <button type="button" class="btn btn-danger" style="background:#ef4444; color:white; padding: 0 16px;" onclick="removeCheckpoint(this, '${id}')">✕</button>
            `;
            container.appendChild(row);
            if (!skipDirty) markQrConfigDirty();
            
            // Update UI State
            updateQrLimitUI();
        }

        function updateQrLimitUI() {
            const btn = document.getElementById('add-checkpoint-btn');
            const limitText = document.getElementById('qr-limit-text');
            const container = document.getElementById('checkpoints-container');
            const select = document.getElementById('qr_agency_client_id');
            if (!btn || !limitText || !container || !select) return;

            const mappingId = select.value;
            const limit = parseInt(limitText.dataset.limit || 0);
            const count = container.querySelectorAll('.cp-input-row').length;
            const remaining = Math.max(0, limit - count);

            // 1. Update the "Available: X of Y" text immediately
            limitText.textContent = `Available: ${remaining} of ${limit}`;

            // 2. Toggle the button state
            if (count >= limit) {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
                btn.title = 'Limit reached. Delete an existing checkpoint to add a new one.';
            } else {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.title = '';
            }
        }



        function validateShifts() {
            const total = calculateTotalShiftMinutes();
            if (total > 1440) {
                const hours = (total / 60).toFixed(1);
                showAlert(`Total shift duration cannot exceed 24 hours (Currently: ${hours} hours). Data not saved.`);
                return false;
            }
            return true;
        }

        async function removeCheckpoint(btn, id, isDirectTableDelete = false) {
            const select = document.getElementById('qr_agency_client_id');
            const mappingId = select.value;

            // 1. Instant UI Removal & Refresh
            if (btn && btn.parentElement) {
                btn.parentElement.remove();
                markQrConfigDirty();
                updateQrLimitUI();
            }

            // 2. Background Deletion (if already exists in DB)
            if (id && id !== '') {
                // Background confirm only if it's already saved
                const confirmed = await CustomModal.confirm("Are you sure you want to delete this checkpoint and all its data? This cannot be undone.");
                if (!confirmed) {
                    // Note: In this draft UI, if they cancel the DB delete but already removed the row,
                    // they would need to refresh or re-add it. For consistency, we proceed.
                    return; 
                }
                
                try {
                    const formData = new FormData();
                    formData.append('ajax_delete_checkpoint', '1');
                    formData.append('cp_id', id);
                    
                    const response = await fetch('agency_patrol_management.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    if (response.ok) {
                        const tableRow = document.querySelector(`tr[data-cp-id="${id}"]`);
                        if (tableRow) tableRow.remove();

                        const client = clientsData.find(c => String(c.mapping_id) === String(mappingId));
                        if (client) {
                            client.site_used_qrs = Math.max(0, parseInt(client.site_used_qrs || 0) - 1);
                            updateUsageSidebar(mappingId);
                        }
                    }
                } catch (e) {
                    console.error('Error deleting checkpoint:', e);
                }
            }
        }

        async function handleQrConfigSubmit(e) {
            if (e) e.preventDefault();
            
            const form = document.getElementById('qrLimitForm');
            if (!form) return;

            // 1. Validate 24h shift requirement
            if (!validateShifts()) return;
            
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span style="display:inline-block; animation: spin 1s linear infinite;">⏳</span> Saving...';
            btn.disabled = true;

            try {
                // 2. Prepare Unified Sync Payload
                const formData = new FormData(form);
                formData.append('update_limit', '1');
                formData.append('is_ajax', '1');

                // 3. POST to Unified Handler
                const response = await fetch('agency_patrol_management.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    isQrConfigDirty = false; // RESET DIRTY FLAG

                    const siteSelect = document.getElementById('qr_agency_client_id');
                    const mappingId = siteSelect.value;
                    const container = document.getElementById('checkpoints-container');
                    const currentCount = container ? container.querySelectorAll('.cp-input-row').length : 0;

                    // SYNC: Update the local cache object so the sidebar stays correct
                    const clientRecord = clientsData.find(c => String(c.mapping_id) === String(mappingId));
                    if (clientRecord) {
                        clientRecord.site_used_qrs = currentCount;
                        // Also update site name in case it changed
                        const siteNameInput = document.getElementById('site_name');
                        if (siteNameInput) clientRecord.site_name = siteNameInput.value;
                    }

                    // We reload the mapping form to ensure all new checkpoints get their DB IDs
                    await updateLimitForm();
                    
                    // Update Dropdown text in case site name changed
                    const siteNameInput = document.getElementById('site_name');
                    if (siteSelect && siteSelect.selectedIndex >= 0 && siteNameInput) {
                        siteSelect.options[siteSelect.selectedIndex].text = siteNameInput.value || 'Primary Site';
                    }

                    showAlert(data.message || 'Configuration saved successfully!', 'success');
                } else {
                    showAlert(data.message || 'Failed to save configuration.', 'error');
                }
            } catch (err) {
                console.error('Unified Sync failed:', err);
                showAlert('Failed to save configuration. Please try again.', 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Global Navigation Guards
        document.addEventListener('click', async (e) => {
            const anchor = e.target.closest('a');
            // If it's a link leading away from the current page/hash
            if (anchor && anchor.href && !anchor.href.includes('javascript:') && !anchor.href.includes('#')) {
                // If it's not a tab switch (handled by switchTab)
                const onclick = anchor.getAttribute('onclick') || '';
                if (!onclick.includes('switchTab')) {
                    if (isQrConfigDirty) {
                        e.preventDefault();
                        e.stopPropagation();
                        const canProceed = await checkQrConfigClean();
                        if (canProceed) {
                            window.location.href = anchor.href;
                        }
                    }
                }
            }
        }, true);

        window.onbeforeunload = function() {
            if (isQrConfigDirty) {
                return "You have unsaved changes. Are you sure you want to leave?";
            }
        };

        // Patrol Add Checkpoint Logic
        function addCheckpoint() {
            const select = document.getElementById('checkpoint-select');
            const id = select.value;
            const name = select.options[select.selectedIndex].text;

            if (!id) return;

            let shiftOptions = '';
            if (currentShifts.length > 0) {
                shiftOptions = currentShifts.map(s => `<option value="${s.replace(/"/g, '&quot;')}">${s}</option>`).join('');
            } else {
                shiftOptions = '<option value="" disabled selected>No shifts defined</option>';
            }

            const list = document.getElementById('tour-list');
            const item = document.createElement('div');
            item.className = 'tour-item';
            item.innerHTML = `
                <span class="handle">☰</span>
                <span class="checkpoint-name">${name}</span>
                <input type="hidden" name="checkpoint_ids[]" value="${id}">
                <div class="setting-inputs">
                    <div class="input-group">
                        <label>Interval</label>
                        <input type="number" name="intervals[]" value="0" min="0">
                        <label>min</label>
                    </div>
                    <div class="input-group">
                        <label>Duration</label>
                        <input type="number" name="durations[]" value="1" min="0">
                        <label>min</label>
                    </div>
                    <div class="input-group">
                        <label>Shift Declare</label>
                        <select name="assignment_shifts[]" class="form-control" style="padding: 4px 8px; font-size: 0.85rem; font-weight: 600; min-width: 120px;">
                            ${shiftOptions}
                        </select>
                    </div>
                </div>
                <button type="button" class="remove-btn" onclick="removeCheckpoint(this, '${id}', '${name.replace(/'/g, "\\'")}')">&times;</button>
            `;
            list.appendChild(item);
            
            // Re-filter the dropdown (robust replacement for select.remove)
            filterCheckpointSelect();
            select.value = '';
        }

    function filterCheckpointSelect() {
        const select = document.getElementById('checkpoint-select');
        if (!select) return;

        // Get all used checkpoint IDs specifically within the Patrol Sequence list
        const usedIds = Array.from(document.getElementById('tour-list').querySelectorAll('input[name="checkpoint_ids[]"]')).map(input => input.value);
        
        // Loop through options and hide if already in the sequence
        Array.from(select.options).forEach(option => {
            if (option.value === "") return; // Don't hide the placeholder
            
            if (usedIds.includes(option.value)) {
                option.style.display = 'none';
            } else {
                option.style.display = 'block';
            }
        });
        
        // If the currently selected option was just hidden, reset selection to placeholder
        if (select.value !== "" && select.options[select.selectedIndex].style.display === 'none') {
            select.value = "";
        }
    }

        function removeCheckpoint(btn, id, name) {
            btn.parentElement.remove();
            // Re-filter the dropdown
            filterCheckpointSelect();
            const select = document.getElementById('checkpoint-select');
            select.value = '';
        }

        // Print Modal Logic
        function showPrintModal(code, name, client, siteName, companyName, index) {
            // Priority: Company Name, then Site Name, then Client Name
            const displayTitle = companyName || siteName || client;
            document.getElementById('printSiteName').textContent = displayTitle;
            document.getElementById('codeLabel').textContent = name;
            
            document.getElementById('qrcode').innerHTML = '';
            
            new QRCode(document.getElementById("qrcode"), {
                text: code,
                width: 256,
                height: 256,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
            
            document.getElementById('printQRModal').dataset.filename = (displayTitle + "_" + code).replace(/[^a-z0-9]/gi, '_');
            document.getElementById('printQRModal').classList.add('show');
        }

        function downloadQR() {
            const qrCanvas = document.querySelector('#qrcode canvas');
            const qrImg = document.querySelector('#qrcode img');
            const companyName = document.getElementById('printSiteName').textContent;
            const checkpointLabel = document.getElementById('codeLabel').textContent;
            
            // Create a temporary canvas for the final image (CR80 ID size - 638x1012 @ 300DPI approx)
            const finalCanvas = document.createElement('canvas');
            const ctx = finalCanvas.getContext('2d');
            
            // Set dimensions
            const width = 638;
            const height = 1012;
            finalCanvas.width = width;
            finalCanvas.height = height;
            
            // Fill background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, width, height);
            
            // Draw subtle card border
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 2;
            ctx.strokeRect(5, 5, width - 10, height - 10);
            
            // Draw Company Name
            ctx.fillStyle = '#111827';
            ctx.font = 'bold 36px Arial';
            ctx.textAlign = 'center';
            ctx.fillText(companyName, width / 2, 80, width - 60);
            
            // Decorative line
            ctx.fillStyle = '#10b981'; // Sentinel Green
            ctx.fillRect(width * 0.25, 110, width * 0.5, 4);
            
            // Draw QR Code (Scaled up)
            const qrSize = 480;
            const qrX = (width - qrSize) / 2;
            const qrY = 180;
            
            if (qrCanvas) {
                ctx.drawImage(qrCanvas, qrX, qrY, qrSize, qrSize);
            } else if (qrImg) {
                ctx.drawImage(qrImg, qrX, qrY, qrSize, qrSize);
            }
            
            // Draw Checkpoint Label
            ctx.fillStyle = '#111827';
            ctx.font = 'bold 54px Monospace';
            ctx.textAlign = 'center';
            ctx.fillText(checkpointLabel, width / 2, height - 150);
            
            // Footer branding
            ctx.fillStyle = '#94a3b8';
            ctx.font = 'bold 20px Arial';
            ctx.fillText("GUARD TOUR SYSTEM", width / 2, height - 80);
            
            // Trigger download
            const link = document.createElement('a');
            link.download = (document.getElementById('printQRModal').dataset.filename || 'QR_Code') + '.png';
            link.href = finalCanvas.toDataURL("image/png");
            link.click();
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function showAlert(message, type = 'info') {
            CustomModal.alert(message, 'Notice', type);
        }

        function closeAlertModal() {
            closeModal('alertModal');
            if (elementToFocusAfterAlert) {
                elementToFocusAfterAlert.focus();
                elementToFocusAfterAlert = null;
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            if (typeof updatePlanLimitDisplay === 'function') {
                updatePlanLimitDisplay();
            }
            if (typeof filterCheckpointSelect === 'function') {
                filterCheckpointSelect();
            }
        });

        window.onclick = function(event) {
            const modals = ['logoutModal', 'printQRModal', 'statusModal', 'alertModal', 'visualDesignerModal'];
            modals.forEach(id => {
                const modal = document.getElementById(id);
                if (event.target == modal) {
                    if (id === 'alertModal') {
                        closeAlertModal();
                    } else if (id === 'visualDesignerModal') {
                        closeVisualDesigner();
                    } else {
                        modal.classList.remove('show');
                    }
                }
            });
        }

        // Visual Designer Logic
        const selectedMappingId = '<?php echo $selected_mapping_id; ?>';
        let visualCheckpoints = [];
        let isVisualLocked = false;
        
        // State for Zoom & Pan
        let visualScale = 1;
        let translateX = 0;
        let translateY = 0;
        let lastPinchDist = 0;

        function applyTransform() {
            const world = document.getElementById('visual-world');
            if (world) {
                world.style.transform = `translate(${translateX}px, ${translateY}px) scale(${visualScale})`;
            }
        }

        function zoomMap(factor, focalX = null, focalY = null) {
            const container = document.getElementById('visual-canvas');
            if (!container) return;

            const oldScale = visualScale;
            visualScale = Math.max(0.05, Math.min(2.5, visualScale * factor));
            
            // If no focal point (buttons), zoom toward container center
            if (focalX === null) focalX = container.offsetWidth / 2;
            if (focalY === null) focalY = container.offsetHeight / 2;

            // Maintain focal point
            translateX = focalX - (focalX - translateX) * (visualScale / oldScale);
            translateY = focalY - (focalY - translateY) * (visualScale / oldScale);

            applyTransform();
        }

        function fitMapToScreen() {
            const world = document.getElementById('visual-world');
            const container = document.getElementById('visual-canvas');
            if (!world || !container) return;

            const circles = world.querySelectorAll('.checkpoint-circle');
            if (circles.length === 0) {
                translateX = 0;
                translateY = 0;
                visualScale = 1;
                applyTransform();
                return;
            }

            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            circles.forEach(c => {
                const x = parseInt(c.style.left);
                const y = parseInt(c.style.top);
                minX = Math.min(minX, x);
                minY = Math.min(minY, y);
                maxX = Math.max(maxX, x + 44);
                maxY = Math.max(maxY, y + 44);
            });

            const padding = 60;
            const contentWidth = maxX - minX + (padding * 2);
            const contentHeight = maxY - minY + (padding * 2);

            const containerWidth = container.offsetWidth;
            const containerHeight = container.offsetHeight;

            visualScale = Math.min(1.5, Math.min(containerWidth / contentWidth, containerHeight / contentHeight));
            translateX = (containerWidth / 2) - ((minX + maxX) / 2) * visualScale;
            translateY = (containerHeight / 2) - ((minY + maxY) / 2) * visualScale;

            applyTransform();
        }

        function closeVisualDesigner() {
            document.getElementById('visualDesignerModal').classList.remove('show');
        }

        async function saveVisualLayout() {
            if (!selectedMappingId) return;
            const confirmed = await CustomModal.confirm("Are you sure you want to save and lock the current layout? Once saved, you will need to ask admin permission to move checkpoint locations again.");
            if (!confirmed) return;

            const formData = new FormData();
            formData.append('ajax_lock_visual', '1');
            formData.append('mapping_id', selectedMappingId);

            try {
                const response = await fetch('agency_patrol_management.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    isVisualLocked = true;
                    const saveBtn = document.getElementById('saveVisualBtn');
                    if (saveBtn) saveBtn.style.display = 'none';
                    showAlert("Visual layout saved and locked successfully.");
                    // Close modal and reload so patrol controls become active
                    document.getElementById('visualDesignerModal').classList.remove('show');
                    window.location.reload();
                }
            } catch (err) {
                console.error(err);
                showAlert("Error locking layout. Please try again.");
            }
        }

        function downloadVisualMap() {
            const canvas = document.getElementById('visual-canvas');
            const btn = event.target;
            const originalText = btn.textContent;
            
            btn.textContent = 'Generating PDF...';
            btn.disabled = true;

            // Snapshot mode: static arrows, no animations
            canvas.classList.add('download-mode');

            html2canvas(canvas, {
                backgroundColor: '#ffffff',
                scale: 3, // Premium quality
                logging: false,
                useCORS: true
            }).then(imageCanvas => {
                const { jsPDF } = window.jspdf;
                // Landscape orientation
                const pdf = new jsPDF('l', 'mm', 'a4');
                const imgData = imageCanvas.toDataURL('image/png');
                
                const imgProps = pdf.getImageProperties(imgData);
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
                
                // Add header/title to PDF
                const siteName = document.getElementById('agency_client_id').options[document.getElementById('agency_client_id').selectedIndex].text;
                pdf.setFontSize(18);
                pdf.setTextColor(17, 24, 39);
                pdf.text(`Patrol Map Overview: ${siteName}`, 15, 15);
                pdf.setFontSize(10);
                pdf.setTextColor(107, 114, 128);
                pdf.text(`Generated on: ${new Date().toLocaleString()}`, 15, 22);

                pdf.addImage(imgData, 'PNG', 15, 30, pdfWidth - 30, pdfHeight - 10);
                pdf.save(`Patrol_Map_${siteName.replace(/\s+/g, '_')}_${new Date().getTime()}.pdf`);
                
                canvas.classList.remove('download-mode');
                btn.textContent = originalText;
                btn.disabled = false;
            }).catch(err => {
                console.error('PDF Generation failed:', err);
                canvas.classList.remove('download-mode');
                btn.textContent = originalText;
                btn.disabled = false;
                showAlert('Failed to generate PDF. Please try again.', 'error');
            });
        }

        function drawArrows() {
            const svg = document.getElementById('visual-svg');
            const canvas = document.getElementById('visual-canvas');
            
            // Clear existing elements
            svg.querySelectorAll('.visual-path, .flowing-arrow, .static-arrow').forEach(el => el.remove());

            if (visualCheckpoints.length < 2) return;

            for (let i = 0; i < visualCheckpoints.length - 1; i++) {
                const startCp = visualCheckpoints[i];
                const endCp = visualCheckpoints[i+1];
                
                const startEl = document.getElementById(`cp-circle-${startCp.id}`);
                const endEl = document.getElementById(`cp-circle-${endCp.id}`);
                
                if (!startEl || !endEl) continue;

                const r = 22; // circle radius (44px diameter / 2)
                const x1 = parseInt(startEl.style.left) + r;
                const y1 = parseInt(startEl.style.top) + r;
                const x2 = parseInt(endEl.style.left) + r;
                const y2 = parseInt(endEl.style.top) + r;

                // Calculate edge points
                const angle = Math.atan2(y2 - y1, x2 - x1);
                const edgeX1 = x1 + r * Math.cos(angle);
                const edgeY1 = y1 + r * Math.sin(angle);
                const edgeX2 = x2 - (r + 5) * Math.cos(angle);
                const edgeY2 = y2 - (r + 5) * Math.sin(angle);

                const d = `M ${edgeX1} ${edgeY1} L ${edgeX2} ${edgeY2}`;

                // Create static path (Neutral style for Management)
                const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
                path.setAttribute("d", d);
                path.setAttribute("class", "visual-path");
                svg.appendChild(path);

                // Create midpoint static arrow (for PNG download)
                const midX = (edgeX1 + edgeX2) / 2;
                const midY = (edgeY1 + edgeY2) / 2;
                const deg = angle * (180 / Math.PI);
                
                const sArrow = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
                sArrow.setAttribute("points", "-6,-6 6,0 -6,6");
                sArrow.setAttribute("class", "static-arrow");
                sArrow.setAttribute("transform", `translate(${midX}, ${midY}) rotate(${deg})`);
                svg.appendChild(sArrow);
            }
        }

        function openVisualDesigner() {
            if (!selectedMappingId) {
                showAlert("Please select a Client Site first to view the visual map.");
                return;
            }

            const modal = document.getElementById('visualDesignerModal');
            const canvas = document.getElementById('visual-canvas');
            const world = document.getElementById('visual-world');
            
            modal.classList.add('show');
            world.querySelectorAll('.checkpoint-circle').forEach(c => c.remove());
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'visual-loading';
            loadingDiv.style.cssText = 'position:absolute; top:0; left:0; width:100%; display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; z-index: 100;';
            loadingDiv.textContent = 'Loading checkpoints...';
            canvas.appendChild(loadingDiv);

            // Mouse Zoom Support
            canvas.onwheel = (e) => {
                e.preventDefault();
                const factor = e.deltaY > 0 ? 0.9 : 1.1;
                const rect = canvas.getBoundingClientRect();
                zoomMap(factor, e.clientX - rect.left, e.clientY - rect.top);
            };

            // Background Panning
            let isPanning = false;
            let panStartX, panStartY;

            canvas.onmousedown = (e) => {
                if (e.target.id === 'visual-world' || e.target.id === 'visual-svg' || e.target.id === 'visual-canvas') {
                    isPanning = true;
                    panStartX = e.clientX - translateX;
                    panStartY = e.clientY - translateY;
                    canvas.style.cursor = 'grabbing';
                }
            };

            const stopAllGestures = () => {
                isPanning = false;
                lastPinchDist = 0;
                canvas.style.cursor = '';
            };

            document.addEventListener('mousemove', (e) => {
                if (isPanning) {
                    translateX = e.clientX - panStartX;
                    translateY = e.clientY - panStartY;
                    applyTransform();
                }
            });
            document.addEventListener('mouseup', stopAllGestures);

            // Touch Support (Pinch & Pan)
            canvas.ontouchstart = (e) => {
                if (e.touches.length === 1) {
                    if (e.target.id === 'visual-world' || e.target.id === 'visual-svg' || e.target.id === 'visual-canvas') {
                        isPanning = true;
                        panStartX = e.touches[0].clientX - translateX;
                        panStartY = e.touches[0].clientY - translateY;
                    }
                } else if (e.touches.length === 2) {
                    isPanning = false;
                    lastPinchDist = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                }
            };

            canvas.ontouchmove = (e) => {
                if (e.touches.length === 1 && isPanning) {
                    translateX = e.touches[0].clientX - panStartX;
                    translateY = e.touches[0].clientY - panStartY;
                    applyTransform();
                } else if (e.touches.length === 2) {
                    const dist = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    if (lastPinchDist > 0) {
                        const factor = dist / lastPinchDist;
                        const rect = canvas.getBoundingClientRect();
                        const focalX = (e.touches[0].clientX + e.touches[1].clientX) / 2 - rect.left;
                        const focalY = (e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top;
                        zoomMap(factor, focalX, focalY);
                    }
                    lastPinchDist = dist;
                    e.preventDefault();
                }
            };
            canvas.ontouchend = stopAllGestures;

            fetch(`agency_patrol_management.php?ajax_checkpoints=1&mapping_id=${selectedMappingId}`)
                .then(response => response.json())
                .then(data => {
                    const loader = document.getElementById('visual-loading');
                    if (loader) loader.remove();
                    
                    visualCheckpoints = data.checkpoints || [];
                    isVisualLocked = !!data.is_visual_locked;

                    const saveBtn = document.getElementById('saveVisualBtn');
                    if (saveBtn) {
                        saveBtn.style.display = isVisualLocked ? 'none' : 'block';
                    }

                    if (visualCheckpoints.length === 0) {
                        const emptyDiv = document.createElement('div');
                        emptyDiv.id = 'visual-empty';
                        emptyDiv.style.cssText = 'display:flex; align-items:center; justify-content:center; height:100%; color:#64748b;';
                        emptyDiv.textContent = 'No checkpoints configured for this site.';
                        canvas.appendChild(emptyDiv);
                        return;
                    }

                    visualCheckpoints.forEach((cp, index) => {
                        const circle = document.createElement('div');
                        circle.id = `cp-circle-${cp.id}`;
                        
                        // Default Theme Coloring (Start: Green, End: Orange, Regular: White)
                        circle.className = `checkpoint-circle ${cp.isStart ? 'start' : (cp.isEnd ? 'end' : 'regular')}`;
                        circle.style.zIndex = (cp.isStart || cp.isEnd) ? 10 : 5;
                        const nameTrim = (cp.name || '').trim();
                        const isNumeric = /^\d+$/.test(nameTrim);
                        let innerText = isNumeric ? nameTrim : (nameTrim ? nameTrim.charAt(0).toUpperCase() : '');
                        
                        circle.innerHTML = `
                            <span>${innerText}</span>
                            ${isNumeric ? '' : `<div class="label">${cp.name}</div>`}
                        `;
                        
                        let x = parseInt(cp.visual_pos_x) || 0;
                        let y = parseInt(cp.visual_pos_y) || 0;
                        
                        if (x === 0 && y === 0) {
                            const containerWidth = canvas.offsetWidth || 750;
                            const spacingX = 120; // Increased spacing for arrows
                            const spacingY = 120;
                            const itemsPerRow = Math.floor((containerWidth - 60) / spacingX) || 5;
                            x = 40 + (index % itemsPerRow) * spacingX;
                            y = 40 + Math.floor(index / itemsPerRow) * spacingY;
                        }
                        
                        circle.style.left = x + 'px';
                        circle.style.top = y + 'px';
                        
                        const startDrag = (e) => {
                            if (isVisualLocked) {
                                showAlert("The map layout is already saved and locked. Please ask admin for permission to move the locations.");
                                return;
                            }
                            const isTouch = e.type === 'touchstart';
                            const clientX = isTouch ? e.touches[0].clientX : e.clientX;
                            const clientY = isTouch ? e.touches[0].clientY : e.clientY;
                            
                            let shiftX = clientX - circle.getBoundingClientRect().left;
                            let shiftY = clientY - circle.getBoundingClientRect().top;
                            circle.style.zIndex = 1000;
                            
                            function moveAt(pageX, pageY) {
                                const canvasRect = canvas.getBoundingClientRect();
                                const worldRect = world.getBoundingClientRect();

                                // Calculate current viewport bounds in World Units
                                // account for current translateX/translateY
                                const visMinX = (canvasRect.left - worldRect.left) / visualScale;
                                const visMaxX = (canvasRect.right - worldRect.left) / visualScale;
                                const visMinY = (canvasRect.top - worldRect.top) / visualScale;
                                const visMaxY = (canvasRect.bottom - worldRect.top) / visualScale;

                                let newX = (pageX - worldRect.left) / visualScale - shiftX;
                                let newY = (pageY - worldRect.top) / visualScale - shiftY;
                                
                                // Clamp precisely to visible viewport edges
                                newX = Math.max(visMinX, Math.min(newX, visMaxX - 44));
                                newY = Math.max(visMinY, Math.min(newY, visMaxY - 44));
                                
                                circle.style.left = newX + 'px';
                                circle.style.top = newY + 'px';
                                drawArrows();
                            }
                            
                            function onMove(moveEvent) {
                                if (isTouch) moveEvent.preventDefault(); // Prevent scrolling
                                const moveX = isTouch ? moveEvent.touches[0].pageX : moveEvent.pageX;
                                const moveY = isTouch ? moveEvent.touches[0].pageY : moveEvent.pageY;
                                moveAt(moveX, moveY);
                            }

                            const stopDrag = () => {
                                document.removeEventListener(isTouch ? 'touchmove' : 'mousemove', onMove);
                                document.removeEventListener(isTouch ? 'touchend' : 'mouseup', stopDrag);
                                circle.style.zIndex = cp.isStart ? 10 : 5;
                                drawArrows();
                                
                                const finalX = parseInt(circle.style.left);
                                const finalY = parseInt(circle.style.top);
                                
                                const formData = new FormData();
                                formData.append('ajax_save_position', '1');
                                formData.append('cp_id', cp.id);
                                formData.append('x', finalX);
                                formData.append('y', finalY);
                                
                                fetch('agency_patrol_management.php', {
                                    method: 'POST',
                                    body: formData
                                }).catch(err => console.error(err));
                            };

                            document.addEventListener(isTouch ? 'touchmove' : 'mousemove', onMove, { passive: false });
                            document.addEventListener(isTouch ? 'touchend' : 'mouseup', stopDrag);
                        };

                        circle.onmousedown = startDrag;
                        circle.ontouchstart = startDrag;
                        
                        circle.ondragstart = function() { return false; };
                        world.appendChild(circle);
                    });

                    drawArrows();
                    setTimeout(fitMapToScreen, 50); // Slight delay for rendering
                })
                .catch(err => {
                    const loader = document.getElementById('visual-loading');
                    if (loader) loader.remove();
                    const errDiv = document.createElement('div');
                    errDiv.style.cssText = 'display:flex; align-items:center; justify-content:center; height:100%; color:#ef4444;';
                    errDiv.textContent = 'Error loading checkpoints.';
                    canvas.appendChild(errDiv);
                    console.error(err);
                });
        }
    </script>
    <?php include_once 'includes/common_modals.php'; ?>
    <!-- VERSION: 2.1 -->
</body>
</html>
