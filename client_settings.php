<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Auto-Migration: Add supervisor_id to agency_clients if it doesn't exist
$conn->query("ALTER TABLE agency_clients ADD COLUMN IF NOT EXISTS supervisor_id INT DEFAULT NULL");

// Handle Supervisor Assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_supervisor'])) {
    $mapping_id = (int)$_POST['mapping_id'];
    $supervisor_id = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : 'NULL';

    // Verify mapping belongs to client
    $verify = $conn->query("SELECT id FROM agency_clients WHERE id = $mapping_id AND client_id = $client_id");
    if ($verify && $verify->num_rows > 0) {
        $sql = "UPDATE agency_clients SET supervisor_id = $supervisor_id WHERE id = $mapping_id";
        if ($conn->query($sql)) {
            $message = "Supervisor assigned successfully!";
            $message_type = "success";
        } else {
            $message = "Error assigning supervisor: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Fetch all sites assigned to this client
$sites_sql = "
    SELECT ac.*, s.name as supervisor_name 
    FROM agency_clients ac 
    LEFT JOIN supervisors s ON ac.supervisor_id = s.id 
    WHERE ac.client_id = $client_id
    ORDER BY ac.site_name ASC
";
$sites_res = $conn->query($sites_sql);

// Helper function to get supervisors from the same agency as the site
function getAgencySupervisors($conn, $agency_id) {
    $sql = "SELECT id, name FROM supervisors WHERE agency_id = $agency_id ORDER BY name ASC";
    return $conn->query($sql);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        :root {
            --primary: #3b82f6;
            --bg-main: #f3f4f6;
            --sidebar-bg: #111827;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); padding: 0 16px 0 0; gap: 16px; }

        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: #fff; display: flex; flex-direction: column; flex-shrink: 0; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: var(--primary); }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; border-radius: 16px; border: 1px solid var(--border); background: white; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .content-area { padding: 32px; max-width: 1000px; margin: 0 auto; width: 100%; }
        
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border); margin-bottom: 24px; }
        .card-header { font-size: 1.25rem; font-weight: 700; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        
        .site-item { display: flex; justify-content: space-between; align-items: center; padding: 20px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 16px; transition: background 0.2s; }
        .site-item:hover { background: #f8fafc; }
        .site-info h4 { margin-bottom: 4px; color: var(--text-main); }
        .site-info p { font-size: 0.85rem; color: var(--text-muted); }

        .assign-form { display: flex; align-items: center; gap: 12px; }
        .form-select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; min-width: 200px; }
        .btn-save { padding: 8px 16px; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 600; }
        .btn-save:hover { background: var(--primary-dark); }

        .alert { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 0.95rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">Client Portal</div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="manage_tour.php" class="nav-link">Checkpoint Management</a></li>
            <li><a href="client_guards.php" class="nav-link">My Guards</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="client_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>

        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Portal Settings</h2>
            <span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">ACCOUNT CONTROL</span>
        </header>

        <div class="content-area">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">Manage Site Supervisors</div>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 24px;">
                    Assign a supervisor to your sites. Supervisors are created by your respective security agencies.
                </p>

                <?php if ($sites_res && $sites_res->num_rows > 0): ?>
                    <?php while($site = $sites_res->fetch_assoc()): ?>
                        <div class="site-item">
                            <div class="site-info">
                                <h4><?php echo htmlspecialchars($site['site_name']); ?></h4>
                                <p>Site ID: #<?php echo $site['id']; ?></p>
                            </div>
                            
                            <form action="client_settings.php" method="POST" class="assign-form">
                                <input type="hidden" name="mapping_id" value="<?php echo $site['id']; ?>">
                                <select name="supervisor_id" class="form-select">
                                    <option value="">-- No Supervisor --</option>
                                    <?php 
                                    $sups = getAgencySupervisors($conn, $site['agency_id']);
                                    if ($sups && $sups->num_rows > 0):
                                        while($s = $sups->fetch_assoc()): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php if($site['supervisor_id'] == $s['id']) echo 'selected'; ?>>
                                                <?php echo htmlspecialchars($s['name']); ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                                <button type="submit" name="assign_supervisor" class="btn-save">Assign</button>
                            </form>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 40px;">No sites found for your account.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Logout Modal -->
    <div id="logoutModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:white; padding:32px; border-radius:12px; max-width:400px; text-align:center;">
            <h3 style="margin-bottom:20px;">Logout?</h3>
            <p style="color:var(--text-muted); margin-bottom:24px;">Are you sure you want to end your session?</p>
            <div style="display:flex; gap:12px;">
                <button style="flex:1; padding:10px; border-radius:8px; border:none; background:#f3f4f6; cursor:pointer;" onclick="document.getElementById('logoutModal').style.display='none'">Cancel</button>
                <a href="logout.php" style="flex:1; padding:10px; border-radius:8px; background:#ef4444; color:white; text-decoration:none; display:flex; align-items:center; justify-content:center;">Logout</a>
            </div>
        </div>
    </div>

    <script>
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) modal.style.display = 'none';
        }
        function openLogout() { document.getElementById('logoutModal').style.display = 'flex'; }
    </script>
</body>
</html>
