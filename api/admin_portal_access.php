<?php
/**
 * Allow logged-in admin to open any portal in a new tab without losing admin session.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/portal_roles.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'POST required');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$portal_url = trim((string)($input['portal_url'] ?? ''));

$role = $_SESSION['portal_role'] ?? '';
$is_admin = ($role === 'admin' || $role === 'super_admin');
$is_super = ($role === 'super_admin');

if (!$is_admin) {
    respond(false, null, 'Admin access only. Log in from the main login page as administrator.');
}

$url_to_key = [
    'recruiter-portal.html' => 'recruiter',
    'hr-portal.html' => 'hr',
    'reception-portal.html' => 'receptionist',
    'Management-Portal.html' => 'management',
    'training-portal.html' => 'training',
    'analytics-portal.html' => 'analytics',
    'attendance/attendance-dashboard.html' => 'attendance',
    'employee-portal.html' => 'employee',
    'admin-dashboard.html' => 'admin',
];
$portal_key = null;
foreach ($url_to_key as $fragment => $key) {
    if (stripos($portal_url, $fragment) !== false) {
        $portal_key = $key;
        break;
    }
}
if ($portal_key && !portal_role_may_access($role, $portal_key)) {
    respond(false, null, 'Your account cannot access this portal.');
}

if (role_has_limited_admin_dashboard($role)) {
    $team_allowed = ['attendance', 'employee', 'admin'];
    if ($portal_key && !in_array($portal_key, $team_allowed, true)) {
        respond(false, null, 'Team managers can only open Attendance and Employee Self Service from here.');
    }
}

if ($portal_key === 'recruiter' && $is_super) {
    $_SESSION['recruiter_type'] = 'super';
}

$_SESSION['admin_portal_view'] = true;
$_SESSION['admin_viewing_portal'] = $portal_url ?: 'unknown';

respond(true, [
    'admin_portal_view' => true,
    'portal_url' => $portal_url,
    'admin_name' => getCurrentUserName(),
], 'Admin portal access granted');
