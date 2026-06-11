<?php
/**
 * Leave policy definitions (entitlements) — CRUD adapted for Balitech employee portal.
 * Uses users + leave_balance instead of legacy employees-only tables.
 */

function user_can_manage_leave_policies(array $user): bool {
    return user_can_view_leave_policy($user);
}

function leave_balance_column_for_type(string $leaveType): ?string {
    return match (leave_normalize_type_key($leaveType)) {
        'casual' => 'casual_leaves',
        'sick' => 'sick_leaves',
        'annual' => 'annual_leaves',
        'compensatory' => 'compensatory_leaves',
        'on_duty' => 'on_duty_leaves',
        'wfh' => 'wfh_leaves',
        default => null,
    };
}

function leave_policy_definition_to_array(array $row): array {
    $typeKey = leave_normalize_type_key((string) ($row['leave_type'] ?? ''));
    $meta = leave_type_catalog()[$typeKey] ?? null;
    return [
        'id' => (int) $row['id'],
        'policy_name' => $row['policy_name'],
        'policy_code' => $row['policy_code'],
        'leave_type' => $typeKey,
        'leave_type_label' => $meta['label'] ?? leave_type_label($typeKey),
        'leave_category' => $row['leave_category'],
        'unit' => $row['unit'],
        'credit_value' => (int) $row['credit_value'],
        'reset_enabled' => (int) $row['reset_enabled'] === 1,
        'reset_frequency' => $row['reset_frequency'],
        'reset_day_month' => $row['reset_day_month'],
        'carry_forward_enabled' => (int) $row['carry_forward_enabled'] === 1,
        'carry_forward_value' => (int) $row['carry_forward_value'],
        'encash_enabled' => (int) $row['encash_enabled'] === 1,
        'encash_value' => (int) $row['encash_value'],
        'valid_from' => $row['valid_from'],
        'expires_on' => $row['expires_on'],
        'is_active' => (int) $row['is_active'] === 1,
        'created_by' => $row['created_by'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

/** @return array<int, array> */
function fetch_leave_policy_definitions(mysqli $conn, string $branch, bool $activeOnly = false): array {
    $sql = 'SELECT * FROM leave_policies WHERE company_branch = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY policy_name ASC, id DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = leave_policy_definition_to_array($row);
    }
    return $rows;
}

function fetch_leave_policy_definition(mysqli $conn, int $id, string $branch): ?array {
    $stmt = $conn->prepare('SELECT * FROM leave_policies WHERE id = ? AND company_branch = ? LIMIT 1');
    $stmt->bind_param('is', $id, $branch);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? leave_policy_definition_to_array($row) : null;
}

function leave_policy_rules_from_db(mysqli $conn, string $branch): array {
    $rules = [];
    foreach (fetch_leave_policy_definitions($conn, $branch, true) as $p) {
        $meta = leave_type_catalog()[$p['leave_type']] ?? null;
        $detail = ($p['unit'] === 'hours')
            ? $p['credit_value'] . ' hours per year'
            : $p['credit_value'] . ' days per calendar year';
        if ($p['carry_forward_enabled']) {
            $detail .= ' · carry forward ' . $p['carry_forward_value'];
        }
        $rules[] = [
            'key' => 'policy_' . $p['id'],
            'title' => $p['policy_name'],
            'detail' => $detail . ' (' . $p['policy_code'] . ')',
            'icon' => $meta['icon'] ?? 'fa-file-contract',
            'group' => ($meta['group'] ?? 'balance'),
        ];
    }
    if (empty($rules)) {
        return [];
    }
    $rules[] = [
        'key' => 'holidays',
        'title' => 'Eid & Public Holidays',
        'detail' => 'Allotted by HR / Super Admin — synced to attendance',
        'icon' => 'fa-mosque',
        'group' => 'holiday',
    ];
    return $rules;
}

function fetch_leave_policy_by_code(mysqli $conn, string $branch, string $code, int $excludeId = 0): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    if ($excludeId > 0) {
        $stmt = $conn->prepare('SELECT id, policy_name, policy_code FROM leave_policies WHERE company_branch = ? AND policy_code = ? AND id != ? LIMIT 1');
        $stmt->bind_param('ssi', $branch, $code, $excludeId);
    } else {
        $stmt = $conn->prepare('SELECT id, policy_name, policy_code FROM leave_policies WHERE company_branch = ? AND policy_code = ? LIMIT 1');
        $stmt->bind_param('ss', $branch, $code);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function leave_policy_code_error(mysqli $conn, string $branch, string $code, int $excludeId = 0): ?string {
    $existing = fetch_leave_policy_by_code($conn, $branch, $code, $excludeId);
    if (!$existing) {
        return null;
    }
    return 'Policy code "' . $existing['policy_code'] . '" is already assigned to "' . $existing['policy_name'] . '". Please change the policy code (e.g. ' . $existing['policy_code'] . '-2) or click Edit on that policy to update it.';
}

function leave_policy_db_error(mysqli $conn, ?mysqli_stmt $stmt, string $action): string {
    if ((int) $conn->errno === 1062) {
        return 'This policy code is already registered for your branch. Please use a unique code.';
    }
    $msg = trim((string) ($stmt?->error ?? ''));
    if ($msg === '') {
        $msg = trim((string) $conn->error);
    }
    return $msg !== '' ? "Could not {$action} policy: {$msg}" : "Could not {$action} policy. Please try again.";
}

function leave_policy_parse_input(array $input): array {
    $leaveType = leave_normalize_type_key(trim((string) ($input['leave_type'] ?? '')));
    $balanceTypes = array_keys(leave_standard_quotas());
    if (!in_array($leaveType, $balanceTypes, true)) {
        return ['error' => 'Select a valid leave entitlement type (Casual, Sick, Annual, etc.).'];
    }
    $policyName = trim((string) ($input['policy_name'] ?? ''));
    $policyCode = strtoupper(trim((string) ($input['policy_code'] ?? '')));
    $creditValue = (int) ($input['credit_value'] ?? 0);
    if ($policyName === '' || $policyCode === '' || $creditValue < 0) {
        return ['error' => 'Policy name, code, and credit value are required.'];
    }
    return [
        'policy_name' => $policyName,
        'policy_code' => $policyCode,
        'leave_type' => $leaveType,
        'leave_category' => trim((string) ($input['leave_category'] ?? 'paid')) ?: 'paid',
        'unit' => trim((string) ($input['unit'] ?? 'days')) ?: 'days',
        'credit_value' => $creditValue,
        'reset_enabled' => !empty($input['reset_enabled']) ? 1 : 0,
        'reset_frequency' => trim((string) ($input['reset_frequency'] ?? 'yearly')) ?: 'yearly',
        'reset_day_month' => trim((string) ($input['reset_day_month'] ?? '31-Dec')) ?: '31-Dec',
        'carry_forward_enabled' => !empty($input['carry_forward_enabled']) ? 1 : 0,
        'carry_forward_value' => (int) ($input['carry_forward_value'] ?? 0),
        'encash_enabled' => !empty($input['encash_enabled']) ? 1 : 0,
        'encash_value' => (int) ($input['encash_value'] ?? 0),
        'valid_from' => trim((string) ($input['valid_from'] ?? '')) ?: null,
        'expires_on' => trim((string) ($input['expires_on'] ?? '')) ?: null,
        'apply_to_all' => !empty($input['apply_to_all']),
        'user_ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($input['user_ids'] ?? []))))),
    ];
}

function ensure_leave_balance_row(mysqli $conn, string $employeeCode, string $branch): void {
    $stmt = $conn->prepare('
        INSERT IGNORE INTO leave_balance (employee_code, company_branch)
        VALUES (?, ?)
    ');
    $stmt->bind_param('ss', $employeeCode, $branch);
    $stmt->execute();
}

function set_leave_balance_credit(mysqli $conn, string $employeeCode, string $branch, string $leaveType, float $credit): bool {
    $col = leave_balance_column_for_type($leaveType);
    if (!$col) {
        return false;
    }
    ensure_leave_balance_row($conn, $employeeCode, $branch);
    $allowed = ['casual_leaves', 'sick_leaves', 'annual_leaves', 'compensatory_leaves', 'on_duty_leaves', 'wfh_leaves'];
    if (!in_array($col, $allowed, true)) {
        return false;
    }
    $sql = "UPDATE leave_balance SET `$col` = ? WHERE employee_code = ? AND company_branch = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('dss', $credit, $employeeCode, $branch);
    return $stmt->execute();
}

/** @return string[] */
function fetch_branch_employee_codes(mysqli $conn, string $branch): array {
    $stmt = $conn->prepare("
        SELECT employee_code FROM users
        WHERE status = 'active' AND company_branch = ?
          AND employee_code IS NOT NULL AND employee_code != ''
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $codes = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $code = trim((string) $row['employee_code']);
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    return $codes;
}

function resolve_policy_apply_employee_codes(mysqli $conn, string $branch, bool $applyToAll, array $userIds): array {
    if ($applyToAll) {
        return fetch_branch_employee_codes($conn, $branch);
    }
    if (empty($userIds)) {
        return [];
    }
    $codes = [];
    foreach ($userIds as $uid) {
        $picked = fetch_user_by_id($conn, (int) $uid);
        if (!$picked || ($picked['status'] ?? '') !== 'active') {
            continue;
        }
        if (normalize_company_branch($picked['company_branch'] ?? '') !== $branch) {
            continue;
        }
        $code = trim((string) ($picked['employee_code'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    return array_values(array_unique($codes));
}

function assign_policy_to_employees(
    mysqli $conn,
    int $policyId,
    string $branch,
    string $leaveType,
    float $credit,
    bool $applyToAll,
    array $userIds,
    array $allotter,
    string $policyName
): int {
    $employeeCodes = resolve_policy_apply_employee_codes($conn, $branch, $applyToAll, $userIds);
    if (empty($employeeCodes)) {
        return 0;
    }
    $assigned = 0;
    $today = date('Y-m-d');
    foreach ($employeeCodes as $code) {
        $map = $conn->prepare('
            INSERT IGNORE INTO employee_leave_policy_map (employee_code, policy_id, company_branch)
            VALUES (?, ?, ?)
        ');
        $map->bind_param('sis', $code, $policyId, $branch);
        $map->execute();

        $empStmt = $conn->prepare("
            SELECT id, full_name, team, department FROM users
            WHERE employee_code = ? AND company_branch = ? AND status = 'active' LIMIT 1
        ");
        $empStmt->bind_param('ss', $code, $branch);
        $empStmt->execute();
        $emp = $empStmt->get_result()->fetch_assoc();
        if (!$emp) {
            continue;
        }

        $reason = 'Policy credit: ' . $policyName . ' (' . $credit . ' days)';
        $leaveId = create_policy_credit_request(
            $conn,
            $allotter,
            $emp,
            $branch,
            $leaveType,
            $credit,
            $policyId,
            $reason,
            $today
        );
        if ($leaveId > 0) {
            $assigned++;
            create_leave_notifications(
                $conn,
                $leaveId,
                [(int) $emp['id']],
                'Leave pending approval',
                $reason . ' — awaiting HR approval in Approvals.'
            );
        }
    }
    if ($assigned > 0) {
        $approverIds = fetch_portal_approver_user_ids($conn, $branch);
        $allotterId = (int) ($allotter['id'] ?? 0);
        $approverIds = array_values(array_filter($approverIds, fn($id) => $id !== $allotterId));
        if (!empty($approverIds)) {
            create_leave_notifications(
                $conn,
                $leaveId ?? 0,
                $approverIds,
                'Policy credit pending',
                "{$assigned} employee(s) have policy credit ({$credit} days) awaiting approval."
            );
        }
    }
    return $assigned;
}

function create_policy_credit_request(
    mysqli $conn,
    array $allotter,
    array $employee,
    string $branch,
    string $leaveType,
    float $credit,
    int $policyId,
    string $reason,
    string $date
): int {
    $empId = (int) $employee['id'];
    $empCode = trim((string) ($employee['employee_code'] ?? '')) ?: ('U' . $empId);
    $allotterId = (int) ($allotter['id'] ?? 0);
    $allotterName = trim((string) ($allotter['full_name'] ?? 'HR'));

    $stmt = $conn->prepare("INSERT INTO leave_requests (
        user_id, employee_code, employee_name, team, department, company_branch,
        leave_type, duration_type, start_date, end_date, half_day_slot, reason, apply_through,
        approver_user_id, approver_name,
        status, tl_status, fm_status, hr_status,
        is_policy_allotment, allotted_by_user_id, allotted_by_name,
        policy_credit_value, policy_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'full_day', ?, ?, NULL, ?, 'hr', ?, ?, 'pending', 'none', 'none', 'pending', 1, ?, ?, ?, ?)");
    $stmt->bind_param(
        'isssssssssisisdi',
        $empId,
        $empCode,
        $employee['full_name'],
        $employee['team'],
        $employee['department'],
        $branch,
        $leaveType,
        $date,
        $date,
        $reason,
        $allotterId,
        $allotterName,
        $allotterId,
        $allotterName,
        $credit,
        $policyId
    );
    if (!$stmt->execute()) {
        return 0;
    }
    return (int) $conn->insert_id;
}

function apply_approved_policy_credit(mysqli $conn, array $request): void {
    $credit = (float) ($request['policy_credit_value'] ?? 0);
    if ($credit <= 0) {
        return;
    }
    $code = trim((string) ($request['employee_code'] ?? ''));
    $branch = normalize_company_branch($request['company_branch'] ?? 'main');
    $leaveType = leave_normalize_type_key((string) ($request['leave_type'] ?? ''));
    if ($code === '') {
        return;
    }
    set_leave_balance_credit($conn, $code, $branch, $leaveType, $credit);
}

function save_leave_policy_definition(mysqli $conn, string $branch, array $user, array $input): array {
    $parsed = leave_policy_parse_input($input);
    if (!empty($parsed['error'])) {
        return ['success' => false, 'error' => $parsed['error']];
    }
    $codeError = leave_policy_code_error($conn, $branch, $parsed['policy_code']);
    if ($codeError) {
        return ['success' => false, 'error' => $codeError];
    }
    $createdBy = trim((string) ($user['full_name'] ?? $user['email'] ?? 'HR'));
    $stmt = $conn->prepare('
        INSERT INTO leave_policies (
            company_branch, policy_name, policy_code, leave_type, leave_category, unit, credit_value,
            reset_enabled, reset_frequency, reset_day_month,
            carry_forward_enabled, carry_forward_value, encash_enabled, encash_value,
            valid_from, expires_on, is_active, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ');
    if (!$stmt) {
        return ['success' => false, 'error' => leave_policy_db_error($conn, null, 'save')];
    }
    $stmt->bind_param(
        'ssssssiissiiiisss',
        $branch,
        $parsed['policy_name'],
        $parsed['policy_code'],
        $parsed['leave_type'],
        $parsed['leave_category'],
        $parsed['unit'],
        $parsed['credit_value'],
        $parsed['reset_enabled'],
        $parsed['reset_frequency'],
        $parsed['reset_day_month'],
        $parsed['carry_forward_enabled'],
        $parsed['carry_forward_value'],
        $parsed['encash_enabled'],
        $parsed['encash_value'],
        $parsed['valid_from'],
        $parsed['expires_on'],
        $createdBy
    );
    if (!$stmt->execute()) {
        return ['success' => false, 'error' => leave_policy_db_error($conn, $stmt, 'save')];
    }
    $policyId = (int) $conn->insert_id;
    $shouldApply = $parsed['apply_to_all'] || !empty($parsed['user_ids']);
    $assigned = assign_policy_to_employees(
        $conn,
        $policyId,
        $branch,
        $parsed['leave_type'],
        (float) $parsed['credit_value'],
        $parsed['apply_to_all'],
        $parsed['user_ids'],
        $user,
        $parsed['policy_name']
    );
    return [
        'success' => true,
        'id' => $policyId,
        'assigned' => $assigned,
        'message' => $shouldApply
            ? "Policy saved. {$assigned} credit request(s) sent to Approvals for review."
            : 'Policy saved successfully.',
    ];
}

function update_leave_policy_definition(mysqli $conn, string $branch, array $user, array $input): array {
    $policyId = (int) ($input['policy_id'] ?? 0);
    if ($policyId <= 0) {
        return ['success' => false, 'error' => 'Invalid policy.'];
    }
    $existing = fetch_leave_policy_definition($conn, $policyId, $branch);
    if (!$existing) {
        return ['success' => false, 'error' => 'Policy not found.'];
    }
    $parsed = leave_policy_parse_input($input);
    if (!empty($parsed['error'])) {
        return ['success' => false, 'error' => $parsed['error']];
    }
    $codeError = leave_policy_code_error($conn, $branch, $parsed['policy_code'], $policyId);
    if ($codeError) {
        return ['success' => false, 'error' => $codeError];
    }
    $stmt = $conn->prepare('
        UPDATE leave_policies SET
            policy_name = ?, policy_code = ?, leave_type = ?, leave_category = ?, unit = ?, credit_value = ?,
            reset_enabled = ?, reset_frequency = ?, reset_day_month = ?,
            carry_forward_enabled = ?, carry_forward_value = ?, encash_enabled = ?, encash_value = ?,
            valid_from = ?, expires_on = ?
        WHERE id = ? AND company_branch = ?
    ');
    if (!$stmt) {
        return ['success' => false, 'error' => leave_policy_db_error($conn, null, 'update')];
    }
    $stmt->bind_param(
        'sssssiissiiiissis',
        $parsed['policy_name'],
        $parsed['policy_code'],
        $parsed['leave_type'],
        $parsed['leave_category'],
        $parsed['unit'],
        $parsed['credit_value'],
        $parsed['reset_enabled'],
        $parsed['reset_frequency'],
        $parsed['reset_day_month'],
        $parsed['carry_forward_enabled'],
        $parsed['carry_forward_value'],
        $parsed['encash_enabled'],
        $parsed['encash_value'],
        $parsed['valid_from'],
        $parsed['expires_on'],
        $policyId,
        $branch
    );
    if (!$stmt->execute()) {
        return ['success' => false, 'error' => leave_policy_db_error($conn, $stmt, 'update')];
    }
    $shouldApply = $parsed['apply_to_all'] || !empty($parsed['user_ids']);
    $assigned = assign_policy_to_employees(
        $conn,
        $policyId,
        $branch,
        $parsed['leave_type'],
        (float) $parsed['credit_value'],
        $parsed['apply_to_all'],
        $parsed['user_ids'],
        $user,
        $parsed['policy_name']
    );
    return [
        'success' => true,
        'id' => $policyId,
        'assigned' => $assigned,
        'message' => $shouldApply
            ? "Policy updated. {$assigned} credit request(s) sent to Approvals for review."
            : 'Policy updated successfully.',
    ];
}

function delete_leave_policy_definition(mysqli $conn, int $policyId, string $branch): array {
    if ($policyId <= 0) {
        return ['success' => false, 'error' => 'Invalid policy.'];
    }
    $delMap = $conn->prepare('DELETE FROM employee_leave_policy_map WHERE policy_id = ? AND company_branch = ?');
    $delMap->bind_param('is', $policyId, $branch);
    $delMap->execute();
    $stmt = $conn->prepare('DELETE FROM leave_policies WHERE id = ? AND company_branch = ?');
    $stmt->bind_param('is', $policyId, $branch);
    if (!$stmt->execute() || $stmt->affected_rows < 1) {
        return ['success' => false, 'error' => 'Policy not found or could not be deleted.'];
    }
    return ['success' => true, 'message' => 'Leave policy deleted.'];
}

/** Override catalog quotas from leave_balance when row exists. */
function leave_balance_quotas_for_user(mysqli $conn, int $userId, string $branch): array {
    $stmt = $conn->prepare('SELECT employee_code FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $code = trim((string) ($row['employee_code'] ?? ''));
    if ($code === '') {
        return [];
    }
    $bal = $conn->prepare('SELECT * FROM leave_balance WHERE employee_code = ? AND company_branch = ? LIMIT 1');
    $bal->bind_param('ss', $code, $branch);
    $bal->execute();
    $b = $bal->get_result()->fetch_assoc();
    if (!$b) {
        return [];
    }
    $map = [
        'casual' => (float) $b['casual_leaves'],
        'sick' => (float) $b['sick_leaves'],
        'annual' => (float) $b['annual_leaves'],
        'compensatory' => (float) $b['compensatory_leaves'],
        'on_duty' => (float) $b['on_duty_leaves'],
        'wfh' => (float) $b['wfh_leaves'],
    ];
    $out = [];
    foreach ($map as $key => $val) {
        if ($val > 0 || array_key_exists($key, leave_standard_quotas())) {
            $out[$key] = $val;
        }
    }
    return $out;
}

function leave_policy_type_options(): array {
    $options = [];
    foreach (leave_standard_quotas() as $key => $meta) {
        $options[] = ['key' => $key, 'label' => $meta['label']];
    }
    return $options;
}
