<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

// config.php: sets $conn, Content-Type: application/json, calls ensure_app_schema
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/performance_helpers.php';
require_once __DIR__ . '/../includes/productivity_score.php';

header('Content-Type: application/json');

function prod_respond(bool $success, $data = null, string $error = ''): void {
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

$user = resolve_logged_in_user($conn);
if (!$user) prod_respond(false, null, 'Not authenticated');

$userId = (int) $user['id'];
$empCode = (string) ($user['employee_code'] ?? '');
$branch  = (string) ($user['company_branch'] ?? 'main');
$action  = $_GET['action'] ?? '';
$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$today   = date('Y-m-d');

// ─── Helpers ─────────────────────────────────────────────────────────────────

function prod_log_activity(mysqli $conn, int $userId, string $type, string $title, string $desc): void {
    $t = $conn->real_escape_string($type);
    $ti = $conn->real_escape_string($title);
    $d = $conn->real_escape_string($desc);
    $conn->query("INSERT INTO activity_feed (employee_id, event_type, title, description) VALUES ($userId, '$t', '$ti', '$d')");
}

function prod_get_active_log(mysqli $conn, int $userId): ?array {
    $res = $conn->query("SELECT * FROM time_logs WHERE employee_id = $userId AND status IN ('active','on_break') ORDER BY id DESC LIMIT 1");
    return $res ? ($res->fetch_assoc() ?: null) : null;
}

function prod_get_break_total(mysqli $conn, int $logId): int {
    $res = $conn->query("SELECT COALESCE(SUM(duration_minutes),0) AS t FROM time_breaks WHERE log_id=$logId");
    return $res ? (int)($res->fetch_assoc()['t'] ?? 0) : 0;
}

function prod_is_manager(array $user): bool {
    return in_array($user['portal_role'] ?? '', ['super_admin','admin','hr','management','team_lead','floor_manager'], true);
}

// ─── Router ──────────────────────────────────────────────────────────────────

switch ($action) {

    // ════════════════════════════════════════════════════════════════════════
    // TIME TRACKING
    // ════════════════════════════════════════════════════════════════════════

    case 'current_status':
        $log = prod_get_active_log($conn, $userId);
        $breakMins = $log ? prod_get_break_total($conn, (int)$log['id']) : 0;
        prod_respond(true, ['active_log' => $log, 'break_total_minutes' => $breakMins]);
        break;

    case 'clock_in':
        if (prod_get_active_log($conn, $userId)) prod_respond(false, null, 'Already clocked in');
        $conn->query("INSERT INTO time_logs (employee_id, clock_in, status) VALUES ($userId, NOW(), 'active')");
        $logId = (int)$conn->insert_id;
        prod_log_activity($conn, $userId, 'clock_in', 'Clocked In', 'Started work session.');
        $log = prod_get_active_log($conn, $userId);
        prod_respond(true, ['log_id' => $logId, 'active_log' => $log]);
        break;

    case 'clock_out':
        $log = prod_get_active_log($conn, $userId);
        if (!$log) prod_respond(false, null, 'Not clocked in');
        $id = (int)$log['id'];
        // Auto-close open break
        if ($log['status'] === 'on_break') {
            $openBreak = $conn->query("SELECT id FROM time_breaks WHERE log_id=$id AND break_end IS NULL ORDER BY id DESC LIMIT 1");
            if ($openBreak && $row = $openBreak->fetch_assoc()) {
                $bid = (int)$row['id'];
                $conn->query("UPDATE time_breaks SET break_end=NOW(), duration_minutes=TIMESTAMPDIFF(MINUTE,break_start,NOW()) WHERE id=$bid");
            }
        }
        $conn->query("UPDATE time_logs SET clock_out=NOW(), status='completed', total_hours=ROUND(TIMESTAMPDIFF(SECOND,clock_in,NOW())/3600,2) WHERE id=$id");
        prod_log_activity($conn, $userId, 'clock_out', 'Clocked Out', 'Ended work session.');
        // Recalculate score
        $score = prod_score_upsert($conn, $userId, $empCode, $today, $branch);
        prod_respond(true, ['status' => 'completed', 'score' => $score]);
        break;

    case 'break_start':
        $log = prod_get_active_log($conn, $userId);
        if (!$log) prod_respond(false, null, 'Not clocked in');
        if ($log['status'] === 'on_break') prod_respond(false, null, 'Already on break');
        $id = (int)$log['id'];
        $conn->query("UPDATE time_logs SET status='on_break' WHERE id=$id");
        $conn->query("INSERT INTO time_breaks (log_id, break_start) VALUES ($id, NOW())");
        prod_log_activity($conn, $userId, 'break_start', 'On Break', 'Took a break.');
        prod_respond(true, ['status' => 'on_break']);
        break;

    case 'break_end':
        $log = prod_get_active_log($conn, $userId);
        if (!$log || $log['status'] !== 'on_break') prod_respond(false, null, 'Not on break');
        $id = (int)$log['id'];
        $openBreak = $conn->query("SELECT id FROM time_breaks WHERE log_id=$id AND break_end IS NULL ORDER BY id DESC LIMIT 1");
        if ($openBreak && $row = $openBreak->fetch_assoc()) {
            $bid = (int)$row['id'];
            $conn->query("UPDATE time_breaks SET break_end=NOW(), duration_minutes=TIMESTAMPDIFF(MINUTE,break_start,NOW()) WHERE id=$bid");
        }
        $conn->query("UPDATE time_logs SET status='active' WHERE id=$id");
        prod_log_activity($conn, $userId, 'break_end', 'Break Ended', 'Returned to work.');
        prod_respond(true, ['status' => 'active']);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // TIMESHEETS
    // ════════════════════════════════════════════════════════════════════════

    case 'get_timesheet':
        $weekStart = $input['week_start'] ?? $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        $res = $conn->query("SELECT * FROM timesheets WHERE employee_id=$userId AND week_start='$weekStart' LIMIT 1");
        $ts = $res ? ($res->fetch_assoc() ?: null) : null;
        if (!$ts) {
            $conn->query("INSERT INTO timesheets (employee_id, week_start, week_end, status) VALUES ($userId, '$weekStart', '$weekEnd', 'draft')");
            $tsId = (int)$conn->insert_id;
            $res  = $conn->query("SELECT * FROM timesheets WHERE id=$tsId LIMIT 1");
            $ts   = $res ? $res->fetch_assoc() : null;
        }
        $entries = [];
        $res = $conn->query("SELECT * FROM timesheet_entries WHERE timesheet_id={$ts['id']} ORDER BY log_date ASC");
        if ($res) while ($r = $res->fetch_assoc()) $entries[] = $r;
        prod_respond(true, ['timesheet' => $ts, 'entries' => $entries]);
        break;

    case 'save_timesheet_entry':
        $tsId    = (int)($input['timesheet_id'] ?? 0);
        $project = trim($input['project'] ?? '');
        $task    = trim($input['task'] ?? '');
        $logDate = $input['log_date'] ?? '';
        $hours   = (float)($input['hours'] ?? 0);
        if (!$tsId || !$project || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) prod_respond(false, null, 'Missing required fields');
        // Verify ownership
        $own = $conn->query("SELECT id FROM timesheets WHERE id=$tsId AND employee_id=$userId AND status='draft' LIMIT 1");
        if (!$own || !$own->fetch_row()) prod_respond(false, null, 'Timesheet not found or already submitted');
        $pQ = $conn->real_escape_string($project);
        $tQ = $conn->real_escape_string($task);
        $dQ = $conn->real_escape_string($logDate);
        if ($hours > 0) {
            $conn->query("INSERT INTO timesheet_entries (timesheet_id, project, task, log_date, hours) VALUES ($tsId, '$pQ', '$tQ', '$dQ', $hours)
                          ON DUPLICATE KEY UPDATE task='$tQ', hours=$hours");
        } else {
            $conn->query("DELETE FROM timesheet_entries WHERE timesheet_id=$tsId AND project='$pQ' AND log_date='$dQ'");
        }
        $conn->query("UPDATE timesheets SET total_hours=(SELECT COALESCE(SUM(hours),0) FROM timesheet_entries WHERE timesheet_id=$tsId) WHERE id=$tsId");
        prod_score_upsert($conn, $userId, $empCode, $today, $branch);
        prod_respond(true, ['saved' => true]);
        break;

    case 'submit_timesheet':
        $tsId = (int)($input['timesheet_id'] ?? 0);
        if (!$tsId) prod_respond(false, null, 'Missing timesheet_id');
        $conn->query("UPDATE timesheets SET status='submitted' WHERE id=$tsId AND employee_id=$userId AND status='draft'");
        if (!$conn->affected_rows) prod_respond(false, null, 'Nothing to submit');
        prod_log_activity($conn, $userId, 'timesheet_submit', 'Submitted Timesheet', 'Weekly timesheet submitted for approval.');
        prod_respond(true, ['status' => 'submitted']);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // TIMESHEET APPROVAL (MANAGERS)
    // ════════════════════════════════════════════════════════════════════════

    case 'pending_timesheets':
        if (!prod_is_manager($user)) prod_respond(false, null, 'Access denied');
        $res = $conn->query("
            SELECT t.*, u.full_name, u.designation, u.team,
                   (SELECT SUM(hours) FROM timesheet_entries WHERE timesheet_id=t.id) AS hours_logged
            FROM timesheets t
            JOIN users u ON u.id = t.employee_id
            WHERE t.status = 'submitted'
              AND u.company_branch = '{$conn->real_escape_string($branch)}'
            ORDER BY t.updated_at DESC
            LIMIT 50
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        prod_respond(true, ['timesheets' => $rows]);
        break;

    case 'approve_timesheet':
        if (!prod_is_manager($user)) prod_respond(false, null, 'Access denied');
        $tsId   = (int)($input['timesheet_id'] ?? 0);
        $status = $input['status'] ?? 'approved'; // 'approved' | 'rejected'
        $note   = trim($input['note'] ?? '');
        if (!in_array($status, ['approved', 'rejected'])) prod_respond(false, null, 'Invalid status');
        $conn->query("UPDATE timesheets SET status='$status', approver_id=$userId WHERE id=$tsId");
        // Fetch employee to notify
        $empRes = $conn->query("SELECT employee_id FROM timesheets WHERE id=$tsId LIMIT 1");
        $empRow = $empRes ? $empRes->fetch_assoc() : null;
        if ($empRow) {
            $empIdTarget = (int)$empRow['employee_id'];
            $statusLabel = $status === 'approved' ? '✅ Approved' : '❌ Rejected';
            $noteMsg     = $note ? " Note: $note" : '';
            prod_log_activity($conn, $empIdTarget, "timesheet_$status", "Timesheet $statusLabel", "Your timesheet was $status by manager.$noteMsg");
        }
        prod_respond(true, ['status' => $status]);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // ACTIVITY FEED (BIRTHDAYS + OFFICIAL ANNOUNCEMENTS)
    // ════════════════════════════════════════════════════════════════════════

    case 'feed':
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $canPost = prod_is_manager($user);

        // Ensure announcements table exists
        $conn->query("
            CREATE TABLE IF NOT EXISTS `announcements` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `posted_by_id` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `content` TEXT NOT NULL,
                `category` ENUM('general', 'urgent', 'policy', 'event', 'holiday') DEFAULT 'general',
                `is_pinned` TINYINT(1) DEFAULT 0,
                `company_branch` VARCHAR(32) NOT NULL DEFAULT 'all',
                `status` ENUM('active', 'archived') DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_ann_created` (`created_at`),
                INDEX `idx_ann_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $birthdays = [];
        $announcements = [];

        // 1. Fetch Birthday Celebrations for Today (matches month and day)
        $bdayRes = $conn->query("
            SELECT u.id AS employee_id, u.full_name AS employee_name, u.department, u.designation,
                   u.team, u.chat_avatar, epd.date_of_birth,
                   COALESCE(epm.designation, u.designation) AS effective_designation
            FROM users u
            JOIN employee_profile_details epd ON epd.user_id = u.id
            LEFT JOIN employee_payroll_meta epm ON CONVERT(epm.employee_code USING utf8mb4) = CONVERT(u.employee_code USING utf8mb4)
            WHERE u.status = 'active'
              AND epd.date_of_birth IS NOT NULL
              AND DATE_FORMAT(epd.date_of_birth, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
            ORDER BY u.full_name ASC
        ");
        if ($bdayRes) {
            while ($bRow = $bdayRes->fetch_assoc()) {
                $empName = $bRow['employee_name'];
                $birthdays[] = [
                    'id'            => 'bday_' . $bRow['employee_id'] . '_' . date('Ymd'),
                    'employee_id'   => (int)$bRow['employee_id'],
                    'event_type'    => 'birthday',
                    'title'         => 'Birthday Celebration! 🎂🎉',
                    'description'   => "Wishing a very Happy Birthday to {$empName}!",
                    'employee_name' => $empName,
                    'chat_avatar'   => $bRow['chat_avatar'] ?? null,
                    'department'    => $bRow['department'] ?? '',
                    'designation'   => $bRow['effective_designation'] ?? ($bRow['designation'] ?? ''),
                    'team'          => $bRow['team'] ?? '',
                    'created_at'    => date('Y-m-d 00:00:00'),
                    'is_birthday'   => true,
                ];
            }
        }

        // 2. Fetch Active Announcements from HR / Super Admin
        $annRes = $conn->query("
            SELECT a.id, a.posted_by_id, a.title, a.content, a.category, a.is_pinned, a.created_at,
                   u.full_name AS author_name, u.portal_role AS author_role, u.department AS author_department,
                   u.chat_avatar AS author_avatar
            FROM announcements a
            JOIN users u ON a.posted_by_id = u.id
            WHERE a.status = 'active'
            ORDER BY a.is_pinned DESC, a.created_at DESC
            LIMIT $limit
        ");
        if ($annRes) {
            while ($annRow = $annRes->fetch_assoc()) {
                $announcements[] = [
                    'id'                => (int)$annRow['id'],
                    'posted_by_id'      => (int)$annRow['posted_by_id'],
                    'title'             => $annRow['title'],
                    'content'           => $annRow['content'],
                    'category'          => $annRow['category'],
                    'is_pinned'         => (bool)$annRow['is_pinned'],
                    'created_at'        => $annRow['created_at'],
                    'author_name'       => $annRow['author_name'],
                    'author_role'       => $annRow['author_role'],
                    'author_department' => $annRow['author_department'] ?? '',
                    'author_avatar'     => $annRow['author_avatar'] ?? null,
                ];
            }
        }

        prod_respond(true, [
            'birthdays'              => $birthdays,
            'announcements'          => $announcements,
            'can_post_announcement'  => $canPost,
            'user_role'              => $user['portal_role'] ?? 'user',
        ]);
        break;

    case 'create_announcement':
        if (!prod_is_manager($user)) {
            prod_respond(false, null, 'Access denied. Only HR and Super Admin can post announcements.');
        }
        $title    = trim($input['title'] ?? '');
        $content  = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? 'general');
        $isPinned = !empty($input['is_pinned']) ? 1 : 0;

        if ($title === '' || $content === '') {
            prod_respond(false, null, 'Title and content are required.');
        }

        $validCats = ['general', 'urgent', 'policy', 'event', 'holiday'];
        if (!in_array($category, $validCats, true)) {
            $category = 'general';
        }

        $tEsc = $conn->real_escape_string($title);
        $cEsc = $conn->real_escape_string($content);
        $catEsc = $conn->real_escape_string($category);

        $sql = "INSERT INTO announcements (posted_by_id, title, content, category, is_pinned, company_branch, status)
                VALUES ($userId, '$tEsc', '$cEsc', '$catEsc', $isPinned, 'all', 'active')";

        if ($conn->query($sql)) {
            $annId = (int)$conn->insert_id;
            prod_respond(true, ['id' => $annId, 'message' => 'Announcement posted successfully.']);
        } else {
            prod_respond(false, null, 'Database error: ' . $conn->error);
        }
        break;

    case 'delete_announcement':
        if (!prod_is_manager($user)) {
            prod_respond(false, null, 'Access denied. Only HR and Super Admin can delete announcements.');
        }
        $annId = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$annId) {
            prod_respond(false, null, 'Invalid announcement ID.');
        }
        $conn->query("UPDATE announcements SET status = 'archived' WHERE id = $annId");
        prod_respond(true, ['message' => 'Announcement removed.']);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // PRODUCTIVITY SCORE
    // ════════════════════════════════════════════════════════════════════════

    case 'my_score':
        $date   = $_GET['date'] ?? $today;
        $score  = prod_score_upsert($conn, $userId, $empCode, $date, $branch);
        $detail = prod_score_compute($conn, $userId, $empCode, $date, $branch);
        $week   = prod_score_week($conn, $userId, $branch);
        prod_respond(true, ['score' => $score, 'detail' => $detail, 'week' => $week]);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // ANALYTICS
    // ════════════════════════════════════════════════════════════════════════

    case 'analytics':
        $days = max(7, min(90, (int)($_GET['days'] ?? 30)));
        $since = date('Y-m-d', strtotime("-$days days"));

        // Weekly hours (last 7 days)
        $hoursRes = $conn->query("
            SELECT DATE(clock_in) AS work_date, ROUND(SUM(total_hours),2) AS hours
            FROM time_logs
            WHERE employee_id=$userId AND DATE(clock_in) >= '$since' AND status='completed'
            GROUP BY work_date ORDER BY work_date ASC
        ");
        $hoursData = [];
        if ($hoursRes) while ($r = $hoursRes->fetch_assoc()) $hoursData[] = $r;

        // Calls last 30 days
        $callsRes = $conn->query("
            SELECT report_date, calls_made, sales_closed, leads_contacted
            FROM employee_daily_reports
            WHERE user_id=$userId AND report_date >= '$since'
            ORDER BY report_date ASC
        ");
        $callsData = [];
        if ($callsRes) while ($r = $callsRes->fetch_assoc()) $callsData[] = $r;

        // Score history
        $scoreRes = $conn->query("
            SELECT score_date, score, attendance_score, report_score, timesheet_score, activity_score
            FROM productivity_scores
            WHERE employee_id=$userId AND score_date >= '$since'
            ORDER BY score_date ASC
        ");
        $scoreData = [];
        if ($scoreRes) while ($r = $scoreRes->fetch_assoc()) $scoreData[] = $r;

        // Attendance heatmap (last 90 days)
        $attRes = $conn->query("
            SELECT DATE(clock_in) AS work_date,
                   ROUND(SUM(total_hours),2) AS hours,
                   COUNT(*) AS sessions
            FROM time_logs
            WHERE employee_id=$userId AND DATE(clock_in) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND status='completed'
            GROUP BY work_date ORDER BY work_date ASC
        ");
        $heatmap = [];
        if ($attRes) while ($r = $attRes->fetch_assoc()) $heatmap[] = $r;

        // Timesheet summary
        $tsRes = $conn->query("
            SELECT status, COUNT(*) AS cnt
            FROM timesheets
            WHERE employee_id=$userId
            GROUP BY status
        ");
        $tsSummary = [];
        if ($tsRes) while ($r = $tsRes->fetch_assoc()) $tsSummary[$r['status']] = (int)$r['cnt'];

        // Break analytics
        $breakData = prod_break_analytics($conn, $userId, $days);

        prod_respond(true, [
            'hours_data'   => $hoursData,
            'calls_data'   => $callsData,
            'score_data'   => $scoreData,
            'heatmap'      => $heatmap,
            'ts_summary'   => $tsSummary,
            'break_analytics' => $breakData,
        ]);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // LEADERBOARD
    // ════════════════════════════════════════════════════════════════════════

    case 'leaderboard':
        $board = prod_score_leaderboard($conn, $branch, 20);
        // Find my rank
        $myRank = null;
        foreach ($board as $i => $row) {
            if ((int)$row['employee_id'] === $userId) { $myRank = $i + 1; break; }
        }
        prod_respond(true, ['leaderboard' => $board, 'my_rank' => $myRank, 'my_id' => $userId]);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // SMART ALERTS
    // ════════════════════════════════════════════════════════════════════════

    case 'alerts':
        $log = prod_get_active_log($conn, $userId);
        prod_fire_smart_alerts($conn, $userId, $log, $branch);
        // Return unread alerts
        $res = $conn->query("
            SELECT * FROM smart_alerts
            WHERE employee_id=$userId AND is_read=0
            ORDER BY created_at DESC LIMIT 20
        ");
        $alerts = [];
        if ($res) while ($r = $res->fetch_assoc()) $alerts[] = $r;
        prod_respond(true, ['alerts' => $alerts]);
        break;

    case 'dismiss_alert':
        $alertId = (int)($input['alert_id'] ?? 0);
        if ($alertId) $conn->query("UPDATE smart_alerts SET is_read=1 WHERE id=$alertId AND employee_id=$userId");
        prod_respond(true, ['dismissed' => true]);
        break;

    case 'dismiss_all_alerts':
        $conn->query("UPDATE smart_alerts SET is_read=1 WHERE employee_id=$userId AND is_read=0");
        prod_respond(true, ['dismissed' => true]);
        break;

    default:
        prod_respond(false, null, "Unknown action: $action");
}
