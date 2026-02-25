<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

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

$message = '';
$message_type = '';

// Handle creating QR Checkpoint
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_qr'])) {
    $mapping_id = (int)$_POST['agency_client_id'];
    $qr_name = $conn->real_escape_string($_POST['qr_name']);
    $checkpoint_code = $conn->real_escape_string($_POST['checkpoint_code']);
    
    // Check if code exists globally (codes should be unique)
    $check_code = $conn->query("SELECT id FROM checkpoints WHERE checkpoint_code = '$checkpoint_code'");
    if ($check_code && $check_code->num_rows > 0) {
        $message = "Error: Code '$checkpoint_code' is already assigned to another checkpoint.";
        $message_type = "error";
    } else {
        // Verify this mapping belongs to the client and check limits
        $verify_sql = "SELECT id, qr_limit, qr_override, is_disabled FROM agency_clients WHERE id = $mapping_id AND client_id = $client_id";
        $verify_res = $conn->query($verify_sql);
        
        if ($verify_res && $verify_res->num_rows > 0) {
            $mapping = $verify_res->fetch_assoc();
            if ($mapping['is_disabled']) {
                $message = "QR creation is currently disabled for this site.";
                $message_type = "error";
            } else {
                // Check usage
                $count_res = $conn->query("SELECT COUNT(*) as c FROM checkpoints WHERE agency_client_id = $mapping_id");
                $current_usage = $count_res->fetch_assoc()['c'];
                
                if ($current_usage >= $mapping['qr_limit'] && !$mapping['qr_override']) {
                    $message = "QR limit reached for this site. Contact your agency to increase your limit.";
                    $message_type = "error";
                } else {
                    $insert_sql = "INSERT INTO checkpoints (agency_client_id, name, checkpoint_code, qr_code_data) VALUES ($mapping_id, '$qr_name', '$checkpoint_code', '$checkpoint_code')";
                    if ($conn->query($insert_sql)) {
                        $message = "Checkpoint '$qr_name' created successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Database error: " . $conn->error;
                        $message_type = "error";
                    }
                }
            }
        }
    }
}

// Handle Editing QR Code
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_code'])) {
    $cp_id = (int)$_POST['checkpoint_id'];
    $new_code = $conn->real_escape_string($_POST['new_code']);
    
    // Verify ownership
    $verify_own = $conn->query("
        SELECT cp.id FROM checkpoints cp
        JOIN agency_clients ac ON cp.agency_client_id = ac.id
        WHERE cp.id = $cp_id AND ac.client_id = $client_id
    ");
    
    if ($verify_own && $verify_own->num_rows > 0) {
        // Check uniqueness
        $check_unique = $conn->query("SELECT id FROM checkpoints WHERE checkpoint_code = '$new_code' AND id != $cp_id");
        if ($check_unique && $check_unique->num_rows > 0) {
            $message = "Error: Code '$new_code' is already in use.";
            $message_type = "error";
        } else {
            if ($conn->query("UPDATE checkpoints SET checkpoint_code = '$new_code', qr_code_data = '$new_code' WHERE id = $cp_id")) {
                $message = "Checkpoint code updated successfully!";
                $message_type = "success";
            } else {
                $message = "Update error: " . $conn->error;
                $message_type = "error";
            }
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
        (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id) as current_qrs
    FROM agency_clients ac
    JOIN users u ON ac.agency_id = u.id
    WHERE ac.client_id = $client_id
";
$limits_res = $conn->query($limits_sql);
$limits_data = [];
if ($limits_res) while($r = $limits_res->fetch_assoc()) $limits_data[] = $r;

// Fetch checkpoints and their latest scan
$qrs_sql = "
    SELECT 
        c.id,
        c.name as checkpoint_name,
        c.checkpoint_code,
        c.agency_client_id,
        MAX(s.scan_time) as last_scanned
    FROM checkpoints c
    LEFT JOIN scans s ON c.id = s.checkpoint_id
    WHERE c.agency_client_id IN ($mapping_ids_str)
    GROUP BY c.id
    ORDER BY c.name ASC
";
$qrs_result = $conn->query($qrs_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkpoints List - Client Portal</title>
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
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .btn-confirm:hover { background: #be123c; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        /* Custom Styles for Checkpoints */
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;}
        
        .view-only-badge { font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; background: #f3f4f6; padding: 4px 10px; border-radius: 9999px; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        
        .status-active { color: #10b981; font-weight: 600; }
        .status-inactive { color: #ef4444; font-weight: 600; }

        .empty-state { text-align: center; padding: 40px; color: #6b7280; font-style: italic; }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; display: flex; align-items: center; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .alert-warning { background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 12px 16px; margin-top: 12px;}

        .form-group { margin-bottom: 16px; text-align: left;}
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #3b82f6; }
        .btn { padding: 10px 18px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: background-color 0.2s; width: 100%; font-size: 1rem; }
        .btn:hover { background-color: #2563eb; }
        .btn:disabled { background-color: #9ca3af; cursor: not-allowed; }

        .qr-display { padding: 20px; text-align: center; }
        .qr-img { margin: 0 auto; display: block; }
        .code-display { font-family: monospace; font-size: 1.5rem; font-weight: bold; margin-top: 10px; }

        @media print {
            .sidebar, .topbar, .card:not(.print-card), .alert, .btn:not(.no-print) { display: none !important; }
            body { background: white; }
            .content-area { padding: 0; }
            .print-card { box-shadow: none !important; border: none !important; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Client Portal
        </div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="client_qrs.php" class="nav-link active">Checkpoints</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Checkpoint Directory</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            
            <div class="card">
                <div class="card-header">
                    Manage Checkpoints
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <!-- Create Form -->
                    <div style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <h4 style="margin-bottom: 15px; color: #1e293b;">Create New Checkpoint</h4>
                        <?php if (empty($limits_data)): ?>
                            <p style="color: #64748b; font-size: 0.9rem;">No agency assignments found.</p>
                        <?php else: ?>
                            <form action="client_qrs.php" method="POST">
                                <div class="form-group">
                                    <label class="form-label">Select Site / Agency</label>
                                    <select name="agency_client_id" class="form-control" required id="siteSelect" onchange="updateFormState()">
                                        <option value="" disabled selected>-- Choose Site --</option>
                                        <?php foreach($limits_data as $l): ?>
                                            <option value="<?php echo $l['mapping_id']; ?>">
                                                Site: <?php echo $l['site_name'] ?: '#' . $l['mapping_id']; ?> (via <?php echo htmlspecialchars($l['agency_name']); ?>)
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
                                    <label class="form-label">Unique Code</label>
                                    <input type="text" name="checkpoint_code" class="form-control" placeholder="e.g. LOB-001" required>
                                </div>
                                <button type="submit" name="create_qr" class="btn" id="submitBtn">Create Checkpoint</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Usage Stats -->
                    <div style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <h4 style="margin-bottom: 15px; color: #1e293b;">QR Limits Summary</h4>
                        <?php foreach($limits_data as $l): ?>
                            <div style="margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 5px;">
                                    <strong><?php echo $l['site_name'] ?: 'Site #' . $l['mapping_id']; ?></strong>
                                    <span><?php echo $l['current_qrs']; ?> / <?php echo $l['qr_limit']; ?> used</span>
                                </div>
                                <div style="width: 100%; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                    <div style="width: <?php echo min(100, ($l['qr_limit'] > 0 ? ($l['current_qrs'] / $l['qr_limit']) * 100 : 0)); ?>%; height: 100%; background: #3b82f6;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card-header" style="border-top: 1px solid #e5e7eb; padding-top: 20px; border-bottom: none;">
                    Assigned QR Locations
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Checkpoint Name</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Last Scanned</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($qrs_result && $qrs_result->num_rows > 0): ?>
                                <?php while($row = $qrs_result->fetch_assoc()): ?>
                                    <?php
                                        // A checkpoint is considered inactive if the admin disabled the assigned agency-client mapping.
                                        $is_disabled = $mapping_status[$row['agency_client_id']] ?? 0;
                                        $status_text = $is_disabled ? 'Inactive' : 'Active';
                                        $status_class = $is_disabled ? 'status-inactive' : 'status-active';
                                        $last_scan = $row['last_scanned'] ? date('M d, Y h:i A', strtotime($row['last_scanned'])) : '<span style="color:#9ca3af">Never Scanned</span>';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['checkpoint_name']); ?></strong></td>
                                        <td><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($row['checkpoint_code']); ?></code></td>
                                        <td class="<?php echo $status_class; ?>">&#9679; <?php echo $status_text; ?></td>
                                        <td><?php echo $last_scan; ?></td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto;" onclick="showPrintModal('<?php echo $row['checkpoint_code']; ?>', '<?php echo $row['checkpoint_name']; ?>')">Print</button>
                                                <button class="btn" style="padding: 6px 12px; font-size: 0.8rem; width: auto; background: #64748b;" onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo $row['checkpoint_code']; ?>')">Edit Code</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">No checkpoints have been created for your locations yet.</td>
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

    <!-- Print QR Modal -->
    <div class="modal-overlay" id="printQRModal">
        <div class="modal-content print-card" style="max-width: 500px;">
            <h3 class="modal-title no-print">Checkpoint QR Code</h3>
            <div class="qr-display" id="qrContainer">
                <p id="checkpointLabel" style="font-size: 1.1rem; font-weight: 600; color: #374151; margin-bottom: 20px;"></p>
                <div id="qrcode" class="qr-img"></div>
                <div class="code-display" id="codeLabel"></div>
            </div>
            <div class="modal-actions no-print" style="margin-top: 20px;">
                <button class="btn-modal btn-cancel" onclick="document.getElementById('printQRModal').classList.remove('show');">Close</button>
                <button class="btn-modal" style="background: #111827; color: white;" onclick="window.print()">Print Code</button>
            </div>
        </div>
    </div>

    <!-- Edit Code Modal -->
    <div class="modal-overlay" id="editCodeModal">
        <div class="modal-content" style="max-width: 400px;">
            <h3 class="modal-title">Reassign Checkpoint Code</h3>
            <p class="modal-text">Enter a new unique alphanumeric code for this checkpoint.</p>
            <form action="client_qrs.php" method="POST">
                <input type="hidden" name="checkpoint_id" id="edit_cp_id">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">New Code</label>
                    <input type="text" name="new_code" id="edit_new_code" class="form-control" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-cancel" onclick="document.getElementById('editCodeModal').classList.remove('show');">Cancel</button>
                    <button type="submit" name="update_code" class="btn-modal" style="background: #3b82f6; color: white;">Update Code</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const limitsData = <?php echo json_encode($limits_data); ?>;

        function updateFormState() {
            const select = document.getElementById('siteSelect');
            const mId = parseInt(select.value);
            const warning = document.getElementById('limit-warning');
            const submitBtn = document.getElementById('submitBtn');

            warning.innerHTML = '';
            submitBtn.disabled = false;

            const limit = limitsData.find(l => parseInt(l.mapping_id) === mId);
            if (!limit) return;

            if (parseInt(limit.is_disabled) === 1) {
                warning.innerHTML = '<div class="alert-warning" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; margin-bottom:15px;">QR creation is disabled for this site.</div>';
                submitBtn.disabled = true;
            } else if (parseInt(limit.current_qrs) >= parseInt(limit.qr_limit) && parseInt(limit.qr_override) === 0) {
                warning.innerHTML = '<div class="alert-warning" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; margin-bottom:15px;">QR limit reached for this site.</div>';
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

        function showEditModal(id, currentCode) {
            document.getElementById('edit_cp_id').value = id;
            document.getElementById('edit_new_code').value = currentCode;
            document.getElementById('editCodeModal').classList.add('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['logoutModal', 'printQRModal', 'editCodeModal'];
            modals.forEach(id => {
                const modal = document.getElementById(id);
                if (event.target == modal) {
                    modal.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>
