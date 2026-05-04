<?php
// api/test_visual.php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../db_config.php';
    echo "DB Config loaded.\n";
    if (isset($conn)) {
        echo "Connection exists. Status: " . ($conn->connect_error ? "Failed: " . $conn->connect_error : "OK") . "\n";
    } else {
        echo "Connection variable \$conn is NOT set!\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
