<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

function sendError($msg, $details = null, $file = '', $line = '') {
    $log = "[" . date('Y-m-d H:i:s') . "] ERROR: $msg | File: $file | Line: $line | Details: " . json_encode($details) . "\n";
    file_put_contents('debug_visual.log', $log, FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => $msg,
        'details' => $details,
        'file' => $file,
        'line' => $line
    ]);
    exit;
}

try {
    $config_path = dirname(__DIR__) . '/db_config.php';
    if (!file_exists($config_path)) {
        sendError("Config file missing: " . basename($config_path), null, __FILE__, __LINE__);
    }
    require_once $config_path;

    if (!isset($conn) || $conn->connect_error) {
        sendError("Database connection failed", ($conn->connect_error ?? 'Not initialized'), __FILE__, __LINE__);
    }

    $tour_session_id = $_GET['tour_session_id'] ?? '';
    $mapping_id = isset($_GET['mapping_id']) ? (int)$_GET['mapping_id'] : 0;

    // Resolve mapping_id from tour_session_id if missing
    if ($mapping_id === 0 && !empty($tour_session_id)) {
        $m_stmt = $conn->prepare("SELECT agency_client_id FROM scans s JOIN checkpoints c ON s.checkpoint_id = c.id WHERE s.tour_session_id = ? LIMIT 1");
        if ($m_stmt) {
            $m_stmt->bind_param("s", $tour_session_id);
            $m_stmt->execute();
            $m_stmt->bind_result($resolved_id);
            if ($m_stmt->fetch()) {
                $mapping_id = (int)$resolved_id;
            }
            $m_stmt->close();
        }
    }

    if ($mapping_id === 0) {
        sendError("Site Identification Error: No mapping_id provided or resolved.", null, __FILE__, __LINE__);
    }

    $sql = "
        SELECT cp.id, cp.name, cp.visual_pos_x, cp.visual_pos_y, 
               s.scan_time, s.status,
               COALESCE(ta.sort_order, 999) as sort_order
        FROM checkpoints cp
        LEFT JOIN scans s ON cp.id = s.checkpoint_id AND s.tour_session_id = ?
        LEFT JOIN tour_assignments ta ON cp.id = ta.checkpoint_id AND ta.agency_client_id = ?
        WHERE cp.agency_client_id = ?
        ORDER BY sort_order ASC, cp.id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        sendError("SQL Preparation Error", $conn->error, __FILE__, __LINE__);
    }

    $stmt->bind_param("sii", $tour_session_id, $mapping_id, $mapping_id);
    if (!$stmt->execute()) {
        sendError("Query Execution Error", $stmt->error, __FILE__, __LINE__);
    }

    $stmt->bind_result($cp_id, $cp_name, $visual_x, $visual_y, $scan_time, $status, $sort_order);

    $path = [];
    while ($stmt->fetch()) {
        $path[] = [
            'checkpoint_id' => $cp_id,
            'checkpoint_name' => $cp_name,
            'visual_pos_x' => (float)($visual_x ?? 0),
            'visual_pos_y' => (float)($visual_y ?? 0),
            'scan_time' => $scan_time,
            'status' => $status,
            'sort_order' => (int)$sort_order,
            'is_zero_checkpoint' => (stripos($cp_name, 'Starting') !== false)
        ];
    }
    
    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'mapping_id' => $mapping_id,
        'path' => $path
    ]);

} catch (Throwable $t) {
    sendError("System Error: " . $t->getMessage(), null, basename($t->getFile()), $t->getLine());
}
?>
