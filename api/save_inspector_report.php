<?php
require_once '../db_config.php';
require_once '../jwt_helper.php';

header('Content-Type: application/json');

// Session check
session_start();
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'inspector') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspector_id = (int)$_POST['inspector_id'];
    $agency_client_id = (int)$_POST['agency_client_id'];
    $checkpoint_id = isset($_POST['checkpoint_id']) && $_POST['checkpoint_id'] !== '' ? (int)$_POST['checkpoint_id'] : null;
    $status = $conn->real_escape_string($_POST['status'] ?? 'Routine');
    $remarks = $conn->real_escape_string($_POST['remarks'] ?? '');
    $scan_time = date('Y-m-d H:i:s');

    $photo_path = null;
    
    // Handle File Upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/inspections/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $file_name = 'insp_' . time() . '_' . uniqid() . '.jpg'; // Force jpg as per JS blob
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
            $photo_path = 'uploads/inspections/' . $file_name;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
            exit();
        }
    }

    // Insert into database
    $sql = "INSERT INTO inspector_scans (inspector_id, agency_client_id, checkpoint_id, scan_time, status, remarks, photo_path) 
            VALUES (?, ?, ?, NOW(), ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iissss", $inspector_id, $agency_client_id, $checkpoint_id, $status, $remarks, $photo_path);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Inspection report saved successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>
