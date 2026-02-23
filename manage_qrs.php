<?php
session_start();
include 'db_config.php';

if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'agency') {
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

// Handle creating QR
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_qr'])) {
    $mapping_id = (int)$_POST['agency_client_id'];
    $qr_name = $conn->real_escape_string($_POST['qr_name']);
    
    // First, verify this mapping actually belongs to this agency
    $verify_sql = "SELECT id, qr_limit, qr_override, is_disabled FROM agency_clients WHERE id = $mapping_id AND agency_id = $agency_id";
    $verify_res = $conn->query($verify_sql);
    
    if ($verify_res && $verify_res->num_rows > 0) {
        $mapping = $verify_res->fetch_assoc();
        
        if ($mapping['is_disabled']) {
            $message = "QR creation has been disabled by the admin.";
            $message_type = "error";
        } else {
            // Check current usage
            $count_res = $conn->query("SELECT COUNT(*) as c FROM checkpoints WHERE agency_client_id = $mapping_id");
            $current_usage = $count_res->fetch_assoc()['c'];
            
            if ($current_usage >= $mapping['qr_limit'] && !$mapping['qr_override']) {
                $message = "QR checkpoint limit reached. Contact admin to request more checkpoints.";
                $message_type = "error";
            } else {
                // Allowed to create! Mocking QR data for now since we don't have a library
                $qr_data = "QR_DATA_" . time() . "_" . rand(1000,9999); 
                $insert_sql = "INSERT INTO checkpoints (agency_client_id, name, qr_code_data) VALUES ($mapping_id, '$qr_name', '$qr_data')";
                if ($conn->query($insert_sql)) {
                    $message = "QR Checkpoint '$qr_name' created successfully!";
                    $message_type = "success";
                } else {
                    $message = "Database error: " . $conn->error;
                    $message_type = "error";
                }
            }
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
    SELECT cp.name, cp.created_at, c.username as client_name
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
    <title>Manage QRs - Agency Dashboard</title>
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
    </style>
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
            <li><a href="#" class="nav-link">Reports</a></li>
            <li><a href="#" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Manage QR Checkpoints</h2>
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
                <!-- Create QR Form -->
                <div class="card">
                    <h3 class="card-header">Create New Checkpoint</h3>
                    <?php if (empty($clients_data)): ?>
                        <p style="color: #6b7280;">You have not been assigned any clients yet.</p>
                    <?php else: ?>
                        <form action="manage_qrs.php" method="POST" id="qrForm">
                            <div class="form-group">
                                <label class="form-label" for="agency_client_id">Select Client</label>
                                <select id="agency_client_id" name="agency_client_id" class="form-control" required onchange="updateFormState()">
                                    <option value="" disabled selected>-- Choose Client --</option>
                                    <?php foreach($clients_data as $client): ?>
                                        <option value="<?php echo $client['mapping_id']; ?>">
                                            <?php echo htmlspecialchars($client['client_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="dynamic-warning"></div>

                            <div class="form-group" id="name-group">
                                <label class="form-label" for="qr_name">Checkpoint Name</label>
                                <input type="text" id="qr_name" name="qr_name" class="form-control" placeholder="e.g. North Gate" required>
                            </div>
                            
                            <button type="submit" name="create_qr" id="submit-btn" class="btn">Generate QR Code</button>
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
                                    <strong style="color: #111827;"><?php echo htmlspecialchars($client['client_name']); ?></strong>
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
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($checkpoints_result && $checkpoints_result->num_rows > 0): ?>
                                <?php while($row = $checkpoints_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['client_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo date('M d, Y H:i A', strtotime($row['created_at'])); ?></td>
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

    <!-- JavaScript to handle UX Logic -->
    <script>
        const clientsData = <?php echo json_encode($clients_data); ?>;
        
        function updateFormState() {
            const select = document.getElementById('agency_client_id');
            const mappingId = parseInt(select.value);
            const warningDiv = document.getElementById('dynamic-warning');
            const nameGroup = document.getElementById('name-group');
            const btn = document.getElementById('submit-btn');
            
            warningDiv.innerHTML = '';
            nameGroup.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Generate QR Code';

            if (!mappingId) return;

            const client = clientsData.find(c => parseInt(c.mapping_id) === mappingId);
            if (!client) return;
            
            const limit = parseInt(client.qr_limit);
            const current = parseInt(client.current_qrs);
            const percent = limit > 0 ? (current/limit) * 100 : 0;
            const disabled = parseInt(client.is_disabled) === 1;
            const override = parseInt(client.qr_override) === 1;

            if (disabled) {
                nameGroup.style.display = 'none';
                btn.disabled = true;
                warningDiv.innerHTML = '<div class="alert-warning" style="background-color: #fee2e2; color: #991b1b; border-color: #f87171;">QR creation has been disabled by the admin.</div>';
                return;
            }

            if (percent >= 100 && !override) {
                nameGroup.style.display = 'none';
                btn.disabled = true;
                warningDiv.innerHTML = '<div class="alert-warning" style="background-color: #fee2e2; color: #991b1b; border-color: #f87171;">QR checkpoint limit reached. Contact admin to request more checkpoints.</div>';
                return;
            }

            if (percent >= 80 && !override) {
                warningDiv.innerHTML = '<div class="alert-warning">You are nearing your QR checkpoint limit. ('+current+' / '+limit+' created)</div>';
            }
        }
    </script>
</body>
</html>
