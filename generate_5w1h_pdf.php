<?php
session_start();
require_once('db_config.php');

if (!isset($_GET['id'])) {
    die("Report ID missing.");
}

$id = intval($_GET['id']);
$sql = "
    SELECT i.*, 
           CASE WHEN i.reporter_role = 'inspector' THEN ins.name ELSE g.name END as reporter_name,
           ac.site_name,
           a.agency_name,
           a.username as agency_username
    FROM incidents i
    LEFT JOIN guards g ON i.guard_id = g.id AND (i.reporter_role = 'guard' OR i.reporter_role IS NULL)
    LEFT JOIN inspectors ins ON i.guard_id = ins.id AND i.reporter_role = 'inspector'
    LEFT JOIN agency_clients ac ON i.agency_client_id = ac.id
    LEFT JOIN users a ON i.agency_id = a.id
    WHERE i.id = $id
";
$res = $conn->query($sql);
if (!$res || $res->num_rows == 0) {
    die("Report not found.");
}

$report = $res->fetch_assoc();
$prefix = ($report['reporter_role'] == 'inspector') ? 'Insp. ' : 'SG. ';
$full_reporter_name = $prefix . ($report['reporter_name'] ?: 'Unknown');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report - <?php echo $report['id']; ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap');
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1f2937; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; border: 1px solid #e5e7eb; padding: 40px; border-radius: 8px; background: white; }
        .header { text-align: center; border-bottom: 2px solid #3b82f6; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #111827; font-size: 24px; text-transform: uppercase; letter-spacing: 1px; }
        .header p { margin: 5px 0 0; color: #6b7280; font-size: 14px; }
        
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 40px; background: #f9fafb; padding: 20px; border-radius: 8px; }
        .meta-item { display: flex; flex-direction: column; }
        .meta-label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; }
        .meta-value { font-size: 15px; font-weight: 600; color: #111827; }

        .report-section { margin-bottom: 25px; }
        .report-label { font-size: 13px; font-weight: 700; color: #3b82f6; text-transform: uppercase; margin-bottom: 8px; border-bottom: 1px solid #dbeafe; display: inline-block; }
        .report-content { font-size: 16px; color: #374151; white-space: pre-wrap; background: #fff; padding: 10px 0; }

        .footer { margin-top: 50px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #f3f4f6; padding-top: 20px; }
        
        #download-btn { position: fixed; top: 20px; right: 20px; padding: 12px 24px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        #download-btn:hover { background: #2563eb; }

        @media print {
            #download-btn { display: none; }
            body { padding: 0; }
            .container { border: none; width: 100%; max-width: 100%; }
        }
    </style>
</head>
<body>

<button id="download-btn" onclick="generatePDF()">Download as PDF</button>

<div class="container" id="report-content">
    <div class="header">
        <h1>Report</h1>
        <p><?php echo htmlspecialchars($report['agency_name'] ?: $report['agency_username']); ?> Security Services</p>
    </div>

    <div class="meta-grid">
        <div class="meta-item">
            <span class="meta-label">Reported By</span>
            <span class="meta-value"><?php echo htmlspecialchars($full_reporter_name); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Site / Location</span>
            <span class="meta-value"><?php echo htmlspecialchars($report['site_name'] ?: 'General Site'); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Date Submitted</span>
            <span class="meta-value"><?php echo date('F d, Y h:i A', strtotime($report['created_at'])); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Report Reference</span>
            <span class="meta-value">#IR-<?php echo str_pad($report['id'], 5, '0', STR_PAD_LEFT); ?></span>
        </div>
    </div>

    <div class="report-section">
        <div class="report-label">What happened?</div>
        <div class="report-content"><?php echo htmlspecialchars($report['report_what'] ?: '---'); ?></div>
    </div>

    <div class="report-section">
        <div class="report-label">Who was involved?</div>
        <div class="report-content"><?php echo htmlspecialchars($report['report_who'] ?: '---'); ?></div>
    </div>

    <div class="report-section">
        <div class="report-label">When did it occur?</div>
        <div class="report-content"><?php echo htmlspecialchars($report['report_when'] ?: '---'); ?></div>
    </div>

    <div class="report-section">
        <div class="report-label">Where exactly?</div>
        <div class="report-content"><?php echo htmlspecialchars($report['report_where'] ?: '---'); ?></div>
    </div>

    <div class="report-section">
        <div class="report-label">Why did it happen?</div>
        <div class="report-content"><?php echo htmlspecialchars($report['report_why'] ?: '---'); ?></div>
    </div>

    <div class="report-section">
        <div class="report-label">How was it handled?</div>
        <div class="report-content"><?php echo htmlspecialchars($report['report_how'] ?: '---'); ?></div>
    </div>

    <div class="footer">
        This is an official document generated by the Guard Tour Monitoring System.<br>
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($report['agency_name'] ?: $report['agency_username']); ?>
    </div>
</div>

<script>
    function generatePDF() {
        const element = document.getElementById('report-content');
        const opt = {
            margin:       0.5,
            filename:     'Report_<?php echo $report['id']; ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2 },
            jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
        };
        
        // Hide button during capture
        document.getElementById('download-btn').style.display = 'none';
        
        html2pdf().set(opt).from(element).save().then(() => {
            document.getElementById('download-btn').style.display = 'block';
        });
    }
    
    // Auto trigger if needed, or just let user click
</script>

</body>
</html>
