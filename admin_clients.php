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

// Handle Add Client
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_client'])) {
    $client_username         = $conn->real_escape_string(trim($_POST['client_username']));
    $client_password         = password_hash($_POST['client_password'], PASSWORD_DEFAULT);
    $agency_id               = (int)$_POST['agency_id'];
    $company_name            = $conn->real_escape_string($_POST['company_name'] ?? '');
    $company_address         = $conn->real_escape_string($_POST['company_address'] ?? '');
    $contact_no              = $conn->real_escape_string($_POST['contact_no'] ?? '');
    $email_address           = $conn->real_escape_string($_POST['email_address'] ?? '');
    $contact_person          = $conn->real_escape_string($_POST['contact_person'] ?? '');
    $website_link            = $conn->real_escape_string($_POST['website_link'] ?? '');
    $contact_person_position = $conn->real_escape_string($_POST['contact_person_position'] ?? '');
    $contact_person_no       = $conn->real_escape_string($_POST['contact_person_no'] ?? '');

    $conn->begin_transaction();
    try {
        $chk = $conn->query("SELECT id FROM users WHERE username = '$client_username'");
        if ($chk && $chk->num_rows > 0) throw new Exception("Username '$client_username' is already taken.");

        $lim_res = $conn->query("SELECT client_limit FROM users WHERE id = $agency_id");
        $cl_limit = ($lim_res && $lr = $lim_res->fetch_assoc()) ? (int)$lr['client_limit'] : 0;
        $cnt_res  = $conn->query("SELECT COUNT(DISTINCT client_id) as c FROM agency_clients WHERE agency_id = $agency_id");
        $cl_count = ($cnt_res && $cr = $cnt_res->fetch_assoc()) ? (int)$cr['c'] : 0;
        if ($cl_limit > 0 && $cl_count >= $cl_limit) {
            throw new Exception("Agency has reached the client limit ($cl_limit). Increase it first.");
        }

        if (!$conn->query("INSERT INTO users (username, password, user_level) VALUES ('$client_username', '$client_password', 'client')"))
            throw new Exception("Error creating user: " . $conn->error);
        $new_client_id = $conn->insert_id;

        if (!$conn->query("INSERT INTO agency_clients (agency_id, client_id, company_name, company_address, contact_no, email_address, contact_person, website_link, contact_person_position, contact_person_no)
                           VALUES ($agency_id, $new_client_id, '$company_name', '$company_address', '$contact_no', '$email_address', '$contact_person', '$website_link', '$contact_person_position', '$contact_person_no')"))
            throw new Exception("Error creating client profile: " . $conn->error);

        $conn->commit();
        $message = "Client '$client_username' created and assigned to agency successfully!";
        $message_type = "success";
        $show_status_modal = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
        $show_status_modal = true;
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
                <button onclick="document.getElementById('addClientModal').classList.add('show')"
                        style="padding: 12px 24px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 700; cursor: pointer; white-space: nowrap; height: 48px;">
                    ＋ Add New Client
                </button>
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
                                    <strong><?php echo htmlspecialchars($c['company_name'] ?: '—'); ?></strong>
                                    <?php if ($c['company_address']): ?>
                                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;"><?php echo htmlspecialchars($c['company_address']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="background: #ede9fe; color: #6d28d9; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($c['agency_name'] ?: $c['agency_username']); ?>
                                    </span>
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
                                    <div style="display: flex; gap: 6px;">
                                        <?php if (($c['status'] ?? 'active') === 'suspended'): ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="user_id" value="<?php echo $c['user_id']; ?>">
                                                <input type="hidden" name="new_status" value="active">
                                                <button type="submit" name="toggle_status" style="padding: 5px 12px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer;">Restore</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" onsubmit="CustomModal.confirmForm(event, 'Suspend this client?')" style="margin:0;">
                                                <input type="hidden" name="user_id" value="<?php echo $c['user_id']; ?>">
                                                <input type="hidden" name="new_status" value="suspended">
                                                <button type="submit" name="toggle_status" style="padding: 5px 12px; background: #f59e0b; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer;">Suspend</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="CustomModal.confirmForm(event, 'Permanently delete this client and all their data? This cannot be undone.')" style="margin:0;">
                                            <input type="hidden" name="user_id" value="<?php echo $c['user_id']; ?>">
                                            <button type="submit" name="delete_client" style="padding: 5px 12px; background: #ef4444; color: white; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer;">Delete</button>
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

    <!-- Add Client Modal -->
    <div id="addClientModal" class="modal">
        <div class="modal-content" style="max-width: 640px; text-align: left; padding: 32px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px;">
                <h3 style="margin: 0; font-size: 1.2rem; color: #1e293b;">Add New Client</h3>
                <button type="button" onclick="document.getElementById('addClientModal').classList.remove('show')"
                        style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af; line-height: 1;">&times;</button>
            </div>
            <form action="admin_clients.php" method="POST" autocomplete="off">
                <div class="form-group">
                    <label class="form-label">Assign to Agency <span style="color:#ef4444;">*</span></label>
                    <select name="agency_id" class="form-control" required>
                        <option value="" disabled selected>— Select Agency —</option>
                        <?php foreach ($agencies as $ag): ?>
                            <option value="<?php echo $ag['id']; ?>">
                                <?php echo htmlspecialchars($ag['agency_name'] ?: $ag['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="client_username" class="form-control" required placeholder="e.g. client_acme" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span style="color:#ef4444;">*</span></label>
                        <input type="password" name="client_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                    </div>
                </div>
                <div style="border-top: 1px solid #f1f5f9; padding-top: 16px; margin: 4px 0 16px;">
                    <p style="font-size: 0.78rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Company Profile (Optional)</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Company Name</label>
                    <input type="text" name="company_name" class="form-control" placeholder="e.g. Acme Corp">
                </div>
                <div class="form-group">
                    <label class="form-label">Company Address</label>
                    <textarea name="company_address" class="form-control" rows="2" placeholder="Full Business Address"></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Contact No.</label>
                        <input type="text" name="contact_no" class="form-control" placeholder="Company Phone">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email_address" class="form-control" placeholder="contact@company.com">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Website / Social Link <span style="color:#94a3b8; font-weight:400;">(Optional)</span></label>
                    <input type="text" name="website_link" class="form-control" placeholder="FB, Viber, or Website URL">
                </div>

                <div style="border-top: 1px solid #f1f5f9; padding-top: 16px; margin: 8px 0 16px;">
                    <p style="font-size: 0.78rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Contact Person Details</p>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" placeholder="Full Name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" name="contact_person_position" class="form-control" placeholder="Job Title">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Contact No. (Contact Person)</label>
                    <input type="text" name="contact_person_no" class="form-control" placeholder="Personal or Office Phone">
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end;">
                    <button type="button" onclick="document.getElementById('addClientModal').classList.remove('show')"
                            class="btn" style="background: #f1f5f9; color: #475569; width: auto; padding: 10px 24px;">Cancel</button>
                    <button type="submit" name="add_client" class="btn btn-primary" style="width: auto; padding: 10px 32px;">Create Client</button>
                </div>
            </form>
        </div>
    </div>

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

<?php include 'admin_layout/footer.php'; ?>
</html>
