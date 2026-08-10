<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';
ensure_app_schema($conn);

$action       = $_GET['action'] ?? '';
$user_id      = (int)$_SESSION['user_id'];
$biometric_id = $_SESSION['employee_code'] ?? '';

// ────────────────────────────────────────────────────
// Helper: get date range for a period
// ────────────────────────────────────────────────────
function period_range(string $period): array {
    switch ($period) {
        case 'week':
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')];
        case 'month':
            return [date('Y-m-01'), date('Y-m-d')];
        default: // today
            return [date('Y-m-d'), date('Y-m-d')];
    }
}

// ────────────────────────────────────────────────────
// action: load_dashboard
// Returns: biometric_id, qa_stats, transfers (by period),
//          mini_stats (today/week/month counts), streak
// ────────────────────────────────────────────────────
if ($action === 'load_dashboard') {
    $period = $_GET['period'] ?? 'today';
    [$from, $to] = period_range($period);

    // QA Stats (current month)
    $qa_sales = $qa_rejected = $qa_transfers = 0;
    if (!empty($biometric_id)) {
        $stmt = $conn->prepare(
            "SELECT SUM(sales) as s, SUM(rejected) as r, SUM(transfers) as t
             FROM qa_performance_stats
             WHERE biometric_id = ?
               AND MONTH(report_date) = MONTH(CURRENT_DATE())
               AND YEAR(report_date)  = YEAR(CURRENT_DATE())"
        );
        $stmt->bind_param("s", $biometric_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $qa_sales     = (int)$row['s'];
            $qa_rejected  = (int)$row['r'];
            $qa_transfers = (int)$row['t'];
        }
        $stmt->close();
    }

    // Transfers for selected period (full row detail)
    $transfers = [];
    $stmt = $conn->prepare(
        "SELECT id, customer_number, customer_name, customer_first_name, customer_last_name, customer_state,
                customer_zip, customer_age, verifier_real_name, agent_pseudo, team_name,
                transfer_on, call_notes, call_duration_mins, is_offline_sync, created_at
         FROM agent_daily_transfers
         WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
         ORDER BY created_at DESC"
    );
    $stmt->bind_param("iss", $user_id, $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $transfers[] = $row;
    }
    $stmt->close();

    // Mini-stats: today / week / month counts
    $mini = [];
    $periods = [
        'today' => [date('Y-m-d'), date('Y-m-d')],
        'week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
        'month' => [date('Y-m-01'), date('Y-m-d')],
    ];
    foreach ($periods as $pkey => [$pFrom, $pTo]) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as total,
                    SUM(transfer_on='D1') as d1,
                    SUM(transfer_on='D2') as d2
             FROM agent_daily_transfers
             WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->bind_param("iss", $user_id, $pFrom, $pTo);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mini[$pkey] = [
            'total' => (int)($r['total'] ?? 0),
            'd1'    => (int)($r['d1']    ?? 0),
            'd2'    => (int)($r['d2']    ?? 0),
        ];
    }

    // Streak: consecutive days with at least 1 transfer (going back from today)
    $streak = 0;
    $checkDate = date('Y-m-d');
    for ($i = 0; $i < 60; $i++) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as c FROM agent_daily_transfers
             WHERE user_id = ? AND DATE(created_at) = ?"
        );
        $stmt->bind_param("is", $user_id, $checkDate);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($r['c'] ?? 0) > 0) {
            $streak++;
            $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
        } else {
            break;
        }
    }

    // Top 5 Leaderboard
    $leaderboard = [];
    $stmt = $conn->prepare(
        "SELECT u.full_name, COUNT(*) as cnt
         FROM agent_daily_transfers t
         JOIN users u ON t.user_id = u.id
         WHERE DATE(t.created_at) = CURDATE()
         GROUP BY t.user_id
         ORDER BY cnt DESC
         LIMIT 5"
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $leaderboard[] = [
            'name' => $row['full_name'],
            'cnt'  => (int)$row['cnt']
        ];
    }
    $stmt->close();

    // Fetch active HRMS Users for Verifier Real Name dropdown
    $verifiers = [];
    $vRes = $conn->query("SELECT id, full_name, employee_code, team FROM users WHERE status = 'active' ORDER BY full_name ASC");
    if ($vRes) {
        while ($vRow = $vRes->fetch_assoc()) {
            $verifiers[] = $vRow;
        }
    }

    // Fetch logged in user real name from HRMS
    $user_full_name = $_SESSION['full_name'] ?? '';
    if (empty($user_full_name)) {
        $uStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $uStmt->bind_param("i", $user_id);
        $uStmt->execute();
        if ($uRow = $uStmt->get_result()->fetch_assoc()) {
            $user_full_name = $uRow['full_name'];
        }
        $uStmt->close();
    }

    echo json_encode([
        'success'        => true,
        'biometric_id'   => $biometric_id,
        'user_full_name' => $user_full_name,
        'verifiers'      => $verifiers,
        'data' => [
            'biometric_id'   => $biometric_id,
            'user_full_name' => $user_full_name,
            'period'         => $period,
            'transfers'      => $transfers,
            'mini_stats'     => $mini,
            'streak'         => $streak,
            'verifiers'      => $verifiers,
            'qa_stats'       => [
                'sales'     => $qa_sales,
                'rejected'  => $qa_rejected,
                'transfers' => $qa_transfers,
            ],
            'leaderboard'    => $leaderboard,
        ],
        'transfers'   => $transfers,
        'mini_stats'  => $mini,
        'streak'      => $streak,
        'leaderboard' => $leaderboard,
    ]);
    exit;
}

// ────────────────────────────────────────────────────
// action: load_analytics  (QA charts — existing)
// ────────────────────────────────────────────────────
if ($action === 'load_analytics') {
    $qa_sales = $qa_rejected = $qa_transfers = 0;
    if (!empty($biometric_id)) {
        $stmt = $conn->prepare(
            "SELECT SUM(sales) as s, SUM(rejected) as r, SUM(transfers) as t
             FROM qa_performance_stats
             WHERE biometric_id = ?
               AND MONTH(report_date) = MONTH(CURRENT_DATE())
               AND YEAR(report_date)  = YEAR(CURRENT_DATE())"
        );
        $stmt->bind_param("s", $biometric_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $qa_sales     = (int)$row['s'];
            $qa_rejected  = (int)$row['r'];
            $qa_transfers = (int)$row['t'];
        }
        $stmt->close();
    }

    $history = [];
    if (!empty($biometric_id)) {
        $stmt = $conn->prepare(
            "SELECT report_date as date, sales, rejected, transfers
             FROM qa_performance_stats
             WHERE biometric_id = ? AND report_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
             ORDER BY report_date ASC"
        );
        $stmt->bind_param("s", $biometric_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $history[] = $row;
        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'qa_stats' => ['sales' => $qa_sales, 'rejected' => $qa_rejected, 'transfers' => $qa_transfers],
            'history'  => $history,
        ]
    ]);
    exit;
}

// ────────────────────────────────────────────────────
// action: load_charts  (Advanced transfer analytics)
// Returns: daily_trend, hour_distribution, weekly_comparison,
//          best_day_of_week, d1_vs_d2
// ────────────────────────────────────────────────────
if ($action === 'load_charts') {
    // Daily trend: last 30 days
    $daily_trend = [];
    $stmt = $conn->prepare(
        "SELECT DATE(created_at) as day,
                COUNT(*) as total,
                SUM(transfer_on='D1') as d1,
                SUM(transfer_on='D2') as d2
         FROM agent_daily_transfers
         WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY day ORDER BY day ASC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $daily_trend[] = $row;
    $stmt->close();

    // Hour distribution: when does the agent log most?
    $hour_dist = array_fill(0, 24, 0);
    $stmt = $conn->prepare(
        "SELECT HOUR(created_at) as hr, COUNT(*) as cnt
         FROM agent_daily_transfers
         WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY hr"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $hour_dist[(int)$row['hr']] = (int)$row['cnt'];
    $stmt->close();

    // Weekly comparison: last 4 weeks totals
    $weekly = [];
    for ($w = 3; $w >= 0; $w--) {
        $wStart = date('Y-m-d', strtotime("monday -{$w} week"));
        $wEnd   = date('Y-m-d', strtotime("sunday -{$w} week"));
        $label  = 'W' . date('W', strtotime($wStart));
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as total FROM agent_daily_transfers
             WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->bind_param("iss", $user_id, $wStart, $wEnd);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $weekly[] = ['label' => $label, 'total' => (int)($r['total'] ?? 0)];
    }

    // Best day of week: Mon-Sun average
    $day_labels = ['', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $dow_raw = array_fill(1, 7, ['sum' => 0, 'cnt' => 0]);
    $stmt = $conn->prepare(
        "SELECT DAYOFWEEK(DATE(created_at)) as dow,
                COUNT(DISTINCT DATE(created_at)) as days_active,
                COUNT(*) as total
         FROM agent_daily_transfers
         WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY dow"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $d = (int)$row['dow'];
        $dow_raw[$d] = [
            'label' => $day_labels[$d] ?? "D$d",
            'avg'   => $row['days_active'] > 0 ? round($row['total'] / $row['days_active'], 1) : 0,
        ];
    }
    $stmt->close();
    $best_dow = array_values(array_map(fn($d) => ['label' => $d['label'] ?? '?', 'avg' => $d['avg'] ?? 0], $dow_raw));

    // D1 vs D2 totals (all time)
    $d1_total = $d2_total = 0;
    $stmt = $conn->prepare(
        "SELECT SUM(transfer_on='D1') as d1, SUM(transfer_on='D2') as d2
         FROM agent_daily_transfers WHERE user_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) {
        $d1_total = (int)$r['d1'];
        $d2_total = (int)$r['d2'];
    }
    $stmt->close();

    // QA status distribution
    $qa_pending = $qa_approved = $qa_rejected = 0;
    $stmt = $conn->prepare(
        "SELECT qa_status, COUNT(*) as cnt FROM agent_daily_transfers WHERE user_id = ? GROUP BY qa_status"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if ($row['qa_status'] === 'pending') $qa_pending = (int)$row['cnt'];
        if ($row['qa_status'] === 'approved') $qa_approved = (int)$row['cnt'];
        if ($row['qa_status'] === 'rejected') $qa_rejected = (int)$row['cnt'];
    }
    $stmt->close();

    // QA approved vs logged daily trend (last 14 days)
    $qa_trend = [];
    $stmt = $conn->prepare(
        "SELECT DATE(created_at) as day,
                COUNT(*) as logged,
                SUM(qa_status='approved') as approved
         FROM agent_daily_transfers
         WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         GROUP BY day ORDER BY day ASC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $qa_trend[] = $row;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'daily_trend'      => $daily_trend,
            'hour_distribution'=> array_values($hour_dist),
            'weekly_comparison'=> $weekly,
            'best_day_of_week' => $best_dow,
            'd1_vs_d2'         => ['d1' => $d1_total, 'd2' => $d2_total],
            'qa_status_dist'   => ['pending' => $qa_pending, 'approved' => $qa_approved, 'rejected' => $qa_rejected],
            'qa_trend'         => $qa_trend,
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
