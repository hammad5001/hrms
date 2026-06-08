<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config.php';
require_once __DIR__ . '/../includes/session_user.php';

$user = resolve_logged_in_user($conn);
if (!$user) {
    echo json_encode(['success' => false, 'authenticated' => false, 'message' => 'Not authenticated']);
    exit;
}

if (($user['status'] ?? 'active') !== 'active') {
    session_destroy();
    echo json_encode(['success' => false, 'authenticated' => false, 'message' => 'Account inactive or not found']);
    exit;
}

$user_id = (int)$user['id'];

$stmt = $conn->prepare("
    SELECT r.recruiter_type, r.total_leads, r.total_calls, r.total_hired
    FROM recruiters r
    WHERE r.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc() ?: [];
$user = array_merge($user, $rec);

// Sync session
$_SESSION['full_name'] = $user['full_name'];
$user['portal_role'] = sync_user_portal_role($conn, $user);
$_SESSION['portal_role'] = $user['portal_role'];
if ($user['portal_role'] === 'super_admin') {
    $_SESSION['recruiter_type'] = 'super';
} else {
    $_SESSION['recruiter_type'] = $user['recruiter_type'] ?? 'regular';
}
if (empty($_SESSION['company_branch'])) {
    $_SESSION['company_branch'] = $user['company_branch'];
}

echo json_encode([
    'success' => true,
    'authenticated' => true,
    'user' => [
        'id'             => $user['id'],
        'full_name'      => $user['full_name'],
        'email'          => $user['email'],
        'portal_role'    => $user['portal_role'],
        'recruiter_type' => $user['recruiter_type'] ?? 'regular',
        'employee_code'  => $user['employee_code'],
        'phone'          => $user['phone'],
        'total_leads'    => (int)($user['total_leads'] ?? 0),
        'total_hired'    => (int)($user['total_hired'] ?? 0),
        'company_branch' => $_SESSION['company_branch'] ?? $user['company_branch'],
        'company_branch_label' => company_branch_label($_SESSION['company_branch'] ?? $user['company_branch']),
        'department'     => $user['department'] ?? '',
        'designation'    => $user['designation'] ?? '',
    ]
]);
?>