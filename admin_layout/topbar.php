<?php
/**
 * Global Topbar Component
 */
?>
<header class="topbar">
    <div style="display: flex; align-items: center; gap: 12px;">
        <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
        <h2><?php echo $header_title ?? 'Overview'; ?></h2>
    </div>
    <div class="user-pill">
        <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></strong></span>
        <span class="role-badge">Admin</span>
    </div>
</header>
