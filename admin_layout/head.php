<?php
/**
 * Global Header Component
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin Dashboard'; ?> - Guard Tour System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --background: #f8fafc;
            --sidebar: #0f172a;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { display: flex; height: 100vh; background-color: var(--background); color: var(--text-main); padding: 0 16px 0 0; gap: 16px; transition: padding 0.3s ease; }

        /* Sidebar Styles */
        .sidebar { width: 260px; background-color: var(--sidebar); color: #fff; display: flex; flex-direction: column; transition: all 0.3s ease; overflow: hidden; }
        .sidebar-header { padding: 32px 24px; font-size: 1.5rem; font-weight: 800; text-align: center; border-bottom: 1px solid #1e293b; letter-spacing: -0.5px; }
        .nav-links { list-style: none; flex: 1; padding: 20px 0; }
        .nav-link { padding: 14px 24px; display: flex; align-items: center; color: #94a3b8; text-decoration: none; font-weight: 500; transition: all 0.2s; border-left: 4px solid transparent; gap: 12px; }
        .nav-link:hover, .nav-link.active { background-color: rgba(255,255,255,0.05); color: #fff; border-left-color: var(--primary); }
        
        .submenu { display: none; background-color: rgba(0,0,0,0.2); list-style: none; }
        .submenu.open { display: block; }
        .submenu-link { padding: 10px 24px 10px 52px; display: block; color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: all 0.2s; }
        .submenu-link:hover, .submenu-link.active { color: #fff; }

        .sidebar-footer { padding: 24px; border-top: 1px solid #1e293b; }
        .logout-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px; background-color: var(--danger); color: white; text-decoration: none; border-radius: var(--radius-md); font-weight: 600; transition: opacity 0.2s; cursor: pointer;}
        .logout-btn:hover { opacity: 0.9; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; border-radius: var(--radius-lg); border: 1px solid var(--border); background: var(--card-bg); transition: all 0.3s ease; }
        .topbar { background: var(--card-bg); padding: 16px 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 10; transition: padding 0.3s ease; }
        .topbar h2 { font-size: 1.5rem; font-weight: 700; color: var(--text-main); letter-spacing: -0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-pill { padding: 6px 16px; background: #f1f5f9; border-radius: 9999px; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; border: 1px solid var(--border); flex-shrink: 0; }
        .role-badge { background: var(--primary); color: white; padding: 2px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }

        .content-area { padding: 40px; max-width: 1400px; margin: 0 auto; width: 100%; transition: padding 0.3s ease; }

        /* Cards & Forms */
        .card { background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow); margin-bottom: 32px; }
        .card-header { padding: 24px 32px; border-bottom: 1px solid var(--border); background: #fafafa; }
        .card-header h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-main); }
        .card-body { padding: 32px; }

        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
        @media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 0.95rem; transition: all 0.2s; background: #fbfcfd; }
        .form-control:focus { outline: none; border-color: var(--primary); background: #fff; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }
        
        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.95rem; }
        .btn-primary { background: var(--primary); color: white; width: 100%; }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2); }
        .btn-danger { background-color: white; color: var(--danger); border: 1.5px solid var(--danger); }
        .btn-danger:hover { background-color: #fee2e2; }

        /* Table Design */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { background: #f8fafc; padding: 16px 24px; text-align: left; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid var(--border); }
        td { padding: 20px 24px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fcfdfe; }

        /* Badges & Status */
        .status-badge { padding: 4px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .status-success { background: #dcfce7; color: #166534; }
        .status-danger { background: #fee2e2; color: #991b1b; }
        .status-warning { background: #fef3c7; color: #92400e; }

        /* Alerts */
        .alert { padding: 16px 24px; border-radius: var(--radius-md); margin-bottom: 32px; display: flex; align-items: center; gap: 12px; font-weight: 500; animation: slideDown 0.3s ease; border: 1px solid transparent; }
        .alert-success { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        /* Modal */
        .modal, .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); z-index: 1000; overflow-y: auto; padding: 20px; align-items: center; justify-content: center; }
        .modal.show, .modal-overlay.show { display: flex; }
        .modal-content { background: white; padding: 40px; border-radius: 24px; max-width: 560px; width: 100%; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); margin: auto; transition: all 0.3s ease; }
        .modal-icon { width: 64px; height: 64px; background: #fee2e2; color: var(--danger); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 2rem; }

        /* Mobile Adjustments */
        .mobile-toggle { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-main); padding: 8px; }
        .sidebar-close { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; position: absolute; top: 20px; right: 20px; }

        @media (max-width: 1024px) {
            body { padding: 0; gap: 0; }
            .sidebar { position: fixed; left: -260px; top: 0; bottom: 0; z-index: 1001; width: 260px; transition: left 0.3s ease; box-shadow: 10px 0 20px rgba(0,0,0,0.2); }
            .sidebar.show { left: 0; }
            .sidebar-close { display: block; }
            .main-content { border-radius: 0; border: none; }
            .topbar { padding: 16px 20px; }
            .mobile-toggle { display: block; }
            .content-area { padding: 24px 16px; }
            .card { margin-bottom: 24px; }
            .card-header, .card-body { padding: 20px 16px; }
            .modal-content { padding: 32px 20px; border-radius: 20px; }
            .modal { padding: 20px 10px; }
            
            /* Responsive Tables */
            .table-container { -webkit-overflow-scrolling: touch; overflow-x: auto; width: 100%; }
            table { min-width: 700px; }
            th, td { padding: 12px 16px; }
        }

        @media (max-width: 640px) {
            .topbar { padding: 12px 16px; }
            .topbar h2 { font-size: 1.1rem; }
            .user-pill { padding: 4px 10px; font-size: 0.8rem; }
            .user-pill span { display: none; }
            .user-pill strong { display: block; }
            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            .btn { width: 100%; }
            .content-area { padding: 16px 12px; }
            .card-header, .card-body { padding: 16px; }
            .modal-content { padding: 24px 16px; border-radius: 16px; }
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; backdrop-filter: blur(2px); }
        .sidebar-overlay.show { display: block; }

        /* Helper Utilities */
        .text-muted { color: var(--text-muted); }
        .gap-2 { gap: 0.5rem; }
    </style>
    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                const newOverlay = document.createElement('div');
                newOverlay.className = 'sidebar-overlay';
                newOverlay.onclick = toggleSidebar;
                document.body.appendChild(newOverlay);
            }
            sidebar.classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="js/modal_system.js?v=<?php echo time(); ?>"></script>
</head>
<body>
