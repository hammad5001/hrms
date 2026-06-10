<?php
require_once 'config.php';
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/employee_resolve.php';
require_once __DIR__ . '/includes/portal_roles.php';
header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$session_user = resolve_logged_in_user($conn);
if (!$session_user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$can_manage = user_can_manage_user_accounts($session_user);

if (!$can_manage && (int)$session_user['id'] !== $id) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, full_name, email, phone, portal_role, employee_code, department, designation,
           branch, team, joined_date, status,
           COALESCE(NULLIF(company_branch, ''), 'main') AS company_branch
    FROM users WHERE id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$user['portal_role'] = sync_user_portal_role($conn, $user);
$user['effective_portal_role'] = $user['portal_role'];

$resolution = resolve_employee_code_for_user($conn, $user, $can_manage);
$emp_code = $resolution['code'];
if ($emp_code !== '') {
    $user['employee_code'] = $emp_code;
    enrich_user_from_sheet($conn, $user, $emp_code);
}

$attendance_raw = [];
if (!$can_manage) {
    $attendance = fetch_attendance_bundle($conn, $emp_code, date('Y-m-d'));
    $attendance_raw = $attendance['attendance_raw'];
}
$user['company_branch_label'] = company_branch_label($user['company_branch']);

echo json_encode([
    'success' => true,
    'user' => $user,
    'attendance_raw' => $attendance_raw,
    'meta' => [
        'resolved_employee_code' => $emp_code,
        'profile_employee_code' => $resolution['profile_code'],
        'resolution_source' => $resolution['source'],
        'bid_auto_updated' => $resolution['auto_updated'],
    ],
]);
