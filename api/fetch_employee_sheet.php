<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/employee_sheet.php';

$is_admin = isset($_SESSION['portal_role']) && $_SESSION['portal_role'] === 'admin';
if (!$is_admin) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$code = trim($_GET['employee_code'] ?? $_GET['code'] ?? '');
if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'Employee ID is required']);
    exit;
}

$emp = get_employee_from_sheet($conn, $code);
if (!$emp) {
    echo json_encode(['success' => false, 'error' => 'No employee found in sheet for ID: ' . $code]);
    exit;
}

$suggested_email = suggest_email_from_name($emp['full_name']);
$suggested_portal_role = suggest_portal_role_from_sheet(
    $emp['department'] ?? '',
    $emp['team'] ?? '',
    $emp['designation'] ?? ''
);

echo json_encode([
    'success' => true,
    'data' => [
        'employee_code' => $emp['employee_code'],
        'full_name' => $emp['full_name'],
        'department' => $emp['department'],
        'designation' => $emp['designation'],
        'branch' => $emp['branch'],
        'team' => $emp['team'],
        'suggested_email' => $suggested_email,
        'suggested_portal_role' => $suggested_portal_role,
        'source' => $emp['source'],
    ],
]);
