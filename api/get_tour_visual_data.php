<?php
// Prevent any stray output from breaking JSON
ob_start();

// Disable error display but enable internal logging for the catch block
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Use a custom error handler to convert notices/warnings into exceptions
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Relative path is often more reliable than dirname(__DIR__) on shared hosts
    $config_path = '../db_config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Configuration file not found. Please ensure db_config.php exists in the parent directory.");
    }
    require_once $config_path;
    // $conn is already created in db_config.php

    $tour_session_id = $_GET['tour_session_id'] ?? '';
    // Ensure mapping_id is a clean integer
    $mapping_id = isset($_GET['mapping_id']) ? (int)$_GET['mapping_id'] : 0;

    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Database connection failed or not initialized in db_config.php");
    }

    // Resolve mapping_id from tour_session_id if missing
    if (($mapping_id === 0) && !empty($tour_session_id)) {
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
        throw new Exception("Site Identification Error: No mapping_id provided or resolved.");
    }

    // Main Query - Joining with tour_assignments to get the established sequence
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
        throw new Exception("SQL Error: " . $conn->error);
    }

    $stmt->bind_param("sii", $tour_session_id, $mapping_id, $mapping_id);
    if (!$stmt->execute()) {
        throw new Exception("Execution Error: " . $stmt->error);
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

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'mapping_id' => $mapping_id,
        'path' => $path
    ]);

} catch (Throwable $t) {
    ob_clean();
    echo json_encode([
        'status' => 'error',
        'message' => $t->getMessage(),
        'file' => basename($t->getFile()),
        'line' => $t->getLine()
    ]);
}
?>
