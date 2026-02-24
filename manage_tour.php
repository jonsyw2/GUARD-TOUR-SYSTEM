<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'] ?? null;

// Ensure table exists
$conn->query("
    CREATE TABLE IF NOT EXISTS tour_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agency_client_id INT NOT NULL,
        checkpoint_id INT NOT NULL,
        sort_order INT NOT NULL,
        interval_minutes INT DEFAULT 0,
        FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE
    )
");

// Fetch mapping info for this client
$mapping_sql = "SELECT id, qr_limit, qr_override FROM agency_clients WHERE client_id = $client_id LIMIT 1";
$mapping_res = $conn->query($mapping_sql);
$mapping = $mapping_res->fetch_assoc();
$mapping_id = $mapping['id'] ?? null;
$qr_limit = (int)($mapping['qr_limit'] ?? 0);
$qr_override = (int)($mapping['qr_override'] ?? 0);

if (!$mapping_id) {
    die("Error: No agency-client mapping found for this account.");
}

$message = '';
$message_type = '';

// Handle Save
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_tour'])) {
    $checkpoint_ids = $_POST['checkpoint_ids'] ?? [];
    $intervals = $_POST['intervals'] ?? [];
    
    // Validate limit
    if (count($checkpoint_ids) > $qr_limit && !$qr_override) {
        $message = "Error: You cannot exceed the limit of $qr_limit checkpoints in your tour.";
        $message_type = "error";
    } else {
        // Clear existing and save new
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM tour_assignments WHERE agency_client_id = $mapping_id");
            for ($i = 0; $i < count($checkpoint_ids); $i++) {
                $cp_id = (int)$checkpoint_ids[$i];
                $interval = (int)($intervals[$i] ?? 0);
                $order = $i + 1;
                $conn->query("INSERT INTO tour_assignments (agency_client_id, checkpoint_id, sort_order, interval_minutes) VALUES ($mapping_id, $cp_id, $order, $interval)");
            }
            $conn->commit();
            $message = "Tour sequence saved successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error saving tour: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Fetch available checkpoints for this client
$checkpoints_res = $conn->query("SELECT id, name FROM checkpoints WHERE agency_client_id = $mapping_id ORDER BY name ASC");
$available_checkpoints = [];
while ($row = $checkpoints_res->fetch_assoc()) {
    $available_checkpoints[] = $row;
}

// Fetch current assignments
$assignments_res = $conn->query("
    SELECT ta.checkpoint_id, ta.interval_minutes, cp.name 
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
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

        .content-area { padding: 32px; max-width: 1000px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px; }
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        .tour-item { display: flex; align-items: center; gap: 12px; padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px; }
        .tour-item:hover { background: #f3f4f6; }
        .handle { cursor: grab; color: #9ca3af; }
        .checkpoint-name { flex: 1; font-weight: 500; }
        .interval-input { width: 120px; display: flex; align-items: center; gap: 6px; }
        .interval-input input { width: 60px; padding: 6px; border: 1px solid #d1d5db; border-radius: 4px; text-align: center; }
        .remove-btn { color: #ef4444; border: none; background: none; cursor: pointer; padding: 4px; border-radius: 4px; transition: background 0.2s; }
        .remove-btn:hover { background: #fee2e2; }

        .add-area { margin-top: 20px; display: flex; gap: 12px; }
        .select-control { flex: 1; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn-add { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-add:hover { background: #2563eb; }

        .save-btn { margin-top: 24px; padding: 12px 24px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; width: 100%; font-size: 1rem; }
        .save-btn:hover { background: #059669; }

        .limit-info { font-size: 0.875rem; color: #6b7280; }
        .limit-count { font-weight: 700; color: #111827; }
        .limit-count.danger { color: #ef4444; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link active">My Tours</a></li>
            <li><a href="#" class="nav-link">Reports</a></li>
            <li><a href="#" class="nav-link">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Tour Assignment Setup</h2>
        </header>

        <div class="content-area">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <span>Tour Sequence</span>
                    <div class="limit-info">
                        Limit: <span id="current-count" class="limit-count">0</span> / <span class="limit-count"><?php echo $qr_limit; ?></span>
                    </div>
                </div>

                <form id="tour-form" method="POST">
                    <div id="tour-list">
                        <!-- Items added here dynamically -->
                        <?php foreach ($current_assignments as $index => $item): ?>
                            <div class="tour-item">
                                <span class="handle">☰</span>
                                <span class="checkpoint-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                <input type="hidden" name="checkpoint_ids[]" value="<?php echo $item['checkpoint_id']; ?>">
                                <div class="interval-input">
                                    <input type="number" name="intervals[]" value="<?php echo $item['interval_minutes']; ?>" min="0">
                                    <small>min</small>
                                </div>
                                <button type="button" class="remove-btn" onclick="removeItem(this)">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="add-area">
                        <select id="checkpoint-select" class="select-control">
                            <option value="">-- Select Checkpoint to Add --</option>
                            <?php foreach ($available_checkpoints as $cp): ?>
                                <option value="<?php echo $cp['id']; ?>"><?php echo htmlspecialchars($cp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-add" onclick="addItem()">Add to Sequence</button>
                    </div>

                    <button type="submit" name="save_tour" class="save-btn">Save Tour Sequence</button>
                </form>
            </div>
        </div>
    </main>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Ready to Leave?</h3>
            <div style="display: flex; gap: 12px;">
                <button style="flex: 1; padding: 10px; border-radius: 8px; border: none; cursor: pointer;" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" style="flex: 1; padding: 10px; background: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        const qrLimit = <?php echo $qr_limit; ?>;
        const qrOverride = <?php echo $qr_override; ?>;
        const tourList = document.getElementById('tour-list');

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

            const div = document.createElement('div');
            div.className = 'tour-item';
            div.innerHTML = `
                <span class="handle">☰</span>
                <span class="checkpoint-name">${name}</span>
                <input type="hidden" name="checkpoint_ids[]" value="${id}">
                <div class="interval-input">
                    <input type="number" name="intervals[]" value="0" min="0">
                    <small>min</small>
                </div>
                <button type="button" class="remove-btn" onclick="removeItem(this)">&times;</button>
            `;
            tourList.appendChild(div);
            updateCount();
        }

        function removeItem(btn) {
            btn.closest('.tour-item').remove();
            updateCount();
        }

        // Initialize count
        updateCount();
    </script>
</body>
</html>
