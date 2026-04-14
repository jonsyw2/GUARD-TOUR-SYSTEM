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

    /* Print Specific Styles */
    @media print {
        body * { visibility: hidden; }
        #globalQRModal, #globalQRModal * { visibility: visible; }
        #globalQRModal { position: fixed; left: 0; top: 0; width: 100%; height: 100%; display: flex !important; justify-content: center; align-items: center; background: white !important; backdrop-filter: none !important; }
        .global-modal-content { box-shadow: none !important; border: none !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; }
        .global-modal-btn, button[onclick="CustomModal.closeQR()"] { display: none !important; }
        #globalQRCodeContainer { border: none !important; padding: 0 !important; }
    }
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

<!-- Global QR Display Modal -->
<div id="globalQRModal" class="global-modal-overlay" onclick="if(event.target === this) CustomModal.closeQR()">
    <div class="global-modal-content" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
            <div style="text-align: left;">
                <h3 id="qrModalTitle" style="margin: 0; color: #111827; font-size: 1.25rem;">QR Checkpoint</h3>
                <p id="qrModalSubtitle" style="margin: 4px 0 0 0; font-size: 0.85rem; color: #6b7280;"></p>
            </div>
            <button onclick="CustomModal.closeQR()" style="background: #f3f4f6; border: none; border-radius: 6px; width: 32px; height: 32px; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">&times;</button>
        </div>
        
        <div id="globalQRCodeContainer" style="background: white; padding: 24px; border-radius: 16px; border: 2px dashed #e2e8f0; display: flex; justify-content: center; align-items: center; margin-bottom: 24px; min-height: 256px;">
            <!-- QR Code generated here -->
        </div>

        <div style="background: #f8fafc; padding: 16px; border-radius: 12px; margin-bottom: 24px; text-align: left;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <span id="qrCodeValue" style="font-family: 'Courier New', monospace; font-weight: 700; font-size: 1.1rem; color: #4f46e5; letter-spacing: 0.05em;"></span>
                <span style="font-size: 0.7rem; background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 4px; font-weight: 700; text-transform: uppercase;">KEY</span>
            </div>
            <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">This code is uniquely assigned to this checkpoint. Scan this code using the Guard App to record a visit.</p>
        </div>

        <button class="global-modal-btn global-modal-btn-primary" onclick="window.print()">
            🖨️ Print QR Code
        </button>
    </div>
</div>
