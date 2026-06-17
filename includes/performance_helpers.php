<?php
/**
 * Employee Performance — daily full-day reports + attendance/punctuality (read-only).
 */
declare(strict_types=1);

require_once __DIR__ . '/performance_schema.php';
require_once __DIR__ . '/leave_helpers.php';
require_once __DIR__ . '/employee_resolve.php';
require_once __DIR__ . '/reportees_helpers.php';
require_once __DIR__ . '/attendance_shift.php';

function perf_respond(bool $success, $data = null, ?string $error = null): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

function user_can_view_team_performance(array $user): bool
{
    return user_is_manager($user);
}

function perf_format_report_row(?array $row): ?array
{
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'report_date' => $row['report_date'],
        'calls_made' => (int) $row['calls_made'],
        'sales_closed' => (int) $row['sales_closed'],
        'transfers_done' => (int) $row['transfers_done'],
        'leads_contacted' => (int) $row['leads_contacted'],
        'follow_ups' => (int) $row['follow_ups'],
        'callbacks_done' => (int) ($row['callbacks_done'] ?? 0),
        'talk_minutes' => (int) ($row['talk_minutes'] ?? 0),
        'day_summary' => $row['day_summary'] ?? '',
        'submitted_at' => $row['submitted_at'],
    ];
}

function perf_get_report(mysqli $conn, int $userId, string $date, string $branch): ?array
{
    ensure_performance_schema($conn);
    $stmt = $conn->prepare(
        'SELECT * FROM employee_daily_reports WHERE user_id = ? AND report_date = ? AND company_branch = ? LIMIT 1'
    );
    $stmt->bind_param('iss', $userId, $date, $branch);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? perf_format_report_row($row) : null;
}

function perf_shift_attendance_detail(mysqli $conn, string $empCode, string $shiftDate, ?string $branch): array
{
    $codes = employee_code_variants($empCode);
    if (empty($codes)) {
        return perf_empty_attendance($shiftDate);
    }

    $table = branch_attendance_table($branch);
    $windows = ess_get_shift_windows($shiftDate);
    $from = $windows['checkin_start'];
    $to = $windows['checkout_end'];

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes)) . 'ss';
    $sql = "SELECT timestamp FROM `$table`
            WHERE user_id IN ($placeholders) AND timestamp >= ? AND timestamp <= ?
            ORDER BY timestamp ASC";
    $stmt = $conn->prepare($sql);
    $params = array_merge($codes, [$from, $to]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $timestamps = [];
    while ($row = $res->fetch_assoc()) {
        $timestamps[] = $row['timestamp'];
    }

    $shift = ess_resolve_shift_punches($timestamps, $shiftDate);
    $checkIn = $shift['check_in'] ?? null;
    $checkOut = $shift['check_out'] ?? null;
    $status = ess_attendance_status_for_shift($checkIn, $checkOut, $shiftDate);

    $dutySeconds = ess_duty_seconds($checkIn, $checkOut, $conn, $shiftDate);
    $workingHours = ess_working_hours($checkIn, $checkOut, $conn, $shiftDate);

    $punctuality = perf_punctuality_meta($status, $checkIn, $shiftDate);

    return [
        'shift_date' => $shiftDate,
        'check_in' => $checkIn ? date('h:i A', strtotime($checkIn)) : null,
        'check_out' => $checkOut ? date('h:i A', strtotime($checkOut)) : null,
        'check_in_raw' => $checkIn,
        'check_out_raw' => $checkOut,
        'status' => $status['status'] ?? 'absent',
        'status_label' => $status['label'] ?? 'Absent',
        'is_late' => !empty($status['is_late']),
        'is_present' => ($status['status'] ?? '') === 'present',
        'duty_hours' => round($workingHours, 2),
        'duty_seconds' => $dutySeconds,
        'punch_count' => count($timestamps),
        'punctuality' => $punctuality,
    ];
}

function perf_empty_attendance(string $shiftDate): array
{
    return [
        'shift_date' => $shiftDate,
        'check_in' => null,
        'check_out' => null,
        'check_in_raw' => null,
        'check_out_raw' => null,
        'status' => 'absent',
        'status_label' => 'Absent',
        'is_late' => false,
        'is_present' => false,
        'duty_hours' => 0,
        'duty_seconds' => 0,
        'punch_count' => 0,
        'punctuality' => [
            'grade' => 'absent',
            'label' => 'Not Present',
            'score' => 0,
            'icon' => 'fa-circle-xmark',
            'color' => 'danger',
        ],
    ];
}

function perf_punctuality_meta(array $status, ?string $checkIn, string $shiftDate): array
{
    $st = $status['status'] ?? 'absent';
    if ($st === 'absent' || !$checkIn) {
        return ['grade' => 'absent', 'label' => 'Not Present', 'score' => 0, 'icon' => 'fa-circle-xmark', 'color' => 'danger'];
    }
    if (!empty($status['is_late'])) {
        return ['grade' => 'late', 'label' => 'Late Arrival', 'score' => 55, 'icon' => 'fa-clock', 'color' => 'warn'];
    }
    if ($st === 'present') {
        return ['grade' => 'ontime', 'label' => 'On Time', 'score' => 100, 'icon' => 'fa-circle-check', 'color' => 'good'];
    }
    return ['grade' => 'partial', 'label' => $status['label'] ?? 'Partial', 'score' => 70, 'icon' => 'fa-circle-half-stroke', 'color' => 'warn'];
}

function perf_submit_report(mysqli $conn, int $userId, string $branch, string $date, array $payload): array
{
    ensure_performance_schema($conn);

    if ($date > date('Y-m-d')) {
        return ['ok' => false, 'error' => 'Cannot submit a report for a future date.'];
    }

    if (perf_get_report($conn, $userId, $date, $branch)) {
        return ['ok' => false, 'error' => 'Daily report already submitted for this date. Reports cannot be edited.'];
    }

    $calls = max(0, (int) ($payload['calls_made'] ?? 0));
    $sales = max(0, (int) ($payload['sales_closed'] ?? 0));
    $transfers = max(0, (int) ($payload['transfers_done'] ?? 0));
    $leads = max(0, (int) ($payload['leads_contacted'] ?? 0));
    $followUps = max(0, (int) ($payload['follow_ups'] ?? 0));
    $callbacks = max(0, (int) ($payload['callbacks_done'] ?? 0));
    $talkMin = max(0, (int) ($payload['talk_minutes'] ?? 0));
    $summary = trim((string) ($payload['day_summary'] ?? ''));

    if ($calls === 0 && $sales === 0 && $transfers === 0 && $leads === 0) {
        return ['ok' => false, 'error' => 'Enter at least calls, sales, transfers, or leads contacted for your day report.'];
    }

    $stmt = $conn->prepare(
        'INSERT INTO employee_daily_reports
        (user_id, company_branch, report_date, calls_made, sales_closed, transfers_done,
         leads_contacted, follow_ups, callbacks_done, talk_minutes, day_summary, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param(
        'issiiiiiiis',
        $userId,
        $branch,
        $date,
        $calls,
        $sales,
        $transfers,
        $leads,
        $followUps,
        $callbacks,
        $talkMin,
        $summary
    );

    if (!$stmt->execute()) {
        return ['ok' => false, 'error' => 'Could not save report. It may already exist for this date.'];
    }

    return ['ok' => true, 'id' => (int) $conn->insert_id];
}

function perf_list_reports(mysqli $conn, int $userId, string $branch, int $limit = 30): array
{
    ensure_performance_schema($conn);
    $stmt = $conn->prepare(
        'SELECT * FROM employee_daily_reports
         WHERE user_id = ? AND company_branch = ?
         ORDER BY report_date DESC LIMIT ?'
    );
    $stmt->bind_param('isi', $userId, $branch, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = perf_format_report_row($row);
    }
    return $rows;
}

function perf_period_summary(mysqli $conn, int $userId, string $branch, string $from, string $to): array
{
    ensure_performance_schema($conn);
    $stmt = $conn->prepare(
        'SELECT
            COUNT(*) AS days_reported,
            COALESCE(SUM(calls_made), 0) AS total_calls,
            COALESCE(SUM(sales_closed), 0) AS total_sales,
            COALESCE(SUM(transfers_done), 0) AS total_transfers,
            COALESCE(SUM(leads_contacted), 0) AS total_leads,
            COALESCE(SUM(follow_ups), 0) AS total_followups,
            COALESCE(SUM(talk_minutes), 0) AS total_talk_minutes
         FROM employee_daily_reports
         WHERE user_id = ? AND company_branch = ? AND report_date >= ? AND report_date <= ?'
    );
    $stmt->bind_param('isss', $userId, $branch, $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    return [
        'days_reported' => (int) ($row['days_reported'] ?? 0),
        'total_calls' => (int) ($row['total_calls'] ?? 0),
        'total_sales' => (int) ($row['total_sales'] ?? 0),
        'total_transfers' => (int) ($row['total_transfers'] ?? 0),
        'total_leads' => (int) ($row['total_leads'] ?? 0),
        'total_followups' => (int) ($row['total_followups'] ?? 0),
        'total_talk_minutes' => (int) ($row['total_talk_minutes'] ?? 0),
    ];
}

function perf_day_bundle(mysqli $conn, array $user, string $date, string $branch): array
{
    $userId = (int) $user['id'];
    $empCode = (string) ($user['employee_code'] ?? '');
    $report = perf_get_report($conn, $userId, $date, $branch);
    $attendance = perf_shift_attendance_detail($conn, $empCode, $date, $branch);
    $isToday = ($date === date('Y-m-d'));
    $activeShift = function_exists('ess_active_shift_date') ? ess_active_shift_date() : date('Y-m-d');
    $canSubmit = !$report && $date <= date('Y-m-d');

    return [
        'report_date' => $date,
        'is_today' => $isToday,
        'active_shift_date' => $activeShift,
        'submitted' => $report !== null,
        'can_submit' => $canSubmit,
        'report' => $report,
        'attendance' => $attendance,
        'employee' => [
            'full_name' => $user['full_name'] ?? '',
            'employee_code' => $empCode,
            'designation' => $user['designation'] ?? '',
            'team' => $user['team'] ?? '',
        ],
    ];
}

function perf_team_daily_status(mysqli $conn, array $manager, string $date, string $branch): array
{
    $members = perf_team_members($conn, $manager, $branch);
    $rows = [];
    foreach ($members as $member) {
        $report = perf_get_report($conn, (int) $member['id'], $date, $branch);
        $att = perf_shift_attendance_detail($conn, (string) ($member['employee_code'] ?? ''), $date, $branch);
        $rows[] = [
            'user_id' => (int) $member['id'],
            'full_name' => $member['full_name'],
            'team' => $member['team'] ?? '',
            'submitted' => $report !== null,
            'submitted_at' => $report['submitted_at'] ?? null,
            'calls_made' => $report['calls_made'] ?? 0,
            'sales_closed' => $report['sales_closed'] ?? 0,
            'transfers_done' => $report['transfers_done'] ?? 0,
            'attendance_status' => $att['status_label'],
            'punctuality' => $att['punctuality']['label'],
            'is_late' => $att['is_late'],
        ];
    }
    usort($rows, fn($a, $b) => ($b['submitted'] <=> $a['submitted']) ?: strcmp($a['full_name'], $b['full_name']));
    $submitted = count(array_filter($rows, fn($r) => $r['submitted']));
    return [
        'date' => $date,
        'team' => $rows,
        'summary' => [
            'total' => count($rows),
            'submitted' => $submitted,
            'pending' => count($rows) - $submitted,
        ],
    ];
}

function perf_team_members(mysqli $conn, array $manager, string $branch): array
{
    $managerId = (int) $manager['id'];
    $role = $manager['portal_role'] ?? '';

    if (in_array($role, ['super_admin', 'admin', 'hr', 'management'], true)) {
        $stmt = $conn->prepare(
            "SELECT id, full_name, employee_code, designation, team, department, portal_role
             FROM users WHERE status = 'active' AND company_branch = ?
             AND portal_role IN ('agent','recruiter','dialer','team_lead','user')
             ORDER BY full_name ASC LIMIT 120"
        );
        $stmt->bind_param('s', $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        $members = [];
        while ($row = $res->fetch_assoc()) {
            $members[] = $row;
        }
        return $members;
    }

    $stmt = $conn->prepare(
        'SELECT u.id, u.full_name, u.employee_code, u.designation, u.team, u.department, u.portal_role
         FROM employee_reporting er
         JOIN users u ON u.id = er.employee_user_id
         WHERE er.manager_user_id = ? AND er.company_branch = ?
         ORDER BY u.full_name ASC'
    );
    $stmt->bind_param('is', $managerId, $branch);
    $stmt->execute();
    $res = $stmt->get_result();
    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = $row;
    }
    return $members;
}

function perf_history_with_attendance(mysqli $conn, array $user, string $branch, int $limit = 20): array
{
    $reports = perf_list_reports($conn, (int) $user['id'], $branch, $limit);
    $empCode = (string) ($user['employee_code'] ?? '');
    $out = [];
    foreach ($reports as $report) {
        $att = perf_shift_attendance_detail($conn, $empCode, $report['report_date'], $branch);
        $out[] = [
            'report' => $report,
            'attendance' => [
                'status_label' => $att['status_label'],
                'is_late' => $att['is_late'],
                'check_in' => $att['check_in'],
                'check_out' => $att['check_out'],
                'duty_hours' => $att['duty_hours'],
                'punctuality' => $att['punctuality'],
            ],
        ];
    }
    return $out;
}
