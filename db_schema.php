<?php
include 'db_config.php';
$tables_result = $conn->query("SHOW TABLES");
$tables = [];
if ($tables_result) {
    while ($row = $tables_result->fetch_array()) {
        $table = $row[0];
        $desc_result = $conn->query("DESCRIBE `$table`");
        $cols = [];
        while ($col = $desc_result->fetch_assoc()) {
            $cols[] = $col;
        }
        $tables[$table] = $cols;
    }
}
echo json_encode($tables, JSON_PRETTY_PRINT);
?>
