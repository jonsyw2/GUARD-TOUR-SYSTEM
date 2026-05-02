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
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; cursor: pointer; }

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

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .modal-actions .btn-modal { flex: 1; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: var(--primary); color: white; text-decoration: none; }
        .btn-confirm:hover { filter: brightness(0.9); }
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
            <li><a href="client_settings.php" class="nav-link active">Settings</a></li>
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
                                <button type="button" class="btn-save" style="background: #e0f2fe; color: #0369a1; border: none;" onclick='openNotificationsModal(<?php echo $site['id']; ?>, "<?php echo addslashes($site['site_name']); ?>")'>Notifications</button>
                            </form>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 40px;">No sites found for your account.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Authorized Persons / Notifications Modal -->
    <div id="notificationsModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 500px; text-align: left;">
            <h3 class="modal-title" style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 16px;">Authorized Persons: <span id="notif_target_name"></span></h3>
            <p class="modal-text" style="font-size: 0.85rem; margin-bottom: 20px;">Manage additional emails that will automatically receive reports for this site.</p>
            
            <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; font-size: 0.85rem; text-transform: uppercase; color: #475569;">Add Authorized Person</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px;">
                    <input type="text" id="notif_new_name" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85rem;" placeholder="Name (Optional)">
                    <input type="email" id="notif_new_email" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85rem;" placeholder="Email Address">
                    <button class="btn-save" style="padding: 0 12px;" onclick="addNotificationEmail()">Add</button>
                </div>
            </div>

            <div id="notif_list_container" style="max-height: 250px; overflow-y: auto;">
                <div style="text-align: center; padding: 20px; color: #94a3b8;">
                    <p>Loading...</p>
                </div>
            </div>

            <div style="margin-top: 24px; text-align: right; border-top: 1px solid #e5e7eb; padding-top: 16px;">
                <button class="btn-save" style="background:#f3f4f6; color:#374151;" onclick="document.getElementById('notificationsModal').classList.remove('show')">Close</button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </div>
            <h3 class="modal-title">Ready to Leave?</h3>
            <p class="modal-text">Select "Logout" below if you are ready to end your current session.</p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" class="btn-modal btn-confirm" style="display: flex; align-items: center; justify-content: center; text-decoration: none;">Logout</a>
            </div>
        </div>
    </div>

    <script>
        let currentNotifTargetId = 0;
        const parentType = 'client';

        function openNotificationsModal(id, name) {
            currentNotifTargetId = id;
            document.getElementById('notif_target_name').innerText = name;
            document.getElementById('notif_list_container').innerHTML = '<div style="text-align: center; padding: 20px; color: #94a3b8;"><p>Loading...</p></div>';
            document.getElementById('notificationsModal').classList.add('show');
            loadNotificationEmails();
        }

        async function loadNotificationEmails() {
            try {
                const formData = new FormData();
                formData.append('action', 'list');
                formData.append('parent_id', currentNotifTargetId);
                formData.append('parent_type', parentType);

                const response = await fetch('api/manage_notifications.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                
                const container = document.getElementById('notif_list_container');
                if (res.status === 'success') {
                    if (res.data.length === 0) {
                        container.innerHTML = '<p style="text-align: center; color: #94a3b8; font-size: 0.9rem; padding: 20px;">No authorized persons registered.</p>';
                    } else {
                        let html = '<div style="display: grid; gap: 8px;">';
                        res.data.forEach(item => {
                            html += `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px;">
                                    <div>
                                        <div style="font-weight: 600; color: #1e293b; font-size: 0.85rem;">${item.name || 'Anonymous'}</div>
                                        <div style="font-size: 0.8rem; color: #64748b;">${item.email}</div>
                                    </div>
                                    <button class="btn-save" style="width: auto; padding: 4px 8px; font-size: 0.7rem; color: #ef4444; background: #fee2e2; border: none;" onclick="deleteNotificationEmail(${item.id})">Remove</button>
                                </div>
                            `;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }
                } else {
                    container.innerHTML = `<p style="text-align: center; color: #ef4444;">Error: ${res.message}</p>`;
                }
            } catch (err) {
                document.getElementById('notif_list_container').innerHTML = '<p style="text-align: center; color: #ef4444;">Network Error</p>';
            }
        }

        async function addNotificationEmail() {
            const nameInput = document.getElementById('notif_new_name');
            const emailInput = document.getElementById('notif_new_email');
            const name = nameInput.value.trim();
            const email = emailInput.value.trim();

            if (!email) {
                alert('Email address is required');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'add');
                formData.append('parent_id', currentNotifTargetId);
                formData.append('parent_type', parentType);
                formData.append('name', name);
                formData.append('email', email);

                const response = await fetch('api/manage_notifications.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                
                if (res.status === 'success') {
                    nameInput.value = '';
                    emailInput.value = '';
                    loadNotificationEmails();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch (err) {
                alert('Network Error');
            }
        }

        async function deleteNotificationEmail(id) {
            if (!confirm('Are you sure you want to remove this person?')) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                formData.append('parent_id', currentNotifTargetId);
                formData.append('parent_type', parentType);

                const response = await fetch('api/manage_notifications.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                
                if (res.status === 'success') {
                    loadNotificationEmails();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch (err) {
                alert('Network Error');
            }
        }

        window.onclick = function(event) {
            const logoutModal = document.getElementById('logoutModal');
            const notifModal = document.getElementById('notificationsModal');
            if (event.target == logoutModal) logoutModal.classList.remove('show');
            if (event.target == notifModal) notifModal.classList.remove('show');
        }
        function openLogout() { document.getElementById('logoutModal').classList.add('show'); }
    </script>
</body>
</html>
            if (event.target.classList.contains('modal-overlay')) {
                 event.target.classList.remove('show');
            }
        });
        function openLogout() { document.getElementById('logoutModal').classList.add('show'); }
    </script>
    <?php include 'admin_layout/footer.php'; ?>
