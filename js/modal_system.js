window.CustomModal = {
    _resolve: null,
    
    alert: function(message, title = 'Notice', type = 'info') {
        return new Promise((resolve) => {
            const modal = document.getElementById('globalAlertModal');
            if (!modal) {
                console.warn("Global alert modal not found, using fallback.");
                alert(message);
                resolve();
                return;
            }
            
            const titleEl = document.getElementById('globalAlertTitle');
            const textEl = document.getElementById('globalAlertText');
            const iconEl = document.getElementById('globalAlertIcon');
            
            if (titleEl) titleEl.innerText = title;
            if (textEl) textEl.innerText = message;
            
            if (iconEl) {
                if (type === 'success') {
                    iconEl.style.background = '#d1fae5';
                    iconEl.style.color = '#10b981';
                    iconEl.innerText = '✓';
                } else if (type === 'error' || type === 'danger') {
                    iconEl.style.background = '#fee2e2';
                    iconEl.style.color = '#ef4444';
                    iconEl.innerText = '!';
                } else {
                    iconEl.style.background = '#f1f5f9';
                    iconEl.style.color = '#64748b';
                    iconEl.innerText = 'i';
                }
            }
            
            modal.classList.add('show');
            this._resolve = resolve;
        });
    },
    
    confirm: function(message, title = 'Confirm Action') {
        return new Promise((resolve) => {
            const modal = document.getElementById('globalConfirmModal');
            if (!modal) {
                console.warn("Global confirm modal not found, using fallback.");
                resolve(confirm(message));
                return;
            }
            
            const titleEl = document.getElementById('globalConfirmTitle');
            const textEl = document.getElementById('globalConfirmText');
            
            if (titleEl) titleEl.innerText = title;
            if (textEl) textEl.innerText = message;
            
            modal.classList.add('show');
            
            window._modalConfirmAction = () => {
                modal.classList.remove('show');
                resolve(true);
            };
            
            window._modalCancelAction = () => {
                modal.classList.remove('show');
                resolve(false);
            };
        });
    },
    
    confirmForm: async function(event, message) {
        event.preventDefault();
        const form = event.target;
        const res = await this.confirm(message);
        if (res) {
            form.submit();
        }
    },
    
    closeAlert: function() {
        const modal = document.getElementById('globalAlertModal');
        if (modal) modal.classList.remove('show');
        if (this._resolve) {
            const r = this._resolve;
            this._resolve = null; // Clear before resolving to prevent recursion if resolve calls another alert
            r();
        }
    }
};

// Global listener for escape key to close modals
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const alertModal = document.getElementById('globalAlertModal');
        const confirmModal = document.getElementById('globalConfirmModal');
        if (alertModal && alertModal.classList.contains('show')) {
            CustomModal.closeAlert();
        }
        if (confirmModal && confirmModal.classList.contains('show')) {
            if (typeof window._modalCancelAction === 'function') window._modalCancelAction();
        }
    }
});
