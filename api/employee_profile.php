<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/employee_profile.php';

header('Content-Type: application/json; charset=utf-8');

ensure_app_schema($conn);

$user = resolve_logged_in_user($conn);
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int) $user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($input['action'] ?? '');

if ($action === 'get') {
    echo json_encode([
        'success' => true,
        'profile' => fetch_employee_profile_details($conn, $user_id),
    ]);
    exit;
}

if ($action === 'save') {
    $result = save_employee_profile_details($conn, $user_id, $input);
    if (!$result['ok']) {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Could not save profile']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'profile' => $result['profile'],
        'phone' => $result['profile']['personal_mobile'] ?? '',
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
