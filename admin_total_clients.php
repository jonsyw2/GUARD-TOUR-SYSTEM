<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch all clients with their agency assignments and guard names
$clients_sql = "
    SELECT 
        u.id as client_user_id,
        u.username as client_name,
        a.id as agency_id,
        a.username as agency_name,
        ac.id as mapping_id,
        ac.site_name,
        (SELECT COUNT(*) FROM guard_assignments ga WHERE ga.agency_client_id = ac.id) as guard_count,
        (
            SELECT GROUP_CONCAT(g.name SEPARATOR ' | ')
            FROM guard_assignments ga
            JOIN guards g ON ga.guard_id = g.id
            WHERE ga.agency_client_id = ac.id
        ) as assigned_guard_names
    FROM users u
    LEFT JOIN agency_clients ac ON u.id = ac.client_id
    LEFT JOIN users a ON ac.agency_id = a.id
    WHERE u.user_level = 'client'
    ORDER BY u.username ASC, a.username ASC
";
$clients_res = $conn->query($clients_sql);

// Fetch all agencies for the dropdown
$all_agencies = [];
$agencies_res = $conn->query("SELECT id, username FROM users WHERE user_level = 'agency' ORDER BY username ASC");
if ($agencies_res) {
    while ($ag = $agencies_res->fetch_assoc()) {
        $all_agencies[] = $ag;
    }
}

// Handle updating client details
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_client_details'])) {
    $user_id = (int)$_POST['client_user_id'];
    $mapping_id = isset($_POST['mapping_id']) && !empty($_POST['mapping_id']) ? (int)$_POST['mapping_id'] : null;
    $username = $conn->real_escape_string($_POST['username']);
    $target_agency_id = isset($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;

    $conn->begin_transaction();
    try {
        // Update username in users table
        if (!$conn->query("UPDATE users SET username = '$username' WHERE id = $user_id")) {
            throw new Exception("Error updating username: " . $conn->error);
        }

        if ($target_agency_id) {
            if ($mapping_id) {
                // Update existing mapping
                if (!$conn->query("UPDATE agency_clients SET agency_id = $target_agency_id WHERE id = $mapping_id")) {
                    throw new Exception("Error updating agency: " . $conn->error);
                }
            } else {
                // Create new mapping
                if (!$conn->query("INSERT INTO agency_clients (agency_id, client_id) VALUES ($target_agency_id, $user_id)")) {
                    throw new Exception("Error creating assignment: " . $conn->error);
                }
            }
        } else if ($mapping_id) {
            // If agency is set to none/null, we might want to unassign (optional logic)
            // For now let's just allow unassigning if selected
            if (!$conn->query("DELETE FROM agency_clients WHERE id = $mapping_id")) {
                throw new Exception("Error unassigning client: " . $conn->error);
            }
        }

        $conn->commit();
        $message = "Client details and assignment updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
    header("Location: admin_total_clients.php?msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

$msg = $_GET['msg'] ?? '';
$msg_type = $_GET['type'] ?? '';

$page_title = 'Total Clients';
$header_title = 'Total Clients Directory';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="contentArea" style="padding: 40px;">
            <?php if ($msg): ?>
                <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>" style="padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; <?php echo $msg_type === 'success' ? 'background: #d1fae5; color: #065f46;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>
            <style>
                tbody tr { cursor: pointer; transition: background 0.2s; }
                tbody tr:hover { background-color: #f8fafc !important; }
            </style>
            <div class="card">
                <div class="card-header">
                    <h3>Client Assignments & Usage</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Client Name</th>
                                <th>Assigned Agency</th>
                                <th>Site / Mapping</th>
                                <th>Assigned Guards</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($clients_res && $clients_res->num_rows > 0): ?>
                                <?php while($row = $clients_res->fetch_assoc()): ?>
                                    <tr onclick="openClientEditModal(<?php echo $row['client_user_id']; ?>, '<?php echo addslashes($row['client_name']); ?>', '<?php echo addslashes($row['agency_id'] ?? ''); ?>', '<?php echo addslashes($row['assigned_guard_names'] ?? ''); ?>', <?php echo $row['mapping_id'] ?? 'null'; ?>)">
                                        <td><strong><?php echo htmlspecialchars($row['client_name']); ?></strong></td>
                                        <td>
                                            <?php if ($row['agency_name']): ?>
                                                <span class="badge" style="background: #ede9fe; color: #6d28d9; padding: 4px 12px; border-radius: 9999px; font-size: 0.8rem; font-weight: 600;">
                                                    <?php echo htmlspecialchars($row['agency_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-style: italic;">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-muted"><?php echo htmlspecialchars($row['site_name'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="font-weight: 700; font-size: 1.1rem;"><?php echo $row['guard_count']; ?></span>
                                                <span class="text-muted" style="font-size: 0.8rem;">guards</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $is_active = $row['agency_name'] && $row['guard_count'] > 0;
                                            ?>
                                            <span class="status-badge <?php echo $is_active ? 'status-success' : 'status-warning'; ?>">
                                                <?php echo $is_active ? 'ACTIVE' : 'NOT ASSIGNED'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">No clients found in the system.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div id="editClientModal" class="modal">
                <div class="modal-content">
                    <h3 style="margin-bottom: 20px;">Edit Client & Assignment</h3>
                    <form action="admin_total_clients.php" method="POST">
                        <input type="hidden" name="client_user_id" id="edit_client_user_id">
                        <input type="hidden" name="mapping_id" id="edit_mapping_id">
                        
                        <div class="form-group" style="text-align: left;">
                            <label class="form-label">Client Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>

                        <div class="form-group" style="text-align: left;">
                            <label class="form-label">Assigned Agency</label>
                            <input list="agency_list" name="agency_id_display" id="edit_agency_display" class="form-control" placeholder="Type to search agency...">
                            <input type="hidden" name="agency_id" id="edit_agency_id">
                            <datalist id="agency_list">
                                <?php foreach($all_agencies as $ag): ?>
                                    <option data-id="<?php echo $ag['id']; ?>" value="<?php echo htmlspecialchars($ag['username']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group" style="text-align: left; background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid var(--border);">
                            <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: var(--primary);">👤</span> Assigned Guards
                            </label>
                            <div id="assigned_guards_list" style="font-size: 0.9rem; color: var(--text-main); font-weight: 500; line-height: 1.6;">
                                <!-- Guard names will be injected here -->
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 24px;">
                            <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('editClientModal')">Cancel</button>
                            <button type="submit" name="update_client_details" class="btn btn-primary" style="flex: 1;" onclick="return syncAgencyId()">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function openClientEditModal(userId, clientName, agencyId, guardNames, mappingId) {
                    document.getElementById('edit_client_user_id').value = userId;
                    document.getElementById('edit_mapping_id').value = mappingId || '';
                    document.getElementById('edit_username').value = clientName;
                    
                    // Handle Agency selection
                    const agencyInput = document.getElementById('edit_agency_display');
                    const agencyHidden = document.getElementById('edit_agency_id');
                    agencyHidden.value = agencyId || '';
                    
                    if (agencyId) {
                        const option = document.querySelector(`#agency_list option[data-id="${agencyId}"]`);
                        agencyInput.value = option ? option.value : '';
                    } else {
                        agencyInput.value = '';
                    }

                    // Handle Guards Display
                    const guardsList = document.getElementById('assigned_guards_list');
                    if (guardNames && guardNames.trim() !== '') {
                        guardsList.innerHTML = guardNames.split(' | ').map(name => `• ${name}`).join('<br>');
                    } else {
                        guardsList.innerHTML = '<span style="color: var(--text-muted); font-style: italic;">No guards assigned to this client.</span>';
                    }
                    
                    document.getElementById('editClientModal').classList.add('show');
                }

                function syncAgencyId() {
                    const agencyInput = document.getElementById('edit_agency_display');
                    const agencyHidden = document.getElementById('edit_agency_id');
                    const val = agencyInput.value;
                    const opts = document.getElementById('agency_list').childNodes;
                    
                    let foundId = '';
                    for (let i = 0; i < opts.length; i++) {
                        if (opts[i].value === val) {
                            foundId = opts[i].getAttribute('data-id');
                            break;
                        }
                    }
                    agencyHidden.value = foundId;
                    return true;
                }
                function closeModal(id) {
                    document.getElementById(id).classList.remove('show');
                }
                window.onclick = function(e) {
                    if (e.target.classList.contains('modal')) e.target.classList.remove('show');
                }
            </script>
        </div>
    </main>

<?php include 'admin_layout/footer.php'; ?>
