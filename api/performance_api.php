<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/performance_helpers.php';

ensure_app_schema($conn);
ensure_performance_schema($conn);

require_once __DIR__ . '/../includes/session_user.php';
$user = resolve_logged_in_user($conn);
if (!$user) {
    perf_respond(false, null, 'Not authenticated');
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$branch = function_exists('get_active_company_branch')
    ? get_active_company_branch()
    : normalize_company_branch($user['company_branch'] ?? 'main');
$userId = (int) $user['id'];

$reportDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['date'] ?? $_GET['date'] ?? ''))
    ? ($input['date'] ?? $_GET['date'])
    : date('Y-m-d');

switch ($action) {

    case 'day':
        perf_respond(true, perf_day_bundle($conn, $user, $reportDate, $branch));
        break;

    case 'submit':
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['report_date'] ?? ''))
            ? $input['report_date']
            : date('Y-m-d');
        $result = perf_submit_report($conn, $userId, $branch, $date, $input);
        if (!$result['ok']) {
            perf_respond(false, null, $result['error']);
        }
        perf_respond(true, [
            'saved' => true,
            'report' => perf_get_report($conn, $userId, $date, $branch),
            'day' => perf_day_bundle($conn, $user, $date, $branch),
        ]);
        break;

    case 'history':
        $limit = min(60, max(5, (int) ($_GET['limit'] ?? 20)));
        $history = perf_history_with_attendance($conn, $user, $branch, $limit);

        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $summary = perf_period_summary($conn, $userId, $branch, $monthStart, $monthEnd);

        perf_respond(true, [
            'history' => $history,
            'month_summary' => $summary,
            'permissions' => [
                'can_view_team' => user_can_view_team_performance($user),
            ],
        ]);
        break;

    case 'team_day':
        if (!user_can_view_team_performance($user)) {
            perf_respond(false, null, 'Not authorized to view team reports');
        }
        perf_respond(true, perf_team_daily_status($conn, $user, $reportDate, $branch));
        break;

    case 'month_summary':
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? ''))
            ? $_GET['month']
            : date('Y-m');
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        perf_respond(true, perf_period_summary($conn, $userId, $branch, $from, $to));
        break;

    default:
        perf_respond(false, null, 'Unknown action');
}
