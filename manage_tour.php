<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'] ?? null;

// Ensure duration column exists
$conn->query("ALTER TABLE tour_assignments ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT 0");

// Fetch mapping info for this client
$mapping_sql = "SELECT id, qr_limit, qr_override, is_patrol_locked FROM agency_clients WHERE client_id = $client_id LIMIT 1";
$mapping_res = $conn->query($mapping_sql);
$mapping = $mapping_res->fetch_assoc();
$mapping_id = $mapping['id'] ?? null;
$qr_limit = (int)($mapping['qr_limit'] ?? 0);
$qr_override = (int)($mapping['qr_override'] ?? 0);
$is_patrol_locked = (int)($mapping['is_patrol_locked'] ?? 0);

if (!$mapping_id) {
    die("Error: No agency-client mapping found for this account.");
}

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id, is_disabled FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
$mapping_status = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
        $mapping_status[(int)$r['id']] = (int)$r['is_disabled'];
    }
}

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
}

// Ensure every mapping has a starting point (is_zero_checkpoint = 1)
if (!empty($mapping_ids)) {
    foreach ($mapping_ids as $m_id) {
        $check_stmt = "SELECT id FROM checkpoints WHERE agency_client_id = $m_id AND is_zero_checkpoint = 1 LIMIT 1";
        $check_res = $conn->query($check_stmt);
        if ($check_res && $check_res->num_rows == 0) {
            $code_unique = false;
            $start_code = "";
            while (!$code_unique) {
                $start_code = "START-" . $m_id . "-" . strtoupper(bin2hex(random_bytes(3)));
                $c = $conn->query("SELECT id FROM checkpoints WHERE checkpoint_code = '$start_code'");
                if ($c && $c->num_rows == 0) $code_unique = true;
            }
            $conn->query("INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, qr_code_data, is_zero_checkpoint) VALUES ($m_id, 'Starting Point', '$start_code', '$start_code', 1)");
        }
    }
}

$message = '';
$message_type = '';
$show_status_modal = false;
$show_limit_modal = false;

// Handle Save
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_tour'])) {
    $checkpoint_ids = $_POST['checkpoint_ids'] ?? [];
    $intervals = $_POST['intervals'] ?? [];
    $durations = $_POST['durations'] ?? [];
    
    if ($is_patrol_locked) {
        $message = "Error: Patrol configuration is locked by your agency and cannot be modified.";
        $message_type = "error";
        $show_status_modal = true;
    } else {
        // Validation: Count checkpoints excluding the starting point
        $zero_res = $conn->query("SELECT id FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
        $zero_id = ($zero_res && $zero_res->num_rows > 0) ? $zero_res->fetch_assoc()['id'] : null;
        
        $count = 0;
        foreach($checkpoint_ids as $id) if($id != $zero_id) $count++;

        if ($count > $qr_limit && !$qr_override) {
            $message = "Error: You cannot exceed the limit of $qr_limit checkpoints.";
            $message_type = "error";
            $show_status_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mapping_id");
                for ($i = 0; $i < count($checkpoint_ids); $i++) {
                    $cp_id = (int)$checkpoint_ids[$i];
                    $interval = (int)($intervals[$i] ?? 0);
                    $duration = (int)($durations[$i] ?? 0);
                    $order = $i + 1;
                    $stmt = $conn->prepare("INSERT INTO tour_assignments (agency_client_id, checkpoint_id, sort_order, interval_minutes, duration_minutes) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiii", $mapping_id, $cp_id, $order, $interval, $duration);
                    $stmt->execute();
                }
                $conn->commit();
                $message = "Tour sequence saved successfully!";
                $message_type = "success";
                $show_status_modal = true;
            } catch (Exception $e) {
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
    
    $is_zero_checkpoint = 0; // Manually created checkpoints are never zero checkpoints
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
        } else {
            // Check Per-Site QR Limit (excludes starting point)
            $limit_check = $conn->query("
                SELECT u.qr_limit, 
                (SELECT COUNT(*) FROM checkpoints cp 
                 WHERE cp.agency_client_id = ac.id AND cp.is_zero_checkpoint = 0) as total_site_qrs
                FROM agency_clients ac
                JOIN users u ON ac.agency_id = u.id
                WHERE ac.id = $mapping_id_qr
            ");
            
            if ($limit_check && $limit_check->num_rows > 0) {
                $limit_data = $limit_check->fetch_assoc();
                if ($limit_data['qr_limit'] > 0 && $limit_data['total_site_qrs'] >= $limit_data['qr_limit'] && !$mapping_qr['qr_override']) {
                    $message = "QR limit reached for this site ($limit_data[qr_limit] QRs max). Please contact the agency administrator.";
                    $message_type = "error";
                    $show_limit_modal = true;
                } else {
                    $insert_sql = "INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, qr_code_data, is_zero_checkpoint, scan_interval) VALUES ($mapping_id_qr, '$qr_name', '$checkpoint_code', '$checkpoint_code', $is_zero_checkpoint, $scan_interval)";
                    if ($conn->query($insert_sql)) {
                        $message = "Checkpoint '$qr_name' created successfully!";
                        $message_type = "success";
                        $show_status_modal = true;
                    } else {
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
        } else if ($conn->query("DELETE FROM checkpoints WHERE id = $cp_id")) {
            // Delete associated tour assignments first
            $conn->query("DELETE FROM tour_assignments WHERE checkpoint_id = $cp_id");
            $message = "Checkpoint deleted successfully!";
            $message_type = "success";
            $show_status_modal = true;
        } else {
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
        u.qr_limit, 
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
if ($limits_res) while($r = $limits_res->fetch_assoc()) $limits_data[] = $r;

// Fetch checkpoints for the directory table
$qrs_sql = "
    SELECT 
        c.id,
        c.name as checkpoint_name,
        c.checkpoint_code,
        c.agency_client_id,
        c.is_zero_checkpoint,
        c.scan_interval,
        MAX(s.scan_time) as last_scanned
    FROM checkpoints c
    LEFT JOIN scans s ON c.id = s.checkpoint_id
    WHERE c.agency_client_id IN ($mapping_ids_str)
    GROUP BY c.id
    ORDER BY c.is_zero_checkpoint DESC, c.name ASC
";
$qrs_result = $conn->query($qrs_sql);

// Fetch available checkpoints for Tour Setup tab (exclude zero)
$checkpoints_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 0 ORDER BY name ASC");
$available_checkpoints = [];
while ($row = $checkpoints_res->fetch_assoc()) {
    $available_checkpoints[] = $row;
}

// Fetch Starting Point for Tour Setup Tab
$starting_point = null;
$start_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
if ($start_res && $start_res->num_rows > 0) {
    $starting_point = $start_res->fetch_assoc();
}

// Fetch current assignments for Tour Setup Tab
$assignments_res = $conn->query("
    SELECT ta.checkpoint_id, ta.interval_minutes, ta.duration_minutes, cp.name, cp.is_zero_checkpoint 
    FROM tour_assignments ta 
    JOIN checkpoints cp ON ta.checkpoint_id = cp.id 
    WHERE ta.agency_client_id = $mapping_id 
    ORDER BY ta.sort_order ASC
");
$current_assignments = [];
while ($row = $assignments_res->fetch_assoc()) {
    $current_assignments[] = $row;
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
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; padding: 16px; gap: 16px; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0; border-radius: 16px; overflow: hidden; }
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
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; animation: modalFadeIn 0.3s ease-out; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .qr-display { padding: 20px; text-align: center; display: flex; flex-direction: column; align-items: center;}
        .qr-img { margin: 0 auto; display: block; }
        .code-display { font-family: monospace; font-size: 1.5rem; font-weight: bold; margin-top: 10px; text-align: center;}

        @media print {
            .sidebar, .topbar, .tabs, .card:not(.print-card), .alert, .btn:not(.no-print) { display: none !important; }
            body { background: white; }
            .content-area { padding: 0; }
            .print-card { box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100% !important;}
        }
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
            <h2>Tours & Checkpoints Dashboard</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
            <?php if ($is_patrol_locked && $active_tab === 'tour'): ?>
                <div style="background: #fff7ed; color: #9a3412; padding: 10px 20px; border-radius: 8px; border: 1px solid #fed7aa; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;">
                    <span>🔒 Patrol sequence is managed and locked by your agency.</span>
                </div>
            <?php endif; ?>
        </header>

        <div class="content-area">
                <div class="card">
                    <div class="card-header">
                        <span>Manage Tour Pattern</span>
                        <div class="limit-info">
                            QR Limit: <span id="current-count" class="limit-count">0</span> / <span class="limit-count"><?php echo $qr_limit; ?></span>
                        </div>
                    </div>

                    <form id="tour-form" method="POST" action="manage_tour.php">
                        <div class="tour-list-container">
                            <?php if ($starting_point): ?>
                                <div class="tour-list" style="margin-bottom: 0px; border-bottom: none; border-bottom-left-radius: 0; border-bottom-right-radius: 0; padding-bottom: 0;">
                                    <div class="tour-item" style="cursor: default; background: #e0f2fe; border-color: #bae6fd; margin-bottom: 0;">
                                        <span class="handle" style="visibility: hidden; cursor: default;">☰</span>
                                        <span class="checkpoint-name"><?php echo htmlspecialchars($starting_point['name']); ?></span>
                                        <input type="hidden" name="checkpoint_ids[]" value="<?php echo $starting_point['id']; ?>">
                                        <div class="setting-inputs">
                                            <div class="input-group">
                                                <label>Interval</label>
                                                <input type="number" name="intervals[]" value="0" min="0" disabled>
                                                <label>min</label>
                                            </div>
                                            <div class="input-group">
                                                <label>Stay</label>
                                                <input type="number" name="durations[]" value="0" min="0" disabled>
                                                <label>min</label>
                                            </div>
                                        </div>
                                        <button type="button" class="remove-btn" style="visibility: hidden;">&times;</button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div id="tour-list" class="tour-list" style="<?php echo $starting_point ? 'margin-top: 0; border-top-left-radius: 0; border-top-right-radius: 0;' : ''; ?>">
                                <?php foreach ($current_assignments as $item): ?>
                                    <?php if ($item['is_zero_checkpoint']) continue; ?>
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
                                                <label>Stay</label>
                                                <input type="number" name="durations[]" value="<?php echo $item['duration_minutes']; ?>" min="0" <?php echo $is_patrol_locked ? 'disabled' : ''; ?>>
                                                <label>min</label>
                                            </div>
                                        </div>
                                        <?php if (!$is_patrol_locked): ?>
                                            <button type="button" class="remove-btn" onclick="removeItem(this)">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if (!$is_patrol_locked): ?>
                            <div class="add-area">
                                <select id="checkpoint-select" class="form-control">
                                    <option value="">-- Add Checkpoint to Sequence --</option>
                                    <?php foreach ($available_checkpoints as $cp): ?>
                                        <option value="<?php echo $cp['id']; ?>"><?php echo htmlspecialchars($cp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn" style="width: auto; padding: 12px 24px;" onclick="addItem()">Add to sequence</button>
                            </div>
                            <button type="submit" name="save_tour" class="btn btn-success" style="width: 100%; margin-top:24px;">Save Patrol Configuration</button>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card">
                    <div class="card-header">
                        <span>Directory of Checkpoints</span>
                        <button class="btn" style="width: auto; padding: 8px 16px; font-size: 0.85rem;" onclick="document.getElementById('createQRModal').classList.add('show'); updateFormState();">Create New Checkpoint</button>
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
                                    <?php $counter = 1; while($row = $qrs_result->fetch_assoc()): ?>
                                        <?php
                                            $is_disabled = $mapping_status[$row['agency_client_id']] ?? 0;
                                            $status_text = $is_disabled ? 'Inactive' : 'Active';
                                            $status_class = $is_disabled ? 'status-inactive' : 'status-active';
                                            $last_scan = $row['last_scanned'] ? date('M d, Y h:i A', strtotime($row['last_scanned'])) : '<span style="color:#9ca3af">Never Scanned</span>';
                                        ?>
                                        <tr>
                                            <td><?php echo $row['is_zero_checkpoint'] ? '0' : $counter++; ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['checkpoint_name']); ?></strong></td>
                                            <td>
                                                <div style="margin-bottom: 4px;"><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($row['checkpoint_code']); ?></code></div>
                                            </td>
                                            <td class="<?php echo $status_class; ?>">&#9679; <?php echo $status_text; ?></td>
                                            <td><?php echo $last_scan; ?></td>
                                            <td>
                                                <div style="display: flex; gap: 8px;">
                                                    <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #10b981;" onclick="showPrintModal('<?php echo htmlspecialchars(addslashes($row['checkpoint_code'])); ?>', '<?php echo htmlspecialchars(addslashes($row['checkpoint_name'])); ?>')">Show</button>
                                                    <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #3b82f6;" onclick="downloadQR('<?php echo htmlspecialchars(addslashes($row['checkpoint_code'])); ?>', '<?php echo htmlspecialchars(addslashes($row['checkpoint_name'])); ?>')">Download</button>
                                                    <?php if (!$row['is_zero_checkpoint']): ?>
                                                    <form action="manage_tour.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this checkpoint? This will also remove it from any tour sequence.');">
                                                        <input type="hidden" name="checkpoint_id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="delete_checkpoint" class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #ef4444;">Delete</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="empty-state">No checkpoints have been created for your locations yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn" style="background:#f3f4f6; color:#374151; flex:1;" onclick="closeModal('logoutModal')">Cancel</button>
                <a href="logout.php" style="flex:1; padding:12px; background:#ef4444; color:white; text-decoration:none; border-radius:8px; font-weight:600; font-size:1rem; display:block; box-sizing:border-box;">Log Out</a>
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
                                <?php foreach($limits_data as $l): ?>
                                    <option value="<?php echo $l['mapping_id']; ?>">
                                        Site: <?php echo $l['site_name'] ?: '#' . $l['mapping_id']; ?>
                                    </option>
                                <?php endforeach; ?>
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
                    <?php foreach($limits_data as $l): ?>
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                                <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px;"><?php echo htmlspecialchars($l['site_name'] ?: 'Site #' . $l['mapping_id']); ?></strong>
                                <span><?php echo $l['current_site_qrs']; ?> / <?php echo $l['qr_limit']; ?></span>
                            </div>
                            <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                                <div style="width: <?php echo min(100, ($l['qr_limit'] > 0 ? ($l['current_site_qrs'] / $l['qr_limit']) * 100 : 0)); ?>%; height: 100%; background: #3b82f6;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p style="font-size: 0.7rem; color: #6b7280; margin-top: 10px;">*Limits are set by your agency administrator.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Print QR Modal -->
    <div class="modal-overlay" id="printQRModal">
        <div class="modal-content print-card" style="max-width: 500px;">
            <h3 class="no-print" style="margin-bottom: 20px; font-weight: 700; color: #111827;">Checkpoint QR Code</h3>
            <div class="qr-display" id="qrContainer">
                <p id="checkpointLabel" style="font-size: 1.1rem; font-weight: 600; color: #374151; margin-bottom: 20px;"></p>
                <div id="qrcode" class="qr-img"></div>
                <div class="code-display" id="codeLabel"></div>
            </div>
            <div class="no-print" style="margin-top: 20px; display: flex; gap: 12px;">
                <button class="btn" style="background:#f3f4f6; color:#374151;" onclick="closeModal('printQRModal')">Close</button>
                <button class="btn" style="background: #111827; color: white;" onclick="window.print()">Print Document</button>
            </div>
        </div>
    </div>

    <script>
        // --- Sequence Setup Variables and Logic ---
        const qrLimit = <?php echo $qr_limit; ?>;
        const qrOverride = <?php echo $qr_override; ?>;
        const tourList = document.getElementById('tour-list');

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

            // Check if already in list
            const existing = Array.from(document.querySelectorAll('input[name="checkpoint_ids[]"]')).map(i => i.value);
            if (existing.includes(id)) {
                alert("This checkpoint is already in the sequence.");
                return;
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
                        <label>Stay</label>
                        <input type="number" name="durations[]" value="0" min="0">
                        <label>min</label>
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

        function showPrintModal(code, name) {
            document.getElementById('checkpointLabel').textContent = name;
            document.getElementById('codeLabel').textContent = code;
            document.getElementById('qrcode').innerHTML = '';
            new QRCode(document.getElementById("qrcode"), {
                text: code,
                width: 200,
                height: 200,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
            document.getElementById('printQRModal').classList.add('show');
        }

        function downloadQR(code, name) {
            const tempDiv = document.createElement('div');
            new QRCode(tempDiv, {
                text: code,
                width: 300,
                height: 300,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
            
            setTimeout(() => {
                let img = tempDiv.querySelector('img');
                let canvas = tempDiv.querySelector('canvas');
                
                let fileUrl = '';
                if (canvas) {
                    fileUrl = canvas.toDataURL("image/png");
                } else if (img && img.src) {
                    fileUrl = img.src;
                }
                
                if (fileUrl) {
                    const a = document.createElement('a');
                    a.href = fileUrl;
                    a.download = `QR_${name.replace(/[^a-z0-9]/gi, '_')}_${code}.png`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                } else {
                    alert('Could not generate QR code for download.');
                }
            }, 300);
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.classList.remove('show');
            }
        }
    </script>
</body>
</html>
