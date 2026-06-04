<?php
chdir(dirname(__DIR__));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'create';
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'Test HR';
$_SESSION['portal_role'] = 'hr';
$_SESSION['company_branch'] = 'main';

require_once __DIR__ . '/../api/config.php';
ensure_app_schema($conn);

$payload = json_encode([
    'type' => 'voice_call',
    'target' => 'reception',
    'payload' => ['name' => 'Test User', 'room' => 'HR', 'repeat_count' => 3],
]);

$stmt = $conn->prepare("INSERT INTO portal_notifications (notification_type, target_portal, payload, company_branch) VALUES ('voice_call', 'reception', ?, 'main')");
$stmt->bind_param('s', $payload);
if ($stmt->execute()) {
    echo "OK insert id=" . $conn->insert_id . PHP_EOL;
} else {
    echo "FAIL: " . $conn->error . PHP_EOL;
    exit(1);
}
