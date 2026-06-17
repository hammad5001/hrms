<?php
declare(strict_types=1);

/**
 * Productivity Score Engine
 * Computes a 0-100 composite score for an employee on a given date.
 *
 * WEIGHTS:
 *   Attendance / Punctuality  25 pts
 *   Daily Report submitted    20 pts
 *   Timesheet logged          20 pts
 *   Sales/Activity output     25 pts
 *   Overtime bonus            10 pts
 */

function prod_score_compute(mysqli $conn, int $userId, string $empCode, string $date, string $branch): array
{
    $scores = [
        'attendance_score' => 0,
        'report_score'     => 0,
        'timesheet_score'  => 0,
        'activity_score'   => 0,
        'overtime_score'   => 0,
    ];

    // ── 1. Attendance + Punctuality (25 pts) ─────────────────────────────────
    if (function_exists('perf_shift_attendance_detail') && $empCode) {
        $att = perf_shift_attendance_detail($conn, $empCode, $date, $branch);
        $pScore = (int) ($att['punctuality']['score'] ?? 0);   // 0, 55, 70, or 100
        $scores['attendance_score'] = (int) round($pScore * 0.25);  // max 25
    }

    // ── 2. Daily Report submitted (20 pts) ───────────────────────────────────
    if (function_exists('perf_get_report')) {
        $report = perf_get_report($conn, $userId, $date, $branch);
        $scores['report_score'] = $report ? 20 : 0;
    }

    // ── 3. Timesheet hours logged today (20 pts) ─────────────────────────────
    $tsCheck = $conn->prepare("
        SELECT COALESCE(SUM(te.hours), 0) AS total_hours
        FROM timesheet_entries te
        JOIN timesheets t ON t.id = te.timesheet_id
        WHERE t.employee_id = ? AND te.log_date = ?
    ");
    if ($tsCheck) {
        $tsCheck->bind_param('is', $userId, $date);
        $tsCheck->execute();
        $tsRow = $tsCheck->get_result()->fetch_assoc();
        $tsCheck->close();
        $tsHours = (float) ($tsRow['total_hours'] ?? 0);
        // 20 pts for >= 6h, proportional otherwise
        $scores['timesheet_score'] = (int) min(20, round(($tsHours / 6) * 20));
    }

    // ── 4. Sales / Activity output (25 pts) ──────────────────────────────────
    if (function_exists('perf_get_report') && isset($report)) {
        if ($report) {
            $calls  = (int) ($report['calls_made']    ?? 0);
            $sales  = (int) ($report['sales_closed']  ?? 0);
            $leads  = (int) ($report['leads_contacted'] ?? 0);
            // Score: 1pt per call (max 10), 3pt per sale (max 12), 1pt per lead (max 3)
            $actScore = min(10, $calls) + min(12, $sales * 3) + min(3, (int) round($leads * 0.5));
            $scores['activity_score'] = min(25, $actScore);
        }
    } elseif (function_exists('perf_get_report')) {
        $report = perf_get_report($conn, $userId, $date, $branch);
        if ($report) {
            $calls  = (int) ($report['calls_made']    ?? 0);
            $sales  = (int) ($report['sales_closed']  ?? 0);
            $leads  = (int) ($report['leads_contacted'] ?? 0);
            $actScore = min(10, $calls) + min(12, $sales * 3) + min(3, (int) round($leads * 0.5));
            $scores['activity_score'] = min(25, $actScore);
        }
    }

    // ── 5. Overtime bonus (10 pts) ────────────────────────────────────────────
    $otCheck = $conn->prepare("
        SELECT overtime_hours FROM time_logs
        WHERE employee_id = ? AND DATE(clock_in) = ? AND status = 'completed'
        ORDER BY id DESC LIMIT 1
    ");
    if ($otCheck) {
        $otCheck->bind_param('is', $userId, $date);
        $otCheck->execute();
        $otRow = $otCheck->get_result()->fetch_assoc();
        $otCheck->close();
        $otHours = (float) ($otRow['overtime_hours'] ?? 0);
        $scores['overtime_score'] = $otHours >= 1 ? min(10, (int) round($otHours * 3)) : 0;
    }

    $total = array_sum($scores);
    $scores['score'] = min(100, $total);

    return $scores;
}

/**
 * Upsert a score row for today. Called after any significant action (clock out, report submit, etc.)
 */
function prod_score_upsert(mysqli $conn, int $userId, string $empCode, string $date, string $branch): int
{
    $s = prod_score_compute($conn, $userId, $empCode, $date, $branch);

    $stmt = $conn->prepare("
        INSERT INTO productivity_scores
            (employee_id, score_date, score, attendance_score, report_score,
             timesheet_score, activity_score, overtime_score, company_branch)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            attendance_score = VALUES(attendance_score),
            report_score = VALUES(report_score),
            timesheet_score = VALUES(timesheet_score),
            activity_score = VALUES(activity_score),
            overtime_score = VALUES(overtime_score),
            updated_at = NOW()
    ");
    if ($stmt) {
        $stmt->bind_param(
            'isiiiiiss',
            $userId, $date, $s['score'],
            $s['attendance_score'], $s['report_score'],
            $s['timesheet_score'], $s['activity_score'],
            $s['overtime_score'], $branch
        );
        $stmt->execute();
        $stmt->close();
    }

    return (int) $s['score'];
}

/**
 * Get this week's scores for an employee (Mon–Sun of current week).
 */
function prod_score_week(mysqli $conn, int $userId, string $branch): array
{
    $monday = date('Y-m-d', strtotime('monday this week'));
    $sunday = date('Y-m-d', strtotime('sunday this week'));

    $stmt = $conn->prepare("
        SELECT score_date, score, attendance_score, report_score, timesheet_score, activity_score, overtime_score
        FROM productivity_scores
        WHERE employee_id = ? AND score_date BETWEEN ? AND ?
        ORDER BY score_date ASC
    ");
    if (!$stmt) return [];
    $stmt->bind_param('iss', $userId, $monday, $sunday);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

/**
 * Leaderboard — top N employees by average score in the current week.
 */
function prod_score_leaderboard(mysqli $conn, string $branch, int $limit = 20): array
{
    $monday = date('Y-m-d', strtotime('monday this week'));
    $sunday = date('Y-m-d', strtotime('sunday this week'));

    $branchQ = $conn->real_escape_string($branch);
    $res = $conn->query("
        SELECT ps.employee_id, u.full_name, u.chat_avatar, u.designation,
               ROUND(AVG(ps.score)) AS avg_score,
               MAX(ps.score) AS best_score,
               COUNT(*) AS days_active
        FROM productivity_scores ps
        JOIN users u ON u.id = ps.employee_id
        WHERE ps.company_branch = '$branchQ'
          AND ps.score_date BETWEEN '$monday' AND '$sunday'
        GROUP BY ps.employee_id, u.full_name, u.chat_avatar, u.designation
        ORDER BY avg_score DESC
        LIMIT $limit
    ");
    $rows = [];
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

/**
 * Break pattern analytics for an employee.
 */
function prod_break_analytics(mysqli $conn, int $userId, int $days = 14): array
{
    $since = date('Y-m-d', strtotime("-$days days"));
    $res = $conn->query("
        SELECT DATE(tl.clock_in) AS work_date,
               COUNT(tb.id) AS break_count,
               COALESCE(SUM(tb.duration_minutes), 0) AS total_break_min,
               ROUND(COALESCE(tl.total_hours, 0), 2) AS total_hours,
               ROUND(COALESCE(tl.overtime_hours, 0), 2) AS overtime_hours
        FROM time_logs tl
        LEFT JOIN time_breaks tb ON tb.log_id = tl.id
        WHERE tl.employee_id = $userId AND DATE(tl.clock_in) >= '$since'
          AND tl.status = 'completed'
        GROUP BY tl.id, work_date
        ORDER BY work_date ASC
    ");
    $rows = [];
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;

    if (!$rows) {
        return ['days' => [], 'avg_breaks' => 0, 'avg_break_min' => 0, 'best_day' => null, 'total_overtime' => 0];
    }

    $totalBreaks    = array_sum(array_column($rows, 'break_count'));
    $totalBreakMins = array_sum(array_column($rows, 'total_break_min'));
    $totalOT        = array_sum(array_column($rows, 'overtime_hours'));
    $count          = count($rows);

    // Best day = most hours
    usort($rows, fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);
    $bestDay = $rows[0] ?? null;

    return [
        'days'            => $rows,
        'avg_breaks'      => round($totalBreaks / $count, 1),
        'avg_break_min'   => round($totalBreakMins / $count, 0),
        'best_day'        => $bestDay,
        'total_overtime'  => round($totalOT, 2),
    ];
}

/**
 * Smart alert generator — fires contextual reminders.
 * Returns list of newly created alert IDs.
 */
function prod_fire_smart_alerts(mysqli $conn, int $userId, ?array $activeLog, string $branch): array
{
    $now   = new DateTime();
    $hour  = (int) $now->format('H');
    $today = $now->format('Y-m-d');
    $dow   = (int) $now->format('N'); // 1=Mon … 7=Sun
    $fired = [];

    // Helper: only fire each alert_type once per day
    $alreadyFired = function(string $type) use ($conn, $userId, $today): bool {
        $res = $conn->query("
            SELECT 1 FROM smart_alerts
            WHERE employee_id = $userId AND alert_type = '$type'
              AND DATE(created_at) = '$today'
            LIMIT 1
        ");
        return $res && $res->fetch_row() !== null;
    };
    $fire = function(string $type, string $title, string $msg) use ($conn, $userId, &$fired): void {
        $typeQ  = $conn->real_escape_string($type);
        $titleQ = $conn->real_escape_string($title);
        $msgQ   = $conn->real_escape_string($msg);
        $conn->query("INSERT INTO smart_alerts (employee_id, alert_type, title, message)
                      VALUES ($userId, '$typeQ', '$titleQ', '$msgQ')");
        $fired[] = (int) $conn->insert_id;
    };

    // Alert: No clock-in after 10 AM on a weekday
    if ($hour >= 10 && !$activeLog && $dow <= 5 && !$alreadyFired('no_clockin')) {
        $fire('no_clockin', '⏰ Not Clocked In', "It's after 10 AM and you haven't clocked in yet. Don't forget to log your work session!");
    }

    // Alert: Extended break (> 45 min)
    if ($activeLog && $activeLog['status'] === 'on_break' && !$alreadyFired('long_break')) {
        $breakStart = $conn->query("
            SELECT break_start FROM time_breaks
            WHERE log_id = {$activeLog['id']} AND break_end IS NULL
            ORDER BY id DESC LIMIT 1
        ");
        if ($breakStart) {
            $bRow = $breakStart->fetch_assoc();
            if ($bRow) {
                $diffMin = (int) round((time() - strtotime($bRow['break_start'])) / 60);
                if ($diffMin >= 45) {
                    $fire('long_break', '☕ Long Break Alert', "You've been on break for $diffMin minutes. Time to get back to work!");
                }
            }
        }
    }

    // Alert: Friday timesheet reminder
    if ($dow === 5 && $hour >= 16 && !$alreadyFired('ts_reminder')) {
        $monday = date('Y-m-d', strtotime('monday this week'));
        $res = $conn->query("SELECT status FROM timesheets WHERE employee_id = $userId AND week_start = '$monday' LIMIT 1");
        $ts  = $res ? $res->fetch_assoc() : null;
        if (!$ts || $ts['status'] === 'draft') {
            $fire('ts_reminder', '📋 Timesheet Reminder', "Don't forget to submit your weekly timesheet before the weekend!");
        }
    }

    // Alert: Daily report not submitted (after 4 PM on a weekday)
    if ($dow <= 5 && $hour >= 16 && !$alreadyFired('report_reminder')) {
        // Check if report exists today (using raw query since this is a helper)
        $res = $conn->query("SELECT id FROM employee_daily_reports WHERE user_id = $userId AND report_date = '$today' LIMIT 1");
        if ($res && !$res->fetch_row()) {
            $fire('report_reminder', '📝 Daily Report Pending', "Your daily performance report hasn't been submitted yet. Please submit before end of shift.");
        }
    }

    return $fired;
}
