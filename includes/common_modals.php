<style>
    /* Global Modal System Styles */
    .global-modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(17, 24, 39, 0.7);
        z-index: 10000; /* Ensure it stays above everything */
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
        font-family: 'Inter', sans-serif;
    }
    .global-modal-overlay.show { display: flex; }
    .global-modal-content {
        background: white;
        padding: 32px;
        border-radius: 12px;
        width: 100%;
        max-width: 420px;
        text-align: center;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        animation: globalModalFadeIn 0.3s ease-out forwards;
        position: relative;
    }
    @keyframes globalModalFadeIn {
        from { opacity: 0; transform: translateY(20px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .global-modal-icon {
        width: 60px; height: 60px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 20px;
        font-size: 1.5rem;
        font-weight: 800;
    }
    .global-modal-content h3 { margin: 0 0 10px 0; font-size: 1.25rem; font-weight: 700; color: #111827; }
    .global-modal-content p { color: #6b7280; margin: 0 0 24px 0; line-height: 1.5; font-size: 0.95rem; }
    
    .global-modal-btn {
        width: 100%;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        display: flex; align-items: center; justify-content: center;
    }
    .global-modal-btn-primary { background: #10b981; color: white; }
    .global-modal-btn-primary:hover { background: #059669; }
    .global-modal-btn-cancel { background: #f3f4f6; color: #374151; }
    .global-modal-btn-cancel:hover { background: #e5e7eb; }
    .global-modal-actions { display: flex; gap: 12px; }
</style>

<!-- Global Alert Modal -->
<div id="globalAlertModal" class="global-modal-overlay" onclick="if(event.target === this) CustomModal.closeAlert()">
    <div class="global-modal-content">
        <div id="globalAlertIcon" class="global-modal-icon">!</div>
        <h3 id="globalAlertTitle">Notice</h3>
        <p id="globalAlertText"></p>
        <button class="global-modal-btn global-modal-btn-primary" onclick="CustomModal.closeAlert()">OK</button>
    </div>
</div>

<!-- Global Confirm Modal -->
<div id="globalConfirmModal" class="global-modal-overlay" onclick="if(event.target === this && typeof window._modalCancelAction === 'function') window._modalCancelAction()">
    <div class="global-modal-content">
        <h3 id="globalConfirmTitle">Confirm Action</h3>
        <p id="globalConfirmText"></p>
        <div class="global-modal-actions">
            <button class="global-modal-btn global-modal-btn-cancel" onclick="if(typeof window._modalCancelAction === 'function') window._modalCancelAction()">Cancel</button>
            <button class="global-modal-btn global-modal-btn-primary" onclick="if(typeof window._modalConfirmAction === 'function') window._modalConfirmAction()">Confirm</button>
        </div>
    </div>
</div>
