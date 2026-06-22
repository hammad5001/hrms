<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) {
    $ip = explode(',', $ip)[0];
}
$ip = substr(trim($ip), 0, 45);

$stmt = $conn->prepare("UPDATE users SET last_seen = NOW(), ip_address = ? WHERE id = ?");
$stmt->bind_param("si", $ip, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
