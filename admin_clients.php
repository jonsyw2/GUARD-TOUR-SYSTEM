<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';
$message_type = '';
$show_status_modal = false;

// Ensure agency-level pool limit columns exist
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS agency_qr_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS agency_guard_limit INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS agency_inspector_limit INT DEFAULT 0");

// ── AJAX: Load QR checkpoints for a client mapping ──────────────────────────
if (isset($_GET['ajax_qrs']) && isset($_GET['mapping_id'])) {
    header('Content-Type: application/json');
    $mapping_id = (int)$_GET['mapping_id'];

    // Pull QR limit and client info
    $info_res = $conn->query("
        SELECT ac.qr_limit, ac.company_name, u.username
        FROM agency_clients ac
        JOIN users u ON ac.client_id = u.id
        WHERE ac.id = $mapping_id
        LIMIT 1
    ");
    if (!$info_res || $info_res->num_rows === 0) {
        echo json_encode(['error' => 'Client not found']);
        exit;
    }
    $info = $info_res->fetch_assoc();
    $qr_limit = (int)$info['qr_limit'];

    // Pull existing checkpoints (exclude the Starting Point)
    $cp_res = $conn->query("
        SELECT id, name, checkpoint_code
        FROM checkpoints
        WHERE agency_client_id = $mapping_id
          AND (is_zero_checkpoint = 0 OR is_zero_checkpoint IS NULL)
        ORDER BY id ASC
    ");
    $checkpoints = [];
    if ($cp_res) {
        while ($row = $cp_res->fetch_assoc()) {
            $checkpoints[] = $row;
        }
    }

    echo json_encode([
        'qr_limit'    => $qr_limit,
        'client_name' => $info['company_name'] ?: $info['username'],
        'checkpoints' => $checkpoints,
    ]);
    exit;
}

// ── POST: Save QR edits from admin ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_qrs'])) {
    $mapping_id  = (int)$_POST['mapping_id'];
    $cp_ids      = $_POST['cp_ids']   ?? [];
    $cp_names    = $_POST['cp_names'] ?? [];
    $cp_codes    = $_POST['cp_codes'] ?? [];

    // Verify mapping exists
    $v = $conn->query("SELECT id, qr_limit FROM agency_clients WHERE id = $mapping_id");
    if (!$v || $v->num_rows === 0) {
        $message = 'Invalid client mapping.';
        $message_type = 'error';
        $show_status_modal = true;
    } else {
        $vrow = $v->fetch_assoc();
        $qr_limit = (int)$vrow['qr_limit'];

        $conn->begin_transaction();
        try {
            $saved = 0;
            for ($i = 0; $i < count($cp_names); $i++) {
                $cp_id   = (int)($cp_ids[$i]   ?? 0);
                $cp_name = trim($cp_names[$i]  ?? '');
                $cp_code = trim($cp_codes[$i]  ?? '');

                if ($cp_name === '') continue;  // Skip blank slots

                if ($cp_id > 0) {
                    // Update existing checkpoint
                    $stmt = $conn->prepare(
                        'UPDATE checkpoints SET name = ?, checkpoint_code = ? WHERE id = ? AND agency_client_id = ?'
                    );
                    $stmt->bind_param('ssii', $cp_name, $cp_code, $cp_id, $mapping_id);
                    $stmt->execute();
                } else {
                    // Insert new checkpoint (only if within limit)
                    $count_res = $conn->query("SELECT COUNT(*) as cnt FROM checkpoints WHERE agency_client_id = $mapping_id AND (is_zero_checkpoint = 0 OR is_zero_checkpoint IS NULL)");
                    $cnt = (int)$count_res->fetch_assoc()['cnt'];
                    if ($qr_limit > 0 && $cnt >= $qr_limit) continue;

                    // Auto-generate code if blank
                    if ($cp_code === '') {
                        $cp_code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                    }
                    $stmt = $conn->prepare(
                        'INSERT INTO checkpoints (agency_client_id, name, checkpoint_code) VALUES (?, ?, ?)'
                    );
                    $stmt->bind_param('iss', $mapping_id, $cp_name, $cp_code);
                    $stmt->execute();
                }
                $saved++;
            }
            $conn->commit();
            $message = "QR checkpoints saved successfully ($saved updated/added).";
            $message_type = 'success';
            $show_status_modal = true;
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error saving QRs: ' . $e->getMessage();
            $message_type = 'error';
            $show_status_modal = true;
        }
    }
}


// Handle Toggle Suspend
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $user_id    = (int)$_POST['user_id'];
    $new_status = $_POST['new_status'] === 'suspended' ? 'suspended' : 'active';
    if ($conn->query("UPDATE users SET status = '$new_status' WHERE id = $user_id AND user_level = 'client'")) {
        $message = "Client status updated to '$new_status'.";
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error updating status.";
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Delete Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_client'])) {
    $user_id = (int)$_POST['user_id'];
    $conn->begin_transaction();
    try {
        $maps = $conn->query("SELECT id FROM agency_clients WHERE client_id = $user_id");
        while ($m = $maps->fetch_assoc()) {
            $mid = $m['id'];
            $conn->query("DELETE FROM guard_assignments WHERE agency_client_id = $mid");
            $conn->query("DELETE FROM inspector_assignments WHERE agency_client_id = $mid");
            $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mid");
            $conn->query("DELETE FROM shifts WHERE agency_client_id = $mid");
            $conn->query("DELETE FROM checkpoints WHERE agency_client_id = $mid");
        }
        $conn->query("DELETE FROM agency_clients WHERE client_id = $user_id");
        $conn->query("DELETE FROM users WHERE id = $user_id AND user_level = 'client'");
        $conn->commit();
        $message = "Client deleted successfully.";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Deletion error: " . $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Handle Sequence Change Requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['handle_sequence_request'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $action     = $_POST['action']; // 'approve' or 'deny'
    
    if ($action === 'approve') {
        $sql = "UPDATE agency_clients SET sequence_change_request = 'approved' WHERE id = $mapping_id";
        $msg = "Sequence change request approved.";
    } else {
        $sql = "UPDATE agency_clients SET sequence_change_request = 'none' WHERE id = $mapping_id";
        $msg = "Sequence change request denied.";
    }
    
    if ($conn->query($sql)) {
        $message = $msg;
        $message_type = "success";
        $show_status_modal = true;
    } else {
        $message = "Error handling request: " . $conn->error;
        $message_type = "error";
        $show_status_modal = true;
    }
}

// Fetch all agencies
$agencies_res = $conn->query("SELECT id, username, agency_name, client_limit FROM users WHERE user_level = 'agency' ORDER BY agency_name ASC");
$agencies = [];
while ($a = $agencies_res->fetch_assoc()) $agencies[] = $a;

// Fetch all clients with agency info
$clients_sql = "
    SELECT u.id as user_id, u.username, u.status,
           ac.id as mapping_id, ac.company_name, ac.company_address, ac.contact_no, ac.email_address, ac.contact_person,
           ac.is_sequence_fixed, ac.sequence_change_request,
           ag.id as agency_id, ag.agency_name, ag.username as agency_username
    FROM users u
    JOIN agency_clients ac ON u.id = ac.client_id
    JOIN users ag ON ac.agency_id = ag.id
    WHERE u.user_level = 'client'
    ORDER BY ag.agency_name ASC, ac.id ASC
";
$clients_res = $conn->query($clients_sql);
$clients = [];
if ($clients_res) while ($r = $clients_res->fetch_assoc()) $clients[] = $r;

// Fetch pending sequence requests
$pending_requests = array_filter($clients, fn($c) => $c['sequence_change_request'] === 'pending');

$page_title = 'Client Management';
$header_title = 'Client Management';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>


    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="content-area">

            <!-- Pending Requests Section -->
            <?php if (!empty($pending_requests)): ?>
            <div style="margin-bottom: 32px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                    <div style="width: 12px; height: 12px; background: #3b82f6; border-radius: 50%; box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);"></div>
                    <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b;">Sequence Change Requests (<?php echo count($pending_requests); ?>)</h3>
                </div>
                <div class="card" style="border-left: 4px solid #3b82f6;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                                <th style="padding: 12px 16px; font-size: 0.75rem; text-transform: uppercase; color: #64748b;">Client / Site</th>
                                <th style="padding: 12px 16px; font-size: 0.75rem; text-transform: uppercase; color: #64748b;">Agency</th>
                                <th style="padding: 12px 16px; font-size: 0.75rem; text-transform: uppercase; color: #64748b;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $pr): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px 16px;">
                                    <div style="font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($pr['company_name'] ?: $pr['username']); ?></div>
                                    <div style="font-size: 0.75rem; color: #64748b;">Username: <?php echo htmlspecialchars($pr['username']); ?></div>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span style="background: #ede9fe; color: #6d28d9; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($pr['agency_name'] ?: $pr['agency_username']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="display: flex; gap: 8px;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="mapping_id" value="<?php echo $pr['mapping_id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" name="handle_sequence_request" style="padding: 6px 16px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">Approve</button>
                                        </form>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="mapping_id" value="<?php echo $pr['mapping_id']; ?>">
                                            <input type="hidden" name="action" value="deny">
                                            <button type="submit" name="handle_sequence_request" style="padding: 6px 16px; border: 1px solid #e2e8f0; background: white; color: #64748b; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">Deny</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <div style="display: flex; align-items: flex-end; gap: 16px; margin-bottom: 28px;">
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Filter by Agency</label>
                    <select id="agencyFilter" onchange="filterByAgency()" class="form-control" style="font-weight: 600; font-size: 0.95rem; border-radius: 10px; cursor: pointer;">
                        <option value="">— All Agencies —</option>
                        <?php foreach ($agencies as $ag):
                            $ag_count = count(array_filter($clients, fn($c) => $c['agency_id'] == $ag['id']));
                        ?>
                            <option value="<?php echo $ag['id']; ?>" data-name="<?php echo htmlspecialchars($ag['agency_name'] ?: $ag['username']); ?>">
                                <?php echo htmlspecialchars($ag['agency_name'] ?: $ag['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Agency Banner -->
            <div id="agencyBanner" style="display: none; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 10px; padding: 14px 20px; margin-bottom: 20px; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="background: #ede9fe; color: #6d28d9; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">AGENCY</span>
                    <span id="bannerAgencyName" style="font-weight: 700; color: #1e293b; font-size: 1rem;"></span>
                </div>
                <span id="bannerClientCount" style="font-size: 0.85rem; color: #64748b; font-weight: 600;"></span>
            </div>

            <!-- Clients Table -->
            <div class="card">
                <div class="table-container">
                    <?php if (empty($clients)): ?>
                        <div style="text-align: center; padding: 60px;">
                            <div style="font-size: 3rem; margin-bottom: 16px;">📭</div>
                            <h3 style="color: #64748b; font-weight: 600;">No clients found</h3>
                            <p style="color: #94a3b8; margin-top: 8px;">Add your first client using the button above.</p>
                        </div>
                    <?php else: ?>
                    <table id="clientsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Username</th>
                                <th>Company Name</th>
                                <th>Agency</th>
                                <th>Contact Person</th>
                                <th>Contact No.</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="clientsTableBody">
                            <?php foreach ($clients as $i => $c): ?>
                            <tr data-agency-id="<?php echo $c['agency_id']; ?>">
                                <td style="color: #94a3b8; font-size: 0.85rem;" class="row-num"><?php echo $i + 1; ?></td>
                                <td>
                                    <code style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($c['username']); ?>
                                    </code>
                                </td>
                                <td>
                                    <a href="agency_maintenance.php?highlight_id=<?php echo $c['agency_id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                                        <strong><?php echo htmlspecialchars($c['company_name'] ?: '—'); ?></strong>
                                        <?php if ($c['company_address']): ?>
                                            <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;"><?php echo htmlspecialchars($c['company_address']); ?></div>
                                        <?php endif; ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="agency_maintenance.php?highlight_id=<?php echo $c['agency_id']; ?>" style="text-decoration: none; display: inline-block;">
                                        <span style="background: #ede9fe; color: #6d28d9; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#ddd6fe'" onmouseout="this.style.background='#ede9fe'">
                                            <?php echo htmlspecialchars($c['agency_name'] ?: $c['agency_username']); ?>
                                        </span>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($c['contact_person'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($c['contact_no'] ?: '—'); ?></td>
                                <td>
                                    <?php if (($c['status'] ?? 'active') === 'suspended'): ?>
                                        <span class="status-badge status-danger">Suspended</span>
                                    <?php else: ?>
                                        <span class="status-badge status-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <!-- Manage QRs Button -->
                                        <button type="button"
                                            onclick="openQrModal(<?php echo $c['mapping_id']; ?>, '<?php echo addslashes(htmlspecialchars($c['company_name'] ?: $c['username'])); ?>')"
                                            style="padding: 5px 12px; background: #6366f1; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display:flex; align-items:center; gap:4px;">
                                            🔲 Manage QRs
                                        </button>
                                        <?php if (($c['status'] ?? 'active') === 'suspended'): ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="toggle_status" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo $c['user_id']; ?>">
                                                <input type="hidden" name="new_status" value="active">
                                                <button type="submit" style="padding: 5px 12px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer;">Restore</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" onsubmit="CustomModal.confirmForm(event, 'Suspend this client?')" style="margin:0;">
                                                <input type="hidden" name="toggle_status" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo $c['user_id']; ?>">
                                                <input type="hidden" name="new_status" value="suspended">
                                                <button type="submit" style="padding: 5px 12px; background: #f59e0b; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer;">Suspend</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="CustomModal.confirmForm(event, 'Permanently delete this client and all their data? This cannot be undone.')" style="margin:0;">
                                            <input type="hidden" name="delete_client" value="1">
                                            <input type="hidden" name="user_id" value="<?php echo $c['user_id']; ?>">
                                            <button type="submit" style="padding: 5px 12px; background: #ef4444; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer;">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="noResultsMsg" style="display: none; text-align: center; padding: 48px; color: #94a3b8;">
                        <div style="font-size: 2.5rem; margin-bottom: 12px;">🔍</div>
                        <p style="font-weight: 600;">No clients found for this agency.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <script>
        function filterByAgency() {
            const sel = document.getElementById('agencyFilter');
            const agencyId = sel.value;
            const agencyName = sel.options[sel.selectedIndex].dataset.name || '';
            const rows = document.querySelectorAll('#clientsTableBody tr');
            const banner = document.getElementById('agencyBanner');
            const noMsg = document.getElementById('noResultsMsg');
            const table = document.getElementById('clientsTable');
            let visible = 0;

            rows.forEach(row => {
                const show = !agencyId || row.dataset.agencyId === agencyId;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            let num = 1;
            rows.forEach(row => {
                if (row.style.display !== 'none') row.querySelector('.row-num').textContent = num++;
            });

            if (agencyId) {
                document.getElementById('bannerAgencyName').textContent = agencyName;
                document.getElementById('bannerClientCount').textContent = visible + ' client' + (visible !== 1 ? 's' : '');
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }

            if (table) table.style.display = visible === 0 ? 'none' : '';
            noMsg.style.display = visible === 0 ? 'block' : 'none';
        }
    </script>

    <!-- ════════════════════════════════════════════
         QR MANAGEMENT MODAL
    ═══════════════════════════════════════════════ -->
    <style>
        /* QR Modal overlay */
        #qrModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
            padding: 20px;
            animation: qrFadeIn 0.2s ease;
        }
        #qrModal.show { display: flex; }
        @keyframes qrFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        #qrModalBox {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 860px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 60px rgba(0,0,0,0.25);
            animation: qrSlideUp 0.25s cubic-bezier(.4,0,.2,1);
        }
        @keyframes qrSlideUp {
            from { transform: translateY(30px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        #qrModalHeader {
            padding: 20px 24px 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        #qrModalHeader h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #qrModalHeader .qr-badge {
            background: #ede9fe;
            color: #6d28d9;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            letter-spacing: 0.04em;
        }
        #qrModalCloseBtn {
            background: #f1f5f9;
            border: none;
            border-radius: 8px;
            width: 34px;
            height: 34px;
            font-size: 1.2rem;
            cursor: pointer;
            color: #64748b;
            transition: background 0.15s, color 0.15s;
            line-height: 1;
        }
        #qrModalCloseBtn:hover { background: #e2e8f0; color: #0f172a; }

        #qrModalMeta {
            padding: 12px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 24px;
            flex-shrink: 0;
            font-size: 0.86rem;
            color: #475569;
        }
        #qrModalMeta span strong { color: #1e293b; }

        #qrModalBody {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
        }

        /* QR slots table */
        .qr-slots-table { width: 100%; border-collapse: collapse; }
        .qr-slots-table thead tr {
            background: #f1f5f9;
            border-bottom: 2px solid #e2e8f0;
        }
        .qr-slots-table th {
            padding: 10px 14px;
            text-align: left;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
        }
        .qr-slots-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .qr-slots-table tbody tr:hover { background: #fafbff; }
        .qr-slots-table .slot-num {
            font-size: 0.8rem;
            font-weight: 700;
            color: #94a3b8;
            text-align: center;
            width: 36px;
        }
        .qr-slot-input {
            width: 100%;
            padding: 7px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            font-size: 0.88rem;
            color: #1e293b;
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
            font-family: inherit;
        }
        .qr-slot-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .qr-slot-input.code-input {
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            letter-spacing: 0.05em;
            color: #6d28d9;
        }
        .qr-slot-status {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }
        .qr-slot-status.existing {
            background: #d1fae5;
            color: #065f46;
        }
        .qr-slot-status.empty {
            background: #f1f5f9;
            color: #94a3b8;
        }
        .qr-auto-badge {
            font-size: 0.68rem;
            color: #94a3b8;
            margin-left: 4px;
        }

        /* Modal footer */
        #qrModalFooter {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            gap: 12px;
        }
        #qrModalFooter .hint {
            font-size: 0.78rem;
            color: #94a3b8;
        }
        #qrSaveBtn {
            padding: 9px 24px;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #qrSaveBtn:hover { opacity: 0.9; transform: translateY(-1px); }
        #qrSaveBtn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* Loading spinner */
        .qr-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 48px;
            color: #64748b;
            font-size: 0.95rem;
        }
        .qr-spinner::before {
            content: '';
            width: 24px;
            height: 24px;
            border: 3px solid #e2e8f0;
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Empty state */
        .qr-no-limit {
            text-align: center;
            padding: 48px 24px;
            color: #94a3b8;
        }
        .qr-no-limit .icon { font-size: 2.5rem; margin-bottom: 12px; }
    </style>

    <div id="qrModal">
        <div id="qrModalBox">
            <div id="qrModalHeader">
                <h3>
                    🔲 QR Checkpoint Manager
                    <span class="qr-badge" id="qrClientBadge">—</span>
                </h3>
                <button id="qrModalCloseBtn" onclick="closeQrModal()" title="Close">✕</button>
            </div>
            <div id="qrModalMeta">
                <span>Client: <strong id="qrMetaClient">—</strong></span>
                <span>QR Limit: <strong id="qrMetaLimit">—</strong></span>
                <span>Used: <strong id="qrMetaUsed">—</strong></span>
            </div>
            <div id="qrModalBody">
                <div class="qr-spinner">Loading QR data…</div>
            </div>
            <div id="qrModalFooter">
                <span class="hint">💡 Leave a row blank to skip it. QR codes auto-generate if not set.</span>
                <button id="qrSaveBtn" onclick="submitQrForm()" disabled>💾 Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Hidden form used to submit QR saves -->
    <form id="qrSaveForm" method="POST" style="display:none;">
        <input type="hidden" name="save_qrs" value="1">
        <input type="hidden" name="mapping_id" id="qrSaveFormMappingId">
        <div id="qrSaveFormFields"></div>
    </form>

    <script>
        let _qrMappingId = null;

        function openQrModal(mappingId, clientName) {
            _qrMappingId = mappingId;
            document.getElementById('qrModal').classList.add('show');
            document.getElementById('qrClientBadge').textContent = clientName;
            document.getElementById('qrMetaClient').textContent  = clientName;
            document.getElementById('qrMetaLimit').textContent   = '…';
            document.getElementById('qrMetaUsed').textContent    = '…';
            document.getElementById('qrModalBody').innerHTML     = '<div class="qr-spinner">Loading QR data…</div>';
            document.getElementById('qrSaveBtn').disabled        = true;

            fetch('admin_clients.php?ajax_qrs=1&mapping_id=' + mappingId)
                .then(r => r.json())
                .then(data => renderQrSlots(data, mappingId))
                .catch(() => {
                    document.getElementById('qrModalBody').innerHTML =
                        '<div class="qr-no-limit"><div class="icon">⚠️</div>Failed to load QR data.</div>';
                });
        }

        function closeQrModal() {
            document.getElementById('qrModal').classList.remove('show');
            _qrMappingId = null;
        }

        // Close on backdrop click
        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) closeQrModal();
        });

        function renderQrSlots(data, mappingId) {
            const limit      = data.qr_limit  ?? 0;
            const existing   = data.checkpoints ?? [];
            const usedCount  = existing.length;

            document.getElementById('qrMetaLimit').textContent = limit > 0 ? limit + ' QRs' : 'No limit set';
            document.getElementById('qrMetaUsed').textContent  = usedCount + ' / ' + (limit > 0 ? limit : '∞');

            if (limit === 0) {
                document.getElementById('qrModalBody').innerHTML =
                    '<div class="qr-no-limit">' +
                    '<div class="icon">🔒</div>' +
                    '<strong>No QR limit configured</strong>' +
                    '<p style="margin-top:8px;font-size:0.85rem;">Set a QR limit in <em>QR Limit Control</em> first.</p>' +
                    '</div>';
                document.getElementById('qrSaveBtn').disabled = true;
                return;
            }

            // Build the editable table
            let html = '<form id="qrInlineForm">';
            html += '<table class="qr-slots-table">';
            html += '<thead><tr>' +
                    '<th style="width:40px;text-align:center;">#</th>' +
                    '<th>Checkpoint Name</th>' +
                    '<th style="width:200px;">QR Code / Key</th>' +
                    '<th style="width:90px;text-align:center;">Status</th>' +
                    '</tr></thead><tbody>';

            for (let i = 0; i < limit; i++) {
                const cp         = existing[i] ?? null;
                const isExisting = cp !== null;
                const cpId       = isExisting ? cp.id : 0;
                const cpName     = isExisting ? escHtml(cp.name) : '';
                const cpCode     = isExisting ? escHtml(cp.checkpoint_code) : '';
                const statusHtml = isExisting
                    ? '<span class="qr-slot-status existing">✓ Existing</span>'
                    : '<span class="qr-slot-status empty">Empty</span>';

                html += `<tr>
                    <td class="slot-num">${i + 1}</td>
                    <td>
                        <input type="text" class="qr-slot-input"
                            data-idx="${i}"
                            data-cpid="${cpId}"
                            data-field="name"
                            value="${cpName}"
                            placeholder="e.g. Main Entrance"
                            oninput="syncRow(${i})"
                        />
                    </td>
                    <td style="display: flex; align-items: center; gap: 8px;">
                        <div style="flex: 1; position: relative;">
                            <input type="text" class="qr-slot-input code-input"
                                data-idx="${i}"
                                data-cpid="${cpId}"
                                data-field="code"
                                value="${cpCode}"
                                placeholder="${isExisting ? '' : '(auto-generate)'}"
                                oninput="syncRow(${i})"
                                style="padding-right: 32px;"
                            />
                            ${!isExisting ? '<span class="qr-auto-badge">auto</span>' : ''}
                        </div>
                        ${isExisting ? `
                            <button type="button" 
                                onclick="console.log('Eye clicked', '${escJs(cp.checkpoint_code)}'); CustomModal.showQRCode('${escJs(cp.checkpoint_code)}', '${escJs(cp.name)}', '${escJs(data.client_name)}')"
                                title="View QR Code"
                                style="width: 32px; height: 32px; flex-shrink:0; background: #f1f5f9; border: 1.5px solid #e2e8f0; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #64748b; transition: all 0.2s;"
                                onmouseover="this.style.background='#e2e8f0'; this.style.color='#1e293b'"
                                onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'">
                                👁️
                            </button>
                        ` : ''}
                    </td>
                    <td style="text-align:center;" id="qr-status-${i}">${statusHtml}</td>
                </tr>`;
            }

            html += '</tbody></table></form>';
            document.getElementById('qrModalBody').innerHTML = html;
            document.getElementById('qrSaveBtn').disabled = false;
        }

        function syncRow(idx) {
            // When user types in an empty slot, change status label to "Editing"
            const nameInput = document.querySelector(`[data-idx="${idx}"][data-field="name"]`);
            const cpId      = parseInt(nameInput.dataset.cpid);
            const statusEl  = document.getElementById('qr-status-' + idx);
            if (!statusEl) return;

            if (cpId === 0 && nameInput.value.trim() !== '') {
                statusEl.innerHTML = '<span class="qr-slot-status" style="background:#fef3c7;color:#92400e;">✏️ New</span>';
            } else if (cpId === 0) {
                statusEl.innerHTML = '<span class="qr-slot-status empty">Empty</span>';
            } else {
                statusEl.innerHTML = '<span class="qr-slot-status" style="background:#e0e7ff;color:#3730a3;">✏️ Editing</span>';
            }
        }

        function submitQrForm() {
            if (!_qrMappingId) return;

            const rows  = document.querySelectorAll('#qrInlineForm tbody tr');
            const fields = document.getElementById('qrSaveFormFields');
            fields.innerHTML = '';

            rows.forEach((tr, i) => {
                const nameInput = tr.querySelector('[data-field="name"]');
                const codeInput = tr.querySelector('[data-field="code"]');
                const cpId      = nameInput ? parseInt(nameInput.dataset.cpid) : 0;

                const addHidden = (name, val) => {
                    const inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = name;
                    inp.value = val;
                    fields.appendChild(inp);
                };

                addHidden('cp_ids[]',   cpId);
                addHidden('cp_names[]', nameInput ? nameInput.value : '');
                addHidden('cp_codes[]', codeInput ? codeInput.value : '');
            });

            document.getElementById('qrSaveFormMappingId').value = _qrMappingId;
            document.getElementById('qrSaveBtn').disabled = true;
            document.getElementById('qrSaveBtn').textContent = '⏳ Saving…';
            document.getElementById('qrSaveForm').submit();
        }

        function escHtml(str) {
            return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function escJs(str) {
            return (str ? String(str) : '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/`/g, '\\`');
        }
    </script>


    <!-- Status Modal -->
    <div id="statusModal" class="modal <?php echo $show_status_modal ? 'show' : ''; ?>">
        <div class="modal-content">
            <div style="width: 60px; height: 60px; background: <?php echo $message_type === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem;">
                <?php echo $message_type === 'success' ? '✓' : '!'; ?>
            </div>
            <h3 style="margin-bottom: 10px;"><?php echo $message_type === 'success' ? 'Success!' : 'Notice'; ?></h3>
            <p style="color: #6b7280; margin-bottom: 24px;"><?php echo $message; ?></p>
            <button class="btn btn-primary" onclick="document.getElementById('statusModal').classList.remove('show')">Done</button>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn" style="background: #f1f5f9; color: #475569; flex: 1;" onclick="document.getElementById('logoutModal').classList.remove('show')">Cancel</button>
                <a href="logout.php" class="btn btn-primary" style="flex: 1; text-decoration: none; background: #ef4444;">Log Out</a>
            </div>
        </div>
    </div>

    <?php include 'includes/common_modals.php'; ?>
    <?php include 'admin_layout/footer.php'; ?>
</html>
