<?php
/**
 * Resolve real biometric BID for portal users (recruiters often have REC#### placeholders).
 */
require_once __DIR__ . '/employee_sheet.php';

function normalize_person_name(string $name): string {
    return strtolower(preg_replace('/[^a-z0-9]/', '', trim($name)));
}

/** All attendance lookup keys for a BID. */
function employee_code_variants(string $code): array {
    $code = trim($code);
    if ($code === '') {
        return [];
    }
    $variants = [$code];
    $trim = ltrim($code, '0');
    if ($trim !== '' && $trim !== $code) {
        $variants[] = $trim;
    }
    if (preg_match('/^\d+$/', $trim)) {
        $variants[] = str_pad($trim, 4, '0', STR_PAD_LEFT);
    }
    if (preg_match('/^REC(\d+)$/i', $code, $m)) {
        $variants[] = $m[1];
        $variants[] = str_pad($m[1], 4, '0', STR_PAD_LEFT);
    }
    return array_values(array_unique(array_filter($variants)));
}

function attendance_codes_have_punches(mysqli $conn, array $codes): bool {
    $variants = [];
    foreach ($codes as $c) {
        foreach (employee_code_variants((string)$c) as $v) {
            $variants[$v] = true;
        }
    }
    $variants = array_keys($variants);
    if (empty($variants)) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $types = str_repeat('s', count($variants));
    $stmt = $conn->prepare("SELECT 1 FROM attendance_raw WHERE user_id IN ($placeholders) LIMIT 1");
    $stmt->bind_param($types, ...$variants);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function find_employee_code_by_sheet_name(mysqli $conn, string $fullName): ?string {
    $want = normalize_person_name($fullName);
    if ($want === '') {
        return null;
    }
    $all = merge_employee_db_row($conn, load_employee_sheet_data());
    $exact = null;
    $bestCode = null;
    $bestPct = 0.0;
    foreach ($all as $emp) {
        $got = normalize_person_name($emp['full_name'] ?? '');
        if ($got === '') {
            continue;
        }
        if ($got === $want) {
            return (string)$emp['employee_code'];
        }
        similar_text($want, $got, $pct);
        if ($pct > $bestPct && $pct >= 85.0) {
            $bestPct = $pct;
            $bestCode = (string)$emp['employee_code'];
        }
    }
    return $bestCode;
}

function find_employee_code_by_attendance_name(mysqli $conn, string $fullName): ?string {
    $want = normalize_person_name($fullName);
    if ($want === '') {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT user_id, name FROM attendance_raw
        WHERE name IS NOT NULL AND name != ''
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        GROUP BY user_id, name
        LIMIT 500
    ");
    if (!$stmt || !$stmt->execute()) {
        return null;
    }
    $res = $stmt->get_result();
    $bestId = null;
    $bestPct = 0.0;
    while ($row = $res->fetch_assoc()) {
        $got = normalize_person_name($row['name'] ?? '');
        if ($got === '') {
            continue;
        }
        if ($got === $want) {
            return (string)$row['user_id'];
        }
        similar_text($want, $got, $pct);
        if ($pct > $bestPct && $pct >= 82.0) {
            $bestPct = $pct;
            $bestId = (string)$row['user_id'];
        }
    }
    return $bestId;
}

function find_employee_code_by_email(mysqli $conn, string $email): ?string {
    $email = trim(strtolower($email));
    if ($email === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT employee_code FROM employees WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['employee_code'])) {
            return trim((string)$row['employee_code']);
        }
    }
    return null;
}

function is_placeholder_employee_code(string $code): bool {
    return $code === '' || (bool)preg_match('/^REC\d+$/i', $code);
}

/**
 * Resolve BID for attendance/payroll. Optionally saves real BID when profile had REC####.
 *
 * @return array{code: string, source: string, profile_code: string, auto_updated: bool}
 */
function resolve_employee_code_for_user(mysqli $conn, array $user, bool $persist = true): array {
    $profileCode = trim((string)($user['employee_code'] ?? ''));
    $userId = (int)($user['id'] ?? 0);
    $fullName = trim((string)($user['full_name'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));

    $candidates = [];

    if ($profileCode !== '' && !is_placeholder_employee_code($profileCode)) {
        $candidates[] = ['code' => $profileCode, 'source' => 'profile'];
    }

    if ($email !== '') {
        $byEmail = find_employee_code_by_email($conn, $email);
        if ($byEmail) {
            $candidates[] = ['code' => $byEmail, 'source' => 'email'];
        }
    }

    if ($fullName !== '') {
        $bySheet = find_employee_code_by_sheet_name($conn, $fullName);
        if ($bySheet) {
            $candidates[] = ['code' => $bySheet, 'source' => 'sheet_name'];
        }
        $byAtt = find_employee_code_by_attendance_name($conn, $fullName);
        if ($byAtt) {
            $candidates[] = ['code' => $byAtt, 'source' => 'attendance_name'];
        }
    }

    if ($profileCode !== '' && is_placeholder_employee_code($profileCode)) {
        $candidates[] = ['code' => $profileCode, 'source' => 'profile_placeholder'];
    } elseif ($profileCode !== '') {
        $candidates[] = ['code' => $profileCode, 'source' => 'profile'];
    }

    $seen = [];
    $ordered = [];
    foreach ($candidates as $c) {
        $code = trim((string)$c['code']);
        if ($code === '' || isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;
        $ordered[] = $c;
    }

    $resolved = '';
    $source = 'none';

    foreach ($ordered as $c) {
        $code = $c['code'];
        $variants = employee_code_variants($code);
        $hasPunches = attendance_codes_have_punches($conn, $variants);
        $inSheet = (bool)get_employee_from_sheet($conn, $code);
        if ($hasPunches || $inSheet) {
            $resolved = $code;
            $source = $c['source'];
            break;
        }
    }

    if ($resolved === '' && !empty($ordered)) {
        foreach ($ordered as $c) {
            if ($c['source'] !== 'profile_placeholder') {
                $resolved = $c['code'];
                $source = $c['source'];
                break;
            }
        }
    }

    if ($resolved === '' && $profileCode !== '') {
        $resolved = $profileCode;
        $source = 'profile';
    }

    $autoUpdated = false;
    if (
        $persist && $userId > 0 && $resolved !== '' && $resolved !== $profileCode
        && (is_placeholder_employee_code($profileCode) || $profileCode === '')
        && !is_placeholder_employee_code($resolved)
    ) {
        $upd = $conn->prepare('UPDATE users SET employee_code = ? WHERE id = ?');
        if ($upd) {
            $upd->bind_param('si', $resolved, $userId);
            if ($upd->execute()) {
                $autoUpdated = true;
            }
        }
    }

    return [
        'code' => $resolved,
        'source' => $source,
        'profile_code' => $profileCode,
        'auto_updated' => $autoUpdated,
    ];
}

function enrich_user_from_sheet(mysqli $conn, array &$user, string $empCode): void {
    if ($empCode === '') {
        return;
    }
    $sheet = get_employee_from_sheet($conn, $empCode);
    if (!$sheet) {
        return;
    }
    if (empty($user['full_name']) && !empty($sheet['full_name'])) {
        $user['full_name'] = $sheet['full_name'];
    }
    if (empty($user['department'])) {
        $user['department'] = $sheet['department'];
    }
    if (empty($user['designation'])) {
        $user['designation'] = $sheet['designation'];
    }
    if (empty($user['branch'])) {
        $user['branch'] = $sheet['branch'];
    }
    if (empty($user['team'])) {
        $user['team'] = $sheet['team'];
    }
}

function fetch_attendance_bundle(mysqli $conn, string $empCode, string $today): array {
    $attendance_raw = [];
    $check_in = null;
    $check_out = null;
    $times = [];
    $attendance_summary = [
        'present_days' => 0,
        'late_days' => 0,
        'total_punches' => 0,
    ];

    $codes = employee_code_variants($empCode);
    if (empty($codes)) {
        return compact('attendance_raw', 'check_in', 'check_out', 'times', 'attendance_summary');
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));

    $sql = "SELECT timestamp FROM attendance_raw
            WHERE user_id IN ($placeholders)
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY timestamp ASC";
    $hist = $conn->prepare($sql);
    $hist->bind_param($types, ...$codes);
    $hist->execute();
    $res = $hist->get_result();
    while ($row = $res->fetch_assoc()) {
        $attendance_raw[] = ['timestamp' => $row['timestamp']];
    }
    $attendance_summary['total_punches'] = count($attendance_raw);

    $daySql = "SELECT timestamp FROM attendance_raw
               WHERE user_id IN ($placeholders) AND DATE(timestamp) = ?
               ORDER BY timestamp ASC";
    $day = $conn->prepare($daySql);
    $dayTypes = $types . 's';
    $dayParams = array_merge($codes, [$today]);
    $day->bind_param($dayTypes, ...$dayParams);
    $day->execute();
    $punches = $day->get_result();
    while ($p = $punches->fetch_assoc()) {
        $times[] = $p['timestamp'];
    }
    if (count($times) >= 1) {
        $check_in = $times[0];
    }
    if (count($times) >= 2) {
        $check_out = $times[count($times) - 1];
    }

    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $mSql = "SELECT DATE(timestamp) AS d, MIN(TIME(timestamp)) AS first_in
             FROM attendance_raw
             WHERE user_id IN ($placeholders) AND DATE(timestamp) BETWEEN ? AND ?
             GROUP BY DATE(timestamp)";
    $mStmt = $conn->prepare($mSql);
    $mTypes = $types . 'ss';
    $mParams = array_merge($codes, [$monthStart, $monthEnd]);
    $mStmt->bind_param($mTypes, ...$mParams);
    $mStmt->execute();
    $mRes = $mStmt->get_result();
    while ($row = $mRes->fetch_assoc()) {
        $attendance_summary['present_days']++;
        $parts = explode(':', $row['first_in']);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        if ($h > 18 || ($h === 18 && $m > 10)) {
            $attendance_summary['late_days']++;
        }
    }

    return compact('attendance_raw', 'check_in', 'check_out', 'times', 'attendance_summary');
}

function fetch_payroll_bundle(mysqli $conn, string $empCode, string $month, string $branch): array {
    $payroll = [
        'month' => $month,
        'basic_salary' => null,
        'designation' => null,
        'bank_name' => null,
        'account_no' => null,
        'account_title' => null,
        'bonus' => 0,
        'tada' => 0,
        'advance_per_month' => 0,
        'leaves_this_month' => 0,
        'has_data' => false,
    ];

    if ($empCode === '') {
        return $payroll;
    }

    $codesToTry = employee_code_variants($empCode);
    $codesToTry = array_values(array_unique($codesToTry));

    foreach ($codesToTry as $tryCode) {
        $meta = $conn->prepare("SELECT basic_salary, designation, bank_name, account_no, account_title
            FROM employee_payroll_meta WHERE employee_code = ? LIMIT 1");
        if ($meta) {
            $meta->bind_param('s', $tryCode);
            $meta->execute();
            if ($mr = $meta->get_result()->fetch_assoc()) {
                $payroll['has_data'] = true;
                $payroll['basic_salary'] = (float)$mr['basic_salary'];
                $payroll['designation'] = $mr['designation'] ?? null;
                $payroll['bank_name'] = $mr['bank_name'];
                $payroll['account_no'] = $mr['account_no'];
                $payroll['account_title'] = $mr['account_title'];
                $empCode = $tryCode;
                break;
            }
        }
        $meta2 = $conn->prepare("SELECT basic_salary, designation, bank_name, account_no, account_title
            FROM employee_payroll_meta WHERE employee_code = ? AND company_branch = ? LIMIT 1");
        if ($meta2) {
            $meta2->bind_param('ss', $tryCode, $branch);
            $meta2->execute();
            if ($mr = $meta2->get_result()->fetch_assoc()) {
                $payroll['has_data'] = true;
                $payroll['basic_salary'] = (float)$mr['basic_salary'];
                $payroll['designation'] = $mr['designation'] ?? null;
                $payroll['bank_name'] = $mr['bank_name'];
                $payroll['account_no'] = $mr['account_no'];
                $payroll['account_title'] = $mr['account_title'];
                $empCode = $tryCode;
                break;
            }
        }
        // Legacy table without designation / company_branch
        $legacy = $conn->prepare("SELECT basic_salary, bank_name, account_no, account_title
            FROM employee_payroll_meta WHERE employee_code = ? LIMIT 1");
        if ($legacy) {
            $legacy->bind_param('s', $tryCode);
            $legacy->execute();
            if ($mr = $legacy->get_result()->fetch_assoc()) {
                $payroll['has_data'] = true;
                $payroll['basic_salary'] = (float)$mr['basic_salary'];
                $payroll['bank_name'] = $mr['bank_name'];
                $payroll['account_no'] = $mr['account_no'];
                $payroll['account_title'] = $mr['account_title'];
                $empCode = $tryCode;
                break;
            }
        }
    }

    $bonus = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payroll_adjustments
        WHERE employee_code = ? AND month = ? AND adj_type = 'bonus'");
    if ($bonus) {
        $bonus->bind_param('ss', $empCode, $month);
        $bonus->execute();
        $payroll['bonus'] = (float)($bonus->get_result()->fetch_assoc()['total'] ?? 0);
        if ($payroll['bonus'] > 0) {
            $payroll['has_data'] = true;
        }
    }

    $tada = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payroll_adjustments
        WHERE employee_code = ? AND month = ? AND adj_type = 'tada'");
    if ($tada) {
        $tada->bind_param('ss', $empCode, $month);
        $tada->execute();
        $payroll['tada'] = (float)($tada->get_result()->fetch_assoc()['total'] ?? 0);
        if ($payroll['tada'] > 0) {
            $payroll['has_data'] = true;
        }
    }

    $adv = $conn->prepare("SELECT per_month FROM payroll_advances WHERE employee_code = ? LIMIT 1");
    if ($adv) {
        $adv->bind_param('s', $empCode);
        $adv->execute();
        if ($ar = $adv->get_result()->fetch_assoc()) {
            $payroll['advance_per_month'] = (float)$ar['per_month'];
            $payroll['has_data'] = true;
        }
    }

    $lv = $conn->prepare("SELECT COUNT(*) AS c FROM employee_leaves
        WHERE employee_code = ? AND leave_date LIKE CONCAT(?, '%')");
    if ($lv) {
        $lv->bind_param('ss', $empCode, $month);
        $lv->execute();
        $payroll['leaves_this_month'] = (int)($lv->get_result()->fetch_assoc()['c'] ?? 0);
    }

    return $payroll;
}
