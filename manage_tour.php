<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'] ?? null;

// Ensure columns exist
$conn->query("ALTER TABLE tour_assignments ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT 0");
$conn->query("ALTER TABLE tour_assignments ADD COLUMN IF NOT EXISTS shift_name VARCHAR(50)");
$conn->query("ALTER TABLE checkpoints ADD COLUMN IF NOT EXISTS is_end_checkpoint TINYINT(1) DEFAULT 0");

// Fetch mapping info for this client
$maps_sql = "SELECT id, qr_limit, qr_override, is_patrol_locked, site_name, is_disabled FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$all_mappings = [];
$mapping_ids = [];
$mapping_status = [];

if ($maps_res && $maps_res->num_rows > 0) {
    while ($r = $maps_res->fetch_assoc()) {
        $all_mappings[] = $r;
        $mapping_ids[] = (int)$r['id'];
        $mapping_status[(int)$r['id']] = (int)$r['is_disabled'];
    }
}

$no_mappings = empty($all_mappings);
$mapping_id = null;
$qr_limit = 0;
$qr_override = 0;
$is_patrol_locked = 0;
$selected_site_name = "No Site Selected";

if (!$no_mappings) {
    // Determine which mapping is active
    $requested_m_id = isset($_GET['mapping_id']) ? (int)$_GET['mapping_id'] : (isset($_POST['agency_client_id']) ? (int)$_POST['agency_client_id'] : 0);
    
    $current_mapping = null;
    if ($requested_m_id > 0) {
        foreach ($all_mappings as $m) {
            if ((int)$m['id'] === $requested_m_id) {
                $current_mapping = $m;
                break;
            }
        }
    }
    
    // Fallback to first mapping if none selected or invalid
    if (!$current_mapping) {
        $current_mapping = $all_mappings[0];
    }
    
    $mapping_id = $current_mapping['id'];
    $qr_limit = (int)($current_mapping['qr_limit'] ?? 0);
    $qr_override = (int)($current_mapping['qr_override'] ?? 0);
    $is_patrol_locked = (int)($current_mapping['is_patrol_locked'] ?? 0);
    $selected_site_name = $current_mapping['site_name'] ?: "Site #" . $mapping_id;
}

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
}
else {
    $mapping_ids_str = implode(',', $mapping_ids);
}

// Ensure every mapping has a starting point (is_zero_checkpoint = 1)
if (!empty($mapping_ids)) {
    foreach ($mapping_ids as $m_id) {
        // Starting Point
        $start_check = "SELECT id FROM checkpoints WHERE agency_client_id = $m_id AND is_zero_checkpoint = 1 LIMIT 1";
        if ($conn->query($start_check)->num_rows == 0) {
            $start_code = "START-" . $m_id . "-" . strtoupper(bin2hex(random_bytes(3)));
            $conn->query("INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, qr_code_data, is_zero_checkpoint) VALUES ($m_id, 'Starting Point', '$start_code', '$start_code', 1)");
        }
    }
}

$message = '';
$message_type = '';
$show_status_modal = false;
$show_limit_modal = false;

// AJAX Handler for fetching checkpoint sequence (Client version)
if (isset($_GET['ajax_checkpoints']) && isset($_GET['mapping_id'])) {
    $m_id = (int)$_GET['mapping_id'];

    // Security: Check if mapping_id belongs to this client
    $verify_sql = "SELECT id FROM agency_clients WHERE id = $m_id AND client_id = $client_id";
    $verify_res = $conn->query($verify_sql);

    if ($verify_res && $verify_res->num_rows > 0) {
        $checkpoints = [];

        // 1. Fetch Starting Point with latest status
        $start_res = $conn->query("
            SELECT cp.id, cp.name, cp.visual_pos_x, cp.visual_pos_y,
            (SELECT status FROM scans WHERE checkpoint_id = cp.id ORDER BY scan_time DESC LIMIT 1) as latest_status
            FROM checkpoints cp 
            WHERE cp.agency_client_id = $m_id AND cp.is_zero_checkpoint = 1 
            LIMIT 1
        ");
        $starting_point_visual = null;
        if ($start_res && $start_res->num_rows > 0) {
            $starting_point_visual = $start_res->fetch_assoc();
            $starting_point_visual['isStart'] = true;
        }

        $assign_res = $conn->query("
            SELECT cp.id, cp.name, cp.visual_pos_x, cp.visual_pos_y, cp.is_zero_checkpoint, cp.is_end_checkpoint,
            (SELECT status FROM scans WHERE checkpoint_id = cp.id ORDER BY scan_time DESC LIMIT 1) as latest_status
            FROM tour_assignments ta 
            JOIN checkpoints cp ON ta.checkpoint_id = cp.id 
            WHERE ta.agency_client_id = $m_id 
            ORDER BY ta.sort_order ASC
        ");
        if ($starting_point_visual) {
            $checkpoints[] = $starting_point_visual;
        }

        while ($row = $assign_res->fetch_assoc()) {
            if ($starting_point_visual && $row['id'] == $starting_point_visual['id'])
                continue;

            $row['isStart'] = (bool)($row['is_zero_checkpoint'] ?? false);
            $row['isEnd'] = (bool)($row['is_end_checkpoint'] ?? false);
            $checkpoints[] = $row;
        }

        header('Content-Type: application/json');
        echo json_encode($checkpoints);
    }
    else {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'Unauthorized']);
    }
    exit();
}

// AJAX Handler for saving checkpoint position (Client version)
if (isset($_POST['ajax_save_position']) && isset($_POST['cp_id'])) {
    $cp_id = (int)$_POST['cp_id'];
    $x = (int)($_POST['x'] ?? 0);
    $y = (int)($_POST['y'] ?? 0);

    // Security: Verify checkpoint belongs to this client
    $stmt = $conn->prepare("
        UPDATE checkpoints cp
        JOIN agency_clients ac ON cp.agency_client_id = ac.id
        SET cp.visual_pos_x = ?, cp.visual_pos_y = ?
        WHERE cp.id = ? AND ac.client_id = ?
    ");
    $stmt->bind_param("iiii", $x, $y, $cp_id, $client_id);
    $stmt->execute();

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}

// Handle Save
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_tour'])) {
    if ($is_patrol_locked) {
        $message = "Error: Patrol configuration is locked by your agency and cannot be modified.";
        $message_type = "error";
        $show_status_modal = true;
    }
    else {
        $checkpoint_ids = $_POST['checkpoint_ids'] ?? [];
        $intervals = $_POST['intervals'] ?? [];
        $assignment_shifts = $_POST['assignment_shifts'] ?? [];
        // Durations are automated to 1 min for non-zero points, except for points already in sequence which might have 0
        $durations = $_POST['durations'] ?? [];
        // Validation: Count checkpoints excluding the starting point
        $excluded_res = $conn->query("SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1");
        $excluded_ids = [];
        while ($row = $excluded_res->fetch_assoc())
            $excluded_ids[] = $row['id'];

        $count = 0;
        foreach ($checkpoint_ids as $id)
            if (!in_array($id, $excluded_ids))
                $count++;

        // Rebuild arrays to ensure Starting Point is first and Ending Point is last
        $final_ids = [];
        $final_intervals = [];
        $final_shifts = [];
        $final_durations = [];

        // 1. Identification of special points
        $start_point_id = 0;
        $end_point_id = 0;

        $start_res = $conn->query("SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
        if ($start_res && $start_res->num_rows > 0) $start_point_id = (int)$start_res->fetch_assoc()['id'];

        $end_res = $conn->query("SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id AND is_end_checkpoint = 1 LIMIT 1");
        if ($end_res && $end_res->num_rows > 0) $end_point_id = (int)$end_res->fetch_assoc()['id'];

        // 2. Prepare lookup for submitted values
        $submitted_data = [];
        for ($i = 0; $i < count($checkpoint_ids); $i++) {
            $submitted_data[(int)$checkpoint_ids[$i]] = [
                'interval' => (int)($intervals[$i] ?? 0),
                'shift' => $assignment_shifts[$i] ?? '',
                'duration' => (int)($durations[$i] ?? 0)
            ];
        }

        // 3. Construct the FINAL sequence
        // Always Start Point FIRST
        if ($start_point_id > 0 && isset($submitted_data[$start_point_id])) {
            $final_ids[] = $start_point_id;
            $final_intervals[] = $submitted_data[$start_point_id]['interval'];
            $final_shifts[] = $submitted_data[$start_point_id]['shift'];
            $final_durations[] = $submitted_data[$start_point_id]['duration'];
        }

        // Add middle points (not start, not end)
        for ($i = 0; $i < count($checkpoint_ids); $i++) {
            $cp_id = (int)$checkpoint_ids[$i];
            if ($cp_id !== $start_point_id && $cp_id !== $end_point_id) {
                $final_ids[] = $cp_id;
                $final_intervals[] = (int)($intervals[$i] ?? 0);
                $final_shifts[] = $conn->real_escape_string($assignment_shifts[$i] ?? '');
                $final_durations[] = (int)($durations[$i] ?? 1); // Default duration 1 for regular points
            }
        }

        // Always End Point LAST
        if ($end_point_id > 0 && isset($submitted_data[$end_point_id])) {
            $final_ids[] = $end_point_id;
            $final_intervals[] = $submitted_data[$end_point_id]['interval'];
            $final_shifts[] = $submitted_data[$end_point_id]['shift'];
            $final_durations[] = $submitted_data[$end_point_id]['duration'];
        }

        if ($count > $qr_limit && !$qr_override) {
            $message = "Error: You cannot exceed the limit of $qr_limit checkpoints.";
            $message_type = "error";
            $show_status_modal = true;
        }
        else {
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mapping_id");
                for ($i = 0; $i < count($final_ids); $i++) {
                    $cp_id = (int)$final_ids[$i];
                    $interval = (int)($final_intervals[$i] ?? 0);
                    $duration = (int)($final_durations[$i] ?? 0);
                    $shift = $final_shifts[$i] ?? '';
                    $order = $i + 1;
                    $stmt = $conn->prepare("INSERT INTO tour_assignments (agency_client_id, checkpoint_id, sort_order, interval_minutes, duration_minutes, shift_name) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiiis", $mapping_id, $cp_id, $order, $interval, $duration, $shift);
                    $stmt->execute();
                }
                $conn->commit();
                $message = "Tour sequence saved successfully!";
                $message_type = "success";
                $show_status_modal = true;
            }
            catch (Exception $e) {
                $conn->rollback();
                $message = "Error saving tour: " . $e->getMessage();
                $message_type = "error";
                $show_status_modal = true;
            }
        }
    }
}

// Handle creating QR Checkpoint
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_qr'])) {
    $mapping_id_qr = (int)$_POST['agency_client_id'];
    $qr_name = $conn->real_escape_string($_POST['qr_name']);
    // Auto-generate a unique checkpoint code
    $code_unique = false;
    $checkpoint_code = "";
    while (!$code_unique) {
        $checkpoint_code = "CP-" . strtoupper(bin2hex(random_bytes(4)));
        $check = $conn->query("SELECT id FROM checkpoints WHERE checkpoint_code = '$checkpoint_code'");
        if ($check && $check->num_rows == 0) {
            $code_unique = true;
        }
    }

    $is_zero_checkpoint = 0; // Manually created checkpoints are never zero
    $scan_interval = (int)($_POST['scan_interval'] ?? 0);

    // Verify this mapping belongs to the client and check limits
    $verify_sql = "SELECT id, qr_limit, qr_override, is_disabled FROM agency_clients WHERE id = $mapping_id_qr AND client_id = $client_id";
    $verify_res = $conn->query($verify_sql);

    if ($verify_res && $verify_res->num_rows > 0) {
        $mapping_qr = $verify_res->fetch_assoc();
        if ($mapping_qr['is_disabled']) {
            $message = "QR creation is currently disabled for this site.";
            $message_type = "error";
            $show_status_modal = true;
        }
        else {
            // Check Per-Site QR Limit (excludes starting point)
            $limit_check = $conn->query("
                SELECT ac.qr_limit, 
                (SELECT COUNT(*) FROM checkpoints cp 
                 WHERE cp.agency_client_id = ac.id AND cp.is_zero_checkpoint = 0) as total_site_qrs
                FROM agency_clients ac
                WHERE ac.id = $mapping_id_qr
            ");

            if ($limit_check && $limit_check->num_rows > 0) {
                $limit_data = $limit_check->fetch_assoc();
                if ($limit_data['qr_limit'] > 0 && $limit_data['total_site_qrs'] >= $limit_data['qr_limit'] && !$mapping_qr['qr_override']) {
                    $message = "QR limit reached for this site ($limit_data[qr_limit] QRs max). Please contact the agency administrator.";
                    $message_type = "error";
                    $show_limit_modal = true;
                }
                else {
                    $insert_sql = "INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, qr_code_data, is_zero_checkpoint, scan_interval) VALUES ($mapping_id_qr, '$qr_name', '$checkpoint_code', '$checkpoint_code', $is_zero_checkpoint, $scan_interval)";
                    if ($conn->query($insert_sql)) {
                        $message = "Checkpoint '$qr_name' created successfully!";
                        $message_type = "success";
                        $show_status_modal = true;
                    }
                    else {
                        $message = "Database error: " . $conn->error;
                        $message_type = "error";
                        $show_status_modal = true;
                    }
                }
            }
        }
    }
}

// Handle Deleting QR Code
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_checkpoint'])) {
    $cp_id = (int)$_POST['checkpoint_id'];

    // Verify ownership
    $verify_own = $conn->query("
        SELECT cp.id, cp.is_zero_checkpoint FROM checkpoints cp
        JOIN agency_clients ac ON cp.agency_client_id = ac.id
        WHERE cp.id = $cp_id AND ac.client_id = $client_id
    ");

    if ($verify_own && $verify_own->num_rows > 0) {
        $cp_data = $verify_own->fetch_assoc();
        if ($cp_data['is_zero_checkpoint']) {
            $message = "Error: The Starting Point checkpoint cannot be deleted.";
            $message_type = "error";
            $show_status_modal = true;
        }
        else if ($conn->query("DELETE FROM checkpoints WHERE id = $cp_id")) {
            // Delete associated tour assignments first
            $conn->query("DELETE FROM tour_assignments WHERE checkpoint_id = $cp_id");
            $message = "Checkpoint deleted successfully!";
            $message_type = "success";
            $show_status_modal = true;
        }
        else {
            $message = "Delete error: " . $conn->error;
            $message_type = "error";
            $show_status_modal = true;
        }
    }
}

// Fetch limit data for the form
$limits_sql = "
    SELECT 
        ac.id as mapping_id, 
        u.username as agency_name,
        ac.site_name,
        ac.qr_limit, 
        ac.qr_override, 
        ac.is_disabled,
        (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id AND is_zero_checkpoint = 0) as current_site_qrs,
        (SELECT COUNT(*) FROM checkpoints cp2 JOIN agency_clients ac2 ON cp2.agency_client_id = ac2.id WHERE ac2.agency_id = u.id AND cp2.is_zero_checkpoint = 0) as agency_total_qrs
    FROM agency_clients ac
    JOIN users u ON ac.agency_id = u.id
    WHERE ac.client_id = $client_id
";
$limits_res = $conn->query($limits_sql);
$limits_data = [];
if ($limits_res)
    while ($r = $limits_res->fetch_assoc())
        $limits_data[] = $r;

// Fetch checkpoints for the directory table
$qrs_sql = "
    SELECT 
        c.id,
        c.name as checkpoint_name,
        c.checkpoint_code,
        c.agency_client_id,
        c.is_zero_checkpoint,
        c.scan_interval,
        ac.company_name,
        MAX(s.scan_time) as last_scanned
    FROM checkpoints c
    JOIN agency_clients ac ON c.agency_client_id = ac.id
    LEFT JOIN scans s ON c.id = s.checkpoint_id
    WHERE c.agency_client_id IN ($mapping_ids_str)
    GROUP BY c.id
    ORDER BY c.is_zero_checkpoint DESC, c.id ASC
";
$qrs_result = $conn->query($qrs_sql);

// Helper for session alerts in manage_tour if needed would go here if not using AJAX?
// But manage_tour uses it during POST processing.
?>
// Fetch available checkpoints for Tour Setup tab (exclude zero and end)
$available_checkpoints = [];
if ($mapping_id) {
    $checkpoints_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 0 AND is_end_checkpoint = 0 ORDER BY id ASC");
    while ($row = $checkpoints_res->fetch_assoc()) {
        $available_checkpoints[] = $row;
    }
}

$starting_point = null;
if ($mapping_id) {
    $start_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
    if ($start_res && $start_res->num_rows > 0) {
        $starting_point = $start_res->fetch_assoc();
    }
}

$ending_point = null;
if ($mapping_id) {
    $end_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_end_checkpoint = 1 LIMIT 1");
    if ($end_res && $end_res->num_rows > 0) {
        $ending_point = $end_res->fetch_assoc();
    }
}

// Fetch current assignments for Tour Setup Tab
$current_assignments = [];
if ($mapping_id) {
    $assignments_res = $conn->query("
        SELECT ta.checkpoint_id, ta.interval_minutes, ta.duration_minutes, ta.shift_name, cp.name, cp.is_zero_checkpoint, cp.is_end_checkpoint
        FROM tour_assignments ta 
        JOIN checkpoints cp ON ta.checkpoint_id = cp.id 
        WHERE ta.agency_client_id = $mapping_id 
        ORDER BY ta.sort_order ASC
    ");
    while ($row = $assignments_res->fetch_assoc()) {
        $current_assignments[] = $row;
    }
}

// Fetch site shifts
$client_shifts = [];
if ($mapping_id) {
    $shift_res = $conn->query("SELECT shift_name FROM shifts WHERE agency_client_id = $mapping_id ORDER BY id ASC");
    if ($shift_res) {
        while ($s_row = $shift_res->fetch_assoc()) {
            $client_shifts[] = $s_row['shift_name'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <title>My Tours & Checkpoints - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script src="js/modal_system.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 0 16px 0 0; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0; overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }



        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px; }
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }

        /* Form Controls */
        .form-group { margin-bottom: 16px; text-align: left; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #3b82f6; }
        .btn { padding: 10px 18px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; width: 100%; font-size: 1rem; }
        .btn:hover { background-color: #2563eb; }
        .btn:disabled { background-color: #9ca3af; cursor: not-allowed; }

        .btn-success { background: #10b981; color: white; padding: 14px; font-weight: 700; border-radius: 8px;}
        .btn-success:hover { background: #059669; }

        /* Tour Sequence Setup Specific */
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
        .tour-item.sortable-ghost { opacity: 0.4; }
        .handle { cursor: grab; color: #94a3b8; font-size: 1.2rem; display: flex; align-items: center; }
        .checkpoint-name { flex: 1; font-weight: 600; color: #1e293b; }
        
        .setting-inputs { display: flex; gap: 12px; align-items: center; }
        .input-group { display: flex; align-items: center; gap: 6px; background: #fff; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; }
        .input-group input { width: 55px; border: none; text-align: center; font-weight: 600; outline: none; }
        .input-group label { font-size: 0.75rem; color: #64748b; font-weight: 500; }

        .remove-btn { color: #ef4444; background: none; border: none; cursor: pointer; padding: 5px; border-radius: 5px; transition: background 0.2s; display: flex; align-items: center; justify-content: center;}
        .remove-btn:hover { background: #fee2e2; }

        .add-area { margin-top: 20px; display: flex; gap: 12px; }
        
        .limit-info { font-size: 0.875rem; color: #6b7280; font-weight: 500; }
        .limit-count { font-weight: 700; color: #111827; }
        .limit-count.danger { color: #ef4444; }

        /* Checkpoint Directory Specific */

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        
        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .form-card { background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .status-active { color: #10b981; font-weight: 600; }
        .status-inactive { color: #ef4444; font-weight: 600; }

        .empty-state { text-align: center; padding: 40px; color: #6b7280; font-style: italic; }

        .alert-warning { background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 12px 16px; margin-top: 12px; border-radius: 6px;}

        /* Modal Styles */
        .modal-overlay { 
            display: none; 
            position: fixed; 
            top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(17, 24, 39, 0.7); 
            z-index: 2000; 
            backdrop-filter: blur(4px); 
            overflow-y: auto;
            padding: 20px;
        }
        .modal-overlay.show { 
            display: flex; 
            align-items: flex-start; 
            justify-content: center; 
        }
        .modal-content { 
            background: white; 
            padding: 32px; 
            border-radius: 12px; 
            width: 100%; 
            max-width: 450px; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); 
            position: relative;
            margin: auto;
            animation: modalFadeIn 0.3s ease-out forwards;
        }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: 24px;
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
            padding: 4px;
            border-radius: 4px;
        }
        .modal-close:hover { color: #111827; background: #f3f4f6; }
        
        .qr-display { padding: 20px; text-align: center; display: flex; flex-direction: column; align-items: center;}
        .qr-img { margin: 0 auto; display: block; }
        .code-display { font-family: monospace; font-size: 1.5rem; font-weight: bold; margin-top: 10px; text-align: center;}

        @media print {
            .sidebar, .topbar, .tabs, .card:not(.print-card), .alert, .btn:not(.no-print) { display: none !important; }
            body { background: white; }
            .content-area { padding: 0; }
            .print-card { box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100% !important;}
        }

        /* Visual Designer Styles */
        .btn-visual { 
            padding: 6px 14px; 
            background: #4f46e5; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(79, 70, 229, 0.2);
        }
        .btn-visual:hover { background: #4338ca; transform: translateY(-1px); }
        
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
            margin-top: 10px;
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
            transform: rotate(-45deg);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 5;
        }
        .checkpoint-circle:hover {
            transform: rotate(-45deg) scale(1.1);
            z-index: 100 !important;
        }
        .checkpoint-circle > span {
            transform: rotate(45deg);
            display: block;
        }
        .checkpoint-circle.start {
            background: linear-gradient(135deg, #2563eb, #111827);
            color: white;
            z-index: 10;
        }
        .checkpoint-circle.regular {
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            color: #111827;
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
            transform: rotate(45deg);
            transform-origin: center;
            border: 1px solid #e2e8f0;
        }

        /* Static Status Colors & Animations */
        .cp-status-on-time { 
            background: linear-gradient(135deg, #10b981, #059669) !important; 
            color: white !important; 
            animation: pulse-on-time 2s infinite;
        }
        .cp-status-late { 
            background: linear-gradient(135deg, #ef4444, #dc2626) !important; 
            color: white !important; 
            animation: pulse-late 2s infinite;
        }
        .cp-status-none { 
            background: linear-gradient(135deg, #94a3b8, #64748b) !important; 
            color: white !important; 
        }

        @keyframes pulse-on-time {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        @keyframes pulse-late {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .visual-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .visual-path {
            stroke: #6366f1;
            stroke-width: 3;
            stroke-linecap: round;
            fill: none;
            stroke-dasharray: 8 4;
            filter: drop-shadow(0 0 2px rgba(99, 102, 241, 0.5));
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

        .btn-visual-info { font-size: 0.85rem; color: #64748b; margin-bottom: 15px; text-align: left; background: #f1f5f9; padding: 10px; border-radius: 8px; border-left: 4px solid #3b82f6; }

        .static-arrow {
            display: none;
            fill: #6366f1;
        }

        @keyframes flow-arrow {
            from { offset-distance: 0%; }
            to { offset-distance: 100%; }
        }
        
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .btn-confirm:hover { background: #be123c; }

        /* Logout Specific Modal Icons */
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; margin-top: 20px; }

        .modal-content.large { max-width: 900px; }
        #visual-canvas { cursor: crosshair; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link active">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 20px;">
                <h2>Tours & Checkpoints</h2>
                
                <?php if (count($all_mappings) > 1): ?>
                    <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 4px 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <span style="font-size: 0.8rem; font-weight: 600; color: #64748b;">Active Site:</span>
                        <select onchange="location.href='manage_tour.php?mapping_id=' + this.value" style="border: none; background: transparent; font-weight: 700; color: #1e293b; cursor: pointer; outline: none; font-size: 0.9rem;">
                            <?php foreach ($all_mappings as $m): ?>
                                <option value="<?php echo $m['id']; ?>" <?php echo $mapping_id == $m['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['site_name'] ?: 'Site #' . $m['id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif (!$no_mappings): ?>
                    <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;"><?php echo htmlspecialchars($selected_site_name); ?></span>
                <?php endif; ?>
            </div>

            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
            <?php if ($is_patrol_locked): ?>
                <div style="background: #fff7ed; color: #9a3412; padding: 10px 20px; border-radius: 8px; border: 1px solid #fed7aa; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;">
                    <span>🔒 Patrol sequence is managed and locked by your agency.</span>
                </div>
            <?php endif; ?>
        </header>

        <div class="content-area">
            <?php if ($no_mappings): ?>
                <div class="card" style="text-align: center; padding: 60px 20px;">
                    <div style="width: 80px; height: 80px; background: #feedaf; color: #854d0e; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 2rem;">!</div>
                    <h2 style="margin-bottom: 12px; color: #111827;">No Agency-Client Mapping Found</h2>
                    <p style="color: #6b7280; max-width: 500px; margin: 0 auto 32px; line-height: 1.6;">
                        You are not currently assigned to any security agency sites. 
                        Please contact your administrator to assign your account to a location so you can manage tours and checkpoints.
                    </p>
                    <a href="client_dashboard.php" class="btn" style="width: auto; display: inline-block; padding: 12px 32px; background: #111827;">Return to Dashboard</a>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header">
                        <span>Manage Tour Pattern</span>
                        <div class="limit-info">
                            QR Limit: <span id="current-count" class="limit-count">0</span> / <span class="limit-count"><?php echo $qr_limit; ?></span>
                        </div>
                    </div>

                    <form id="tour-form" method="POST" action="manage_tour.php">
                        <div class="tour-list-container">
                            <?php if ($starting_point):
    $start_shift = '';
    $start_interval = 0;
    foreach ($current_assignments as $as) {
        if ($as['checkpoint_id'] == $starting_point['id']) {
            $start_shift = $as['shift_name'];
            $start_interval = $as['interval_minutes'];
            break;
        }
    }
?>
                                <div class="tour-list" style="margin-bottom: 0px; border-bottom: none; border-bottom-left-radius: 0; border-bottom-right-radius: 0; padding-bottom: 0;">
                                    <div class="tour-item" style="cursor: default; background: #e0f2fe; border-color: #bae6fd; margin-bottom: 0;">
                                        <span class="handle" style="visibility: hidden; cursor: default;">☰</span>
                                        <span class="checkpoint-name"><?php echo htmlspecialchars($starting_point['name']); ?></span>
                                        <input type="hidden" name="checkpoint_ids[]" value="<?php echo $starting_point['id']; ?>">
                                        <div class="setting-inputs">
                                            <div class="input-group">
                                                <label>Interval</label>
                                                <input type="number" name="intervals[]" value="<?php echo $start_interval; ?>" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Duration</label>
                                                <input type="number" name="durations[]" value="0" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Shift Declare</label>
                                                <select name="assignment_shifts[]" class="form-control" style="padding: 4px 8px; font-size: 0.85rem; font-weight: 600; min-width: 120px;">
                                                    <?php if (empty($client_shifts)): ?>
                                                        <option value="Day Shift" <?php echo $start_shift === 'Day Shift' ? 'selected' : ''; ?>>Day Shift</option>
                                                        <option value="Night Shift" <?php echo $start_shift === 'Night Shift' ? 'selected' : ''; ?>>Night Shift</option>
                                                    <?php
    else: ?>
                                                        <?php foreach ($client_shifts as $s_name): ?>
                                                            <option value="<?php echo htmlspecialchars($s_name); ?>" <?php echo $start_shift === $s_name ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($s_name); ?>
                                                            </option>
                                                        <?php
        endforeach; ?>
                                                    <?php
    endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <button type="button" class="remove-btn" style="visibility: hidden;">&times;</button>
                                    </div>
                                </div>
                            <?php
endif; ?>

                            <div id="tour-list" class="tour-list" style="<?php echo $starting_point ? 'margin-top: 0; border-top-left-radius: 0; border-top-right-radius: 0;' : ''; ?>">
                                <?php foreach ($current_assignments as $item): ?>
                                    <?php if ($starting_point && $item['checkpoint_id'] == $starting_point['id'])
        continue; ?>
                                    <div class="tour-item">
                                        <span class="handle">☰</span>
                                        <span class="checkpoint-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                        <input type="hidden" name="checkpoint_ids[]" value="<?php echo $item['checkpoint_id']; ?>">
                                        <div class="setting-inputs">
                                            <div class="input-group">
                                                <label>Interval</label>
                                                <input type="number" name="intervals[]" value="<?php echo $item['interval_minutes']; ?>" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Duration</label>
                                                <input type="number" name="durations[]" value="<?php echo $item['duration_minutes'] ?? 0; ?>" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Shift Declare</label>
                                                <select name="assignment_shifts[]" class="form-control" style="padding: 4px 8px; font-size: 0.85rem; font-weight: 600; min-width: 120px;" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                    <?php if (empty($client_shifts)): ?>
                                                        <option value="Day Shift" <?php echo($item['shift_name'] ?? '') === 'Day Shift' ? 'selected' : ''; ?>>Day Shift</option>
                                                        <option value="Night Shift" <?php echo($item['shift_name'] ?? '') === 'Night Shift' ? 'selected' : ''; ?>>Night Shift</option>
                                                    <?php
    else: ?>
                                                        <?php foreach ($client_shifts as $s_name): ?>
                                                            <option value="<?php echo htmlspecialchars($s_name); ?>" <?php echo($item['shift_name'] ?? '') === $s_name ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($s_name); ?>
                                                            </option>
                                                        <?php
        endforeach; ?>
                                                    <?php
    endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <?php if (!$is_patrol_locked): ?>
                                            <button type="button" class="remove-btn" onclick="removeItem(this)">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            </button>
                                        <?php
    endif; ?>
                                    </div>
                                <?php
endforeach; ?>
                            </div>

                            <?php if ($ending_point):
    $end_shift = '';
    $end_interval = 0;
    foreach ($current_assignments as $as) {
        if ($as['checkpoint_id'] == $ending_point['id']) {
            $end_shift = $as['shift_name'];
            $end_interval = $as['interval_minutes'];
            break;
        }
    }
?>
                                <div class="tour-list" style="margin-top: 0px; border-top: none; border-top-left-radius: 0; border-top-right-radius: 0; padding-top: 0;">
                                    <div class="tour-item" style="cursor: default; background: #fff7ed; border-color: #ffedd5; margin-top: 0;">
                                        <span class="handle" style="visibility: hidden; cursor: default;">☰</span>
                                        <span class="checkpoint-name"><?php echo htmlspecialchars($ending_point['name']); ?></span>
                                        <input type="hidden" name="checkpoint_ids[]" value="<?php echo $ending_point['id']; ?>">
                                        <div class="setting-inputs">
                                            <div class="input-group">
                                                <label>Interval</label>
                                                <input type="number" name="intervals[]" value="<?php echo $end_interval; ?>" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Duration</label>
                                                <input type="number" name="durations[]" value="0" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Shift Declare</label>
                                                <select name="assignment_shifts[]" class="form-control" style="padding: 4px 8px; font-size: 0.85rem; font-weight: 600; min-width: 120px;" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                    <?php if (empty($client_shifts)): ?>
                                                        <option value="Day Shift" <?php echo $end_shift === 'Day Shift' ? 'selected' : ''; ?>>Day Shift</option>
                                                        <option value="Night Shift" <?php echo $end_shift === 'Night Shift' ? 'selected' : ''; ?>>Night Shift</option>
                                                    <?php
    else: ?>
                                                        <?php foreach ($client_shifts as $s_name): ?>
                                                            <option value="<?php echo htmlspecialchars($s_name); ?>" <?php echo $end_shift === $s_name ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($s_name); ?>
                                                            </option>
                                                        <?php
        endforeach; ?>
                                                    <?php
    endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <button type="button" class="remove-btn" style="visibility: hidden;">&times;</button>
                                    </div>
                                </div>
                            <?php
endif; ?>
                        </div>

                        <?php if (!$is_patrol_locked): ?>
                            <div class="add-area">
                                <select id="checkpoint-select" class="form-control">
                                    <option value="">-- Add Checkpoint to Sequence --</option>
                                    <?php foreach ($available_checkpoints as $cp): ?>
                                        <option value="<?php echo $cp['id']; ?>"><?php echo htmlspecialchars($cp['name']); ?></option>
                                    <?php
    endforeach; ?>
                                </select>
                                <button type="button" class="btn" style="width: auto; padding: 12px 24px;" onclick="addItem()">Add to sequence</button>
                            </div>
                            <button type="submit" name="save_tour" class="btn btn-success" style="width: 100%; margin-top:24px;">Save Patrol Configuration</button>
                        <?php
endif; ?>
                    </form>
                </div>
                <div class="card">
                    <div class="card-header">
                        <span>Directory of Checkpoints</span>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" class="btn-visual" style="padding: 8px 16px; font-size: 0.85rem;" onclick="openVisualDesigner()">Visual</button>
                            <button type="button" class="btn" style="width: auto; padding: 8px 16px; font-size: 0.85rem;" onclick="document.getElementById('createQRModal').classList.add('show'); updateFormState();">Create New Checkpoint</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 50px;">No.</th>
                                    <th>Checkpoint Name</th>
                                    <th>Code</th>
                                    <th>Status</th>
                                    <th>Last Scanned</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($qrs_result && $qrs_result->num_rows > 0): ?>
                                    <?php $counter = 1;
    while ($row = $qrs_result->fetch_assoc()): ?>
                                        <?php
        $is_disabled = $mapping_status[$row['agency_client_id']] ?? 0;
        $status_text = $is_disabled ? 'Inactive' : 'Active';
        $status_class = $is_disabled ? 'status-inactive' : 'status-active';
        $last_scan = $row['last_scanned'] ? date('M d, Y h:i:s A', strtotime($row['last_scanned'])) : '<span style="color:#9ca3af">Never Scanned</span>';
?>
                                        <tr>
                                            <td><?php echo $row['is_zero_checkpoint'] ? '0' : $counter++; ?></td>
                                            <?php $display_no = $row['is_zero_checkpoint'] ? '0' : ($counter - 1); ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['checkpoint_name']); ?></strong>
                                                <?php if ($row['is_zero_checkpoint']): ?>
                                                    <span class="badge" style="background: #ecfdf5; color: #065f46; font-size: 0.7rem; border: none; padding: 2px 8px; margin-left: 5px;">Site Starting Point</span>
                                                <?php
        endif; ?>
                                            </td>
                                            <td>
                                                <div style="margin-bottom: 4px;"><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($row['checkpoint_code']); ?></code></div>
                                            </td>
                                            <td class="<?php echo $status_class; ?>">&#9679; <?php echo $status_text; ?></td>
                                            <td><?php echo $last_scan; ?></td>
                                            <td>
                                                <div style="display: flex; gap: 8px;">
                                                    <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #10b981;" onclick="showPrintModal('<?php echo htmlspecialchars(addslashes($row['checkpoint_code'])); ?>', '<?php echo htmlspecialchars(addslashes($row['company_name'] ?: $_SESSION['username'] ?: 'Client')); ?>', '<?php echo htmlspecialchars(addslashes($row['checkpoint_name'])); ?>', '<?php echo $display_no; ?>')">Show</button>
                                                    <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #3b82f6;" onclick="downloadQR()">Download QR</button>
                                                    <?php if (!$row['is_zero_checkpoint']): ?>
                                                    <form action="manage_tour.php" method="POST" style="margin: 0;" onsubmit="CustomModal.confirmForm(event, 'Are you sure you want to delete this checkpoint? This will also remove it from any tour sequence.');">
                                                        <input type="hidden" name="checkpoint_id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="delete_checkpoint" class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #ef4444;">Delete</button>
                                                    </form>
                                                    <?php
        endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php
    endwhile; ?>
                                <?php
else: ?>
                                    <tr>
                                        <td colspan="6" class="empty-state">No checkpoints have been created for your locations yet.</td>
                                    </tr>
                                <?php
endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Status Process Modal -->
    <div id="statusModal" class="modal-overlay <?php echo $show_status_modal ? 'show' : ''; ?>">
        <div class="modal-content" style="max-width: 400px;">
            <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                <?php echo $message_type === 'success' ? '✓' : '!'; ?>
            </div>
            <h3 style="margin-bottom: 10px;"><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
            <p style="color: #6b7280; margin-bottom: 24px;"><?php echo htmlspecialchars($message); ?></p>
            <button class="btn" style="width: 100%; border: none; background: #374151;" onclick="closeModal('statusModal')">Done</button>
        </div>
    </div>

    <!-- Limit Reached Modal -->
    <div id="limitModal" class="modal-overlay <?php echo $show_limit_modal ? 'show' : ''; ?>">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">!</div>
            <h3 style="margin-bottom: 10px;">Limit Reached</h3>
            <p style="color: #6b7280; margin-bottom: 24px;"><?php echo htmlspecialchars($message); ?></p>
            <button class="btn" style="background: #111827;" onclick="closeModal('limitModal')">Understand</button>
        </div>
    </div>

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



    <!-- Create Checkpoint Modal -->
    <div class="modal-overlay" id="createQRModal">
        <div class="modal-content" style="max-width: 600px; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">
                <h3 style="margin: 0;">Create New Checkpoint</h3>
                <button type="button" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280;" onclick="closeModal('createQRModal')">&times;</button>
            </div>
            
            <div class="grid-container">
                <!-- Create Form -->
                <div class="form-card">
                    <form action="manage_tour.php" method="POST">
                        <div class="form-group">
                            <label class="form-label">Select Site / Agency</label>
                            <select name="agency_client_id" class="form-control" required id="siteSelect" onchange="updateFormState()">
                                <option value="" disabled selected>-- Choose Site --</option>
                                <?php foreach ($limits_data as $l): ?>
                                    <option value="<?php echo $l['mapping_id']; ?>">
                                        Site: <?php echo $l['site_name'] ?: '#' . $l['mapping_id']; ?>
                                    </option>
                                <?php
endforeach; ?>
                            </select>
                        </div>
                        <div id="limit-warning"></div>
                        <div class="form-group">
                            <label class="form-label">Checkpoint Name</label>
                            <input type="text" name="qr_name" class="form-control" placeholder="e.g. Lobby Entrance" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Scan Interval (Minutes)</label>
                            <input type="number" name="scan_interval" class="form-control" value="0" min="0" required>
                        </div>
                        <button type="submit" name="create_qr" class="btn" id="submitBtn">Create Checkpoint</button>
                    </form>
                </div>

                <!-- Usage Stats -->
                <div class="form-card">
                    <h4 style="margin-bottom: 15px; color: #1e293b; font-size: 0.9rem;">QR Limits Summary</h4>
                    <?php foreach ($limits_data as $l): ?>
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                                <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px;"><?php echo htmlspecialchars($l['site_name'] ?: 'Site #' . $l['mapping_id']); ?></strong>
                                <span><?php echo $l['current_site_qrs']; ?> / <?php echo $l['qr_limit']; ?></span>
                            </div>
                            <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                                <div style="width: <?php echo min(100, ($l['qr_limit'] > 0 ? ($l['current_site_qrs'] / $l['qr_limit']) * 100 : 0)); ?>%; height: 100%; background: #3b82f6;"></div>
                            </div>
                        </div>
                    <?php
endforeach; ?>
                    <div class="btn-visual-info">
                    <strong>Visual Map:</strong> Drag checkpoints to position them. <strong>Blue (S)</strong> is Start point.
                </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Print QR Modal -->
    <div class="modal-overlay" id="printQRModal">
        <div class="modal-content print-card" style="max-width: 500px;">
            <h3 class="no-print" style="margin-bottom: 20px; font-weight: 700; color: #111827;">Checkpoint QR Code</h3>
            <div class="qr-display" id="qrContainer" style="padding: 40px; border: 2px dashed #e2e8f0; border-radius: 12px; background: white;">
                <p id="companyLabel" style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 30px; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; width: 100%;"></p>
                <div id="qrcode" class="qr-img" style="margin: 0 auto; padding: 20px; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"></div>
                <div id="checkpointNoLabel" style="font-size: 3rem; font-weight: 800; color: #1e293b; margin-top: 30px; letter-spacing: -1px;"></div>
            </div>
            <div class="no-print" style="margin-top: 20px; display: flex; gap: 12px;">
                <button class="btn" style="background:#f3f4f6; color:#374151;" onclick="closeModal('printQRModal')">Close</button>
                <button class="btn" style="background: #111827; color: white;" onclick="window.print()">Print Document</button>
            </div>
        </div>
    </div>

    <!-- Visual Designer Modal -->
    <div id="visualDesignerModal" class="modal-overlay">
        <div class="modal-content large">
            <div class="card-header" style="border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1.25rem;">Visual Patrol Map</h3>
                <button type="button" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280;" onclick="closeVisualDesigner()">&times;</button>
            </div>
            <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px; text-align: left;">
                Draggable overview of checkpoints. <strong>Blue (S)</strong> is Start, and <strong>White</strong> are checkpoints.
            </p>
            <div id="visual-canvas" class="visual-container">
                <svg id="visual-svg" class="visual-svg">
                    <defs>
                        <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                            <polygon points="0 0, 10 3.5, 0 7" fill="#6366f1" />
                        </marker>
                    </defs>
                </svg>
            </div>
                <div class="modal-actions" style="margin-top: 20px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button class="btn-modal" style="background: #0ea5e9; color: white; max-width: 200px;" onclick="downloadVisualMap()">Download Map (PDF)</button>
                    <button class="btn-modal btn-cancel" style="max-width: 200px;" onclick="closeVisualDesigner()">Close View</button>
                </div>
        </div>
    </div>

    <script>
        // --- Sequence Setup Variables and Logic ---
        const qrLimit = <?php echo $qr_limit; ?>;
        const qrOverride = <?php echo $qr_override; ?>;
        const tourList = document.getElementById('tour-list');
        const currentShifts = <?php echo json_encode($client_shifts); ?>;

        // Initialize Sortable
        if (tourList && !<?php echo $is_patrol_locked; ?>) {
            new Sortable(tourList, {
                handle: '.handle',
                animation: 150,
                onEnd: updateCount
            });
        }

        function updateCount() {
            const count = tourList.children.length;
            const countDisplay = document.getElementById('current-count');
            countDisplay.innerText = count;
            
            if (count > qrLimit && !qrOverride) {
                countDisplay.classList.add('danger');
            } else {
                countDisplay.classList.remove('danger');
            }
        }

        function addItem() {
            const select = document.getElementById('checkpoint-select');
            const id = select.value;
            const name = select.options[select.selectedIndex].text;

            if (!id) return;

            let shiftOptions = '';
            if (currentShifts.length > 0) {
                shiftOptions = currentShifts.map(s => `<option value="${s.replace(/"/g, '&quot;')}">${s}</option>`).join('');
            } else {
                shiftOptions = '<option value="Day Shift">Day Shift</option><option value="Night Shift">Night Shift</option>';
            }

            const div = document.createElement('div');
            div.className = 'tour-item';
            div.innerHTML = `
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
                        <input type="number" name="durations[]" value="0" min="0">
                        <label>min</label>
                    </div>
                    <div class="input-group">
                        <label>Shift Declare</label>
                        <select name="assignment_shifts[]" class="form-control" style="padding: 4px 8px; font-size: 0.85rem; font-weight: 600; min-width: 120px;">
                            ${shiftOptions}
                        </select>
                    </div>
                </div>
                <button type="button" class="remove-btn" onclick="removeItem(this)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            `;
            tourList.appendChild(div);
            updateCount();
            select.value = '';
        }

        function removeItem(btn) {
            btn.closest('.tour-item').remove();
            updateCount();
        }

        // Initialize count
        updateCount();


        // --- Modals & State ---
        const limitsData = <?php echo json_encode($limits_data); ?>;

        function updateFormState() {
            const select = document.getElementById('siteSelect');
            if(!select) return;
            const mId = parseInt(select.value);
            const warning = document.getElementById('limit-warning');
            const submitBtn = document.getElementById('submitBtn');

            warning.innerHTML = '';
            submitBtn.disabled = false;

            const limit = limitsData.find(l => parseInt(l.mapping_id) === mId);
            if (!limit) return;

            if (parseInt(limit.is_disabled) === 1) {
                warning.innerHTML = '<div class="alert-warning">QR creation is disabled for this site.</div>';
                submitBtn.disabled = true;
            } else if (parseInt(limit.qr_limit) > 0 && parseInt(limit.current_site_qrs) >= parseInt(limit.qr_limit) && parseInt(limit.qr_override) === 0) {
                warning.innerHTML = '<div class="alert-warning">Per-site QR limit reached (' + limit.qr_limit + ').</div>';
                submitBtn.disabled = true;
            }
        }

        function showPrintModal(code, company, cpName, index) {
            document.getElementById('companyLabel').textContent = company;
            document.getElementById('checkpointNoLabel').textContent = cpName;
            document.getElementById('qrcode').innerHTML = '';
            new QRCode(document.getElementById("qrcode"), {
                text: code,
                width: 250,
                height: 250,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
            document.getElementById('printQRModal').dataset.filename = (company + "_" + code).replace(/[^a-z0-9]/gi, '_');
            document.getElementById('printQRModal').classList.add('show');
        }

        function downloadQR() {
            const qrCanvas = document.querySelector('#qrcode canvas');
            const qrImg = document.querySelector('#qrcode img');
            const companyName = document.getElementById('companyLabel').textContent;
            const checkpointLabel = document.getElementById('checkpointNoLabel').textContent;
            
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
            ctx.fillStyle = '#3b82f6'; // Sentinel Blue
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

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
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
                const siteName = "<?php echo $mapping['site_name'] ?? 'My Patrol'; ?>";
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
                CustomModal.alert('Failed to generate PDF. Please try again.', 'Error', 'error');
            });
        }

        // --- Visual Patrol Map Logic ---
        let visualCheckpoints = [];
        const mappingId = <?php echo (int)$mapping_id; ?>;

        function closeVisualDesigner() {
            document.getElementById('visualDesignerModal').classList.remove('show');
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

                // Check destination status for glowing line
                const status = (endCp.latest_status || '').toLowerCase();
                let pathStatusClass = '';
                if (status === 'on-time' || status === 'on time') pathStatusClass = ' path-status-on-time';
                else if (status === 'late') pathStatusClass = ' path-status-late';

                // Create static path
                const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
                path.setAttribute("d", d);
                path.setAttribute("class", "visual-path" + pathStatusClass);
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
            const modal = document.getElementById('visualDesignerModal');
            const canvas = document.getElementById('visual-canvas');
            const svg = document.getElementById('visual-svg');
            
            modal.classList.add('show');
            canvas.querySelectorAll('.checkpoint-circle').forEach(c => c.remove());
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'visual-loading';
            loadingDiv.style.cssText = 'display:flex; align-items:center; justify-content:center; height:100%; color:#64748b;';
            loadingDiv.textContent = 'Loading checkpoints...';
            canvas.appendChild(loadingDiv);

            fetch(`manage_tour.php?ajax_checkpoints=1&mapping_id=${mappingId}`)
                .then(response => response.json())
                .then(data => {
                    const loader = document.getElementById('visual-loading');
                    if (loader) loader.remove();
                    
                    visualCheckpoints = data;

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
                        
                        let statusClass = 'cp-status-none';
                        const status = (cp.latest_status || '').toLowerCase();
                        if (status === 'on-time' || status === 'on time') statusClass = 'cp-status-on-time';
                        else if (status === 'late') statusClass = 'cp-status-late';
                        circle.className = `checkpoint-circle ${cp.isStart ? 'start' : (cp.isEnd ? 'end' : 'regular')} ${statusClass}`;
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
                            const spacingX = 120; 
                            const spacingY = 120;
                            const itemsPerRow = Math.floor((containerWidth - 60) / spacingX) || 5;
                            x = 40 + (index % itemsPerRow) * spacingX;
                            y = 40 + Math.floor(index / itemsPerRow) * spacingY;
                        }
                        circle.style.left = x + 'px';
                        circle.style.top = y + 'px';
                        
                        const startDrag = (e) => {
                            const isTouch = e.type === 'touchstart';
                            const clientX = isTouch ? e.touches[0].clientX : e.clientX;
                            const clientY = isTouch ? e.touches[0].clientY : e.clientY;
                            
                            let shiftX = clientX - circle.getBoundingClientRect().left;
                            let shiftY = clientY - circle.getBoundingClientRect().top;
                            circle.style.zIndex = 1000;
                            
                            function moveAt(pageX, pageY) {
                                let newX = pageX - canvas.getBoundingClientRect().left - shiftX;
                                let newY = pageY - canvas.getBoundingClientRect().top - shiftY;
                                newX = Math.max(0, Math.min(newX, canvas.offsetWidth - circle.offsetWidth));
                                newY = Math.max(0, Math.min(newY, canvas.offsetHeight - circle.offsetHeight));
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
                                
                                fetch('manage_tour.php', {
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
                        canvas.appendChild(circle);
                    });
                    drawArrows();
                })
                .catch(err => {
                    canvas.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#ef4444;">Error loading checkpoints.</div>';
                    console.error(err);
                });
        }

        function closeAllModals() {
            document.querySelectorAll('.modal-overlay').forEach(modal => {
                modal.classList.remove('show');
            });
            document.body.style.overflow = '';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeAllModals();
            }
        }
    </script>
    <?php include_once 'includes/common_modals.php'; ?>
</body>
</html>
