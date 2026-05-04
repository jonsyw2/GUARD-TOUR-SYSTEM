<?php
require 'db_config.php';
$res = $conn->query('SELECT id, name, is_zero_checkpoint, is_end_checkpoint FROM checkpoints');
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
