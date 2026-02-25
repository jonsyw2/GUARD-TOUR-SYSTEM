<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Get all agency_client mapping IDs for this client
$maps_sql = "SELECT id FROM agency_clients WHERE client_id = $client_id";
$maps_res = $conn->query($maps_sql);
$mapping_ids = [];
if ($maps_res && $maps_res->num_rows > 0) {
    while($r = $maps_res->fetch_assoc()) {
        $mapping_ids[] = (int)$r['id'];
    }
}

if (empty($mapping_ids)) {
    $mapping_ids_str = "0";
} else {
    $mapping_ids_str = implode(',', $mapping_ids);
}

// Fetch Incidents
$incidents_sql = "
    SELECT 
        i.created_at,
        c.name as checkpoint_name,
        g.name as guard_name,
        i.incident_type,
        i.description,
        i.status,
        i.photo_path
    FROM incidents i
    LEFT JOIN checkpoints c ON i.checkpoint_id = c.id
    LEFT JOIN guards g ON i.guard_id = g.id
    WHERE i.agency_client_id IN ($mapping_ids_str)
    ORDER BY i.created_at DESC
    LIMIT 100
";
$incidents_result = $conn->query($incidents_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incidents & Alerts - Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: #f3f4f6; color: #1f2937; }

        /* Sidebar Styles */
        .sidebar { width: 250px; background-color: #111827; color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); flex-shrink: 0; }
        .sidebar-header { padding: 24px 20px; font-size: 1.5rem; font-weight: 700; text-align: center; border-bottom: 1px solid #374151; letter-spacing: 0.5px; color: #f9fafb; }
        .nav-links { list-style: none; flex: 1; padding-top: 15px; }
        .nav-link { padding: 15px 24px; display: flex; align-items: center; color: #9ca3af; text-decoration: none; font-weight: 500; transition: background 0.2s, color 0.2s, border-color 0.2s; border-left: 4px solid transparent; }
        .nav-link:hover, .nav-link.active { background-color: #1f2937; color: #fff; border-left-color: #3b82f6; }
        .sidebar-footer { padding: 20px; border-top: 1px solid #374151; }
        .logout-btn { display: block; text-align: center; padding: 12px; background-color: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background 0.3s; }
        .logout-btn:hover { background-color: #dc2626; }

        /* Main Content Styles */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background: white; padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .topbar h2 { font-size: 1.25rem; font-weight: 600; color: #111827; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .badge { background: #dbeafe; color: #3b82f6; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .content-area { padding: 32px; max-width: 1200px; margin: 0 auto; width: 100%; }

        /* Incidents Grid */
        .incidents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
        
        .incident-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); overflow: hidden; display: flex; flex-direction: column; position: relative; border-left: 4px solid #cbd5e1; }
        
        /* Type colors */
        .incident-card.type-emergency { border-left-color: #ef4444; }
        .incident-card.type-missed_patrol { border-left-color: #f59e0b; }
        .incident-card.type-general { border-left-color: #3b82f6; }

        .card-image { width: 100%; height: 160px; background-color: #f1f5f9; object-fit: cover; border-bottom: 1px solid #e5e7eb;}
        .no-image { height: 160px; display: flex; align-items: center; justify-content: center; background: #f9fafb; color: #9ca3af; font-size: 0.85rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; }

        .card-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        
        .card-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .incident-date { font-size: 0.8rem; color: #6b7280; font-weight: 600; }
        
        .incident-type-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-emergency { background-color: #fee2e2; color: #dc2626; }
        .badge-missed { background-color: #fef3c7; color: #b45309; }
        .badge-general { background-color: #e0e7ff; color: #4338ca; }

        .incident-title { font-size: 1.125rem; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .incident-location { font-size: 0.85rem; color: #4b5563; margin-bottom: 12px; display: flex; align-items: center; gap: 4px; }
        .incident-desc { font-size: 0.95rem; color: #374151; line-height: 1.5; margin-bottom: 16px; flex: 1; }
        
        .card-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 16px; border-top: 1px solid #f1f5f9; font-size: 0.85rem; color: #6b7280; }
        .status { font-weight: 600; }
        .status-active { color: #ef4444; }
        .status-resolved { color: #10b981; }

        .empty-state { text-align: center; padding: 60px 20px; grid-column: 1 / -1; color: #6b7280; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.7); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; animation: modalFadeIn 0.3s ease-out forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-icon { width: 48px; height: 48px; background: #ffe4e6; color: #e11d48; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .modal-text { color: #6b7280; font-size: 0.95rem; margin-bottom: 24px; line-height: 1.5; }
        .modal-actions { display: flex; gap: 12px; }
        .btn-modal { flex: 1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm { background: #e11d48; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .btn-confirm:hover { background: #be123c; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            Client Portal
        </div>
        <ul class="nav-links">
            <li><a href="client_dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="client_qrs.php" class="nav-link">Checkpoints</a></li>
            <li><a href="client_patrol_history.php" class="nav-link">Patrol History</a></li>
            <li><a href="client_incidents.php" class="nav-link active">Incident Reports</a></li>
            <li><a href="client_reports.php" class="nav-link">General Reports</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" onclick="document.getElementById('logoutModal').classList.add('show'); return false;">Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Topbar -->
        <header class="topbar">
            <h2>Incident Reports & Alerts</h2>
            <div class="user-info">
                <span>Welcome, <strong><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Client'; ?></strong></span>
                <span class="badge">CLIENT</span>
            </div>
        </header>

        <div class="content-area">
            <div class="incidents-grid">
                <?php if ($incidents_result && $incidents_result->num_rows > 0): ?>
                    <?php while($row = $incidents_result->fetch_assoc()): ?>
                        <?php 
                            $type_class = 'type-' . htmlspecialchars($row['incident_type']);
                            $badge_class = 'badge-general';
                            $display_type = 'General Alert';
                            
                            if ($row['incident_type'] === 'emergency') {
                                $badge_class = 'badge-emergency';
                                $display_type = 'Emergency';
                            } else if ($row['incident_type'] === 'missed_patrol') {
                                $badge_class = 'badge-missed';
                                $display_type = 'Missed Patrol';
                            }

                            $status_class = strtolower($row['status']) === 'active' ? 'status-active' : 'status-resolved';
                        ?>
                        <div class="incident-card <?php echo $type_class; ?>">
                            <?php if (!empty($row['photo_path']) && $row['photo_path'] !== 'NULL'): ?>
                                <img src="<?php echo htmlspecialchars($row['photo_path']); ?>" alt="Incident Evidence" class="card-image">
                            <?php else: ?>
                                <div class="no-image">No Photo Attached</div>
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <div class="card-meta">
                                    <span class="incident-date"><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></span>
                                    <span class="incident-type-badge <?php echo $badge_class; ?>"><?php echo $display_type; ?></span>
                                </div>
                                <h3 class="incident-title">Guard: <?php echo htmlspecialchars($row['guard_name']); ?></h3>
                                <div class="incident-location">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                    <?php echo htmlspecialchars($row['checkpoint_name'] ?? 'Unknown Location'); ?>
                                </div>
                                <p class="incident-desc"><?php echo nl2br(htmlspecialchars($row['description'])); ?></p>
                                
                                <div class="card-footer">
                                    <span>Status: <span class="status <?php echo $status_class; ?>"><?php echo htmlspecialchars($row['status']); ?></span></span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin:0 auto 16px; opacity:0.5;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <h3>All Secure</h3>
                        <p>No incidents or alerts have been reported for your locations.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

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
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>
