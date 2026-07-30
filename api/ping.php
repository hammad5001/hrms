<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
echo json_encode(['ok' => true, 'ts' => time(), 'server' => 'balitech-hrms']);
?>
