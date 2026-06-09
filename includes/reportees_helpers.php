<?php

require_once __DIR__ . '/leave_helpers.php';
require_once __DIR__ . '/employee_resolve.php';
require_once __DIR__ . '/attendance_shift.php';

function reportees_respond(bool $success, $data = null, ?string $error = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

function user_can_manage_reportees(array $user): bool {
    return user_is_manager($user);
}

function manager_search_roles_sql(): string {
    return "'super_admin','admin','hr','team_lead','floor_manager','management'";
}

function search_managers_for_reporting(mysqli $conn, string $q, string $branch, int $exclude_user_id = 0): array {
    $q = trim($q);
    if (strlen($q) < 2) {
        return [];
    }

    $like = '%' . $conn->real_escape_string($q) . '%';
    $roles = manager_search_roles_sql();
    $sql = "SELECT id, full_name, email, portal_role, designation, team, department, employee_code
            FROM users
            WHERE status = 'active'
            AND company_branch = ?
            AND portal_role IN ($roles)
            AND id != ?
            AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ? OR designation LIKE ?)
            ORDER BY full_name ASC
            LIMIT 20";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sissss', $branch, $exclude_user_id, $like, $like, $like, $like);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = format_manager_search_row($row);
    }
    return $rows;
}

function format_manager_search_row(array $row): array {
    return [
        'id' => (int)$row['id'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'portal_role' => $row['portal_role'],
        'designation' => $row['designation'],
        'team' => $row['team'],
        'department' => $row['department'],
        'employee_code' => $row['employee_code'],
        'role_label' => ucwords(str_replace('_', ' ', $row['portal_role'] ?? '')),
    ];
}

function fetch_reporting_manager(mysqli $conn, int $employee_user_id): ?array {
    $stmt = $conn->prepare("
        SELECT er.*, u.full_name AS mgr_full_name, u.email AS mgr_email,
               u.portal_role AS mgr_role, u.designation AS mgr_designation, u.team AS mgr_team
        FROM employee_reporting er
        LEFT JOIN users u ON u.id = er.manager_user_id
        WHERE er.employee_user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $employee_user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    return [
        'user_id' => (int)$row['manager_user_id'],
        'employee_code' => $row['manager_code'],
        'full_name' => $row['manager_name'] ?: $row['mgr_full_name'],
        'email' => $row['mgr_email'],
        'portal_role' => $row['mgr_role'],
        'designation' => $row['mgr_designation'] ?: null,
        'team' => $row['mgr_team'] ?: null,
        'role_label' => ucwords(str_replace('_', ' ', $row['mgr_role'] ?? '')),
        'linked_at' => $row['created_at'],
    ];
}

function fetch_manager_reportees(mysqli $conn, int $manager_user_id, string $branch): array {
    $stmt = $conn->prepare("
        SELECT er.*, u.full_name AS emp_full_name, u.email AS emp_email,
               u.portal_role AS emp_role, u.designation AS emp_designation, u.team AS emp_team
        FROM employee_reporting er
        LEFT JOIN users u ON u.id = er.employee_user_id
        WHERE er.manager_user_id = ? AND er.company_branch = ?
        ORDER BY er.employee_name ASC, er.employee_code ASC
    ");
    $stmt->bind_param('is', $manager_user_id, $branch);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $empCode = $row['employee_code'] ?: '';
        $status = reportee_attendance_status($conn, $empCode, $branch);
        $rows[] = [
            'user_id' => (int)$row['employee_user_id'],
            'employee_code' => $empCode,
            'full_name' => $row['employee_name'] ?: $row['emp_full_name'],
            'email' => $row['emp_email'],
            'team' => $row['emp_team'] ?: null,
            'designation' => $row['emp_designation'] ?: null,
            'linked_at' => $row['created_at'],
            'attendance' => $status,
        ];
    }
    return $rows;
}

function reportee_attendance_status(mysqli $conn, string $empCode, string $branch): array {
    if ($empCode === '') {
        return [
            'status' => 'absent',
            'label' => 'Absent',
            'check_in' => null,
            'check_out' => null,
            'on_duty' => false,
        ];
    }

    $bundle = fetch_attendance_bundle($conn, $empCode, date('Y-m-d'), $branch);

    $checkIn = $bundle['check_in'] ?? null;
    $checkOut = $bundle['check_out'] ?? null;
    $onDuty = !empty($bundle['on_duty']);

    return [
        'status' => $bundle['attendance_status'] ?? 'absent',
        'label' => $bundle['attendance_label'] ?? 'Absent',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'on_duty' => $onDuty,
        'shift_date' => $bundle['shift_date'] ?? null,
        'punch_count' => count($bundle['times'] ?? []),
        'working_hours' => function_exists('ess_working_hours')
            ? ess_working_hours($checkIn, $onDuty ? null : $checkOut, $conn)
            : 0,
    ];
}

function assign_employee_manager(
    mysqli $conn,
    array $employee,
    array $manager,
    string $branch
): array {
    $employee_id = (int)$employee['id'];
    $manager_id = (int)$manager['id'];

    if ($employee_id === $manager_id) {
        return ['ok' => false, 'error' => 'You cannot assign yourself as your manager'];
    }
    if (($manager['status'] ?? 'active') !== 'active') {
        return ['ok' => false, 'error' => 'Selected manager is not active'];
    }
    if (!user_can_be_leave_approver($manager)) {
        return ['ok' => false, 'error' => 'Selected user is not a team lead or manager'];
    }
    if (normalize_company_branch($manager['company_branch'] ?? '') !== $branch) {
        return ['ok' => false, 'error' => 'Manager is not in your branch'];
    }

    $emp_code = trim((string)($employee['employee_code'] ?? ''));
    $mgr_code = trim((string)($manager['employee_code'] ?? ''));
    $emp_name = trim((string)($employee['full_name'] ?? ''));
    $mgr_name = trim((string)($manager['full_name'] ?? ''));

    $stmt = $conn->prepare("
        INSERT INTO employee_reporting
            (employee_user_id, employee_code, employee_name, manager_user_id, manager_code, manager_name, company_branch)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            manager_user_id = VALUES(manager_user_id),
            manager_code = VALUES(manager_code),
            manager_name = VALUES(manager_name),
            company_branch = VALUES(company_branch),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('ississs', $employee_id, $emp_code, $emp_name, $manager_id, $mgr_code, $mgr_name, $branch);
    if (!$stmt->execute()) {
        return ['ok' => false, 'error' => 'Could not save reporting link'];
    }

    notify_manager_new_reportee($conn, $manager_id, $employee, $emp_code, $emp_name);

    return ['ok' => true];
}

function notify_manager_new_reportee(
    mysqli $conn,
    int $manager_id,
    array $employee,
    string $emp_code,
    string $emp_name
): void {
    $code_label = $emp_code !== '' ? " (ID: $emp_code)" : '';
    $title = 'New team reportee';
    $message = ($emp_name ?: 'An employee') . $code_label . ' has added you as their reporting manager in the Employee Portal.';
    $stmt = $conn->prepare("
        INSERT INTO leave_notifications (recipient_user_id, leave_request_id, title, message)
        VALUES (?, 0, ?, ?)
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iss', $manager_id, $title, $message);
    $stmt->execute();
}

function fetch_reporting_hierarchy(mysqli $conn, array $user, string $branch): array {
    $user_id = (int)$user['id'];
    $is_manager = user_can_manage_reportees($user);

    return [
        'is_manager' => $is_manager,
        'reporting_to' => fetch_reporting_manager($conn, $user_id),
        'reportees' => $is_manager ? fetch_manager_reportees($conn, $user_id, $branch) : [],
        'reportee_count' => $is_manager ? count_reportees($conn, $user_id, $branch) : 0,
    ];
}

function count_reportees(mysqli $conn, int $manager_user_id, string $branch): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM employee_reporting WHERE manager_user_id = ? AND company_branch = ?");
    $stmt->bind_param('is', $manager_user_id, $branch);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

function manager_has_reportee(
    mysqli $conn,
    int $manager_user_id,
    string $emp_code,
    int $employee_user_id,
    string $branch
): bool {
    $emp_code = trim($emp_code);
    if ($employee_user_id > 0) {
        $stmt = $conn->prepare("
            SELECT 1 FROM employee_reporting
            WHERE manager_user_id = ? AND company_branch = ? AND employee_user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('isi', $manager_user_id, $branch, $employee_user_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return true;
        }
    }
    if ($emp_code === '') {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT 1 FROM employee_reporting
        WHERE manager_user_id = ? AND company_branch = ? AND employee_code = ?
        LIMIT 1
    ");
    $stmt->bind_param('iss', $manager_user_id, $branch, $emp_code);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

/** Last N shift days with punches, check-in/out, hours, and status. */
function fetch_reportee_shift_history(mysqli $conn, string $empCode, string $branch, int $days = 10): array
{
    if ($empCode === '' || $days < 1) {
        return [];
    }

    $codes = employee_code_variants($empCode);
    if (empty($codes)) {
        return [];
    }

    $table = branch_attendance_table($branch);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));

    $sql = "SELECT timestamp FROM `$table`
            WHERE user_id IN ($placeholders)
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 25 DAY)
            ORDER BY timestamp ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$codes);
    $stmt->execute();
    $res = $stmt->get_result();

    $allTimestamps = [];
    while ($row = $res->fetch_assoc()) {
        $allTimestamps[] = $row['timestamp'];
    }

    $history = [];
    $cursor = strtotime(ess_active_shift_date());

    for ($i = 0; $i < $days; $i++) {
        $shiftDate = date('Y-m-d', $cursor);
        $shift = ess_resolve_shift_punches($allTimestamps, $shiftDate);
        $status = ess_attendance_status_for_shift($shift['check_in'], $shift['check_out'], $shiftDate);
        $onDuty = !empty($status['on_duty']);
        $checkIn = $shift['check_in'];
        $checkOut = $onDuty ? null : $shift['check_out'];

        $history[] = [
            'shift_date' => $shiftDate,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'on_duty' => $onDuty,
            'punch_count' => $shift['punch_count'],
            'punches' => $shift['times'],
            'status' => $status['status'],
            'label' => $status['label'],
            'working_hours' => function_exists('ess_working_hours')
                ? ess_working_hours($checkIn, $onDuty ? null : $checkOut, $conn)
                : 0,
        ];

        $cursor = strtotime('-1 day', $cursor);
    }

    return $history;
}
