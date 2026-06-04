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
    if (in_array($role, ['team_lead', 'floor_manager', 'hr', 'admin', 'management'], true)) {
        return true;
    }
    $des = strtolower($user['designation'] ?? '');
    return str_contains($des, 'team lead') || str_contains($des, 'floor manager') || str_contains($des, 'manager');
}

function user_can_approve_leaves(array $user): bool {
    return user_is_manager($user);
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
    $reason = 'Leave request #' . $request['id'];
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
        'status' => $row['status'],
        'tl_status' => $row['tl_status'],
        'fm_status' => $row['fm_status'],
        'hr_status' => $row['hr_status'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function get_leave_request(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM leave_requests WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
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
