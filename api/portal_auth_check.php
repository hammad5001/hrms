<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/portal_roles.php';
require_once __DIR__ . '/../includes/session_user.php';

$user = null;
if (!empty($_SESSION['user_id'])) {
    $user = resolve_logged_in_user($conn);
}

$role = $user ? sync_user_portal_role($conn, $user) : trim((string)($_SESSION['portal_role'] ?? $_SESSION['role'] ?? ''));
if ($user && $role !== ($_SESSION['portal_role'] ?? '')) {
    $_SESSION['portal_role'] = $role;
}

$email = trim((string)($_SESSION['email'] ?? ''));
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$is_admin = ($role === 'admin' || $role === 'super_admin');
$is_super = ($role === 'super_admin') || (isset($_SESSION['recruiter_type']) && $_SESSION['recruiter_type'] === 'super');
$admin_portal_view = !empty($_SESSION['admin_portal_view']);


$authenticated = ($role !== '') || ($email !== '') || $user_id > 0;

if (!$authenticated) {
    respond(false, ['authenticated' => false], 'Not logged in');
}

respond(true, [
    'authenticated' => true,
    'portal_role' => $role,
    'is_admin' => $is_admin,
    'is_super' => $is_super,
    'admin_portal_view' => $admin_portal_view || $is_admin || $is_super,
    'user_id' => $user_id,
    'full_name' => $_SESSION['full_name'] ?? '',
    'company_branch' => get_active_company_branch(),
]);
