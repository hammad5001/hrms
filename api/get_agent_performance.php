<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';
require_once '../includes/dialer_report_timezone.php';
// Production: schema migrations are run manually during deployment.

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

    // USA timezone only for dialer_daily_transfers. Do not change HRMS/manual report timezone.
    $dialerRange = get_dialer_usa_range($period);
    $dialerFrom = $dialerRange['from'];
    $dialerTo = $dialerRange['to'];

    // QA Stats (current month)
    $qa_sales = $qa_rejected = $qa_transfers = 0;
    $qa_pending = $qa_coaching = 0;

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) as t,
            SUM(LOWER(COALESCE(qa_status,'')) IN ('approved','accepted')) as s,
            SUM(LOWER(COALESCE(qa_status,'')) IN ('rejected','invalid')) as r,
            SUM(LOWER(COALESCE(qa_status,'')) IN ('pending','')) as p,
            SUM(LOWER(COALESCE(qa_status,'')) IN ('coaching_required','coaching required')) as c
         FROM agent_daily_transfers
         WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?"
    );
    $stmt->bind_param("iss", $user_id, $from, $to);
    $stmt->execute();

    if ($row = $stmt->get_result()->fetch_assoc()) {
        $qa_sales     = (int)($row['s'] ?? 0);
        $qa_rejected  = (int)($row['r'] ?? 0);
        $qa_transfers = (int)($row['t'] ?? 0);
        $qa_pending   = (int)($row['p'] ?? 0);
        $qa_coaching  = (int)($row['c'] ?? 0);
    }

    $stmt->close();

    // Transfers for selected period (full row detail)
    $transfers = [];
    $stmt = $conn->prepare(
        "SELECT id, customer_number, customer_name, customer_first_name, customer_last_name, customer_state,
                customer_zip, customer_age, verifier_real_name, agent_pseudo, team_name,
                transfer_on, call_notes, call_duration_mins, is_offline_sync,
                qa_status, qa_score, qa_notes, qa_evaluated_by, qa_evaluated_at, google_sheet_transfer_id,
                created_at
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

    // Add QA/Dialer imported transfers into the same Daily Report table list
    $stmt = $conn->prepare(
        "SELECT id, lead_id, phone_number, customer_name, team, disposition, qa_status, qa_notes, last_call_at
         FROM dialer_daily_transfers
         WHERE hrms_user_id = ? AND last_call_at BETWEEN ? AND ?
         ORDER BY last_call_at DESC, id DESC"
    );
    $stmt->bind_param("iss", $user_id, $dialerFrom, $dialerTo);
    $stmt->execute();
    $dialerRes = $stmt->get_result();

    while ($d = $dialerRes->fetch_assoc()) {
        $name = trim((string)($d['customer_name'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2);

        $transfers[] = [
            'id' => 'DIALER-' . $d['id'],
            'customer_number' => $d['phone_number'] ?? '',
            'customer_name' => $name,
            'customer_first_name' => $parts[0] ?? '',
            'customer_last_name' => $parts[1] ?? '',
            'customer_state' => '',
            'customer_zip' => '',
            'customer_age' => '',
            'verifier_real_name' => $_SESSION['full_name'] ?? '',
            'agent_pseudo' => '',
            'team_name' => $d['team'] ?? '',
            'transfer_on' => strtoupper(trim((string)($d['disposition'] ?? ''))),
            'call_notes' => $d['qa_notes'] ?? '',
            'call_duration_mins' => '',
            'is_offline_sync' => 0,
            'qa_status' => $d['qa_status'] ?? 'pending',
            'qa_score' => null,
            'qa_notes' => $d['qa_notes'] ?? '',
            'qa_evaluated_by' => '',
            'qa_evaluated_at' => null,
            'google_sheet_transfer_id' => $d['lead_id'] ?? '',
            'created_at' => $d['last_call_at'] ?? date('Y-m-d H:i:s'),
            'source' => 'dialer'
        ];
    }
    $stmt->close();

    usort($transfers, function ($a, $b) {
        return strtotime($b['created_at'] ?? '1970-01-01') <=> strtotime($a['created_at'] ?? '1970-01-01');
    });

    // Mini-stats: today / week / month counts
    // Dynamic disposition counts from dialer_daily_transfers + agent_daily_transfers
    $mini = [];
    $periods = [
        'today' => [date('Y-m-d'), date('Y-m-d')],
        'week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
        'month' => [date('Y-m-01'), date('Y-m-d')],
    ];

    $validDispositionOrder = [
        'D1','D2','D3','D4','D5','D5B','D6','D6CPL','D7','D8',
        'HI','HI2','HIB','HIC','HIMAIN'
    ];

    foreach ($periods as $pkey => [$pFrom, $pTo]) {

        // USA timezone only for dialer_daily_transfers mini stats.
        $dialerMiniRange = get_dialer_usa_range($pkey);
        $dialerMiniFrom = $dialerMiniRange['from'];
        $dialerMiniTo = $dialerMiniRange['to'];
$counts = [];
        $total = 0;

        $stmt = $conn->prepare(
            "SELECT UPPER(COALESCE(NULLIF(transfer_on,''), 'UNKNOWN')) AS disposition, COUNT(*) AS total
             FROM agent_daily_transfers
             WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY UPPER(COALESCE(NULLIF(transfer_on,''), 'UNKNOWN'))"
        );
        $stmt->bind_param("iss", $user_id, $pFrom, $pTo);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $disp = strtoupper(trim((string)($row['disposition'] ?? 'UNKNOWN')));
            $cnt = (int)($row['total'] ?? 0);
            if ($cnt > 0) {
                $counts[$disp] = ($counts[$disp] ?? 0) + $cnt;
                $total += $cnt;
            }
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT UPPER(COALESCE(NULLIF(disposition,''), 'UNKNOWN')) AS disposition, COUNT(*) AS total
             FROM dialer_daily_transfers
             WHERE hrms_user_id = ? AND last_call_at BETWEEN ? AND ?
             GROUP BY UPPER(COALESCE(NULLIF(disposition,''), 'UNKNOWN'))"
        );
        $stmt->bind_param("iss", $user_id, $dialerMiniFrom, $dialerMiniTo);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $disp = strtoupper(trim((string)($row['disposition'] ?? 'UNKNOWN')));
            $cnt = (int)($row['total'] ?? 0);
            if ($cnt > 0) {
                $counts[$disp] = ($counts[$disp] ?? 0) + $cnt;
                $total += $cnt;
            }
        }
        $stmt->close();

        $dispositions = [];

        foreach ($validDispositionOrder as $disp) {
            if (!empty($counts[$disp])) {
                $dispositions[] = [
                    'disposition' => $disp,
                    'total' => (int)$counts[$disp]
                ];
            }
        }

        foreach ($counts as $disp => $cnt) {
            if (!in_array($disp, $validDispositionOrder, true) && $cnt > 0) {
                $dispositions[] = [
                    'disposition' => $disp,
                    'total' => (int)$cnt
                ];
            }
        }

        $mini[$pkey] = [
            'total' => (int)$total,
            'd1' => (int)($counts['D1'] ?? 0),
            'd2' => (int)($counts['D2'] ?? 0),
            'dispositions' => $dispositions
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
                'sales'          => $qa_sales,
                'approved'       => $qa_sales,
                'rejected'       => $qa_rejected,
                'transfers'      => $qa_transfers,
                'pending'        => $qa_pending ?? 0,
                'coaching'       => $qa_coaching ?? 0,
                'conversion_rate'=> $qa_transfers > 0 ? round(($qa_sales / $qa_transfers) * 100, 1) : 0,
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
  // action: load_analytics  (My Performance QA stats from agent_daily_transfers)
  // ────────────────────────────────────────────────────
  if ($action === 'load_analytics') {
      $qa_sales = 0;
      $qa_rejected = 0;
      $qa_transfers = 0;

      /*
        My Performance source:
        agent_daily_transfers = agent ki logged transfers
        qa_status = QA ka final result from Google Sheet sync

        approved/accepted = sale closed
        rejected/invalid = rejected
        total = total QA transfers
      */
      $stmt = $conn->prepare(
          "SELECT
              COUNT(*) AS total_transfers,
              SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(qa_status, ''))) IN ('approved','accepted','accept')
                    THEN 1 ELSE 0
                  END) AS approved_count,
              SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(qa_status, ''))) IN ('rejected','reject','invalid')
                    THEN 1 ELSE 0
                  END) AS rejected_count
           FROM agent_daily_transfers
           WHERE user_id = ?
             AND MONTH(created_at) = MONTH(CURRENT_DATE())
             AND YEAR(created_at) = YEAR(CURRENT_DATE())"
      );

      $stmt->bind_param("i", $user_id);
      $stmt->execute();

      if ($row = $stmt->get_result()->fetch_assoc()) {
          $qa_sales     = (int)($row['approved_count'] ?? 0);
          $qa_rejected  = (int)($row['rejected_count'] ?? 0);
          $qa_transfers = (int)($row['total_transfers'] ?? 0);
      }

      $stmt->close();

      $history = [];

      $stmt = $conn->prepare(
          "SELECT
              DATE(created_at) AS date,
              COUNT(*) AS transfers,
              SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(qa_status, ''))) IN ('approved','accepted','accept')
                    THEN 1 ELSE 0
                  END) AS sales,
              SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(qa_status, ''))) IN ('rejected','reject','invalid')
                    THEN 1 ELSE 0
                  END) AS rejected
           FROM agent_daily_transfers
           WHERE user_id = ?
             AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
           GROUP BY DATE(created_at)
           ORDER BY DATE(created_at) ASC"
      );

      $stmt->bind_param("i", $user_id);
      $stmt->execute();
      $res = $stmt->get_result();

      while ($row = $res->fetch_assoc()) {
          $history[] = [
              'date'      => $row['date'],
              'sales'     => (int)($row['sales'] ?? 0),
              'approved'  => (int)($row['sales'] ?? 0),
              'rejected'  => (int)($row['rejected'] ?? 0),
              'transfers' => (int)($row['transfers'] ?? 0),
          ];
      }

      $stmt->close();

      $conversion_rate = $qa_transfers > 0 ? round(($qa_sales / $qa_transfers) * 100, 1) : 0;

      echo json_encode([
          'success' => true,
          'data' => [
              'qa_stats' => [
                  'sales' => $qa_sales,
                  'approved' => $qa_sales,
                  'rejected' => $qa_rejected,
                  'transfers' => $qa_transfers,
                  'conversion_rate' => $conversion_rate
              ],
              'history' => $history,
              'sales_vs_rejections' => [
                  'approved' => $qa_sales,
                  'rejected' => $qa_rejected
              ]
          ]
      ]);
      exit;
  }

// ────────────────────────────────────────────────────
  // action: load_charts  (Advanced transfer + QA analytics)
  // ────────────────────────────────────────────────────
  if ($action === 'load_charts') {
      $daily_trend = [];

      $stmt = $conn->prepare(
          "SELECT
              DATE(created_at) as day,
              COUNT(*) as total,
              SUM(transfer_on='D1') as d1,
              SUM(transfer_on='D2') as d2,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('approved','accepted')) as approved,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('rejected','invalid')) as rejected,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('pending','')) as pending,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('coaching_required','coaching required')) as coaching
           FROM agent_daily_transfers
           WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           GROUP BY DATE(created_at)
           ORDER BY DATE(created_at) ASC"
      );

      $stmt->bind_param("i", $user_id);
      $stmt->execute();
      $res = $stmt->get_result();

      while ($row = $res->fetch_assoc()) {
          $daily_trend[] = [
              'day'      => $row['day'],
              'total'    => (int)($row['total'] ?? 0),
              'd1'       => (int)($row['d1'] ?? 0),
              'd2'       => (int)($row['d2'] ?? 0),
              'approved' => (int)($row['approved'] ?? 0),
              'rejected' => (int)($row['rejected'] ?? 0),
              'pending'  => (int)($row['pending'] ?? 0),
              'coaching' => (int)($row['coaching'] ?? 0),
          ];
      }

      $stmt->close();

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
      while ($row = $res->fetch_assoc()) {
          $hour_dist[(int)$row['hr']] = (int)$row['cnt'];
      }
      $stmt->close();

      $weekly = [];
      for ($w = 3; $w >= 0; $w--) {
          $wStart = date('Y-m-d', strtotime("monday -{$w} week"));
          $wEnd   = date('Y-m-d', strtotime("sunday -{$w} week"));
          $label  = 'W' . date('W', strtotime($wStart));

          $stmt = $conn->prepare(
              "SELECT
                  COUNT(*) as total,
                  SUM(LOWER(COALESCE(qa_status,'')) IN ('approved','accepted')) as approved,
                  SUM(LOWER(COALESCE(qa_status,'')) IN ('rejected','invalid')) as rejected
               FROM agent_daily_transfers
               WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?"
          );

          $stmt->bind_param("iss", $user_id, $wStart, $wEnd);
          $stmt->execute();
          $r = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          $weekly[] = [
              'label'    => $label,
              'total'    => (int)($r['total'] ?? 0),
              'approved' => (int)($r['approved'] ?? 0),
              'rejected' => (int)($r['rejected'] ?? 0),
          ];
      }

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

      $best_dow = array_values(array_map(fn($d) => [
          'label' => $d['label'] ?? '?',
          'avg'   => $d['avg'] ?? 0
      ], $dow_raw));

      $d1_total = $d2_total = 0;
      $stmt = $conn->prepare(
          "SELECT SUM(transfer_on='D1') as d1, SUM(transfer_on='D2') as d2
           FROM agent_daily_transfers WHERE user_id = ?"
      );

      $stmt->bind_param("i", $user_id);
      $stmt->execute();

      if ($r = $stmt->get_result()->fetch_assoc()) {
          $d1_total = (int)($r['d1'] ?? 0);
          $d2_total = (int)($r['d2'] ?? 0);
      }

      $stmt->close();

      $qa_pending = $qa_approved = $qa_rejected = $qa_coaching = 0;

      $stmt = $conn->prepare(
          "SELECT
              SUM(LOWER(COALESCE(qa_status,'')) IN ('approved','accepted')) as approved,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('rejected','invalid')) as rejected,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('pending','')) as pending,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('coaching_required','coaching required')) as coaching
           FROM agent_daily_transfers
           WHERE user_id = ?"
      );

      $stmt->bind_param("i", $user_id);
      $stmt->execute();

      if ($row = $stmt->get_result()->fetch_assoc()) {
          $qa_approved = (int)($row['approved'] ?? 0);
          $qa_rejected = (int)($row['rejected'] ?? 0);
          $qa_pending  = (int)($row['pending'] ?? 0);
          $qa_coaching = (int)($row['coaching'] ?? 0);
      }

      $stmt->close();

      $qa_trend = [];
      $stmt = $conn->prepare(
          "SELECT
              DATE(created_at) as day,
              COUNT(*) as logged,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('approved','accepted')) as approved,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('rejected','invalid')) as rejected,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('pending','')) as pending,
              SUM(LOWER(COALESCE(qa_status,'')) IN ('coaching_required','coaching required')) as coaching
           FROM agent_daily_transfers
           WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           GROUP BY DATE(created_at)
           ORDER BY DATE(created_at) ASC"
      );

      $stmt->bind_param("i", $user_id);
      $stmt->execute();
      $res = $stmt->get_result();

      while ($row = $res->fetch_assoc()) {
          $qa_trend[] = [
              'day'      => $row['day'],
              'logged'   => (int)($row['logged'] ?? 0),
              'approved' => (int)($row['approved'] ?? 0),
              'rejected' => (int)($row['rejected'] ?? 0),
              'pending'  => (int)($row['pending'] ?? 0),
              'coaching' => (int)($row['coaching'] ?? 0),
          ];
      }

      $stmt->close();

      echo json_encode([
          'success' => true,
          'data' => [
              'daily_trend'       => $daily_trend,
              'hour_distribution' => array_values($hour_dist),
              'weekly_comparison' => $weekly,
              'best_day_of_week'  => $best_dow,
              'd1_vs_d2'          => ['d1' => $d1_total, 'd2' => $d2_total],
              'qa_status_dist'    => [
                  'pending'           => $qa_pending,
                  'approved'          => $qa_approved,
                  'accepted'          => $qa_approved,
                  'rejected'          => $qa_rejected,
                  'coaching_required' => $qa_coaching
              ],
              'qa_trend'          => $qa_trend,
              'sales_vs_rejections' => [
                  'approved' => $qa_approved,
                  'rejected' => $qa_rejected
              ],
          ]
      ]);
      exit;
  }

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
