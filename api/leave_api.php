<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/leave_helpers.php';

// Production: schema migrations are run manually during deployment.

require_once __DIR__ . '/../includes/session_user.php';
$user = resolve_logged_in_user($conn);
if (!$user) {
    leave_respond(false, null, 'Not authenticated');
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$branch = normalize_company_branch($user['company_branch'] ?? get_active_company_branch());
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

    case 'searchHrApprovers':
        $q = trim($_GET['q'] ?? '');
        $like = '%' . $conn->real_escape_string($q) . '%';
        $roles = "'hr','admin','super_admin'";
        $sql = "SELECT id, full_name, email, portal_role, designation, team, department, employee_code
                FROM users
                WHERE status = 'active' AND company_branch = ?
                AND (portal_role IN ($roles) OR designation LIKE '%HR%' OR designation LIKE '%Human Resource%')";
        if (strlen($q) >= 1) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ? OR designation LIKE ?)";
            $sql .= " ORDER BY full_name ASC LIMIT 20";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssss', $branch, $like, $like, $like, $like);
        } else {
            $sql .= " ORDER BY full_name ASC LIMIT 20";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $branch);
        }
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

    case 'searchTlApprovers':
        $q = trim($_GET['q'] ?? '');
        $like = '%' . $conn->real_escape_string($q) . '%';
        $roles = "'team_lead','floor_manager','management','admin','super_admin'";
        $sql = "SELECT id, full_name, email, portal_role, designation, team, department, employee_code
                FROM users
                WHERE status = 'active' AND company_branch = ?
                AND (portal_role IN ($roles) OR designation LIKE '%Team Lead%' OR designation LIKE '%team lead%' OR designation LIKE '%Floor Manager%' OR designation LIKE '%floor manager%')";
        if (strlen($q) >= 1) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ? OR designation LIKE ?)";
            $sql .= " ORDER BY full_name ASC LIMIT 20";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssss', $branch, $like, $like, $like, $like);
        } else {
            $sql .= " ORDER BY full_name ASC LIMIT 20";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $branch);
        }
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
        normalize_stale_pending_policy_credits($conn, $branch);
        leave_respond(true, [
            'can_manage' => user_can_manage_leave_policies($user),
            'allotments' => fetch_policy_credit_allotments($conn, $branch),
            'policy_type_options' => leave_policy_type_options(),
        ]);
        break;

    case 'allotLeavePolicy':
        if (!user_can_manage_leave_policies($user)) {
            leave_respond(false, null, 'Not authorized to allot leave credits');
        }
        $result = allot_leave_policy_from_form($conn, $branch, $user, $input);
        if (empty($result['success'])) {
            leave_respond(false, null, $result['error'] ?? 'Could not allot leave credits');
        }
        leave_respond(true, [
            'id' => $result['id'],
            'assigned' => $result['assigned'] ?? 0,
        ], $result['message'] ?? 'Leave credits allotted');
        break;

    case 'allotPolicyCredit':
        if (!user_can_manage_leave_policies($user)) {
            leave_respond(false, null, 'Not authorized to allot policy credit');
        }
        $policyId = (int) ($input['policy_id'] ?? 0);
        $applyToAll = !empty($input['apply_to_all']);
        $userIds = $input['user_ids'] ?? [];
        if (!is_array($userIds)) {
            $userIds = [];
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$applyToAll && empty($userIds)) {
            leave_respond(false, null, 'Select at least one employee or choose all active employees');
        }
        $result = allot_leave_policy_credit($conn, $policyId, $branch, $user, $applyToAll, $userIds);
        if (empty($result['success'])) {
            leave_respond(false, null, $result['error'] ?? 'Could not allot policy credit');
        }
        leave_respond(true, ['assigned' => $result['assigned'] ?? 0], $result['message'] ?? 'Policy credit allotted');
        break;

    case 'policyCreditAction':
        if (!user_can_manage_leave_policies($user)) {
            leave_respond(false, null, 'Not authorized');
        }
        $leave_id = (int) ($input['leave_id'] ?? 0);
        $action = strtolower(trim((string) ($input['credit_action'] ?? '')));
        $note = trim((string) ($input['note'] ?? ''));
        if ($leave_id <= 0 || !in_array($action, ['reject', 'revert'], true)) {
            leave_respond(false, null, 'Invalid request');
        }
        $request = get_leave_request($conn, $leave_id);
        if (!$request || normalize_company_branch($request['company_branch'] ?? '') !== $branch) {
            leave_respond(false, null, 'Allotment not found');
        }
        $credit = (float) ($request['policy_credit_value'] ?? 0);
        if ($credit <= 0) {
            leave_respond(false, null, 'Not a policy credit allotment');
        }
        $status = strtolower(trim((string) ($request['status'] ?? '')));
        if ($action === 'reject') {
            if (!in_array($status, ['pending', 'approved'], true)) {
                leave_respond(false, null, 'This allotment cannot be rejected');
            }
            if ($status === 'approved') {
                revoke_policy_credit_balance($conn, $request);
            }
            $rejNote = $note !== '' ? $note : 'Policy credit rejected by HR';
            $upd = $conn->prepare("
                UPDATE leave_requests SET status='rejected', hr_status='rejected', hr_user_id=?, hr_note=?, updated_at=NOW()
                WHERE id = ? AND company_branch = ?
            ");
            $upd->bind_param('isis', $user_id, $rejNote, $leave_id, $branch);
            if (!$upd->execute()) {
                leave_respond(false, null, 'Could not reject allotment');
            }
            create_leave_notifications(
                $conn,
                $leave_id,
                [(int) $request['user_id']],
                'Policy credit rejected',
                'Your policy credit was rejected.' . ($note ? " Note: {$note}" : '')
            );
            leave_respond(true, null, 'Policy credit rejected');
        }
        if ($status !== 'approved') {
            leave_respond(false, null, 'Only approved allotments can be reverted');
        }
        revoke_policy_credit_balance($conn, $request);
        $revertNote = $note !== '' ? $note : 'Policy credit reverted by HR';
        $upd = $conn->prepare("
            UPDATE leave_requests SET
                status = 'pending',
                tl_status = 'none', fm_status = 'none', hr_status = 'pending',
                hr_user_id = NULL, hr_note = ?,
                updated_at = NOW()
            WHERE id = ? AND company_branch = ?
        ");
        $upd->bind_param('sis', $revertNote, $leave_id, $branch);
        if (!$upd->execute()) {
            leave_respond(false, null, 'Could not revert allotment');
        }
        create_leave_notifications(
            $conn,
            $leave_id,
            [(int) $request['user_id']],
            'Policy credit reverted',
            'Your policy credit was reverted for review.' . ($note ? " Note: {$note}" : '')
        );
        leave_respond(true, null, 'Policy credit reverted to pending');
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
        
        $hr_user_id = (int)($input['hr_user_id'] ?? $input['approver_user_id'] ?? 0);
        $tl_user_id = (int)($input['tl_user_id'] ?? 0);
        
        $hr_user = null;
        $tl_user = null;

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

        // Validate HR (Mandatory)
        if ($hr_user_id > 0) {
            $hr_user = fetch_user_by_id($conn, $hr_user_id);
            if (!$hr_user || !in_array($hr_user['portal_role'], ['hr', 'admin', 'super_admin'], true)) {
                leave_respond(false, null, 'Please select a valid HR / Admin user');
            }
            if (normalize_company_branch($hr_user['company_branch'] ?? '') !== $branch) {
                leave_respond(false, null, 'Tagged HR is not in your branch');
            }
        } else {
            // Find default HR if not explicitly provided
            $hrs = find_managers_for_leave($conn, 'hr', '', $branch);
            if (!empty($hrs)) {
                $hr_user = $hrs[0];
                $hr_user_id = (int)$hr_user['id'];
            } else {
                leave_respond(false, null, 'Tagging HR is mandatory. Please select an HR person.');
            }
        }

        // Validate TL (Optional but recommended)
        if ($tl_user_id > 0) {
            $tl_user = fetch_user_by_id($conn, $tl_user_id);
            if (!$tl_user || !user_can_be_leave_approver($tl_user)) {
                leave_respond(false, null, 'Selected Team Lead / Manager is not valid');
            }
            if (normalize_company_branch($tl_user['company_branch'] ?? '') !== $branch) {
                leave_respond(false, null, 'Tagged Team Lead / Manager is not in your branch');
            }
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

        $tl_status = ($tl_user_id > 0) ? 'pending' : 'none';
        $fm_status = 'none';
        $hr_status = 'pending';
        $apply_through = ($tl_user_id > 0) ? 'team_lead' : 'hr';

        $tl_name = $tl_user ? ($tl_user['full_name'] ?? '') : null;
        $hr_name = $hr_user ? ($hr_user['full_name'] ?? '') : null;
        $primary_approver_id = $hr_user_id;
        $primary_approver_name = $hr_name;

        $emp_code = $subject_user['employee_code'] ?: ('U' . $subject_user_id);
        $stmt = $conn->prepare("INSERT INTO leave_requests (
            user_id, employee_code, employee_name, team, department, company_branch,
            leave_type, duration_type, start_date, end_date, half_day_slot, reason, apply_through,
            approver_user_id, approver_name,
            tl_user_id, tl_name, tl_status,
            hr_user_id, hr_name, hr_status,
            fm_status, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'none', 'pending')");
        
        $tl_uid_val = ($tl_user_id > 0) ? $tl_user_id : null;
        $hr_uid_val = ($hr_user_id > 0) ? $hr_user_id : null;

        $stmt->bind_param(
            'issssssssssssisississ',
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
            $primary_approver_id,
            $primary_approver_name,
            $tl_uid_val,
            $tl_name,
            $tl_status,
            $hr_uid_val,
            $hr_name,
            $hr_status
        );
        if (!$stmt->execute()) {
            leave_respond(false, null, $conn->error);
        }
        $leave_id = (int)$conn->insert_id;

        $recipient_ids = [];
        if ($hr_user_id > 0) $recipient_ids[] = $hr_user_id;
        if ($tl_user_id > 0) $recipient_ids[] = $tl_user_id;
        $recipient_ids = array_values(array_unique(array_filter($recipient_ids)));

        $dur = $duration_type === 'half_day' ? "Half day ($half_day_slot)" : 'Full day';
        $date_label = $start_date . ($end_date !== $start_date ? " to {$end_date}" : '');
        $applicant_label = $subject_user['full_name'];
        if ($subject_user_id !== $user_id) {
            $applicant_label .= " (submitted by {$user['full_name']})";
        }
        $tagged_msg = "HR: {$hr_name}" . ($tl_name ? " | TL: {$tl_name}" : "");
        
        create_leave_notifications(
            $conn,
            $leave_id,
            $recipient_ids,
            'New leave request',
            "{$applicant_label} ({$emp_code}) applied for {$dur} leave ({$date_label}). Tagged: {$tagged_msg}. Please review in Approvals."
        );

        leave_respond(true, ['id' => $leave_id], 'Leave request submitted successfully');
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
        $role = $user['portal_role'] ?? '';
        $isSuperAdmin = ($role === 'super_admin');
        
        if ($isSuperAdmin) {
            $stmt = $conn->prepare("
                SELECT * FROM leave_requests
                WHERE status = 'pending' AND company_branch = ?
                  AND (policy_credit_value IS NULL OR policy_credit_value <= 0)
                ORDER BY created_at ASC
                LIMIT 200
            ");
            $stmt->bind_param('s', $branch);
        } elseif ($role === 'hr' || $role === 'admin') {
            // HR/Admin: sees requests tagged to them or where HR is pending in this branch
            $stmt = $conn->prepare("
                SELECT * FROM leave_requests
                WHERE status = 'pending' AND company_branch = ?
                  AND (hr_user_id = ? OR (hr_user_id IS NULL AND hr_status = 'pending'))
                  AND (policy_credit_value IS NULL OR policy_credit_value <= 0)
                ORDER BY created_at ASC
                LIMIT 200
            ");
            $stmt->bind_param('si', $branch, $user_id);
        } else {
            // Team Lead / Floor Manager / Management: ONLY sees requests where they are explicitly tagged
            $stmt = $conn->prepare("
                SELECT * FROM leave_requests
                WHERE status = 'pending' AND company_branch = ?
                  AND tl_user_id = ?
                  AND (policy_credit_value IS NULL OR policy_credit_value <= 0)
                ORDER BY created_at ASC
                LIMIT 200
            ");
            $stmt->bind_param('si', $branch, $user_id);
        }
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

        $tl_status = $request['tl_status'] ?? 'none';
        $hr_status = $request['hr_status'] ?? 'none';
        $tl_uid = $request['tl_user_id'] ? (int)$request['tl_user_id'] : null;
        $hr_uid = $request['hr_user_id'] ? (int)$request['hr_user_id'] : null;
        $tl_note = $request['tl_note'] ?? null;
        $hr_note = $request['hr_note'] ?? null;
        $tl_name = $request['tl_name'] ?? null;
        $hr_name = $request['hr_name'] ?? null;

        $isHrUser = in_array($user['portal_role'], ['hr', 'admin', 'super_admin'], true);
        $isTlUser = in_array($user['portal_role'], ['team_lead', 'floor_manager', 'management'], true)
                    || ((int)($request['tl_user_id'] ?? 0) === $user_id);

        $action_taken_by = '';

        if (!$approve) {
            // Rejection by either TL or HR immediately rejects the leave
            if ($tl_uid === $user_id || ($isTlUser && $tl_status === 'pending' && !$isHrUser)) {
                $tl_status = 'rejected';
                $tl_uid = $user_id;
                $tl_name = $user['full_name'];
                $tl_note = $note ?: 'Rejected by Team Lead';
                $action_taken_by = 'Team Lead';
            } else {
                $hr_status = 'rejected';
                $hr_uid = $user_id;
                $hr_name = $user['full_name'];
                $hr_note = $note ?: 'Rejected by HR';
                $action_taken_by = 'HR';
            }
            $final_status = 'rejected';
        } else {
            // Approval flow
            if ($tl_uid === $user_id || ($isTlUser && $tl_status === 'pending' && !$isHrUser)) {
                // Team Lead approving
                $tl_status = 'approved';
                $tl_uid = $user_id;
                $tl_name = $user['full_name'];
                if ($note) $tl_note = $note;
                $action_taken_by = 'Team Lead';
            } elseif ($isHrUser) {
                // HR approving
                $hr_status = 'approved';
                $hr_uid = $user_id;
                $hr_name = $user['full_name'];
                if ($note) $hr_note = $note;
                $action_taken_by = 'HR';
            } else {
                leave_respond(false, null, 'You are not assigned as an approver for this request');
            }

            // Dual approval check:
            // If TL was tagged (tl_status != 'none'), BOTH TL and HR must be 'approved'.
            // If TL was NOT tagged (tl_status == 'none'), then HR approval is sufficient.
            $tl_satisfied = ($tl_status === 'none' || $tl_status === 'approved');
            $hr_satisfied = ($hr_status === 'approved');

            if ($tl_satisfied && $hr_satisfied) {
                $final_status = 'approved';
            } else {
                $final_status = 'pending';
            }
        }

        $upd = $conn->prepare("UPDATE leave_requests SET 
            status=?, tl_status=?, hr_status=?, 
            tl_user_id=?, tl_name=?, tl_note=?, 
            hr_user_id=?, hr_name=?, hr_note=?, 
            updated_at=NOW() 
            WHERE id=?");
        $upd->bind_param(
            'sssisssssi', 
            $final_status, $tl_status, $hr_status, 
            $tl_uid, $tl_name, $tl_note, 
            $hr_uid, $hr_name, $hr_note, 
            $leave_id
        );
        $upd->execute();

        $request = get_leave_request($conn, $leave_id);

        if ($final_status === 'approved' && $request) {
            $credit = (float) ($request['policy_credit_value'] ?? 0);
            if ($credit > 0) {
                apply_approved_policy_credit($conn, $request);
            } else {
                sync_leave_to_employee_leaves($conn, $request);
            }

            create_leave_notifications(
                $conn,
                $leave_id,
                [(int)$request['user_id']],
                'Leave fully approved',
                "Your leave request #{$leave_id} ({$request['start_date']}) has been approved by both Team Lead and HR."
            );
            leave_respond(true, ['status' => 'approved'], 'Leave fully approved');
        } elseif ($final_status === 'rejected' && $request) {
            remove_synced_leave_days($conn, $request);
            create_leave_notifications(
                $conn,
                $leave_id,
                [(int)$request['user_id']],
                'Leave rejected',
                "Your leave request #{$leave_id} was rejected by {$action_taken_by}." . ($note ? " Note: {$note}" : '')
            );
            leave_respond(true, ['status' => 'rejected'], 'Leave rejected');
        } else {
            // Partially approved (e.g. TL approved, HR pending or vice-versa)
            $pendingRole = ($tl_status === 'pending') ? 'Team Lead' : 'HR';
            create_leave_notifications(
                $conn,
                $leave_id,
                [(int)$request['user_id']],
                "Leave {$action_taken_by} approved",
                "Your leave request #{$leave_id} was approved by {$action_taken_by}. Awaiting final approval from {$pendingRole}."
            );
            leave_respond(true, ['status' => 'pending'], "Approved by {$action_taken_by}. Awaiting {$pendingRole} approval.");
        }
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
        $credit = (float) ($request['policy_credit_value'] ?? 0);
        if ($credit > 0) {
            leave_respond(false, null, 'Policy credits are managed on the Leave Policy page');
        }
        remove_synced_leave_days($conn, $request);
        $revertNote = $note !== '' ? $note : 'Reverted to pending by HR';
        $upd = $conn->prepare("
            UPDATE leave_requests SET
                status = 'pending',
                hr_status = 'pending',
                hr_note = ?,
                updated_at = NOW()
            WHERE id = ? AND company_branch = ?
        ");
        $upd->bind_param('sis', $revertNote, $leave_id, $branch);
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

    case 'monthlyLeaveRecords':
        if (!user_can_view_leave_policy($user)) {
            leave_respond(false, null, 'Not authorized to view leave policy records');
        }
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        $status = trim($_GET['status'] ?? 'all');
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT * FROM leave_requests 
                WHERE company_branch = ? 
                AND YEAR(start_date) = ?";
        if ($month > 0 && $month <= 12) {
            $sql .= " AND MONTH(start_date) = " . (int)$month;
        }
        if ($status !== 'all' && in_array($status, ['approved', 'pending', 'rejected', 'cancelled'], true)) {
            $sql .= " AND status = '" . $conn->real_escape_string($status) . "'";
        }
        if (strlen($search) > 0) {
            $s = '%' . $conn->real_escape_string($search) . '%';
            $sql .= " AND (employee_name LIKE '$s' OR employee_code LIKE '$s' OR reason LIKE '$s' OR tl_name LIKE '$s' OR hr_name LIKE '$s')";
        }
        $sql .= " ORDER BY start_date DESC, id DESC LIMIT 500";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $branch, $year);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = leave_request_row_to_array($row);
        }
        leave_respond(true, [
            'year' => $year,
            'month' => $month,
            'total' => count($rows),
            'records' => $rows
        ]);
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
        $role = $user['portal_role'] ?? '';
        $isSuperAdmin = ($role === 'super_admin');
        
        $sql = "SELECT * FROM leave_requests WHERE company_branch = ?
            AND (policy_credit_value IS NULL OR policy_credit_value <= 0)";
        if ($status !== 'all') {
            $sql .= " AND status = '" . $conn->real_escape_string($status) . "'";
        }
        
        if (!$isSuperAdmin) {
            if ($role === 'hr' || $role === 'admin') {
                $sql .= " AND (hr_user_id = " . (int)$user_id . " OR hr_user_id IS NULL)";
            } else {
                $sql .= " AND tl_user_id = " . (int)$user_id;
            }
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

    case 'getApprovedLeaves':
        if (!user_can_view_leave_policy($user)) {
            leave_respond(false, null, 'Not authorized to view approved leaves');
        }
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? 0);
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT * FROM leave_requests 
                WHERE company_branch = ? 
                AND status = 'approved'
                AND (policy_credit_value IS NULL OR policy_credit_value <= 0)";
        if ($year >= 2000 && $year <= 2100) {
            $sql .= " AND YEAR(start_date) = " . (int)$year;
        }
        if ($month > 0 && $month <= 12) {
            $sql .= " AND MONTH(start_date) = " . (int)$month;
        }
        if (strlen($search) > 0) {
            $s = '%' . $conn->real_escape_string($search) . '%';
            $sql .= " AND (employee_name LIKE '$s' OR employee_code LIKE '$s' OR reason LIKE '$s' OR tl_name LIKE '$s' OR hr_name LIKE '$s')";
        }
        $sql .= " ORDER BY updated_at DESC, start_date DESC LIMIT 500";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = leave_request_row_to_array($row);
        }
        leave_respond(true, [
            'total' => count($rows),
            'records' => $rows
        ]);
        break;

    case 'summary':
        $pending_approvals = 0;
        if (user_can_access_portal_approvals($user)) {
            $role = $user['portal_role'] ?? '';
            $isSuperAdmin = ($role === 'super_admin');
            if ($isSuperAdmin) {
                $r = $conn->prepare("SELECT COUNT(*) AS c FROM leave_requests WHERE status='pending' AND company_branch = ?
                    AND (policy_credit_value IS NULL OR policy_credit_value <= 0)");
                $r->bind_param('s', $branch);
            } elseif ($role === 'hr' || $role === 'admin') {
                $r = $conn->prepare("SELECT COUNT(*) AS c FROM leave_requests WHERE status='pending' AND company_branch = ?
                    AND (hr_user_id = ? OR (hr_user_id IS NULL AND hr_status = 'pending'))
                    AND (policy_credit_value IS NULL OR policy_credit_value <= 0)");
                $r->bind_param('si', $branch, $user_id);
            } else {
                $r = $conn->prepare("SELECT COUNT(*) AS c FROM leave_requests WHERE status='pending' AND company_branch = ?
                    AND tl_user_id = ?
                    AND (policy_credit_value IS NULL OR policy_credit_value <= 0)");
                $r->bind_param('si', $branch, $user_id);
            }
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
