<?php

function leave_respond(bool $success, $data = null, ?string $error = null): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

function get_session_user(mysqli $conn): ?array {
    require_once __DIR__ . '/session_user.php';
    return resolve_logged_in_user($conn);
}

function user_is_manager(array $user): bool {
    $role = $user['portal_role'] ?? '';
    if (in_array($role, ['super_admin', 'team_lead', 'floor_manager', 'hr', 'admin', 'management'], true)) {
        return true;
    }
    $des = strtolower($user['designation'] ?? '');
    return str_contains($des, 'team lead') || str_contains($des, 'floor manager') || str_contains($des, 'manager');
}

function user_can_approve_leaves(array $user): bool {
    return user_is_manager($user);
}

/** Roles that may be selected as leave approvers in employee portal search. */
function user_can_be_leave_approver(array $user): bool {
    $role = $user['portal_role'] ?? '';
    return in_array($role, ['super_admin', 'admin', 'hr', 'team_lead', 'floor_manager', 'management'], true);
}

/** Managers may apply leave on behalf of another employee. */
function user_can_select_employee_for_leave(array $user): bool {
    return user_can_be_leave_approver($user);
}

/** Admin, HR, and Super Admin may view the leave policy screen. */
function user_can_view_leave_policy(array $user): bool {
    $role = $user['portal_role'] ?? '';
    return in_array($role, ['admin', 'hr', 'super_admin'], true);
}

/** Portal leave approvals queue — HR, Admin, Super Admin only. */
function user_can_access_portal_approvals(array $user): bool {
    return user_can_view_leave_policy($user);
}

/** Only HR and Super Admin may allot company leave (Eid, holidays, bulk). */
function user_can_allot_leave_policy(array $user): bool {
    $role = $user['portal_role'] ?? '';
    return in_array($role, ['hr', 'super_admin'], true);
}

function apply_through_for_approver(array $approver): string {
    $level = approver_level_for_user($approver);
    if ($level === 'team_lead') {
        return 'team_lead';
    }
    if ($level === 'floor_manager') {
        return 'floor_manager';
    }
    return 'hr';
}

function fetch_user_by_id(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, full_name, email, portal_role, designation, team, department, employee_code, company_branch, status FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** @return array<int, array> */
function find_managers_for_leave(mysqli $conn, string $apply_through, string $team, string $branch): array {
    $managers = [];
    $team = trim($team);
    $branch = normalize_company_branch($branch);

    if ($apply_through === 'team_lead') {
        $sql = "SELECT id, full_name, email, portal_role, designation, team FROM users
                WHERE status = 'active' AND company_branch = ?
                AND (
                    portal_role = 'team_lead'
                    OR designation LIKE '%Team Lead%'
                    OR designation LIKE '%team lead%'
                )";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ($team === '' || $row['team'] === '' || strcasecmp($row['team'], $team) === 0) {
                $managers[(int)$row['id']] = $row;
            }
        }
        if (empty($managers)) {
            $managers = find_managers_for_leave($conn, 'hr', $team, $branch);
        }
    } elseif ($apply_through === 'floor_manager') {
        $sql = "SELECT id, full_name, email, portal_role, designation, team FROM users
                WHERE status = 'active' AND company_branch = ?
                AND (
                    portal_role = 'floor_manager'
                    OR designation LIKE '%Floor Manager%'
                    OR designation LIKE '%floor manager%'
                )";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ($team === '' || $row['team'] === '' || strcasecmp($row['team'], $team) === 0) {
                $managers[(int)$row['id']] = $row;
            }
        }
        if (empty($managers)) {
            $managers = find_managers_for_leave($conn, 'hr', $team, $branch);
        }
    } else {
        $sql = "SELECT id, full_name, email, portal_role, designation, team FROM users
                WHERE status = 'active' AND company_branch = ?
                AND portal_role IN ('hr', 'admin')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $managers[(int)$row['id']] = $row;
        }
    }

    return array_values($managers);
}

function create_leave_notifications(mysqli $conn, int $leave_id, array $recipient_ids, string $title, string $message): void {
    $stmt = $conn->prepare("INSERT INTO leave_notifications (recipient_user_id, leave_request_id, title, message) VALUES (?, ?, ?, ?)");
    foreach ($recipient_ids as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) {
            continue;
        }
        $stmt->bind_param('iiss', $uid, $leave_id, $title, $message);
        $stmt->execute();
    }
}

function sync_leave_to_employee_leaves(mysqli $conn, array $request): void {
    if ($request['status'] !== 'approved') {
        return;
    }
    $code = $request['employee_code'];
    $branch = $request['company_branch'];
    $type = $request['duration_type'] === 'half_day' ? 'half_day' : ($request['leave_type'] ?? 'approved');
    $start = new DateTime($request['start_date']);
    $end = new DateTime($request['end_date']);
    $end->modify('+1 day');
    $period = new DatePeriod($start, new DateInterval('P1D'), $end);

    $ins = $conn->prepare("INSERT IGNORE INTO employee_leaves (employee_code, leave_date, leave_type, reason, company_branch) VALUES (?, ?, ?, ?, ?)");
    $reason = !empty($request['is_policy_allotment'])
        ? ($request['reason'] ?? 'Leave request #' . $request['id'])
        : 'Leave request #' . $request['id'];
    foreach ($period as $day) {
        $d = $day->format('Y-m-d');
        $ins->bind_param('sssss', $code, $d, $type, $reason, $branch);
        $ins->execute();
    }
}

function leave_request_row_to_array(array $row): array {
    return [
        'id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'employee_code' => $row['employee_code'],
        'employee_name' => $row['employee_name'],
        'team' => $row['team'],
        'department' => $row['department'],
        'leave_type' => $row['leave_type'],
        'duration_type' => $row['duration_type'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'half_day_slot' => $row['half_day_slot'],
        'reason' => $row['reason'],
        'apply_through' => $row['apply_through'],
        'approver_user_id' => isset($row['approver_user_id']) ? (int)$row['approver_user_id'] : null,
        'approver_name' => $row['approver_name'] ?? null,
        'status' => $row['status'],
        'tl_status' => $row['tl_status'],
        'fm_status' => $row['fm_status'],
        'hr_status' => $row['hr_status'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'is_policy_allotment' => !empty($row['is_policy_allotment']),
        'allotted_by_user_id' => isset($row['allotted_by_user_id']) ? (int)$row['allotted_by_user_id'] : null,
        'allotted_by_name' => $row['allotted_by_name'] ?? null,
        'policy_credit_value' => isset($row['policy_credit_value']) ? (float)$row['policy_credit_value'] : null,
        'policy_id' => isset($row['policy_id']) ? (int)$row['policy_id'] : null,
    ];
}

/** @return array<int, array> Active users in branch for policy bulk allot. */
function fetch_active_branch_users(mysqli $conn, string $branch): array {
    $stmt = $conn->prepare("SELECT id, full_name, email, employee_code, team, department FROM users WHERE status = 'active' AND company_branch = ? ORDER BY full_name ASC");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function create_policy_leave_for_employee(
    mysqli $conn,
    array $allotter,
    array $employee,
    string $branch,
    string $leave_type,
    string $start_date,
    string $end_date,
    string $reason
): int {
    $emp_id = (int)$employee['id'];
    $emp_code = $employee['employee_code'] ?: ('U' . $emp_id);
    $allotter_id = (int)$allotter['id'];
    $allotter_name = $allotter['full_name'] ?? 'HR';

    $stmt = $conn->prepare("INSERT INTO leave_requests (
        user_id, employee_code, employee_name, team, department, company_branch,
        leave_type, duration_type, start_date, end_date, half_day_slot, reason, apply_through,
        approver_user_id, approver_name,
        status, tl_status, fm_status, hr_status,
        is_policy_allotment, allotted_by_user_id, allotted_by_name
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'full_day', ?, ?, NULL, ?, 'hr', ?, ?, 'pending', 'none', 'none', 'pending', 1, ?, ?)");
    $stmt->bind_param(
        'isssssssssisis',
        $emp_id,
        $emp_code,
        $employee['full_name'],
        $employee['team'],
        $employee['department'],
        $branch,
        $leave_type,
        $start_date,
        $end_date,
        $reason,
        $allotter_id,
        $allotter_name,
        $allotter_id,
        $allotter_name
    );
    if (!$stmt->execute()) {
        return 0;
    }
    return (int) $conn->insert_id;
}

function get_leave_request(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM leave_requests WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Employee may withdraw their own pending or future approved leave (not policy allotments). */
function leave_request_can_withdraw(array $request, array $user): bool {
    if ((int)($request['user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
        return false;
    }
    if (!empty($request['is_policy_allotment'])) {
        return false;
    }
    $status = $request['status'] ?? '';
    if ($status === 'pending') {
        return true;
    }
    if ($status === 'approved') {
        $today = date('Y-m-d');
        return ($request['end_date'] ?? '') >= $today;
    }
    return false;
}

/** @return int[] Branch user ids for HR approval notifications. */
function fetch_portal_approver_user_ids(mysqli $conn, string $branch): array {
    $stmt = $conn->prepare("
        SELECT id FROM users
        WHERE status = 'active' AND company_branch = ?
          AND portal_role IN ('admin', 'hr', 'super_admin')
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $ids = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/**
 * Approved leave dates for attendance / activities (Y-m-d).
 * @return array<string, array{leave_type:string,label:string,half_day:bool}>
 */
function fetch_user_on_leave_map(mysqli $conn, int $userId, string $branch, ?int $year = null): array {
    $year = $year ?? (int) date('Y');
    $user = fetch_user_by_id($conn, $userId);
    if (!$user) {
        return [];
    }
    $map = [];
    $stmt = $conn->prepare("
        SELECT leave_type, duration_type, start_date, end_date
        FROM leave_requests
        WHERE user_id = ? AND company_branch = ? AND status = 'approved'
          AND (policy_credit_value IS NULL OR policy_credit_value <= 0)
          AND start_date <= ? AND end_date >= ?
    ");
    $yearEnd = $year . '-12-31';
    $yearStart = $year . '-01-01';
    $stmt->bind_param('isss', $userId, $branch, $yearEnd, $yearStart);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = leave_normalize_type_key((string) ($row['leave_type'] ?? ''));
        $label = leave_type_label($type);
        $half = ($row['duration_type'] ?? '') === 'half_day';
        $start = new DateTime($row['start_date']);
        $end = new DateTime($row['end_date']);
        $end->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $day) {
            $d = $day->format('Y-m-d');
            if ((int) substr($d, 0, 4) !== $year) {
                continue;
            }
            $map[$d] = ['leave_type' => $type, 'label' => $label, 'half_day' => $half];
        }
    }
    $stmt->close();
    ksort($map);
    return $map;
}

function remove_synced_leave_days(mysqli $conn, array $request): void {
    $code = trim((string) ($request['employee_code'] ?? ''));
    $branch = normalize_company_branch($request['company_branch'] ?? 'main');
    $id = (int) ($request['id'] ?? 0);
    $start = trim((string) ($request['start_date'] ?? ''));
    $end = trim((string) ($request['end_date'] ?? $start));
    if ($code === '' || $start === '') {
        return;
    }
    if ($end === '') {
        $end = $start;
    }

    if ($id > 0) {
        $reason = 'Leave request #' . $id;
        $stmt = $conn->prepare('DELETE FROM employee_leaves WHERE employee_code = ? AND company_branch = ? AND reason = ?');
        $stmt->bind_param('sss', $code, $branch, $reason);
        $stmt->execute();
        $stmt->close();
    }

    $legacyReason = trim((string) ($request['reason'] ?? ''));
    if ($legacyReason !== '') {
        $stmt = $conn->prepare('DELETE FROM employee_leaves WHERE employee_code = ? AND company_branch = ? AND leave_date BETWEEN ? AND ? AND reason = ?');
        $stmt->bind_param('sssss', $code, $branch, $start, $end, $legacyReason);
        $stmt->execute();
        $stmt->close();
    }
}

/** @return int[] User ids to notify when a leave is withdrawn. */
function leave_withdraw_notify_recipients(array $request): array {
    $ids = [];
    foreach (['approver_user_id', 'tl_user_id', 'fm_user_id', 'hr_user_id', 'allotted_by_user_id'] as $key) {
        $uid = (int)($request[$key] ?? 0);
        if ($uid > 0) {
            $ids[$uid] = $uid;
        }
    }
    return array_values($ids);
}

function approver_level_for_user(array $user): ?string {
    $role = $user['portal_role'] ?? '';
    if (in_array($role, ['team_lead'], true)) {
        return 'team_lead';
    }
    if (in_array($role, ['floor_manager'], true)) {
        return 'floor_manager';
    }
    if (in_array($role, ['hr', 'admin'], true)) {
        return 'hr';
    }
    $des = strtolower($user['designation'] ?? '');
    if (str_contains($des, 'team lead')) {
        return 'team_lead';
    }
    if (str_contains($des, 'floor manager')) {
        return 'floor_manager';
    }
    if (str_contains($des, 'hr')) {
        return 'hr';
    }
    return null;
}

/**
 * Single source of truth for leave type keys, labels, and behaviour.
 * Keys are stored in leave_requests.leave_type.
 */
function leave_type_catalog(): array {
    return [
        'casual' => [
            'label' => 'Casual Leave',
            'quota' => 12,
            'icon' => 'fa-calendar-days',
            'group' => 'balance',
            'apply' => true,
            'allot' => true,
            'half_day' => true,
        ],
        'sick' => [
            'label' => 'Sick Leave',
            'quota' => 10,
            'icon' => 'fa-notes-medical',
            'group' => 'balance',
            'apply' => true,
            'allot' => true,
            'half_day' => true,
        ],
        'annual' => [
            'label' => 'Annual Leave',
            'quota' => 14,
            'icon' => 'fa-umbrella-beach',
            'group' => 'balance',
            'apply' => true,
            'allot' => true,
            'half_day' => true,
        ],
        'compensatory' => [
            'label' => 'Compensatory Off',
            'quota' => 0,
            'icon' => 'fa-clock-rotate-left',
            'group' => 'balance',
            'apply' => true,
            'allot' => true,
            'half_day' => false,
        ],
        'on_duty' => [
            'label' => 'On Duty',
            'quota' => 5,
            'icon' => 'fa-user-check',
            'group' => 'balance',
            'apply' => true,
            'allot' => true,
            'half_day' => false,
        ],
        'wfh' => [
            'label' => 'Work From Home',
            'quota' => 0,
            'icon' => 'fa-house-laptop',
            'group' => 'balance',
            'apply' => true,
            'allot' => true,
            'half_day' => false,
        ],
        'eid' => [
            'label' => 'Eid Vacation',
            'quota' => null,
            'icon' => 'fa-mosque',
            'group' => 'holiday',
            'apply' => false,
            'allot' => true,
            'half_day' => false,
        ],
        'public_holiday' => [
            'label' => 'Public Holiday',
            'quota' => null,
            'icon' => 'fa-flag',
            'group' => 'holiday',
            'apply' => false,
            'allot' => true,
            'half_day' => false,
        ],
        'company_holiday' => [
            'label' => 'Company Holiday',
            'quota' => null,
            'icon' => 'fa-building',
            'group' => 'holiday',
            'apply' => false,
            'allot' => true,
            'half_day' => false,
        ],
    ];
}

/** Map legacy / external values to catalog keys. */
function leave_normalize_type_key(string $type): string {
    $raw = strtolower(trim($type));
    $raw = preg_replace('/[^a-z0-9]+/', '_', $raw) ?? $raw;
    $raw = trim($raw, '_');
    $aliases = [
        'emergency' => 'on_duty',
        'on_duty' => 'on_duty',
        'unpaid' => 'compensatory',
        'comp_off' => 'compensatory',
        'compensatory_off' => 'compensatory',
        'work_from_home' => 'wfh',
        'workfromhome' => 'wfh',
        'other' => 'company_holiday',
    ];
    if (isset($aliases[$raw])) {
        return $aliases[$raw];
    }
    return isset(leave_type_catalog()[$raw]) ? $raw : $raw;
}

function leave_type_label(string $type): string {
    $key = leave_normalize_type_key($type);
    return leave_type_catalog()[$key]['label'] ?? ucwords(str_replace('_', ' ', $key));
}

/** @return array<string, array{label:string,quota:?int,icon:string,group:string}> */
function leave_standard_quotas(): array {
    $out = [];
    foreach (leave_type_catalog() as $key => $meta) {
        if (($meta['group'] ?? '') !== 'balance') {
            continue;
        }
        $out[$key] = [
            'label' => $meta['label'],
            'quota' => (int) ($meta['quota'] ?? 0),
            'icon' => $meta['icon'],
        ];
    }
    return $out;
}

function leave_type_options_for(string $context): array {
    $context = strtolower(trim($context));
    $flag = match ($context) {
        'apply' => 'apply',
        'allot' => 'allot',
        'half', 'half_day' => 'half_day',
        default => 'apply',
    };
    $options = [];
    foreach (leave_type_catalog() as $key => $meta) {
        if (empty($meta[$flag])) {
            continue;
        }
        $options[] = [
            'key' => $key,
            'label' => $meta['label'],
            'group' => $meta['group'],
        ];
    }
    return $options;
}

function leave_type_catalog_for_api(): array {
    $out = [];
    foreach (leave_type_catalog() as $key => $meta) {
        $out[$key] = [
            'key' => $key,
            'label' => $meta['label'],
            'quota' => $meta['quota'],
            'icon' => $meta['icon'],
            'group' => $meta['group'],
            'apply' => !empty($meta['apply']),
            'allot' => !empty($meta['allot']),
            'half_day' => !empty($meta['half_day']),
        ];
    }
    return $out;
}

function leave_allot_type_keys(): array {
    return array_column(leave_type_options_for('allot'), 'key');
}

require_once __DIR__ . '/leave_policy_store.php';

/** HR policy cards — DB policies when saved, else catalog defaults. */
function leave_policy_rules(?mysqli $conn = null, ?string $branch = null): array {
    if ($conn && $branch) {
        $fromDb = leave_policy_rules_from_db($conn, $branch);
        if (!empty($fromDb)) {
            return $fromDb;
        }
    }
    $rules = [];
    foreach (leave_type_catalog() as $key => $meta) {
        $rules[] = [
            'key' => $key,
            'title' => $meta['label'],
            'detail' => ($meta['group'] ?? '') === 'holiday'
                ? 'Allotted by HR / Super Admin — synced to attendance'
                : (($meta['quota'] ?? 0) > 0
                    ? (int) $meta['quota'] . ' days per calendar year'
                    : 'As approved by HR'),
            'icon' => $meta['icon'],
            'group' => $meta['group'],
        ];
    }
    return $rules;
}

/** Day units for a leave request (half day = 0.5). */
function leave_request_day_units(array $row): float {
    if (($row['duration_type'] ?? '') === 'half_day') {
        return 0.5;
    }
    $start = $row['start_date'] ?? '';
    $end = $row['end_date'] ?? $start;
    if ($start === '' || $end === '') {
        return 0.0;
    }
    $s = strtotime($start);
    $e = strtotime($end);
    if ($s === false || $e === false || $e < $s) {
        return 0.0;
    }
    return (float) max(1, (int) floor(($e - $s) / 86400) + 1);
}

/** Split requested leave days by calendar year (for cross-year spans). */
function leave_requested_days_by_year(string $startDate, string $endDate, string $durationType): array {
    if ($durationType === 'half_day') {
        $ts = strtotime($startDate);
        if ($ts === false) {
            return [];
        }
        return [(int) date('Y', $ts) => 0.5];
    }
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        return [];
    }
    $out = [];
    for ($ts = $startTs; $ts <= $endTs; $ts = strtotime('+1 day', $ts)) {
        $y = (int) date('Y', $ts);
        $out[$y] = ($out[$y] ?? 0.0) + 1.0;
    }
    return $out;
}

function leave_format_day_count(float $days): string {
    return rtrim(rtrim(number_format($days, 1), '0'), '.');
}

/** Block overlapping pending or active approved leave (not policy credits). */
function leave_validate_overlap(
    mysqli $conn,
    int $userId,
    string $startDate,
    string $endDate,
    ?int $excludeId = null
): ?string {
    $sql = "SELECT id FROM leave_requests
            WHERE user_id = ?
            AND status IN ('pending', 'approved')
            AND (policy_credit_value IS NULL OR policy_credit_value <= 0)
            AND (is_policy_allotment IS NULL OR is_policy_allotment = 0)
            AND start_date <= ?
            AND end_date >= ?
            AND (status = 'pending' OR end_date >= CURDATE())";
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id != ?';
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bind_param('issi', $userId, $endDate, $startDate, $excludeId);
    } else {
        $stmt->bind_param('iss', $userId, $endDate, $startDate);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return 'You already have leave on these dates. Change your dates or withdraw the existing request.';
    }
    return null;
}

/** Types that do not consume annual balance buckets. */
function leave_type_counts_toward_quota(string $leaveType): bool {
    $key = leave_normalize_type_key($leaveType);
    $meta = leave_type_catalog()[$key] ?? null;
    return $meta && ($meta['group'] ?? '') === 'balance';
}

/**
 * @return array<string, array{key:string,label:string,quota:float,taken:float,pending:float,available:float,icon:string}>
 */
function leave_balance_for_user(mysqli $conn, int $userId, ?int $year = null, ?string $branch = null): array {
    $year = $year ?? (int) date('Y');
    if ($branch === null) {
        $u = fetch_user_by_id($conn, $userId);
        $branch = normalize_company_branch($u['company_branch'] ?? 'main');
    }
    $balances = [];
    $dbQuotas = leave_balance_quotas_for_user($conn, $userId, $branch);
    foreach (leave_standard_quotas() as $key => $meta) {
        $quota = isset($dbQuotas[$key]) ? (float) $dbQuotas[$key] : (float) $meta['quota'];
        $balances[$key] = [
            'key' => $key,
            'label' => $meta['label'],
            'quota' => $quota,
            'taken' => 0.0,
            'pending' => 0.0,
            'available' => $quota,
            'icon' => $meta['icon'],
        ];
    }

    $stmt = $conn->prepare("
        SELECT leave_type, duration_type, start_date, end_date, status, is_policy_allotment, policy_credit_value
        FROM leave_requests
        WHERE user_id = ? AND YEAR(start_date) = ?
    ");
    if (!$stmt) {
        return array_values($balances);
    }
    $stmt->bind_param('ii', $userId, $year);
    $stmt->execute();
    $res = $stmt->get_result();
    $today = date('Y-m-d');
    while ($row = $res->fetch_assoc()) {
        $type = leave_normalize_type_key((string) ($row['leave_type'] ?? ''));
        if (!leave_type_counts_toward_quota($type) || !isset($balances[$type])) {
            continue;
        }
        $creditVal = isset($row['policy_credit_value']) ? (float) $row['policy_credit_value'] : 0.0;
        if ($creditVal > 0) {
            continue;
        }
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status !== 'approved' && $status !== 'pending') {
            continue;
        }
        $days = leave_request_day_units($row);
        $endDate = trim((string) ($row['end_date'] ?? ''));
        $leaveDone = $endDate !== '' && $endDate < $today;

        if ($status === 'pending') {
            $balances[$type]['pending'] += $days;
        } elseif ($leaveDone) {
            $balances[$type]['taken'] += $days;
        } else {
            $balances[$type]['pending'] += $days;
        }
    }
    $stmt->close();

    foreach ($balances as $key => $b) {
        $balances[$key]['available'] = max(0.0, $b['quota'] - $b['taken'] - $b['pending']);
        $balances[$key]['used'] = $b['taken'];
        $balances[$key]['remaining'] = $balances[$key]['available'];
    }

    return array_values($balances);
}

function leave_validate_balance(
    mysqli $conn,
    int $userId,
    string $leaveType,
    string $startDate,
    string $endDate,
    string $durationType = 'full_day'
): ?string {
    $leaveType = leave_normalize_type_key($leaveType);
    if (!leave_type_counts_toward_quota($leaveType)) {
        return null;
    }
    $byYear = leave_requested_days_by_year($startDate, $endDate, $durationType);
    if (empty($byYear)) {
        return 'Invalid leave dates';
    }
    foreach ($byYear as $year => $requestedDays) {
        $balances = leave_balance_for_user($conn, $userId, (int) $year);
        $matched = null;
        foreach ($balances as $b) {
            if ($b['key'] === $leaveType) {
                $matched = $b;
                break;
            }
        }
        if ($matched === null || $matched['quota'] <= 0) {
            continue;
        }
        if ($requestedDays > $matched['available']) {
            $label = $matched['label'];
            $avail = leave_format_day_count((float) $matched['available']);
            if (count($byYear) > 1) {
                return "Not enough {$label} balance for {$year}. Available: {$avail} day(s).";
            }
            return "Not enough {$label} balance. Available: {$avail} day(s).";
        }
    }
    return null;
}
