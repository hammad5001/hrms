<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/employee_resolve.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/chat_schema.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensure_app_schema($conn);

    $user = resolve_logged_in_user($conn);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
        exit;
    }

    if (($user['status'] ?? 'active') === 'inactive') {
        echo json_encode(['success' => false, 'message' => 'Account is inactive']);
        exit;
    }

    $today = date('Y-m-d');
    $month = date('Y-m');

    $branch = function_exists('get_active_company_branch')
        ? normalize_company_branch(get_active_company_branch())
        : normalize_company_branch($user['company_branch'] ?? 'main');

    if (function_exists('get_active_company_branch')) {
        $_SESSION['company_branch'] = normalize_company_branch(
            $_SESSION['company_branch'] ?? ($user['company_branch'] ?? 'main')
        );
        $branch = normalize_company_branch($_SESSION['company_branch']);
    }

    $user['company_branch'] = $branch;

    $resolution = resolve_employee_code_for_user($conn, $user, true);
    $emp_code = $resolution['code'];
    if ($emp_code !== '') {
        $user['employee_code'] = $emp_code;
        enrich_user_from_sheet($conn, $user, $emp_code);
    }

    $attendance = fetch_attendance_bundle($conn, $emp_code, $today, $branch);
    $attendance_raw = $attendance['attendance_raw'];
    $check_in = $attendance['check_in'];
    $check_out = $attendance['check_out'];
    $times = $attendance['times'];
    $attendance_summary = $attendance['attendance_summary'];
    $shift_date = $attendance['shift_date'] ?? $today;
    $working_hours = function_exists('ess_working_hours')
        ? ess_working_hours($check_in, $check_out)
        : 0;

    $payroll = fetch_payroll_bundle($conn, $emp_code, $month, $branch);

    $branchLabel = function_exists('company_branch_label')
        ? company_branch_label($_SESSION['company_branch'] ?? $user['company_branch'])
        : 'Main';

    $sourceLabels = [
        'profile' => 'Account BID',
        'sheet_name' => 'Matched from employee roster (name)',
        'attendance_name' => 'Matched from attendance device (name)',
        'email' => 'Matched from employee email',
        'profile_placeholder' => 'Placeholder ID only',
        'none' => 'Not linked',
    ];

    $user['avatar_url'] = chat_public_avatar_url($user['chat_avatar'] ?? '');

    echo json_encode([
        'success' => true,
        'user' => $user,
        'company_branch_label' => $branchLabel,
        'active_branch' => $branch,
        'attendance_raw' => $attendance_raw,
        'attendance_summary' => $attendance_summary,
        'today' => [
            'date' => $shift_date,
            'calendar_date' => $today,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'punch_count' => count($times),
            'working_hours' => $working_hours,
            'is_late' => function_exists('ess_is_late_checkin')
                ? ess_is_late_checkin($check_in, $shift_date)
                : false,
        ],
        'shift' => [
            'type' => 'night',
            'label' => 'Night shift (2 PM – next day 12 PM)',
            'checkin_from' => '14:00',
            'shift_start' => '19:00',
            'grace_minutes' => 15,
        ],
        'payroll' => $payroll,
        'meta' => [
            'employee_code_set' => $emp_code !== '',
            'attendance_records' => count($attendance_raw),
            'resolved_employee_code' => $emp_code,
            'profile_employee_code' => $resolution['profile_code'],
            'resolution_source' => $resolution['source'],
            'resolution_label' => $sourceLabels[$resolution['source']] ?? $resolution['source'],
            'bid_auto_updated' => $resolution['auto_updated'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error loading employee data',
        'error' => $e->getMessage(),
    ]);
}
