<?php
/**
 * Global Topbar Component
 */
?>
<header class="topbar">
    <h2><?php echo $header_title ?? 'Overview'; ?></h2>
    <div class="user-pill">
        <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></strong></span>
        <span class="role-badge">Admin</span>
    </div>
</header>
