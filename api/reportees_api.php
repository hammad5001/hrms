<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/reportees_helpers.php';

ensure_app_schema($conn);

$user = resolve_logged_in_user($conn);
if (!$user) {
    reportees_respond(false, null, 'Not authenticated');
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$branch = normalize_company_branch(get_active_company_branch());
$user_id = (int)$user['id'];

switch ($action) {

    case 'hierarchy':
        reportees_respond(true, fetch_reporting_hierarchy($conn, $user, $branch));
        break;

    case 'searchManagers':
        $q = trim($_GET['q'] ?? '');
        $rows = search_managers_for_reporting($conn, $q, $branch, $user_id);
        reportees_respond(true, $rows);
        break;

    case 'assignManager':
        $manager_id = (int)($input['manager_user_id'] ?? 0);
        if ($manager_id <= 0) {
            reportees_respond(false, null, 'Please select a manager');
        }
        $manager = fetch_user_by_id($conn, $manager_id);
        if (!$manager) {
            reportees_respond(false, null, 'Manager not found');
        }
        $result = assign_employee_manager($conn, $user, $manager, $branch);
        if (!$result['ok']) {
            reportees_respond(false, null, $result['error'] ?? 'Could not assign manager');
        }
        reportees_respond(true, [
            'message' => 'You have been added to ' . ($manager['full_name'] ?? 'your manager') . "'s reportees.",
            'hierarchy' => fetch_reporting_hierarchy($conn, $user, $branch),
        ]);
        break;

    case 'reportees':
        if (!user_can_manage_reportees($user)) {
            reportees_respond(false, null, 'Not authorized to view reportees');
        }
        reportees_respond(true, fetch_manager_reportees($conn, $user_id, $branch));
        break;

    case 'reporteeHistory':
        if (!user_can_manage_reportees($user)) {
            reportees_respond(false, null, 'Not authorized to view reportees');
        }
        $emp_code = trim((string) ($_GET['employee_code'] ?? ''));
        $employee_user_id = (int) ($_GET['user_id'] ?? 0);
        if ($emp_code === '' && $employee_user_id <= 0) {
            reportees_respond(false, null, 'Reportee not specified');
        }
        if (!manager_has_reportee($conn, $user_id, $emp_code, $employee_user_id, $branch)) {
            reportees_respond(false, null, 'Not your reportee');
        }
        if ($emp_code === '' && $employee_user_id > 0) {
            $stmt = $conn->prepare('SELECT employee_code FROM employee_reporting WHERE manager_user_id = ? AND employee_user_id = ? AND company_branch = ? LIMIT 1');
            $stmt->bind_param('iis', $user_id, $employee_user_id, $branch);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $emp_code = trim((string) ($row['employee_code'] ?? ''));
        }
        reportees_respond(true, fetch_reportee_shift_history($conn, $emp_code, $branch, 10));
        break;

    default:
        reportees_respond(false, null, 'Invalid action');
}
