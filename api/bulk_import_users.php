<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bulk_users_import.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['portal_role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Only Super Admin can bulk import users']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$rows = $data['users'] ?? [];
$defaultPassword = trim((string) ($data['default_password'] ?? 'Balitech@123'));

if (!is_array($rows) || empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'No user rows provided']);
    exit;
}

if (strlen($defaultPassword) < 4) {
    echo json_encode(['success' => false, 'message' => 'Default password must be at least 4 characters']);
    exit;
}

if (count($rows) > 500) {
    echo json_encode(['success' => false, 'message' => 'Maximum 500 users per upload']);
    exit;
}

$result = bulk_import_users($conn, $rows, $_SESSION['portal_role'], $defaultPassword);

echo json_encode([
    'success' => true,
    'data' => $result,
    'message' => "Created {$result['inserted']} user(s), skipped {$result['skipped']}",
]);
