<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'agency') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Agency Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10b981;
            --primary-hover: #059669;
            --bg-main: #f3f4f6;
            --sidebar-bg: #111827;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: var(--bg-main); color: var(--text-main); padding: 0 16px 0 0; gap: 16px; }
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow: hidden; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #10b981; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; cursor: pointer; }
        .logout-btn:hover { background-color: #dc2626; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: white; border-radius: 16px; border: 1px solid #e5e7eb; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .card-header { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }

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
        .btn-confirm:hover { background: var(--primary-hover); }
        /* Mobile Menu Toggle */
        .mobile-toggle { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #1f2937; padding: 8px; }
        .sidebar-close { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; position: absolute; top: 20px; right: 20px; }
        .sidebar-overlay-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; backdrop-filter: blur(2px); }

        @media (max-width: 1024px) {
            body { padding: 0; gap: 0; overflow-x: hidden; }
            .sidebar { position: fixed; left: -250px; top: 0; bottom: 0; z-index: 1000; }
            .sidebar.show { transform: translateX(250px); }
            .sidebar-close, .mobile-toggle, .sidebar-overlay-bg.show { display: block; }
            .main-content { border-radius: 0; border: none; }
            .topbar { padding: 16px 20px; }
            .content-area { padding: 24px 16px; }
            
            .modal-content { width: 95%; padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay-bg" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <button class="sidebar-close" onclick="toggleSidebar()">✕</button>
        <div class="sidebar-header">Agency Portal</div>
        <ul class="nav-links">
            <li><a href="agency_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="agency_client_management.php" class="nav-link">Client Management</a></li>
            <li><a href="manage_guards.php" class="nav-link">Manage Guards</a></li>
            <li><a href="manage_inspectors.php" class="nav-link">Manage Inspectors</a></li>
            <li><a href="manage_supervisors.php" class="nav-link">Manage Supervisors</a></li>
            <li><a href="agency_patrol_management.php" class="nav-link">Patrol Management</a></li>
            <li><a href="agency_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="agency_inspector_history.php" class="nav-link">Inspector Visits</a></li>
            <li><a href="agency_incidents.php" class="nav-link">Incident Reports</a></li>
            <li><a href="agency_reports.php" class="nav-link">Reports</a></li>
            <li><a href="agency_settings.php" class="nav-link active">Settings</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                <h2>System Settings</h2>
            </div>
            <div class="user-info">
                <span class="badge" style="background: #e0f2fe; color: #0369a1;">AGENCY CONFIG</span>
            </div>
        </header>
        <div class="content-area">
            <div class="card" style="margin-bottom: 24px;">
                <h3 class="card-header">Account Configuration</h3>
                <p style="color: #6b7280;">Manage your agency profile and basic account settings here.</p>
            </div>

            <div class="card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Authorized Persons to Notify</span>
                    <button class="btn-modal btn-confirm" style="width: auto; padding: 8px 16px; font-size: 0.85rem; background: var(--primary);" onclick="openAddNotifModal()">+ Add Person</button>
                </div>
                <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 24px;">These additional emails will automatically receive reports submitted by your guards and inspectors.</p>
                
                <div id="notif_list_container">
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <p>Loading authorized persons...</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Notification Modal -->
    <div class="modal-overlay" id="addNotifModal">
        <div class="modal-content" style="max-width: 450px; text-align: left;">
            <h3 class="modal-title">Add Authorized Person</h3>
            <p class="modal-text">Register a new recipient for automated reports.</p>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #374151;">Name (Optional)</label>
                <input type="text" id="notif_new_name" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;" placeholder="e.g. Operations Manager">
            </div>
            <div style="margin-bottom: 24px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #374151;">Email Address</label>
                <input type="email" id="notif_new_email" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;" placeholder="manager@example.com">
            </div>

            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="closeAddNotifModal()">Cancel</button>
                <button class="btn-modal btn-confirm" style="background: var(--primary);" onclick="addNotificationEmail()">Add Recipient</button>
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
            <p class="modal-text">Select "Log Out" below if you are ready to end your current dashboard session.</p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
                <a href="logout.php" class="btn-modal btn-confirm">Log Out</a>
            </div>
        </div>
    </div>

    <script>
        const agencyId = <?php echo $_SESSION['user_id']; ?>;
        const parentType = 'agency';

        function openAddNotifModal() {
            document.getElementById('addNotifModal').classList.add('show');
        }

        function closeAddNotifModal() {
            document.getElementById('addNotifModal').classList.remove('show');
        }

        async function loadNotificationEmails() {
            try {
                const formData = new FormData();
                formData.append('action', 'list');
                formData.append('parent_id', agencyId);
                formData.append('parent_type', parentType);

                const response = await fetch('api/manage_notifications.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                
                const container = document.getElementById('notif_list_container');
                if (res.status === 'success') {
                    if (res.data.length === 0) {
                        container.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #94a3b8; border: 2px dashed #e5e7eb; border-radius: 12px;">
                                <p>No authorized persons registered yet.</p>
                            </div>`;
                    } else {
                        let html = '<div style="display: grid; gap: 12px;">';
                        res.data.forEach(item => {
                            html += `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; transition: all 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 40px; height: 40px; background: #eff6ff; color: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                            ${(item.name || 'A').charAt(0).toUpperCase()}
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 0.95rem;">${item.name || 'Anonymous'}</div>
                                            <div style="font-size: 0.85rem; color: #64748b;">${item.email}</div>
                                        </div>
                                    </div>
                                    <button class="btn-modal" style="width: auto; padding: 6px 12px; font-size: 0.8rem; color: #ef4444; background: #fee2e2; border: none; cursor: pointer; border-radius: 6px;" onclick="deleteNotificationEmail(${item.id})">Remove</button>
                                </div>
                            `;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }
                } else {
                    container.innerHTML = `<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error: ${res.message}</p></div>`;
                }
            } catch (err) {
                document.getElementById('notif_list_container').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Network Error</p></div>';
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
                formData.append('parent_id', agencyId);
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
                    closeAddNotifModal();
                    loadNotificationEmails();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch (err) {
                alert('Network Error');
            }
        }

        async function deleteNotificationEmail(id) {
            if (!confirm('Are you sure you want to remove this person from the notification list?')) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                formData.append('parent_id', agencyId);
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

        // Initialize
        document.addEventListener('DOMContentLoaded', loadNotificationEmails);

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
    </script>
</body>
</html>
