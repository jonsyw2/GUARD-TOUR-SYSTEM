<?php
/**
 * Global Footer and Modals Component
 */
?>
    <!-- Logout Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-content">
            <div class="modal-icon">⚠️</div>
            <h3 style="margin-bottom: 12px; font-size: 1.5rem;">Confirm Sign Out</h3>
            <p style="color: var(--text-muted); margin-bottom: 32px;">Are you sure you want to end your current dashboard session? You will need to log in again to access the panel.</p>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <button class="btn" style="flex: 1; min-width: 140px; background: #f1f5f9; color: var(--text-main);" onclick="document.getElementById('logoutModal').classList.remove('show');">Stay Logged In</button>
                <a href="logout.php" class="btn btn-primary" style="flex: 1; min-width: 140px; background: var(--danger); text-decoration: none;">Yes, Sign Out</a>
            </div>
        </div>
    </div>

    <script>
        function toggleSubmenu(menuId, element) {
            const menu = document.getElementById(menuId);
            if (menu) {
                menu.classList.toggle('open');
            }
        }

        window.onclick = function(e) {
            const modal = document.getElementById('logoutModal');
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
    <?php include_once 'includes/common_modals.php'; ?>
</body>
</html>
