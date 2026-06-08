<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/leave_helpers.php';

ensure_app_schema($conn);

require_once __DIR__ . '/../includes/session_user.php';
$user = resolve_logged_in_user($conn);
if (!$user) {
    leave_respond(false, null, 'Not authenticated');
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$branch = get_active_company_branch();
$user_id = (int)$user['id'];

switch ($action) {

    case 'searchApprovers':
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            leave_respond(true, []);
        }
        $like = '%' . $conn->real_escape_string($q) . '%';
        $roles = "'super_admin','admin','hr','team_lead','floor_manager','management'";
        $sql = "SELECT id, full_name, email, portal_role, designation, team, department, employee_code
                FROM users
                WHERE status = 'active' AND company_branch = ?
                AND portal_role IN ($roles)
                AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ? OR designation LIKE ?)
                ORDER BY full_name ASC
                LIMIT 20";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssss', $branch, $like, $like, $like, $like);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$row['id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'portal_role' => $row['portal_role'],
                'designation' => $row['designation'],
                'team' => $row['team'],
                'department' => $row['department'],
                'employee_code' => $row['employee_code'],
                'role_label' => ucfirst(str_replace('_', ' ', $row['portal_role'] ?? '')),
            ];
        }
        leave_respond(true, $rows);
        break;

    case 'searchEmployees':
        if (!user_can_select_employee_for_leave($user)) {
            leave_respond(false, null, 'Not authorized to select employees');
        }
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            leave_respond(true, []);
        }
        $like = '%' . $conn->real_escape_string($q) . '%';
        $sql = "SELECT id, full_name, email, portal_role, designation, team, department, employee_code
                FROM users
                WHERE status = 'active' AND company_branch = ?
                AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ?)
                ORDER BY full_name ASC
                LIMIT 20";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $branch, $like, $like, $like);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$row['id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'portal_role' => $row['portal_role'],
                'designation' => $row['designation'],
                'team' => $row['team'],
                'department' => $row['department'],
                'employee_code' => $row['employee_code'],
            ];
        }
        leave_respond(true, $rows);
        break;

    case 'apply':
        $leave_type = trim($input['leave_type'] ?? 'annual');
        $duration_type = $input['duration_type'] ?? 'full_day';
        $start_date = $input['start_date'] ?? '';
        $end_date = $input['end_date'] ?? $start_date;
        $half_day_slot = $input['half_day_slot'] ?? null;
        $reason = trim($input['reason'] ?? '');
        $approver_user_id = (int)($input['approver_user_id'] ?? 0);
        $apply_through = $input['apply_through'] ?? 'team_lead';
        $approver_name = null;

        $subject_user = $user;
        $subject_user_id = $user_id;
        $for_user_id = (int)($input['for_user_id'] ?? 0);
        if ($for_user_id > 0 && $for_user_id !== $user_id) {
            if (!user_can_select_employee_for_leave($user)) {
                leave_respond(false, null, 'You cannot apply leave for another employee');
            }
            $picked = fetch_user_by_id($conn, $for_user_id);
            if (!$picked || ($picked['status'] ?? '') !== 'active') {
                leave_respond(false, null, 'Selected employee not found');
            }
            if (normalize_company_branch($picked['company_branch'] ?? '') !== $branch) {
                leave_respond(false, null, 'Employee is not in your branch');
            }
            $subject_user = $picked;
            $subject_user_id = (int)$picked['id'];
        }

        if ($approver_user_id > 0) {
            $approver = fetch_user_by_id($conn, $approver_user_id);
            if (!$approver || !user_can_be_leave_approver($approver)) {
                leave_respond(false, null, 'Selected approver is not valid');
            }
            if (normalize_company_branch($approver['company_branch'] ?? '') !== $branch) {
                leave_respond(false, null, 'Approver is not in your branch');
            }
            $apply_through = apply_through_for_approver($approver);
            $approver_name = $approver['full_name'];
        } elseif (!in_array($apply_through, ['team_lead', 'floor_manager', 'hr'], true)) {
            leave_respond(false, null, 'Select an approver from the search');
        }
        if (!in_array($duration_type, ['full_day', 'half_day'], true)) {
            leave_respond(false, null, 'Invalid duration');
        }
        if (!$start_date || !$reason) {
            leave_respond(false, null, 'Start date and reason are required');
        }
        if ($duration_type === 'half_day') {
            $end_date = $start_date;
            if (!in_array($half_day_slot, ['morning', 'afternoon'], true)) {
                leave_respond(false, null, 'Select morning or afternoon for half day');
            }
        }
        if (strtotime($end_date) < strtotime($start_date)) {
            leave_respond(false, null, 'End date cannot be before start date');
        }

        $tl_status = 'none';
        $fm_status = 'none';
        $hr_status = 'none';
        if ($apply_through === 'team_lead') {
            $tl_status = 'pending';
        } elseif ($apply_through === 'floor_manager') {
            $fm_status = 'pending';
        } else {
            $hr_status = 'pending';
        }

        $emp_code = $subject_user['employee_code'] ?: ('U' . $subject_user_id);
        $stmt = $conn->prepare("INSERT INTO leave_requests (
            user_id, employee_code, employee_name, team, department, company_branch,
            leave_type, duration_type, start_date, end_date, half_day_slot, reason, apply_through,
            approver_user_id, approver_name,
            status, tl_status, fm_status, hr_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
        $aid = $approver_user_id > 0 ? $approver_user_id : 0;
        $aname = $approver_name ?? '';
        $stmt->bind_param(
            'issssssssssssissss',
            $subject_user_id,
            $emp_code,
            $subject_user['full_name'],
            $subject_user['team'],
            $subject_user['department'],
            $branch,
            $leave_type,
            $duration_type,
            $start_date,
            $end_date,
            $half_day_slot,
            $reason,
            $apply_through,
            $aid,
            $aname,
            $tl_status,
            $fm_status,
            $hr_status
        );
        if (!$stmt->execute()) {
            leave_respond(false, null, $conn->error);
        }
        $leave_id = (int)$conn->insert_id;

        $recipient_ids = [];
        if ($approver_user_id > 0) {
            $recipient_ids[] = $approver_user_id;
        } else {
            $managers = find_managers_for_leave($conn, $apply_through, $subject_user['team'] ?? '', $branch);
            $recipient_ids = array_map(fn($m) => (int)$m['id'], $managers);
        }
        $route_label = $approver_name ?: (['team_lead' => 'Team Lead', 'floor_manager' => 'Floor Manager', 'hr' => 'HR'][$apply_through]);
        $dur = $duration_type === 'half_day' ? "Half day ($half_day_slot)" : 'Full day';
        $applicant_label = $subject_user['full_name'];
        if ($subject_user_id !== $user_id) {
            $applicant_label .= " (submitted by {$user['full_name']})";
        }
        create_leave_notifications(
            $conn,
            $leave_id,
            $recipient_ids,
            'New leave request',
            "{$applicant_label} ({$emp_code}) applied for {$dur} leave. Approver: {$route_label}. Dates: {$start_date}" . ($end_date !== $start_date ? " to {$end_date}" : '')
        );

        leave_respond(true, ['id' => $leave_id], 'Leave request submitted');
        break;

    case 'myRequests':
        $stmt = $conn->prepare("SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = leave_request_row_to_array($row);
        }
        leave_respond(true, $rows);
        break;

    case 'pendingApprovals':
        if (!user_can_approve_leaves($user)) {
            leave_respond(false, null, 'You are not authorized to approve leaves');
        }
        $level = approver_level_for_user($user);
        $team = $user['team'] ?? '';
        $rows = [];

        $sql = "SELECT * FROM leave_requests WHERE status = 'pending' AND company_branch = ? AND (
            approver_user_id = ?
            OR (";
        $parts = [];
        if ($level === 'team_lead' || in_array($user['portal_role'], ['admin', 'super_admin'], true)) {
            $parts[] = "(apply_through = 'team_lead' AND tl_status = 'pending' AND (approver_user_id IS NULL OR approver_user_id = 0))";
        }
        if ($level === 'floor_manager' || in_array($user['portal_role'], ['admin', 'super_admin'], true)) {
            $parts[] = "(apply_through = 'floor_manager' AND fm_status = 'pending' AND (approver_user_id IS NULL OR approver_user_id = 0))";
        }
        if ($level === 'hr' || in_array($user['portal_role'], ['admin', 'hr', 'super_admin'], true)) {
            $parts[] = "(apply_through = 'hr' AND hr_status = 'pending' AND (approver_user_id IS NULL OR approver_user_id = 0))";
        }
        if (empty($parts)) {
            $parts[] = '1=0';
        }
        $sql .= implode(' OR ', $parts) . ')) ORDER BY created_at ASC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $branch, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $assigned = (int)($row['approver_user_id'] ?? 0);
            if ($assigned === $user_id) {
                $rows[] = leave_request_row_to_array($row);
                continue;
            }
            if ($team !== '' && $row['team'] !== '' && strcasecmp($row['team'], $team) !== 0) {
                if (!in_array($user['portal_role'], ['admin', 'hr', 'super_admin'], true)) {
                    continue;
                }
            }
            $rows[] = leave_request_row_to_array($row);
        }
        leave_respond(true, $rows);
        break;

    case 'approve':
    case 'reject':
        if (!user_can_approve_leaves($user)) {
            leave_respond(false, null, 'Not authorized');
        }
        $leave_id = (int)($input['leave_id'] ?? 0);
        $note = trim($input['note'] ?? '');
        $request = get_leave_request($conn, $leave_id);
        if (!$request) {
            leave_respond(false, null, 'Request not found');
        }
        if ($request['status'] !== 'pending') {
            leave_respond(false, null, 'Request already finalized');
        }

        $approve = $action === 'approve';
        $level = approver_level_for_user($user);
        $new_status = $approve ? 'approved' : 'rejected';
        $final_status = $approve ? 'approved' : 'rejected';

        $tl_status = $request['tl_status'];
        $fm_status = $request['fm_status'];
        $hr_status = $request['hr_status'];
        $tl_uid = $request['tl_user_id'];
        $fm_uid = $request['fm_user_id'];
        $hr_uid = $request['hr_user_id'];
        $tl_note = $request['tl_note'];
        $fm_note = $request['fm_note'];
        $hr_note = $request['hr_note'];

        $assigned_approver = (int)($request['approver_user_id'] ?? 0);
        $handled = false;
        if ($assigned_approver > 0 && $assigned_approver === $user_id) {
            if ($tl_status === 'pending') {
                $tl_status = $new_status;
                $tl_uid = $user_id;
                $tl_note = $note;
                $handled = true;
            } elseif ($fm_status === 'pending') {
                $fm_status = $new_status;
                $fm_uid = $user_id;
                $fm_note = $note;
                $handled = true;
            } elseif ($hr_status === 'pending') {
                $hr_status = $new_status;
                $hr_uid = $user_id;
                $hr_note = $note;
                $handled = true;
            }
        }

        $route = $request['apply_through'];
        if (!$handled && $route === 'team_lead' && $tl_status === 'pending') {
            $tl_status = $new_status;
            $tl_uid = $user_id;
            $tl_note = $note;
        } elseif (!$handled && $route === 'floor_manager' && $fm_status === 'pending') {
            $fm_status = $new_status;
            $fm_uid = $user_id;
            $fm_note = $note;
        } elseif (!$handled && $route === 'hr' && $hr_status === 'pending') {
            $hr_status = $new_status;
            $hr_uid = $user_id;
            $hr_note = $note;
        } elseif (!$handled && in_array($user['portal_role'], ['admin', 'hr'], true)) {
            if ($hr_status === 'pending') {
                $hr_status = $new_status;
                $hr_uid = $user_id;
                $hr_note = $note;
            } elseif ($fm_status === 'pending') {
                $fm_status = $new_status;
                $fm_uid = $user_id;
                $fm_note = $note;
            } elseif ($tl_status === 'pending') {
                $tl_status = $new_status;
                $tl_uid = $user_id;
                $tl_note = $note;
            }
        } elseif (!$handled) {
            leave_respond(false, null, 'This request is not awaiting your approval');
        }

        $upd = $conn->prepare("UPDATE leave_requests SET status=?, tl_status=?, fm_status=?, hr_status=?, tl_user_id=?, fm_user_id=?, hr_user_id=?, tl_note=?, fm_note=?, hr_note=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param('ssssiiisssi', $final_status, $tl_status, $fm_status, $hr_status, $tl_uid, $fm_uid, $hr_uid, $tl_note, $fm_note, $hr_note, $leave_id);
        $upd->execute();

        $request = get_leave_request($conn, $leave_id);
        if ($approve && $request) {
            sync_leave_to_employee_leaves($conn, $request);
        }

        create_leave_notifications(
            $conn,
            $leave_id,
            [(int)$request['user_id']],
            $approve ? 'Leave approved' : 'Leave rejected',
            'Your leave request #' . $leave_id . ' was ' . ($approve ? 'approved' : 'rejected') . ($note ? ": $note" : '')
        );

        leave_respond(true, null, $approve ? 'Leave approved' : 'Leave rejected');
        break;

    case 'notifications':
        $stmt = $conn->prepare("SELECT ln.*, lr.employee_name, lr.start_date, lr.end_date FROM leave_notifications ln LEFT JOIN leave_requests lr ON lr.id = ln.leave_request_id WHERE ln.recipient_user_id = ? ORDER BY ln.created_at DESC LIMIT 50");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $unread = $conn->query("SELECT COUNT(*) AS c FROM leave_notifications WHERE recipient_user_id = $user_id AND is_read = 0")->fetch_assoc()['c'] ?? 0;
        leave_respond(true, ['items' => $rows, 'unread' => (int)$unread]);
        break;

    case 'markNotificationsRead':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $conn->query("UPDATE leave_notifications SET is_read = 1 WHERE recipient_user_id = $user_id");
        } else {
            $ids = array_map('intval', $ids);
            $ph = implode(',', $ids);
            $conn->query("UPDATE leave_notifications SET is_read = 1 WHERE recipient_user_id = $user_id AND id IN ($ph)");
        }
        leave_respond(true, null, 'Marked read');
        break;

    case 'approvalHistory':
        if (!user_can_approve_leaves($user)) {
            leave_respond(false, null, 'Not authorized');
        }
        $status = trim($_GET['status'] ?? 'approved');
        if (!in_array($status, ['approved', 'rejected', 'all'], true)) {
            $status = 'approved';
        }
        $sql = "SELECT * FROM leave_requests WHERE company_branch = ?";
        if ($status !== 'all') {
            $sql .= " AND status = '" . $conn->real_escape_string($status) . "'";
        }
        $sql .= " ORDER BY updated_at DESC LIMIT 80";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = leave_request_row_to_array($row);
        }
        leave_respond(true, $rows);
        break;

    case 'summary':
        $pending_approvals = 0;
        if (user_can_approve_leaves($user)) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status='pending' AND company_branch='" . $conn->real_escape_string($branch) . "'");
            $pending_approvals = (int)($r->fetch_assoc()['c'] ?? 0);
        }
        $my_pending = $conn->prepare("SELECT COUNT(*) AS c FROM leave_requests WHERE user_id = ? AND status = 'pending'");
        $my_pending->bind_param('i', $user_id);
        $my_pending->execute();
        $my_p = (int)$my_pending->get_result()->fetch_assoc()['c'];
        leave_respond(true, [
            'can_approve' => user_can_approve_leaves($user),
            'can_select_employee' => user_can_select_employee_for_leave($user),
            'approver_level' => approver_level_for_user($user),
            'pending_approvals' => $pending_approvals,
            'my_pending_leaves' => $my_p,
        ]);
        break;

    default:
        leave_respond(false, null, 'Invalid action');
}
