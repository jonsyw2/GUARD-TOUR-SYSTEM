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
        margin: 20px;
        transition: all 0.3s ease;
    }
    @media (max-width: 480px) {
        .global-modal-content {
            padding: 24px 16px;
            margin: 10px;
            max-width: 95%;
        }
        .global-modal-icon {
            width: 48px; height: 48px;
            font-size: 1.2rem;
            margin-bottom: 16px;
        }
        .global-modal-content h3 { font-size: 1.1rem; }
        .global-modal-content p { font-size: 0.85rem; margin-bottom: 20px; }
        .global-modal-btn { padding: 10px 16px; font-size: 0.9rem; }
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
        @page { size: auto; margin: 0; }
        body * { visibility: hidden; }
        #globalQRModal, #globalQRModal * { visibility: visible; }
        #globalQRModal { 
            position: fixed; left: 0; top: 0; width: 100%; height: 100%; 
            display: flex !important; justify-content: center; align-items: center; 
            background: white !important; backdrop-filter: none !important; 
        }
        .global-modal-content { 
            box-shadow: none !important; border: 1px dashed #94a3b8 !important; 
            width: 2.125in !important; height: 3.375in !important; 
            max-width: none !important; padding: 15px 10px !important; 
            display: flex !important; flex-direction: column !important; 
            justify-content: space-around !important; align-items: center !important;
            border-radius: 0 !important;
            text-align: center !important;
        }
        .global-modal-btn, button[onclick="CustomModal.closeQR()"] { display: none !important; }
        
        /* Header adjustment for print */
        .global-modal-content div:first-child { 
            display: block !important; 
            margin-bottom: 0 !important; 
            width: 100% !important;
        }
        
        #qrModalSubtitle { 
            font-size: 0.95rem !important; 
            font-weight: 700 !important;
            margin: 0 !important; 
            text-align: center !important;
            word-wrap: break-word !important;
        }
        
        #globalQRCodeContainer { 
            border: none !important; padding: 0 !important; 
            margin: 10px 0 !important; min-height: auto !important;
            display: flex !important; justify-content: center !important;
        }
        #globalQRCodeContainer img, #globalQRCodeContainer canvas {
            width: 1.6in !important; 
            height: 1.6in !important;
        }
        
        #qrModalTitle { 
            font-size: 0.95rem !important; 
            font-weight: 700 !important;
            margin: 0 !important; 
            text-align: center !important;
            word-wrap: break-word !important;
        }
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
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <p id="qrModalSubtitle" style="margin: 0; font-size: 1rem; font-weight: 600; color: #111827;"></p>
            <button onclick="CustomModal.closeQR()" style="background: #f3f4f6; border: none; border-radius: 6px; width: 32px; height: 32px; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">&times;</button>
        </div>
        
        <div id="globalQRCodeContainer" style="background: white; padding: 24px; border-radius: 16px; border: 2px dashed #e2e8f0; display: flex; justify-content: center; align-items: center; margin-bottom: 12px; min-height: 256px;">
            <!-- QR Code generated here -->
        </div>

        <h3 id="qrModalTitle" style="margin: 0 0 16px 0; color: #111827; font-size: 1.15rem; font-weight: 700; text-align: center;">QR Checkpoint</h3>

        <button class="global-modal-btn global-modal-btn-primary" onclick="window.print()">
            🖨️ Print QR Code
        </button>
    </div>
</div>
