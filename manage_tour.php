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

$message = '';
$message_type = '';
$show_status_modal = false;

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

// Fetch available checkpoints (exclude zero)
$checkpoints_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 0 ORDER BY name ASC");
$available_checkpoints = [];
while ($row = $checkpoints_res->fetch_assoc()) {
    $available_checkpoints[] = $row;
}

// Fetch Starting Point
$starting_point = null;
$start_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id AND is_zero_checkpoint = 1 LIMIT 1");
if ($start_res && $start_res->num_rows > 0) {
    $starting_point = $start_res->fetch_assoc();
}

// Fetch current assignments
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
    <title>Tour Setup - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0;}
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1000px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px; }
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }

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
        .handle { cursor: grab; color: #94a3b8; font-size: 1.2rem; display: flex; align-items: center; }
        .checkpoint-name { flex: 1; font-weight: 600; color: #1e293b; }
        
        .setting-inputs { display: flex; gap: 12px; align-items: center; }
        .input-group { display: flex; align-items: center; gap: 6px; background: #fff; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; }
        .input-group input { width: 55px; border: none; text-align: center; font-weight: 600; outline: none; }
        .input-group label { font-size: 0.75rem; color: #64748b; font-weight: 500; }

        .remove-btn { color: #ef4444; background: none; border: none; cursor: pointer; padding: 5px; border-radius: 5px; transition: background 0.2s; }
        .remove-btn:hover { background: #fee2e2; }

        .add-area { margin-top: 20px; display: flex; gap: 12px; }
        .select-control { flex: 1; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; }
        .btn-add { padding: 12px 24px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: background 0.2s; }
        .btn-add:hover { background: #2563eb; }

        .save-btn { margin-top: 24px; padding: 14px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; width: 100%; font-size: 1rem; transition: background 0.2s; }
        .save-btn:hover { background: #059669; }

        .limit-info { font-size: 0.875rem; color: #6b7280; font-weight: 500; }
        .limit-count { font-weight: 700; color: #111827; }
        .limit-count.danger { color: #ef4444; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; animation: modalFadeIn 0.3s ease-out; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="client_qrs.php" class="nav-link">Checkpoints</a></li>
            <li><a href="manage_tour.php" class="nav-link active">My Tours</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Tour Sequence Setup</h2>
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

            <div class="card">
                <div class="card-header">
                    <span>Manage Tour Pattern</span>
                    <div class="limit-info">
                        QR Limit: <span id="current-count" class="limit-count">0</span> / <span class="limit-count"><?php echo $qr_limit; ?></span>
                    </div>
                </div>

                <form id="tour-form" method="POST">
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
                                        <button type="button" class="remove-btn" onclick="removeItem(this)">&times;</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!$is_patrol_locked): ?>
                        <div class="add-area">
                            <select id="checkpoint-select" class="select-control">
                                <option value="">-- Add Checkpoint to Sequence --</option>
                                <?php foreach ($available_checkpoints as $cp): ?>
                                    <option value="<?php echo $cp['id']; ?>"><?php echo htmlspecialchars($cp['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-add" onclick="addItem()">Add</button>
                        </div>
                        <button type="submit" name="save_tour" class="save-btn">Save Patrol Configuration</button>
                    <?php endif; ?>
                </form>
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
                <button class="save-btn" style="margin-top: 0;" onclick="closeModal('statusModal')">Done</button>
            </div>
        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button class="btn-add" style="background:#f3f4f6; color:#374151; flex:1;" onclick="closeModal('logoutModal')">Cancel</button>
                <a href="logout.php" style="flex:1; padding:12px; background:#ef4444; color:white; text-decoration:none; border-radius:8px; font-weight:600; font-size:1rem;">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

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
                <button type="button" class="remove-btn" onclick="removeItem(this)">&times;</button>
            `;
            tourList.appendChild(div);
            updateCount();
            select.value = '';
        }

        function removeItem(btn) {
            btn.closest('.tour-item').remove();
            updateCount();
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.classList.remove('show');
            }
        }

        // Initialize count
        updateCount();
    </script>
</body>
</html>
