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

    case 'apply':
        $leave_type = trim($input['leave_type'] ?? 'annual');
        $duration_type = $input['duration_type'] ?? 'full_day';
        $start_date = $input['start_date'] ?? '';
        $end_date = $input['end_date'] ?? $start_date;
        $half_day_slot = $input['half_day_slot'] ?? null;
        $reason = trim($input['reason'] ?? '');
        $apply_through = $input['apply_through'] ?? 'team_lead';

        if (!in_array($apply_through, ['team_lead', 'floor_manager', 'hr'], true)) {
            leave_respond(false, null, 'Invalid approval route');
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

        $emp_code = $user['employee_code'] ?: ('U' . $user_id);
        $stmt = $conn->prepare("INSERT INTO leave_requests (
            user_id, employee_code, employee_name, team, department, company_branch,
            leave_type, duration_type, start_date, end_date, half_day_slot, reason, apply_through,
            status, tl_status, fm_status, hr_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
        $stmt->bind_param(
            'isssssssssssssss',
            $user_id,
            $emp_code,
            $user['full_name'],
            $user['team'],
            $user['department'],
            $branch,
            $leave_type,
            $duration_type,
            $start_date,
            $end_date,
            $half_day_slot,
            $reason,
            $apply_through,
            $tl_status,
            $fm_status,
            $hr_status
        );
        if (!$stmt->execute()) {
            leave_respond(false, null, $conn->error);
        }
        $leave_id = (int)$conn->insert_id;

        $managers = find_managers_for_leave($conn, $apply_through, $user['team'] ?? '', $branch);
        $recipient_ids = array_map(fn($m) => (int)$m['id'], $managers);
        $route_label = ['team_lead' => 'Team Lead', 'floor_manager' => 'Floor Manager', 'hr' => 'HR'][$apply_through];
        $dur = $duration_type === 'half_day' ? "Half day ($half_day_slot)" : 'Full day';
        create_leave_notifications(
            $conn,
            $leave_id,
            $recipient_ids,
            'New leave request',
            "{$user['full_name']} ({$emp_code}) applied for {$dur} leave via {$route_label}. Dates: {$start_date}" . ($end_date !== $start_date ? " to {$end_date}" : '')
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

        $sql = "SELECT * FROM leave_requests WHERE status = 'pending' AND company_branch = ? AND (";
        $parts = [];
        if ($level === 'team_lead' || in_array($user['portal_role'], ['admin'], true)) {
            $parts[] = "(apply_through = 'team_lead' AND tl_status = 'pending')";
        }
        if ($level === 'floor_manager' || in_array($user['portal_role'], ['admin'], true)) {
            $parts[] = "(apply_through = 'floor_manager' AND fm_status = 'pending')";
        }
        if ($level === 'hr' || in_array($user['portal_role'], ['admin', 'hr'], true)) {
            $parts[] = "(apply_through = 'hr' AND hr_status = 'pending')";
        }
        if (empty($parts)) {
            $parts[] = '1=0';
        }
        $sql .= implode(' OR ', $parts) . ') ORDER BY created_at ASC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ($team !== '' && $row['team'] !== '' && strcasecmp($row['team'], $team) !== 0) {
                if (!in_array($user['portal_role'], ['admin', 'hr'], true)) {
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

        $route = $request['apply_through'];
        if ($route === 'team_lead' && $tl_status === 'pending') {
            $tl_status = $new_status;
            $tl_uid = $user_id;
            $tl_note = $note;
        } elseif ($route === 'floor_manager' && $fm_status === 'pending') {
            $fm_status = $new_status;
            $fm_uid = $user_id;
            $fm_note = $note;
        } elseif ($route === 'hr' && $hr_status === 'pending') {
            $hr_status = $new_status;
            $hr_uid = $user_id;
            $hr_note = $note;
        } elseif (in_array($user['portal_role'], ['admin', 'hr'], true)) {
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
        } else {
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
            'approver_level' => approver_level_for_user($user),
            'pending_approvals' => $pending_approvals,
            'my_pending_leaves' => $my_p,
        ]);
        break;

    default:
        leave_respond(false, null, 'Invalid action');
}
