<?php
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

$message = '';
$message_type = '';

// Handle Updating Client QR Limits
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_limit'])) {
    $mapping_id = (int)$_POST['agency_client_id'];
    $new_limit = (int)$_POST['qr_limit'];
    $site_name = $conn->real_escape_string($_POST['site_name']);
    
    // First, verify this mapping actually belongs to this agency
    $verify_sql = "SELECT id FROM agency_clients WHERE id = $mapping_id AND agency_id = $agency_id";
    $verify_res = $conn->query($verify_sql);
    
    if ($verify_res && $verify_res->num_rows > 0) {
        $update_sql = "UPDATE agency_clients SET qr_limit = $new_limit, site_name = '$site_name' WHERE id = $mapping_id";
        if ($conn->query($update_sql)) {
            $message = "QR limit and site name updated successfully!";
            $message_type = "success";
        } else {
            $message = "Database error: " . $conn->error;
            $message_type = "error";
        }
    } else {
        $message = "Invalid client selection.";
        $message_type = "error";
    }
}


// Fetch assigned clients and their limits
$clients_sql = "
    SELECT 
        ac.id as mapping_id, 
        c.username AS client_name, 
        ac.site_name,
        ac.qr_limit, 
        ac.qr_override, 
        ac.is_disabled,
        (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id) as current_qrs
    FROM agency_clients ac
    JOIN users c ON ac.client_id = c.id
    WHERE ac.agency_id = $agency_id
    ORDER BY c.username ASC
";
$clients_result = $conn->query($clients_sql);
$clients_data = [];
if ($clients_result && $clients_result->num_rows > 0) {
    while($row = $clients_result->fetch_assoc()) {
        $clients_data[] = $row;
    }
}

// Fetch all checkpoints total for table view
$checkpoints_sql = "
    SELECT cp.id, cp.name, cp.checkpoint_code, cp.created_at, c.username as client_name, ac.site_name
    FROM checkpoints cp
    JOIN agency_clients ac ON cp.agency_client_id = ac.id
    JOIN users c ON ac.client_id = c.id
    WHERE ac.agency_id = $agency_id
    ORDER BY cp.created_at DESC
";
$checkpoints_result = $conn->query($checkpoints_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Client QR Limits - Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
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
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; }
        .btn-confirm:hover { background: #be123c; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #d1fae5; color: #10b981; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; display: flex; align-items: center; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .alert-warning { background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 12px 16px; margin-top: 12px;}

        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px;}
        @media (max-width: 768px) { .grid-container { grid-template-columns: 1fr; } }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-sm: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #10b981; }
        .btn { padding: 10px 18px; background-color: #10b981; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: background-color 0.2s; width: 100%; font-size: 1rem; }
        .btn:hover { background-color: #059669; }
        .btn:disabled { background-color: #9ca3af; cursor: not-allowed; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }
        
        /* Client cards */
        .client-status-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 12px; }
        .progress-bar { width: 100%; height: 8px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden; margin-top: 8px;}
        .progress-fill { height: 100%; background-color: #10b981; transition: width 0.3s; }
        .progress-fill.warning { background-color: #f59e0b; }
        .progress-fill.danger { background-color: #ef4444; }

        /* Print styles */
        @media print {
            .sidebar, .topbar, .card:not(.print-card), .alert, .btn:not(.no-print) { display: none !important; }
            body { background: white; }
            .content-area { padding: 0; }
            .print-card { box-shadow: none !important; border: none !important; }
        }
        
        .qr-display { padding: 20px; text-align: center; }
        .qr-img { margin: 0 auto; display: block; }
        .code-display { font-family: monospace; font-size: 1.5rem; font-weight: bold; margin-top: 10px; }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Agency Portal
        </div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_qrs.php" class="nav-link active">Manage QRs</a></li>
            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Manage Client QR Limits</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Agency'; ?></strong></span>
                <span class="badge">AGENCY</span>
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
                <!-- Update QR Limit Form -->
                <div class="card">
                    <h3 class="card-header">Set Client QR Limit</h3>
                    <?php if (empty($clients_data)): ?>
                        <p style="color: #6b7280;">You have not been assigned any clients yet.</p>
                    <?php else: ?>
                        <p style="font-size: 0.9rem; color: #6b7280; margin-bottom: 20px;">
                            Specify the maximum number of QR checkpoints each client site is allowed to create.
                        </p>
                        <form action="manage_qrs.php" method="POST" id="qrLimitForm">
                            <div class="form-group">
                                <label class="form-label" for="agency_client_id">Select Client Site</label>
                                <select id="agency_client_id" name="agency_client_id" class="form-control" required onchange="updateLimitForm()">
                                    <option value="" disabled selected>-- Choose Client --</option>
                                    <?php foreach($clients_data as $client): ?>
                                        <option value="<?php echo $client['mapping_id']; ?>">
                                            <?php echo htmlspecialchars($client['client_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="site_name">Site Name / Description</label>
                                <input type="text" id="site_name" name="site_name" class="form-control" placeholder="e.g. Main Factory, West Wing" required>
                                <small style="color: #6b7280; font-size: 0.75rem;">This label will appear in the client's dropdown.</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="qr_limit">QR Checkpoint Limit</label>
                                <input type="number" id="qr_limit" name="qr_limit" class="form-control" min="1" placeholder="e.g. 10" required>
                                <small style="color: #6b7280; font-size: 0.75rem;">This client can create up to this number of QR checkpoints.</small>
                            </div>
                            
                            <button type="submit" name="update_limit" class="btn">Update Site Configuration</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Client Usage Stats -->
                <div class="card">
                    <h3 class="card-header">Client Limits & Usage</h3>
                    <?php if (empty($clients_data)): ?>
                        <p style="color: #6b7280;">No data to display.</p>
                    <?php else: ?>
                        <?php foreach($clients_data as $client): ?>
                            <?php 
                                $limit = $client['qr_limit'];
                                $current = $client['current_qrs'];
                                $percent = ($limit > 0) ? ($current / $limit) * 100 : 0;
                                
                                $fill_class = '';
                                if ($percent >= 100) $fill_class = 'danger';
                                else if ($percent >= 80) $fill_class = 'warning';
                            ?>
                            <div class="client-status-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <strong style="color: #111827;">
                                        <?php echo htmlspecialchars($client['client_name']); ?>
                                        <?php if ($client['site_name']): ?>
                                            <span style="font-weight: normal; color: #6b7280; font-size: 0.85rem;"> - <?php echo htmlspecialchars($client['site_name']); ?></span>
                                        <?php endif; ?>
                                    </strong>
                                    <span style="font-size: 0.85rem; color: #4b5563;">
                                        <?php if($client['is_disabled']): ?>
                                            <span style="color: #ef4444; font-weight: bold;">DISABLED</span>
                                        <?php else: ?>
                                            <?php echo $current; ?> / <?php echo $limit; ?> QRs
                                            <?php if($client['qr_override']) echo "<span style='color:#10b981; margin-left:5px;'>(Override)</span>"; ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $fill_class; ?>" style="width: <?php echo min(100, $percent); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- List of checkpoints -->
            <div class="card">
                <h3 class="card-header">Your Active Checkpoints</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Checkpoint Name</th>
                                <th>Code</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($checkpoints_result && $checkpoints_result->num_rows > 0): ?>
                                <?php while($row = $checkpoints_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['client_name']); ?></strong>
                                            <?php if ($row['site_name']): ?>
                                                <div style="font-size: 0.8rem; color: #6b7280;"><?php echo htmlspecialchars($row['site_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($row['checkpoint_code']); ?></code></td>
                                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <button class="btn" style="padding: 6px 12px; font-size: 0.8rem;" onclick="showPrintModal('<?php echo $row['checkpoint_code']; ?>', '<?php echo $row['name']; ?>', '<?php echo $row['client_name']; ?>')">Print QR</button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="empty-state">No checkpoints created yet.</td>
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
                <h4 id="clientLabel" style="margin-bottom: 5px; color: #111827;"></h4>
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

    <!-- Edit Code Modal Removed as Clients now manage their checkpoints -->

    <!-- JavaScript to handle UX Logic -->
    <script>
        const clientsData = <?php echo json_encode($clients_data); ?>;
        
        function updateLimitForm() {
            const select = document.getElementById('agency_client_id');
            const mappingId = parseInt(select.value);
            const limitInput = document.getElementById('qr_limit');
            const siteNameInput = document.getElementById('site_name');
            
            if (!mappingId) return;

            const client = clientsData.find(c => parseInt(c.mapping_id) === mappingId);
            if (client) {
                limitInput.value = client.qr_limit;
                siteNameInput.value = client.site_name || '';
            }
        }

        function showPrintModal(code, name, client) {
            document.getElementById('clientLabel').textContent = client;
            document.getElementById('checkpointLabel').textContent = name;
            document.getElementById('codeLabel').textContent = code;
            
            // Clear previous QR
            document.getElementById('qrcode').innerHTML = '';
            
            // Generate New QR
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
            const modals = ['logoutModal', 'printQRModal'];
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
