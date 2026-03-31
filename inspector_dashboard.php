<?php
require_once 'auth_check.php';

if ($_SESSION['user_level'] !== 'inspector') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get Inspector Details
$insp_stmt = $conn->prepare("SELECT id, name FROM inspectors WHERE user_id = ?");
$insp_stmt->bind_param("i", $user_id);
$insp_stmt->execute();
$insp_res = $insp_stmt->get_result();
$inspector = $insp_res->fetch_assoc();

if (!$inspector) {
    echo "Inspector profile not found.";
    exit();
}

$inspector_id = $inspector['id'];
$inspector_name = $inspector['name'];

// Get Assigned Sites
$sites_sql = "
    SELECT ac.id, ac.site_name, ac.company_name, cp.id as checkpoint_id, cp.checkpoint_code as qr_code
    FROM inspector_assignments ia
    JOIN agency_clients ac ON ia.agency_client_id = ac.id
    LEFT JOIN checkpoints cp ON cp.agency_client_id = ac.id AND (cp.name LIKE '%Starting Point%' OR cp.is_zero_checkpoint = 1)
    WHERE ia.inspector_id = $inspector_id
    GROUP BY ac.id
";
$sites_res = $conn->query($sites_sql);
$sites = [];
while ($row = $sites_res->fetch_assoc()) {
    $sites[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Portal - Sentinel Tour</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --brand-green: #0eb06b;
            --brand-dark: #0a192f;
            --bg-light: #f8fafc;
            --white: #ffffff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: var(--text-dark); }

        .header {
            background: var(--brand-dark);
            color: white;
            padding: 1.5rem;
            text-align: center;
            border-bottom: 4px solid var(--brand-green);
        }

        .header h1 { font-family: 'Outfit', sans-serif; font-size: 1.5rem; }
        .header p { font-size: 0.875rem; opacity: 0.8; }

        .container { max-width: 600px; margin: 2rem auto; padding: 0 1rem; }

        .step-card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            display: none;
            animation: slideUp 0.4s ease-out;
        }

        .step-card.active { display: block; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 { font-family: 'Outfit', sans-serif; margin-bottom: 1rem; color: var(--brand-dark); }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.875rem; }

        select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-family: inherit;
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 1rem;
            background: var(--brand-dark);
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            text-decoration: none;
        }

        .btn:hover { background: var(--brand-green); transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 2px solid var(--brand-dark); color: var(--brand-dark); }
        .btn-outline:hover { background: var(--brand-dark); color: white; }

        #reader { width: 100%; border-radius: 0.5rem; overflow: hidden; margin-bottom: 1rem; }

        .preview-container {
            width: 100%;
            aspect-ratio: 4/3;
            background: #eee;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #camera-feed, #photo-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        #photo-preview { display: none; }

        .controls { display: flex; gap: 1rem; margin-top: 1rem; }

        .logout-btn {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>

<div class="header">
    <p>Welcome, Inspector</p>
    <h1><?php echo htmlspecialchars($inspector_name); ?></h1>
</div>

<div class="container">
    <!-- STEP 0: SELECT SITE -->
    <div id="step-0" class="step-card active">
        <h2>Select Client Site</h2>
        <div class="form-group">
            <label>Site to Inspect</label>
            <select id="site-selector">
                <option value="">-- Choose a Site --</option>
                <?php foreach ($sites as $site): ?>
                    <option value="<?php echo $site['id']; ?>" data-qr="<?php echo htmlspecialchars($site['qr_code']); ?>" data-cid="<?php echo $site['checkpoint_id']; ?>">
                        <?php echo htmlspecialchars($site['site_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" onclick="goToStep(1)">Begin Inspection</button>
    </div>

    <!-- STEP 1: SCAN QR -->
    <div id="step-1" class="step-card">
        <h2>Scan Starting Point QR</h2>
        <p style="margin-bottom: 1rem; color: var(--text-muted);">Please scan the QR code at the starting location of the site.</p>
        <div id="reader"></div>
        <div class="controls">
            <button class="btn btn-outline" onclick="goToStep(0)">Back</button>
        </div>
    </div>

    <!-- STEP 2: TAKE SELFIE -->
    <div id="step-2" class="step-card">
        <h2>Selfie with Guard</h2>
        <p style="margin-bottom: 1rem; color: var(--text-muted);">Capture a clear selfie with the duty guard.</p>
        
        <div class="preview-container">
            <video id="camera-feed" autoplay playsinline></video>
            <img id="photo-preview" src="" alt="Selfie preview">
        </div>

        <div id="camera-controls">
            <button class="btn" onclick="takeSnapshot()">Capture Photo</button>
        </div>
        <div id="preview-controls" style="display: none;">
            <button class="btn btn-outline" onclick="retakePhoto()" style="margin-bottom: 0.5rem;">Retake</button>
            <button class="btn" onclick="goToStep(3)">Next Step</button>
        </div>
        
        <div class="controls">
            <button class="btn btn-outline" onclick="goToStep(1)">Back</button>
        </div>
    </div>

    <!-- STEP 3: SUBMIT REPORT -->
    <div id="step-3" class="step-card">
        <h2>Inspection Report</h2>
        <div class="form-group">
            <label>Inspection Status</label>
            <select id="status">
                <option value="Routine">Routine</option>
                <option value="Emergency">Emergency</option>
                <option value="Follow-up">Follow-up</option>
                <option value="Irregularity Found">Irregularity Found</option>
            </select>
        </div>
        <div class="form-group">
            <label>Remarks / Observations</label>
            <textarea id="remarks" rows="4" placeholder="Enter detailed observations..."></textarea>
        </div>
        <button class="btn" onclick="submitInspection()" id="submit-btn">Submit Report</button>
        <div class="controls">
            <button class="btn btn-outline" onclick="goToStep(2)">Back</button>
        </div>
    </div>
</div>

<a href="logout.php" class="logout-btn">LOGOUT</a>

<script>
    let currentStep = 0;
    let selectedSiteId = null;
    let targetQRCode = null;
    let targetCheckpointId = null;
    let html5QrCode = null;
    let stream = null;
    let capturedPhotoFile = null;

    function goToStep(step) {
        if (step === 1) {
            selectedSiteId = document.getElementById('site-selector').value;
            if (!selectedSiteId) {
                Swal.fire('Error', 'Please select a site first.', 'error');
                return;
            }
            const opt = document.querySelector(`#site-selector option[value="${selectedSiteId}"]`);
            targetQRCode = opt.getAttribute('data-qr');
            targetCheckpointId = opt.getAttribute('data-cid');
            startScanner();
        } else if (step === 2) {
            stopScanner();
            startCamera();
        } else if (step === 3) {
            stopCamera();
        }

        document.querySelectorAll('.step-card').forEach(card => card.classList.remove('active'));
        document.getElementById(`step-${step}`).classList.add('active');
        currentStep = step;
    }

    // QR SCANNER LOGIC
    function startScanner() {
        html5QrCode = new Html5Qrcode("reader");
        const config = { fps: 10, qrbox: { width: 250, height: 250 } };
        
        html5QrCode.start({ facingMode: "environment" }, config, (decodedText) => {
            if (decodedText === targetQRCode) {
                Swal.fire({
                    icon: 'success',
                    title: 'QR Verified',
                    text: 'Checkpoint identified successfully.',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    goToStep(2);
                });
            } else {
                Swal.fire('Invalid QR', 'This is not the correct starting point for the selected site.', 'warning');
            }
        });
    }

    function stopScanner() {
        if (html5QrCode) {
            html5QrCode.stop().catch(err => console.error(err));
        }
    }

    // CAMERA LOGIC
    async function startCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ 
                video: { facingMode: "user" }, 
                audio: false 
            });
            const video = document.getElementById('camera-feed');
            video.srcObject = stream;
        } catch (err) {
            Swal.fire('Camera Error', 'Could not access the camera. Please ensure permissions are granted.', 'error');
        }
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }
    }

    function takeSnapshot() {
        const video = document.getElementById('camera-feed');
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        canvas.toBlob((blob) => {
            capturedPhotoFile = new File([blob], "selfie.jpg", { type: "image/jpeg" });
            const url = URL.createObjectURL(blob);
            document.getElementById('photo-preview').src = url;
            document.getElementById('photo-preview').style.display = 'block';
            document.getElementById('camera-feed').style.display = 'none';
            document.getElementById('camera-controls').style.display = 'none';
            document.getElementById('preview-controls').style.display = 'block';
        }, 'image/jpeg');
    }

    function retakePhoto() {
        document.getElementById('photo-preview').src = '';
        document.getElementById('photo-preview').style.display = 'none';
        document.getElementById('camera-feed').style.display = 'block';
        document.getElementById('camera-controls').style.display = 'block';
        document.getElementById('preview-controls').style.display = 'none';
        capturedPhotoFile = null;
    }

    // FINAL SUBMISSION
    async function submitInspection() {
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerText = 'Submitting...';

        const formData = new FormData();
        formData.append('inspector_id', '<?php echo $inspector_id; ?>');
        formData.append('agency_client_id', selectedSiteId);
        formData.append('checkpoint_id', targetCheckpointId);
        formData.append('status', document.getElementById('status').value);
        formData.append('remarks', document.getElementById('remarks').value);
        formData.append('photo', capturedPhotoFile);

        try {
            const response = await fetch('api/save_inspector_report.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Inspection report submitted successfully.',
                }).then(() => {
                    window.location.reload();
                });
            } else {
                throw new Error(result.message || 'Submission failed');
            }
        } catch (error) {
            Swal.fire('Error', error.message, 'error');
            btn.disabled = false;
            btn.innerText = 'Submit Report';
        }
    }
</script>

</body>
</html>
