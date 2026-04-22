<?php
require_once 'auth_check.php';
$res = $conn->query("DESCRIBE agency_clients");
echo "<pre>";
while($row = $res->fetch_assoc()){
    print_r($row);
}
echo "</pre>";
?>
