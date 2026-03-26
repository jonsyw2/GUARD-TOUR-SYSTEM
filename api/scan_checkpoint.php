<?php
// Headers for API
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../db_config.php';

// If this is an OPTIONS request, return 200 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["message" => "Method not allowed. Use POST."]);
    exit();
}

// Get raw JSON data from request
$data = json_decode(file_get_contents("php://input"));

// Verify needed parameters exist
if (
    !empty($data->guard_id) &&
    !empty($data->qr_payload) 
) {
    
    $guard_id = (int)$data->guard_id;
    $qr_raw = $data->qr_payload;
    $code_value = null;

    // The frontend scanner might send raw JSON string, or just the parsed code 
    // Example: qr_payload might be "{"type": "checkpoint", "code": "NG-001"}"
    $decoded_qr = json_decode($qr_raw);

    if (json_last_error() === JSON_ERROR_NONE && isset($decoded_qr->code)) {
        $code_value = $decoded_qr->code;
    } else {
        // Fallback: assume the payload itself is the simple string code e.g. "NG-001"
        $code_value = $qr_raw;
    }
    
    // Get additional optional parameters
    $status = !empty($data->status) ? $conn->real_escape_string($data->status) : "on-time";
    $justification = !empty($data->justification) ? "'" . $conn->real_escape_string($data->justification) . "'" : "NULL";
    $photo_path = !empty($data->photo_path) ? "'" . $conn->real_escape_string($data->photo_path) . "'" : "NULL";
    $justification_photo_path = !empty($data->justification_photo_path) ? "'" . $conn->real_escape_string($data->justification_photo_path) . "'" : "NULL";
    $shift = !empty($data->shift) ? "'" . $conn->real_escape_string($data->shift) . "'" : "NULL";

    // Validate the checkpoint code exists
    $cp_sql = "SELECT id, agency_client_id FROM checkpoints WHERE checkpoint_code = '$code_value'";
    $cp_res = $conn->query($cp_sql);

    if ($cp_res && $cp_res->num_rows > 0) {
        $checkpoint = $cp_res->fetch_assoc();
        $checkpoint_id = $checkpoint['id'];

        // Optionally, verify if guard is assigned to this agency_client_id
        $assign_sql = "SELECT id FROM guard_assignments WHERE guard_id = $guard_id AND agency_client_id = " . $checkpoint['agency_client_id'];
        $assign_res = $conn->query($assign_sql);

        if ($assign_res && $assign_res->num_rows > 0) {
            
            // Insert scan record
            $insert_sql = "INSERT INTO scans (checkpoint_id, guard_id, scan_time, status, justification, photo_path, justification_photo_path, shift) 
                          VALUES ($checkpoint_id, $guard_id, NOW(), '$status', $justification, $photo_path, $justification_photo_path, $shift)";
            
            if ($conn->query($insert_sql)) {
                http_response_code(201); // Created
                echo json_encode(["message" => "Checkpoint scanned successfully.", "status" => $status]);
            } else {
                http_response_code(503); // Service Unavailable
                echo json_encode(["message" => "Unable to log scan. Database Error: " . $conn->error]);
            }
        } else {
            http_response_code(403); // Forbidden
            echo json_encode(["message" => "Guard is not assigned to this site."]);
        }
    } else {
        http_response_code(404); // Not Found
        echo json_encode(["message" => "Invalid checkpoint QR code."]);
    }
} else {
    http_response_code(400); // Bad Request
    echo json_encode(["message" => "Incomplete data. Must provide guard_id and qr_payload."]);
}

$conn->close();
?>
