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

    case 'searchPolicyEmployees':
        if (!user_can_allot_leave_policy($user)) {
            leave_respond(false, null, 'Not authorized');
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

    case 'policyList':
        if (!user_can_view_leave_policy($user)) {
            leave_respond(false, null, 'Not authorized');
        }
        leave_respond(true, [
            'can_manage' => user_can_manage_leave_policies($user),
            'definitions' => fetch_leave_policy_definitions($conn, $branch),
            'policy_type_options' => leave_policy_type_options(),
            'used_policy_codes' => array_map(static fn($p) => [
                'id' => $p['id'],
                'code' => $p['policy_code'],
                'name' => $p['policy_name'],
            ], fetch_leave_policy_definitions($conn, $branch)),
        ]);
        break;

    case 'onLeaveDates':
        $year = (int) ($_GET['year'] ?? date('Y'));
        $map = fetch_user_on_leave_map($conn, $user_id, $branch, $year);
        leave_respond(true, [
            'year' => $year,
            'dates' => $map,
            'date_keys' => array_keys($map),
        ]);
        break;

    case 'savePolicyDefinition':
        if (!user_can_manage_leave_policies($user)) {
            leave_respond(false, null, 'Not authorized to manage leave policies');
        }
        $result = save_leave_policy_definition($conn, $branch, $user, $input);
        if (empty($result['success'])) {
            leave_respond(false, null, $result['error'] ?? 'Could not save policy');
        }
        leave_respond(true, [
            'id' => $result['id'],
            'assigned' => $result['assigned'] ?? 0,
        ], $result['message'] ?? 'Policy saved');
        break;

    case 'updatePolicyDefinition':
        if (!user_can_manage_leave_policies($user)) {
            leave_respond(false, null, 'Not authorized to manage leave policies');
        }
        $result = update_leave_policy_definition($conn, $branch, $user, $input);
        if (empty($result['success'])) {
            leave_respond(false, null, $result['error'] ?? 'Could not update policy');
        }
        leave_respond(true, [
            'id' => $result['id'],
            'assigned' => $result['assigned'] ?? 0,
        ], $result['message'] ?? 'Policy updated');
        break;

    case 'deletePolicyDefinition':
        if (!user_can_manage_leave_policies($user)) {
            leave_respond(false, null, 'Not authorized to manage leave policies');
        }
        $policyId = (int) ($input['policy_id'] ?? 0);
        $result = delete_leave_policy_definition($conn, $policyId, $branch);
        if (empty($result['success'])) {
            leave_respond(false, null, $result['error'] ?? 'Could not delete policy');
        }
        leave_respond(true, null, $result['message'] ?? 'Policy deleted');
        break;

    case 'leaveBalances':
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        $for_user_id = (int) ($_GET['user_id'] ?? 0);
        $target_id = $user_id;
        if ($for_user_id > 0 && $for_user_id !== $user_id) {
            if (!user_can_select_employee_for_leave($user)) {
                leave_respond(false, null, 'Not authorized');
            }
            $picked = fetch_user_by_id($conn, $for_user_id);
            if (!$picked || normalize_company_branch($picked['company_branch'] ?? '') !== $branch) {
                leave_respond(false, null, 'Employee not found');
            }
            $target_id = $for_user_id;
        }
        leave_respond(true, [
            'year' => $year,
            'balances' => leave_balance_for_user($conn, $target_id, $year),
            'policy_rules' => leave_policy_rules($conn, $branch),
            'type_catalog' => leave_type_catalog_for_api(),
            'apply_types' => leave_type_options_for('apply'),
            'half_day_types' => leave_type_options_for('half_day'),
        ]);
        break;

    case 'allotLeave':
        if (!user_can_allot_leave_policy($user)) {
            leave_respond(false, null, 'Only HR and Super Admin can allot leave');
        }
        $all_employees = !empty($input['all_employees']);
        $user_ids = $input['user_ids'] ?? [];
        if (!is_array($user_ids)) {
            $user_ids = [];
        }
        $leave_type = trim($input['leave_type'] ?? 'public_holiday');
        $start_date = trim($input['start_date'] ?? '');
        $end_date = trim($input['end_date'] ?? $start_date);
        $reason = trim($input['reason'] ?? '');

        $leave_type = leave_normalize_type_key($leave_type);
        $allowed_types = leave_allot_type_keys();
        if (!in_array($leave_type, $allowed_types, true)) {
            leave_respond(false, null, 'Invalid leave type');
        }
        if (!$start_date || !$reason) {
            leave_respond(false, null, 'Start date and occasion/reason are required');
        }
        if (strtotime($end_date) < strtotime($start_date)) {
            leave_respond(false, null, 'End date cannot be before start date');
        }

        $targets = [];
        if ($all_employees) {
            $targets = fetch_active_branch_users($conn, $branch);
        } else {
            $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
            if (count($user_ids) < 1) {
                leave_respond(false, null, 'Select at least one employee, or choose all employees');
            }
            foreach ($user_ids as $uid) {
                $picked = fetch_user_by_id($conn, $uid);
                if (!$picked || ($picked['status'] ?? '') !== 'active') {
                    leave_respond(false, null, 'One or more selected employees were not found');
                }
                if (normalize_company_branch($picked['company_branch'] ?? '') !== $branch) {
                    leave_respond(false, null, 'One or more employees are not in your branch');
                }
                $targets[] = $picked;
            }
        }

        if (empty($targets)) {
            leave_respond(false, null, 'No employees found to allot leave');
        }

        $allotter_label = $user['full_name'] ?? 'HR';
        $date_label = $start_date . ($end_date !== $start_date ? " to {$end_date}" : '');
        $type_label = leave_type_label($leave_type);
        $created = 0;
        $errors = 0;

        foreach ($targets as $emp) {
            $leave_id = create_policy_leave_for_employee(
                $conn,
                $user,
                $emp,
                $branch,
                $leave_type,
                $start_date,
                $end_date,
                $reason
            );
            if ($leave_id <= 0) {
                $errors++;
                continue;
            }
            $created++;
            create_leave_notifications(
                $conn,
                $leave_id,
                [(int)$emp['id']],
                'Leave pending approval',
                "{$allotter_label} submitted {$type_label} leave ({$reason}) for {$date_label}. Awaiting HR approval."
            );
        }

        $portalApprovers = fetch_portal_approver_user_ids($conn, $branch);
        $portalApprovers = array_values(array_diff($portalApprovers, [$user_id]));
        if ($created > 0 && !empty($portalApprovers)) {
            create_leave_notifications(
                $conn,
                $leave_id ?? 0,
                $portalApprovers,
                'Leave allotment pending',
                "{$allotter_label} submitted {$type_label} leave for {$created} employee(s) ({$date_label}). Review in Approvals."
            );
        }

        if ($created === 0) {
            leave_respond(false, null, 'Could not submit leave allotment. Please try again.');
        }

        $msg = $all_employees
            ? "{$created} leave allotment(s) sent to Approvals for review"
            : 'Leave allotment sent to Approvals for review';
        if ($errors > 0) {
            $msg .= " ({$errors} failed)";
        }
        leave_respond(true, ['allotted' => $created, 'failed' => $errors], $msg);
        break;

    case 'apply':
        $leave_type = leave_normalize_type_key(trim($input['leave_type'] ?? 'annual'));
        $duration_type = $input['duration_type'] ?? 'full_day';
        $typeKeys = array_column(
            leave_type_options_for($duration_type === 'half_day' ? 'half_day' : 'apply'),
            'key'
        );
        if (!in_array($leave_type, $typeKeys, true)) {
            leave_respond(false, null, $duration_type === 'half_day'
                ? 'Invalid leave type for half day'
                : 'Invalid leave type');
        }
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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || strtotime($start_date) === false) {
            leave_respond(false, null, 'Invalid start date');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || strtotime($end_date) === false) {
            leave_respond(false, null, 'Invalid end date');
        }

        $overlapError = leave_validate_overlap($conn, $subject_user_id, $start_date, $end_date);
        if ($overlapError !== null) {
            leave_respond(false, null, $overlapError);
        }

        $balanceError = leave_validate_balance(
            $conn,
            $subject_user_id,
            $leave_type,
            $start_date,
            $end_date,
            $duration_type
        );
        if ($balanceError !== null) {
            leave_respond(false, null, $balanceError);
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
        $date_label = $start_date . ($end_date !== $start_date ? " to {$end_date}" : '');
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
        $portalApprovers = fetch_portal_approver_user_ids($conn, $branch);
        $portalApprovers = array_values(array_diff($portalApprovers, [$user_id]));
        if (!empty($portalApprovers)) {
            create_leave_notifications(
                $conn,
                $leave_id,
                $portalApprovers,
                'Leave pending approval',
                "{$applicant_label} ({$emp_code}) submitted leave for {$date_label}. Review in Approvals."
            );
        }

        leave_respond(true, ['id' => $leave_id], 'Leave request submitted');
        break;

    case 'myRequests':
        $stmt = $conn->prepare("SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $item = leave_request_row_to_array($row);
            $item['can_withdraw'] = leave_request_can_withdraw($row, $user);
            $rows[] = $item;
        }
        leave_respond(true, $rows);
        break;

    case 'withdrawLeave':
        $leave_id = (int)($input['leave_id'] ?? 0);
        $note = trim($input['note'] ?? '');
        if ($leave_id <= 0) {
            leave_respond(false, null, 'Invalid leave request');
        }
        $request = get_leave_request($conn, $leave_id);
        if (!$request) {
            leave_respond(false, null, 'Request not found');
        }
        if (!leave_request_can_withdraw($request, $user)) {
            leave_respond(false, null, 'This leave request cannot be withdrawn');
        }
        $was_approved = ($request['status'] ?? '') === 'approved';
        $withdraw_note = $note !== '' ? $note : 'Withdrawn by employee';
        $upd = $conn->prepare("UPDATE leave_requests SET status='cancelled', hr_note=?, updated_at=NOW() WHERE id=? AND user_id=?");
        $upd->bind_param('sii', $withdraw_note, $leave_id, $user_id);
        if (!$upd->execute() || $upd->affected_rows === 0) {
            leave_respond(false, null, 'Could not withdraw leave request');
        }
        if ($was_approved) {
            remove_synced_leave_days($conn, $request);
        }
        $applicant = $request['employee_name'] ?? $user['full_name'] ?? 'Employee';
        $date_label = $request['start_date'] . (($request['end_date'] ?? '') !== $request['start_date'] ? ' to ' . $request['end_date'] : '');
        $recipients = leave_withdraw_notify_recipients($request);
        if (!empty($recipients)) {
            create_leave_notifications(
                $conn,
                $leave_id,
                $recipients,
                'Leave withdrawn',
                "{$applicant} withdrew leave request #{$leave_id} ({$date_label})." . ($note ? " Note: {$note}" : '')
            );
        }
        leave_respond(true, null, 'Leave request withdrawn successfully');
        break;

    case 'pendingApprovals':
        if (!user_can_access_portal_approvals($user)) {
            leave_respond(false, null, 'You are not authorized to view approvals');
        }
        $stmt = $conn->prepare("
            SELECT * FROM leave_requests
            WHERE status = 'pending' AND company_branch = ?
            ORDER BY created_at ASC
            LIMIT 200
        ");
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = leave_request_row_to_array($row);
        }
        leave_respond(true, $rows);
        break;

    case 'approve':
    case 'reject':
        if (!user_can_access_portal_approvals($user)) {
            leave_respond(false, null, 'Not authorized');
        }
        $leave_id = (int)($input['leave_id'] ?? 0);
        $note = trim($input['note'] ?? '');
        $request = get_leave_request($conn, $leave_id);
        if (!$request) {
            leave_respond(false, null, 'Request not found');
        }
        $approve = $action === 'approve';
        $currentStatus = strtolower(trim((string) ($request['status'] ?? '')));

        if (!$approve && $currentStatus === 'approved') {
            remove_synced_leave_days($conn, $request);
            $credit = (float) ($request['policy_credit_value'] ?? 0);
            if ($credit > 0) {
                set_leave_balance_credit(
                    $conn,
                    (string) $request['employee_code'],
                    $branch,
                    (string) $request['leave_type'],
                    0.0
                );
            }
            $rejNote = $note !== '' ? $note : 'Leave rejected by HR';
            $upd = $conn->prepare("
                UPDATE leave_requests SET status='rejected', hr_status='rejected', hr_user_id=?, hr_note=?, updated_at=NOW()
                WHERE id = ? AND company_branch = ?
            ");
            $upd->bind_param('isis', $user_id, $rejNote, $leave_id, $branch);
            if (!$upd->execute()) {
                leave_respond(false, null, 'Could not reject leave');
            }
            create_leave_notifications(
                $conn,
                $leave_id,
                [(int) $request['user_id']],
                'Leave rejected',
                'Your leave request #' . $leave_id . ' was rejected.' . ($note ? " Note: {$note}" : '')
            );
            leave_respond(true, null, 'Leave rejected');
        }

        if ($currentStatus !== 'pending') {
            leave_respond(false, null, 'Request already finalized');
        }

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
        } elseif (!$handled && in_array($user['portal_role'], ['admin', 'hr', 'super_admin'], true)) {
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
            } else {
                $hr_status = $new_status;
                $hr_uid = $user_id;
                $hr_note = $note;
            }
            $handled = true;
        } elseif (!$handled) {
            leave_respond(false, null, 'This request is not awaiting your approval');
        }

        $upd = $conn->prepare("UPDATE leave_requests SET status=?, tl_status=?, fm_status=?, hr_status=?, tl_user_id=?, fm_user_id=?, hr_user_id=?, tl_note=?, fm_note=?, hr_note=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param('ssssiiisssi', $final_status, $tl_status, $fm_status, $hr_status, $tl_uid, $fm_uid, $hr_uid, $tl_note, $fm_note, $hr_note, $leave_id);
        $upd->execute();

        $request = get_leave_request($conn, $leave_id);
        if (!$approve && $request) {
            remove_synced_leave_days($conn, $request);
        } elseif ($approve && $request) {
            $credit = (float) ($request['policy_credit_value'] ?? 0);
            if ($credit > 0) {
                apply_approved_policy_credit($conn, $request);
            } else {
                sync_leave_to_employee_leaves($conn, $request);
            }
        }

        $rejectTitle = 'Leave rejected';
        $rejectMsg = 'Your leave request #' . $leave_id . ' was rejected.' . ($note ? ": $note" : '');
        create_leave_notifications(
            $conn,
            $leave_id,
            [(int)$request['user_id']],
            $approve ? 'Leave approved' : $rejectTitle,
            $approve
                ? ('Your leave request #' . $leave_id . ' was approved' . ($note ? ": $note" : ''))
                : $rejectMsg
        );

        leave_respond(true, null, $approve ? 'Leave approved' : 'Leave rejected');
        break;

    case 'revert':
        if (!user_can_access_portal_approvals($user)) {
            leave_respond(false, null, 'Not authorized');
        }
        $leave_id = (int) ($input['leave_id'] ?? 0);
        $note = trim($input['note'] ?? '');
        $request = get_leave_request($conn, $leave_id);
        if (!$request) {
            leave_respond(false, null, 'Request not found');
        }
        if (($request['status'] ?? '') !== 'approved') {
            leave_respond(false, null, 'Only approved leave can be reverted to pending');
        }
        remove_synced_leave_days($conn, $request);
        $route = $request['apply_through'] ?? 'hr';
        $tl_status = 'none';
        $fm_status = 'none';
        $hr_status = 'none';
        if ($route === 'team_lead') {
            $tl_status = 'pending';
        } elseif ($route === 'floor_manager') {
            $fm_status = 'pending';
        } else {
            $hr_status = 'pending';
        }
        $revertNote = $note !== '' ? $note : 'Reverted to pending by HR';
        $upd = $conn->prepare("
            UPDATE leave_requests SET
                status = 'pending',
                tl_status = ?, fm_status = ?, hr_status = ?,
                tl_user_id = NULL, fm_user_id = NULL, hr_user_id = NULL,
                tl_note = NULL, fm_note = NULL, hr_note = ?,
                updated_at = NOW()
            WHERE id = ? AND company_branch = ?
        ");
        $upd->bind_param('ssssis', $tl_status, $fm_status, $hr_status, $revertNote, $leave_id, $branch);
        if (!$upd->execute()) {
            leave_respond(false, null, 'Could not revert leave request');
        }
        create_leave_notifications(
            $conn,
            $leave_id,
            [(int) $request['user_id']],
            'Leave reverted to pending',
            'Your leave request #' . $leave_id . ' was reverted for review.' . ($note ? " Note: {$note}" : '')
        );
        leave_respond(true, null, 'Leave reverted to pending');
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
        if (!user_can_access_portal_approvals($user)) {
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
        if (user_can_access_portal_approvals($user)) {
            $r = $conn->prepare("SELECT COUNT(*) AS c FROM leave_requests WHERE status='pending' AND company_branch = ?");
            $r->bind_param('s', $branch);
            $r->execute();
            $pending_approvals = (int) ($r->get_result()->fetch_assoc()['c'] ?? 0);
        }
        $my_pending = $conn->prepare("SELECT COUNT(*) AS c FROM leave_requests WHERE user_id = ? AND status = 'pending'");
        $my_pending->bind_param('i', $user_id);
        $my_pending->execute();
        $my_p = (int)$my_pending->get_result()->fetch_assoc()['c'];
        $leaveYear = (int) date('Y');
        leave_respond(true, [
            'can_approve' => user_can_access_portal_approvals($user),
            'can_access_portal_approvals' => user_can_access_portal_approvals($user),
            'can_select_employee' => user_can_select_employee_for_leave($user),
            'can_view_leave_policy' => user_can_view_leave_policy($user),
            'can_manage_leave_policies' => user_can_manage_leave_policies($user),
            'can_allot_leave_policy' => user_can_allot_leave_policy($user),
            'approver_level' => approver_level_for_user($user),
            'pending_approvals' => $pending_approvals,
            'my_pending_leaves' => $my_p,
            'leave_year' => $leaveYear,
            'on_leave_dates' => fetch_user_on_leave_map($conn, $user_id, $branch, $leaveYear),
            'type_catalog' => leave_type_catalog_for_api(),
            'apply_types' => leave_type_options_for('apply'),
            'allot_types' => leave_type_options_for('allot'),
            'half_day_types' => leave_type_options_for('half_day'),
        ]);
        break;

    default:
        leave_respond(false, null, 'Invalid action');
}
