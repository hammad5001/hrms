<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/employee_resolve.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/chat_schema.php';
require_once __DIR__ . '/../includes/employee_profile.php';

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
    $on_duty = !empty($attendance['on_duty']);
    $attendance_status = $attendance['attendance_status'] ?? 'absent';
    $attendance_label = $attendance['attendance_label'] ?? 'Absent';
    $is_late_today = !empty($attendance['is_late']);
    $auto_closed = !empty($attendance['auto_closed']);
    $serverClock = function_exists('ess_server_clock')
        ? ess_server_clock($conn)
        : ['ts' => time(), 'now_str' => date('Y-m-d H:i:s')];
    $server_ts = (int)$serverClock['ts'];
    $server_now = $serverClock['now_str'];
    $timer_check_in = $on_duty ? $check_in : null;
    $timer_check_out = $on_duty ? null : $check_out;
    $duty_seconds = function_exists('ess_duty_seconds')
        ? ess_duty_seconds($timer_check_in ?: $check_in, $timer_check_out, $conn, $shift_date)
        : 0;
    $working_hours = function_exists('ess_working_hours')
        ? ess_working_hours($timer_check_in ?: $check_in, $timer_check_out, $conn, $shift_date)
        : 0;
    $shift_deadline = $shift_date
        ? date('Y-m-d', strtotime($shift_date . ' +1 day')) . ' 11:00:00'
        : null;
    $check_in_unix = ($check_in && function_exists('ess_punch_unix')) ? ess_punch_unix($conn, $check_in) : ($check_in ? strtotime($check_in) : null);
    $check_out_unix = ($check_out && function_exists('ess_punch_unix')) ? ess_punch_unix($conn, $check_out) : ($check_out ? strtotime($check_out) : null);
    $duty_check_in_unix = $check_in_unix;

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
    $profile_details = fetch_employee_profile_details($conn, (int) $user['id']);

    echo json_encode([
        'success' => true,
        'server_now' => $server_now,
        'user' => $user,
        'profile_details' => $profile_details,
        'company_branch_label' => $branchLabel,
        'active_branch' => $branch,
        'attendance_raw' => $attendance_raw,
        'attendance_summary' => $attendance_summary,
        'today' => [
            'date' => $shift_date,
            'calendar_date' => $today,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'duty_check_in' => $timer_check_in,
            'duty_check_out' => $timer_check_out,
            'punch_count' => count($times),
            'working_hours' => $working_hours,
            'duty_seconds' => $duty_seconds,
            'check_in_unix' => $check_in_unix,
            'check_out_unix' => $check_out_unix,
            'duty_check_in_unix' => $duty_check_in_unix,
            'server_ts' => $server_ts,
            'on_duty' => $on_duty,
            'timer_active' => $on_duty && (bool) $timer_check_in,
            'status' => $attendance_status,
            'status_label' => $attendance_label,
            'is_late' => $is_late_today,
            'auto_closed' => $auto_closed,
            'shift_deadline' => $shift_deadline,
        ],
        'shift' => [
            'type' => 'night',
            'label' => 'Night shift (6 PM – 4 AM) · window 4 PM – next day 11 AM',
            'checkin_from' => '16:00',
            'shift_start' => '18:00',
            'checkout_until' => '11:00',
            'late_after' => '18:00',
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
