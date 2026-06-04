<?php
/**
 * Allow logged-in admin to open any portal in a new tab without losing admin session.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'POST required');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$portal_url = trim((string)($input['portal_url'] ?? ''));

$role = $_SESSION['portal_role'] ?? '';
$is_admin = ($role === 'admin' || $role === 'super_admin');
$is_super = ($role === 'super_admin') || (isset($_SESSION['recruiter_type']) && $_SESSION['recruiter_type'] === 'super');

if (!$is_admin && !$is_super) {
    respond(false, null, 'Admin access only. Log in from the main login page as administrator.');
}

$_SESSION['admin_portal_view'] = true;
$_SESSION['admin_viewing_portal'] = $portal_url ?: 'unknown';

respond(true, [
    'admin_portal_view' => true,
    'portal_url' => $portal_url,
    'admin_name' => getCurrentUserName(),
], 'Admin portal access granted');
