<?php
session_start();
include 'db_config.php';

if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';

// Handle updating limit
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_limit'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $new_limit = (int)$_POST['qr_limit'];
    $override = isset($_POST['qr_override']) ? 1 : 0;
    $disabled = isset($_POST['is_disabled']) ? 1 : 0;

    $update_sql = "UPDATE agency_clients SET qr_limit = $new_limit, qr_override = $override, is_disabled = $disabled WHERE id = $mapping_id";
    
    if ($conn->query($update_sql) === TRUE) {
        $message = "QR Configuration updated successfully.";
        $message_type = "success";
    } else {
        $message = "Error updating configuration: " . $conn->error;
        $message_type = "error";
    }
}

// Fetch agency-client mappings with checkpoint counts
$mapping_sql = "
    SELECT 
        ac.id, 
        a.username AS agency_name, 
        c.username AS client_name, 
        ac.qr_limit, 
        ac.qr_override, 
        ac.is_disabled,
        (SELECT COUNT(*) FROM checkpoints WHERE agency_client_id = ac.id) as current_qrs
    FROM agency_clients ac
    JOIN users a ON ac.agency_id = a.id
    JOIN users c ON ac.client_id = c.id
    ORDER BY a.username ASC, c.username ASC
";
$mappings_result = $conn->query($mapping_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Limits - Admin Dashboard</title>
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

        /* Submenu Styles */
        .has-submenu { cursor: pointer; justify-content: space-between; }
        .submenu { display: none; background-color: #0f172a; list-style: none; padding-left: 0; }
        .submenu.open { display: block; }
        .submenu-link { padding: 12px 24px 12px 48px; display: block; color: #9ca3af; text-decoration: none; font-size: 0.95rem; transition: all 0.2s; }
        .submenu-link:hover, .submenu-link.active { color: #fff; background-color: #1f2937; }
        .caret { transition: transform 0.2s; font-size: 0.8rem; }
        .caret.open { transform: rotate(180deg); }

        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #e0e7ff; color: #4f46e5; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; display: flex; align-items: center; }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); margin-bottom: 24px;}
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: middle;}
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: #1f2937; font-size: 0.95rem; }
        tbody tr:hover { background-color: #f9fafb; }
        .empty-state { text-align: center; padding: 30px; color: #6b7280; font-style: italic; }

        .inline-form { display: flex; gap: 10px; align-items: center; }
        .form-control { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; width: 80px; font-size: 0.95rem; }
        .btn { padding: 8px 14px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: background-color 0.2s; font-size: 0.9rem;}
        .btn:hover { background-color: #2563eb; }
        .checkbox-group { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #4b5563; }
        .checkbox-group input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
        
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .status-danger { background-color: #fee2e2; color: #ef4444; }
        .status-warning { background-color: #fef3c7; color: #d97706; }
        .status-good { background-color: #d1fae5; color: #10b981; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Admin Panel
        </div>
        <ul class="nav-links">
            <li><a href="admin_dashboard.php" class="nav-link">Dashboard</a></li>
            <li>
                <div class="nav-link has-submenu" onclick="toggleSubmenu('maintenanceMenu', this)">
                    <span>Maintenance</span>
                    <span class="caret">&#9660;</span>
                </div>
                <ul class="submenu" id="maintenanceMenu">
                    <li><a href="agency_maintenance.php" class="submenu-link">Agency Maintenance</a></li>
                    <li><a href="users_maintenance.php" class="submenu-link">User Maintenance</a></li>
                </ul>
            </li>
            <li><a href="manage_limits.php" class="nav-link active">QR Limits</a></li>
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
            <h2>QR Checkpoint Limits</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin'; ?></strong></span>
                <span class="badge">admin</span>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="card-header">Manage limits and overrides</h3>
                <p style="color: #6b7280; font-size: 0.95rem; margin-bottom: 20px;">Use this page to set the maximum allowed QR checkpoints per assigned client, grant temporary overrides, or suspend QR creation entirely.</p>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Agency</th>
                                <th>Client</th>
                                <th>Usage</th>
                                <th>Status</th>
                                <th>Configuration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mappings_result && $mappings_result->num_rows > 0): ?>
                                <?php while($row = $mappings_result->fetch_assoc()): ?>
                                    <?php 
                                        $usage_percent = ($row['qr_limit'] > 0) ? ($row['current_qrs'] / $row['qr_limit']) * 100 : 0;
                                        $status_class = 'status-good';
                                        $status_text = 'ACTIVE';
                                        
                                        if ($row['is_disabled']) {
                                            $status_class = 'status-danger';
                                            $status_text = 'DISABLED';
                                        } else if ($row['qr_override']) {
                                            $status_class = 'status-warning';
                                            $status_text = 'OVERRIDE ON';
                                        } else if ($usage_percent >= 100) {
                                            $status_class = 'status-danger';
                                            $status_text = 'LIMIT REACHED';
                                        } else if ($usage_percent >= 80) {
                                            $status_class = 'status-warning';
                                            $status_text = 'NEAR LIMIT';
                                        }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['agency_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                        <td>
                                            <strong><?php echo $row['current_qrs']; ?></strong> / <?php echo $row['qr_limit']; ?> QRs
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        </td>
                                        <td>
                                            <form action="manage_limits.php" method="POST" class="inline-form">
                                                <input type="hidden" name="mapping_id" value="<?php echo $row['id']; ?>">
                                                
                                                <label for="qr_limit_<?php echo $row['id']; ?>" style="font-size: 0.85rem; color:#4b5563;">Limit:</label>
                                                <input type="number" id="qr_limit_<?php echo $row['id']; ?>" name="qr_limit" class="form-control" value="<?php echo $row['qr_limit']; ?>" min="0" required>
                                                
                                                <div style="display: flex; flex-direction: column; gap: 4px; margin: 0 10px;">
                                                    <label class="checkbox-group">
                                                        <input type="checkbox" name="qr_override" <?php echo $row['qr_override'] ? 'checked' : ''; ?>> Allow Override
                                                    </label>
                                                    <label class="checkbox-group">
                                                        <input type="checkbox" name="is_disabled" <?php echo $row['is_disabled'] ? 'checked' : ''; ?>> Disable Creation
                                                    </label>
                                                </div>

                                                <button type="submit" name="update_limit" class="btn">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">No clients have been assigned to any agencies yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        function toggleSubmenu(menuId, element) {
            const menu = document.getElementById(menuId);
            const caret = element.querySelector('.caret');
            menu.classList.toggle('open');
            caret.classList.toggle('open');
        }
    </script>
</body>
</html>
