<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle updating guard details
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_guard'])) {
    $guard_id = (int)$_POST['guard_id'];
    $name = $conn->real_escape_string($_POST['guard_name']);
    $lesp_no = $conn->real_escape_string($_POST['lesp_no']);
    $lesp_expiry = $conn->real_escape_string($_POST['lesp_expiry']);

    if ($conn->query("UPDATE guards SET name = '$name', lesp_no = '$lesp_no', lesp_expiry = '$lesp_expiry' WHERE id = $guard_id")) {
        $message = "Guard details updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating guard: " . $conn->error;
        $message_type = "error";
    }
    // Refresh data
    header("Location: admin_security_guards.php?msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

$msg = $_GET['msg'] ?? '';
$msg_type = $_GET['type'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_agency = isset($_GET['agency']) ? (int)$_GET['agency'] : 0;

// Build WHERE clause
$where = "WHERE 1=1";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where .= " AND (g.name LIKE '%$s%' OR u.username LIKE '%$s%')";
}
if ($filter_agency > 0) {
    $where .= " AND g.agency_id = $filter_agency";
}

// Fetch all guards with agency and client info
$guards_sql = "SELECT g.id, g.user_id, g.name, u.username, g.lesp_no, g.lesp_expiry, g.created_at,
                      ag.username AS agency_name, g.agency_id,
                      GROUP_CONCAT(DISTINCT cu.username ORDER BY cu.username SEPARATOR ', ') AS client_names
               FROM guards g
               JOIN users u  ON g.user_id  = u.id
               LEFT JOIN users ag ON g.agency_id = ag.id
               LEFT JOIN guard_assignments ga ON g.id = ga.guard_id
               LEFT JOIN agency_clients   ac ON ga.agency_client_id = ac.id
               LEFT JOIN users cu ON ac.client_id = cu.id
               $where
               GROUP BY g.id
               ORDER BY ag.username ASC, g.name ASC";
$guards_res = $conn->query($guards_sql);

// Agency list for filter dropdown
$agencies_res = $conn->query("SELECT id, username FROM users WHERE user_level = 'agency' ORDER BY username ASC");

// Counts
$total_guards  = 0;
$assigned_count = 0;
$rows = [];
if ($guards_res) {
    while ($row = $guards_res->fetch_assoc()) {
        $rows[] = $row;
        $total_guards++;
        if (!empty($row['client_names'])) $assigned_count++;
    }
}
$unassigned_count = $total_guards - $assigned_count;

$page_title    = 'Security Guards';
$header_title  = 'Security Guards';
include 'admin_layout/head.php';
include 'admin_layout/sidebar.php';
?>

    <main class="main-content">
        <?php include 'admin_layout/topbar.php'; ?>

        <div class="contentArea">
            <?php if ($msg): ?>
                <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>
            <style>
                tbody tr { cursor: pointer; transition: background 0.2s; }
                tbody tr:hover { background-color: #f8fafc !important; }
                /* ── stat mini-cards ── */
                .sg-stats { display: flex; gap: 20px; margin-bottom: 32px; flex-wrap: wrap; }
                .sg-stat  { flex: 1; min-width: 160px; background: white; border-radius: 14px;
                             padding: 22px 28px; border: 1px solid var(--border);
                             box-shadow: var(--shadow); }
                .sg-stat .lbl { font-size: 0.78rem; font-weight: 700; text-transform: uppercase;
                                letter-spacing: .06em; color: var(--text-muted); margin-bottom: 6px; }
                .sg-stat .val { font-size: 2rem; font-weight: 800; color: var(--text-main); }
                .sg-stat .val.green { color: #10b981; }
                .sg-stat .val.amber { color: #f59e0b; }

                /* ── filter bar ── */
                .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; align-items: center; }
                .filter-bar input, .filter-bar select {
                    padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
                    font-size: 0.9rem; color: var(--text-main); background: white;
                    outline: none; transition: border-color .2s;
                }
                .filter-bar input:focus, .filter-bar select:focus { border-color: #6366f1; }
                .filter-bar input { flex: 1; min-width: 200px; }
                .filter-bar .btn-filter {
                    padding: 10px 20px; background: #6366f1; color: white;
                    border: none; border-radius: 8px; font-weight: 600;
                    cursor: pointer; font-size: 0.9rem; transition: background .2s;
                }
                .filter-bar .btn-filter:hover { background: #4f46e5; }
                .filter-bar .btn-reset {
                    padding: 10px 16px; background: #f3f4f6; color: #374151;
                    border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem;
                    cursor: pointer; font-weight: 500; text-decoration: none;
                    transition: background .2s;
                }
                .filter-bar .btn-reset:hover { background: #e5e7eb; }

                /* ── agency badge ── */
                .badge-agency { display: inline-block; background: #ede9fe; color: #6d28d9;
                                padding: 3px 10px; border-radius: 20px; font-size: 0.78rem;
                                font-weight: 600; }
                /* ── client badges ── */
                .badge-client { display: inline-block; background: #d1fae5; color: #065f46;
                                padding: 3px 10px; border-radius: 20px; font-size: 0.78rem;
                                font-weight: 600; margin: 2px 2px 2px 0; }
                .badge-none   { color: #9ca3af; font-size: 0.82rem; font-style: italic; }

                /* ── lesp expiry warning ── */
                .expiry-warn { color: #dc2626; font-weight: 600; }
                .expiry-ok   { color: #374151; }

                /* ── table tweaks ── */
                .guard-table thead th { white-space: nowrap; }

                /* ── results count ── */
                .results-count { font-size: 0.85rem; color: var(--text-muted); margin-left: auto; }
            </style>

            <!-- Filter bar -->
            <form method="GET" action="admin_security_guards.php">
                <div class="filter-bar">
                    <input type="text" name="search" placeholder="🔍  Search guard name or access key…"
                           value="<?php echo htmlspecialchars($search); ?>"
                           onchange="this.form.submit()">
                    <select name="agency" onchange="this.form.submit()">
                        <option value="0">All Agencies</option>
                        <?php if ($agencies_res): while($ag = $agencies_res->fetch_assoc()): ?>
                            <option value="<?php echo $ag['id']; ?>"
                                <?php echo $filter_agency == $ag['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ag['username']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                    <span class="results-count"><?php echo $total_guards; ?> guard(s) found</span>
                </div>
            </form>

            <!-- Guards table -->
            <div class="card">
                <div class="card-header">
                    <h3>All Security Guards</h3>
                </div>
                <div class="table-container">
                    <table class="guard-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Guard Name</th>
                                <th>Agency</th>
                                <th>Assigned Client(s)</th>
                                <th>Status</th>
                                <th>LESP No.</th>
                                <th>LESP Expiry</th>
                                <th>Date Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php $i = 1; foreach ($rows as $row):
                                    $expiry = $row['lesp_expiry'] ? strtotime($row['lesp_expiry']) : null;
                                    $expiryCls = ($expiry && $expiry < strtotime('+30 days')) ? 'expiry-warn' : 'expiry-ok';
                                    $has_clients = !empty($row['client_names']);
                                ?>
                                <tr onclick="openGuardEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>', '<?php echo addslashes($row['lesp_no']); ?>', '<?php echo $row['lesp_expiry']; ?>')">
                                    <td><span class="text-muted"><?php echo $i++; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td>
                                        <span class="badge-agency">
                                            <?php echo htmlspecialchars($row['agency_name'] ?? 'Unassigned'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($has_clients): ?>
                                            <?php foreach (explode(', ', $row['client_names']) as $cn): ?>
                                                <span class="badge-client"><?php echo htmlspecialchars($cn); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="badge-none">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $has_clients ? 'status-success' : 'status-warning'; ?>" style="font-size: 0.75rem; padding: 4px 10px;">
                                            <?php echo $has_clients ? 'ACTIVE' : 'NOT ASSIGNED'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['lesp_no'] ?: '—'); ?></td>
                                    <td class="<?php echo $expiryCls; ?>">
                                        <?php echo $row['lesp_expiry'] ? date('M d, Y', $expiry) : '—'; ?>
                                        <?php if ($expiry && $expiry < time()): ?>
                                            <span style="font-size:.75rem;">(Expired)</span>
                                        <?php elseif ($expiry && $expiry < strtotime('+30 days')): ?>
                                            <span style="font-size:.75rem;">(Expiring soon)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">No guards found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div id="editGuardModal" class="modal">
                <div class="modal-content">
                    <h3 style="margin-bottom: 20px;">Edit Guard Details</h3>
                    <form action="admin_security_guards.php" method="POST">
                        <input type="hidden" name="guard_id" id="edit_guard_id">
                        <div class="form-group" style="text-align: left;">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="guard_name" id="edit_guard_name" class="form-control" required>
                        </div>
                        <div class="form-group" style="text-align: left;">
                            <label class="form-label">LESP Number</label>
                            <input type="text" name="lesp_no" id="edit_lesp_no" class="form-control">
                        </div>
                        <div class="form-group" style="text-align: left;">
                            <label class="form-label">LESP Expiry</label>
                            <input type="date" name="lesp_expiry" id="edit_lesp_expiry" class="form-control">
                        </div>
                        <div style="display: flex; gap: 12px; margin-top: 24px;">
                            <button type="button" class="btn" style="background: #f3f4f6; color: #374151; flex: 1;" onclick="closeModal('editGuardModal')">Cancel</button>
                            <button type="submit" name="update_guard" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function openGuardEditModal(id, name, lesp_no, expiry) {
                    document.getElementById('edit_guard_id').value = id;
                    document.getElementById('edit_guard_name').value = name;
                    document.getElementById('edit_lesp_no').value = lesp_no || '';
                    document.getElementById('edit_lesp_expiry').value = expiry || '';
                    document.getElementById('editGuardModal').classList.add('show');
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
