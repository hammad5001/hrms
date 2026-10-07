<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$user_id = getCurrentUserId();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';
$portal_role = strtolower(trim((string)($_SESSION['portal_role'] ?? '')));
$is_admin_or_super = ($recruiter_type === 'super' || in_array($portal_role, ['super_admin', 'admin', 'hr'], true));
$is_global_super = isGlobalSuperAdmin();
$active_branch = get_active_company_branch();

// Branch scoping
$branch_req = trim($_GET['branch'] ?? '');
if ($is_admin_or_super) {
    if ($branch_req === 'all') {
        $selected_branch = null; // All branches
    } elseif ($branch_req !== '' && is_valid_company_branch($branch_req)) {
        $selected_branch = normalize_company_branch($branch_req);
    } else {
        $selected_branch = $active_branch;
    }
} else {
    $selected_branch = $active_branch; // Regular recruiter locked to branch
}

// Date Range Filter
$range = trim($_GET['range'] ?? 'all_time');
$date_sql = "";
if ($range === 'today') {
    $date_sql = " AND DATE(created_at) = CURDATE()";
} elseif ($range === 'this_week') {
    $date_sql = " AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($range === 'this_month') {
    $date_sql = " AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
}

// SQL condition snippets
$dialed_cond = "(call_count > 0 OR current_stage IN ('outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg','not_answered','callback') OR last_call_date IS NOT NULL)";
$remaining_cond = "(assigned_recruiter_id IS NOT NULL AND (call_count = 0 OR call_count IS NULL) AND current_stage IN ('new', 'assigned'))";
$scheduled_cond = "(current_stage = 'interview_scheduled')";
$appeared_cond = "(current_stage IN ('receptionist','interview_conducted','hr_passed','hr_rejected','selected','training','deployed','hired'))";
$hired_cond = "(current_stage IN ('training','deployed','hired'))";

if (!$is_admin_or_super) {
    // ==========================================
    // REGULAR RECRUITER (Personal Data Only)
    // ==========================================
    
    // Overall & Filtered stats for this recruiter
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_leads,
            SUM(assigned_recruiter_id IS NOT NULL) AS assigned_leads,
            SUM($dialed_cond) AS dialed_leads,
            SUM($remaining_cond) AS remaining_leads,
            SUM($scheduled_cond) AS scheduled_leads,
            SUM($appeared_cond) AS appeared_leads,
            SUM($hired_cond) AS hired_leads,
            SUM(current_stage IN ('rejected','left','mock_rejected','hr_rejected','gm_rejected')) AS rejected_leads
        FROM leads 
        WHERE assigned_recruiter_id = ? $date_sql
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: [];

    // Ensure all numeric fields are integer
    foreach (['total_leads','assigned_leads','dialed_leads','remaining_leads','scheduled_leads','appeared_leads','hired_leads','rejected_leads'] as $k) {
        $stats[$k] = (int)($stats[$k] ?? 0);
    }

    // Today specific counts
    $td_stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(DISTINCT lead_id) FROM lead_remarks WHERE added_by = ? AND DATE(created_at) = CURDATE()) AS today_dialed,
            (SELECT COUNT(*) FROM leads WHERE assigned_recruiter_id = ? AND $scheduled_cond AND (DATE(updated_at) = CURDATE() OR interview_date = CURDATE())) AS today_scheduled,
            (SELECT COUNT(*) FROM leads WHERE assigned_recruiter_id = ? AND $hired_cond AND DATE(updated_at) = CURDATE()) AS today_hired,
            (SELECT COUNT(*) FROM leads WHERE assigned_recruiter_id = ? AND DATE(assigned_at) = CURDATE()) AS today_assigned
    ");
    $td_stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $td_stmt->execute();
    $today_counts = $td_stmt->get_result()->fetch_assoc() ?: [];
    $stats['today_dialed'] = (int)($today_counts['today_dialed'] ?? 0);
    $stats['today_scheduled'] = (int)($today_counts['today_scheduled'] ?? 0);
    $stats['today_hired'] = (int)($today_counts['today_hired'] ?? 0);
    $stats['today_assigned'] = (int)($today_counts['today_assigned'] ?? 0);
    $stats['calls_today'] = $stats['today_dialed'];

    // Funnel Data
    $stats['funnel'] = [
        'assigned'  => $stats['assigned_leads'],
        'dialed'    => $stats['dialed_leads'],
        'scheduled' => $stats['scheduled_leads'],
        'appeared'  => $stats['appeared_leads'],
        'hired'     => $stats['hired_leads']
    ];

    // Personal 14-day Daily Trend
    $daily_trend = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $d_label = date('d M', strtotime($d));
        $daily_trend[$d] = [
            'date' => $d,
            'label' => $d_label,
            'dialed' => 0,
            'scheduled' => 0,
            'hired' => 0
        ];
    }

    // Fill dialed per day from remarks
    $rem_stmt = $conn->prepare("
        SELECT DATE(created_at) as cdate, COUNT(DISTINCT lead_id) as cnt
        FROM lead_remarks
        WHERE added_by = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at)
    ");
    $rem_stmt->bind_param("i", $user_id);
    $rem_stmt->execute();
    $rem_res = $rem_stmt->get_result();
    while ($r = $rem_res->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) {
            $daily_trend[$r['cdate']]['dialed'] = (int)$r['cnt'];
        }
    }

    // Fill scheduled per day
    $sch_stmt = $conn->prepare("
        SELECT DATE(updated_at) as cdate, COUNT(*) as cnt
        FROM leads
        WHERE assigned_recruiter_id = ? AND $scheduled_cond AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(updated_at)
    ");
    $sch_stmt->bind_param("i", $user_id);
    $sch_stmt->execute();
    $sch_res = $sch_stmt->get_result();
    while ($r = $sch_res->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) {
            $daily_trend[$r['cdate']]['scheduled'] = (int)$r['cnt'];
        }
    }

    // Fill hired per day
    $hi_stmt = $conn->prepare("
        SELECT DATE(updated_at) as cdate, COUNT(*) as cnt
        FROM leads
        WHERE assigned_recruiter_id = ? AND $hired_cond AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(updated_at)
    ");
    $hi_stmt->bind_param("i", $user_id);
    $hi_stmt->execute();
    $hi_res = $hi_stmt->get_result();
    while ($r = $hi_res->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) {
            $daily_trend[$r['cdate']]['hired'] = (int)$r['cnt'];
        }
    }
    $stats['daily_trend'] = array_values($daily_trend);

    // Source breakdown for personal leads
    $src_stmt = $conn->prepare("
        SELECT COALESCE(NULLIF(source, ''), 'Direct / Other') AS src, COUNT(*) AS cnt
        FROM leads
        WHERE assigned_recruiter_id = ?
        GROUP BY src
        ORDER BY cnt DESC
        LIMIT 6
    ");
    $src_stmt->bind_param("i", $user_id);
    $src_stmt->execute();
    $stats['source_breakdown'] = $src_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Calculate Personal Rank in Branch (without leaking other recruiters' details)
    $rk_stmt = $conn->prepare("
        SELECT u.id, 
               COUNT(l.id) AS tot_leads,
               SUM(CASE WHEN l.id IS NOT NULL AND $hired_cond THEN 1 ELSE 0 END) AS hired_cnt
        FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        LEFT JOIN leads l ON l.assigned_recruiter_id = u.id
        WHERE u.status = 'active' AND r.recruiter_type = 'regular' AND u.company_branch = ?
        GROUP BY u.id
        ORDER BY hired_cnt DESC, tot_leads DESC
    ");
    $rk_stmt->bind_param("s", $active_branch);
    $rk_stmt->execute();
    $rk_rows = $rk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $my_rank = 1;
    $total_active_rec = count($rk_rows);
    foreach ($rk_rows as $idx => $row) {
        if ((int)$row['id'] === (int)$user_id) {
            $my_rank = $idx + 1;
            break;
        }
    }
    $stats['personal_rank'] = [
        'rank' => $my_rank,
        'total' => $total_active_rec,
        'branch' => company_branch_label($active_branch)
    ];

    // Priority leads for regular recruiter
    $pr_stmt = $conn->prepare("
        SELECT id, full_name, phone, current_stage, call_count, last_call_date
        FROM leads
        WHERE assigned_recruiter_id = ?
          AND current_stage NOT IN ('hired','deployed','selected','rejected','left','mock_rejected','training')
          AND (call_count = 0 OR last_call_date < DATE_SUB(NOW(), INTERVAL 3 DAY))
        ORDER BY call_count ASC, updated_at ASC
        LIMIT 6
    ");
    $pr_stmt->bind_param("i", $user_id);
    $pr_stmt->execute();
    $stats['priority_leads'] = $pr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Upcoming interviews for this recruiter
    $up_stmt = $conn->prepare("
        SELECT id, full_name, phone, interview_date, current_stage
        FROM leads
        WHERE assigned_recruiter_id = ? AND interview_date >= CURDATE() AND current_stage = 'interview_scheduled'
        ORDER BY interview_date ASC
        LIMIT 6
    ");
    $up_stmt->bind_param("i", $user_id);
    $up_stmt->execute();
    $stats['upcoming_interviews'] = $up_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    respond(true, $stats);

} else {
    // ==========================================
    // SUPER ADMIN / HR / ADMIN (Department View)
    // ==========================================
    $branch_where = "";
    $branch_where_u = "";
    $params = [];
    $types = "";

    if ($selected_branch !== null) {
        $branch_where = " WHERE company_branch = ?";
        $branch_where_u = " AND u.company_branch = ?";
        $params[] = $selected_branch;
        $types .= "s";
    }

    // Overall KPI counts
    $where_clause = $branch_where;
    if ($date_sql !== '') {
        $where_clause .= ($where_clause ? $date_sql : " WHERE 1=1 $date_sql");
    }

    $q_kpis = $conn->prepare("
        SELECT
            COUNT(*) AS total_leads,
            SUM(assigned_recruiter_id IS NULL) AS unassigned_leads,
            SUM(assigned_recruiter_id IS NOT NULL) AS assigned_leads,
            SUM($dialed_cond) AS dialed_leads,
            SUM($remaining_cond) AS remaining_leads,
            SUM($scheduled_cond) AS scheduled_leads,
            SUM($appeared_cond) AS appeared_leads,
            SUM($hired_cond) AS hired_leads
        FROM leads $where_clause
    ");
    if ($types) $q_kpis->bind_param($types, ...$params);
    $q_kpis->execute();
    $kpis = $q_kpis->get_result()->fetch_assoc() ?: [];

    foreach (['total_leads','unassigned_leads','assigned_leads','dialed_leads','remaining_leads','scheduled_leads','appeared_leads','hired_leads'] as $k) {
        $kpis[$k] = (int)($kpis[$k] ?? 0);
    }

    // Today counts across department / branch
    $today_b_filter = $selected_branch !== null ? "AND company_branch = ?" : "";
    $q_today = $conn->prepare("
        SELECT
            (SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE() $today_b_filter) AS today_leads,
            (SELECT COUNT(*) FROM leads WHERE assigned_recruiter_id IS NOT NULL AND DATE(assigned_at) = CURDATE() $today_b_filter) AS today_assigned,
            (SELECT COUNT(*) FROM leads WHERE $scheduled_cond AND (DATE(updated_at) = CURDATE() OR interview_date = CURDATE()) $today_b_filter) AS today_scheduled,
            (SELECT COUNT(*) FROM leads WHERE $appeared_cond AND DATE(updated_at) = CURDATE() $today_b_filter) AS today_appeared,
            (SELECT COUNT(*) FROM leads WHERE $hired_cond AND DATE(updated_at) = CURDATE() $today_b_filter) AS today_hired,
            (SELECT COUNT(DISTINCT lead_id) FROM lead_remarks r LEFT JOIN leads l ON r.lead_id=l.id WHERE DATE(r.created_at) = CURDATE() " . ($selected_branch !== null ? "AND l.company_branch = ?" : "") . ") AS today_dialed
    ");
    if ($selected_branch !== null) {
        $q_today->bind_param("ssssss", $selected_branch, $selected_branch, $selected_branch, $selected_branch, $selected_branch, $selected_branch);
    }
    $q_today->execute();
    $today_row = $q_today->get_result()->fetch_assoc() ?: [];

    // Active and inactive recruiters count
    $q_rec_count = $conn->prepare("
        SELECT 
            SUM(u.status = 'active') AS active_count,
            SUM(u.status = 'inactive') AS inactive_count
        FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        WHERE r.recruiter_type = 'regular' $branch_where_u
    ");
    if ($selected_branch !== null) {
        $q_rec_count->bind_param("s", $selected_branch);
    }
    $q_rec_count->execute();
    $rec_cnt_row = $q_rec_count->get_result()->fetch_assoc() ?: [];

    // Recruiter Performance Breakdown (Leaderboard)
    $bk_sql = "
        SELECT 
            u.id, 
            u.full_name, 
            u.status, 
            u.company_branch,
            COUNT(l.id) AS assigned,
            SUM(CASE WHEN l.id IS NOT NULL AND $dialed_cond THEN 1 ELSE 0 END) AS dialed,
            SUM(CASE WHEN l.id IS NOT NULL AND $remaining_cond THEN 1 ELSE 0 END) AS remaining,
            SUM(CASE WHEN l.id IS NOT NULL AND $scheduled_cond THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN l.id IS NOT NULL AND $appeared_cond THEN 1 ELSE 0 END) AS appeared,
            SUM(CASE WHEN l.id IS NOT NULL AND $hired_cond THEN 1 ELSE 0 END) AS hired,
            MAX(COALESCE(rem.max_rem, aud.max_aud)) AS last_active
        FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        LEFT JOIN leads l ON l.assigned_recruiter_id = u.id " . ($selected_branch !== null ? "AND l.company_branch = u.company_branch" : "") . "
        LEFT JOIN (
            SELECT added_by, MAX(created_at) AS max_rem FROM lead_remarks GROUP BY added_by
        ) rem ON rem.added_by = u.id
        LEFT JOIN (
            SELECT user_id, MAX(created_at) AS max_aud FROM lead_audit GROUP BY user_id
        ) aud ON aud.user_id = u.id
        WHERE r.recruiter_type = 'regular' $branch_where_u
        GROUP BY u.id
        ORDER BY hired DESC, scheduled DESC, dialed DESC, assigned DESC
    ";
    $bk_stmt = $conn->prepare($bk_sql);
    if ($selected_branch !== null) {
        $bk_stmt->bind_param("s", $selected_branch);
    }
    $bk_stmt->execute();
    $breakdown_raw = $bk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $recruiter_breakdown = [];
    foreach ($breakdown_raw as $b) {
        $ass = (int)($b['assigned'] ?? 0);
        $hi = (int)($b['hired'] ?? 0);
        $dia = (int)($b['dialed'] ?? 0);
        $rem = (int)($b['remaining'] ?? 0);
        $sch = (int)($b['scheduled'] ?? 0);
        $app = (int)($b['appeared'] ?? 0);
        $conv = $ass > 0 ? round(($hi / $ass) * 100, 1) : 0;
        $dial_rate = $ass > 0 ? round(($dia / $ass) * 100, 1) : 0;

        $recruiter_breakdown[] = [
            'id'             => (int)$b['id'],
            'full_name'      => $b['full_name'],
            'status'         => $b['status'],
            'company_branch' => $b['company_branch'],
            'branch_label'   => company_branch_label($b['company_branch']),
            'assigned'       => $ass,
            'total'          => $ass, // backward compatibility
            'dialed'         => $dia,
            'remaining'      => $rem,
            'pending'        => $rem, // backward compatibility
            'scheduled'      => $sch,
            'appeared'       => $app,
            'hired'          => $hi,
            'conversion_rate'=> $conv,
            'dial_rate'      => $dial_rate,
            'last_active'    => $b['last_active']
        ];
    }

    // Daily Trend (Last 14 days department trend)
    $daily_trend = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $d_label = date('d M', strtotime($d));
        $daily_trend[$d] = [
            'date' => $d,
            'label' => $d_label,
            'leads' => 0,
            'dialed' => 0,
            'scheduled' => 0,
            'hired' => 0
        ];
    }

    // New leads per day
    $b_filter_leads = $selected_branch !== null ? "AND company_branch = ?" : "";
    $q_dt_leads = $conn->prepare("
        SELECT DATE(created_at) AS cdate, COUNT(*) AS cnt
        FROM leads
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) $b_filter_leads
        GROUP BY DATE(created_at)
    ");
    if ($selected_branch !== null) $q_dt_leads->bind_param("s", $selected_branch);
    $q_dt_leads->execute();
    $res_dt = $q_dt_leads->get_result();
    while ($r = $res_dt->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) $daily_trend[$r['cdate']]['leads'] = (int)$r['cnt'];
    }

    // Dialed per day from lead_remarks
    $q_dt_dia = $conn->prepare("
        SELECT DATE(r.created_at) AS cdate, COUNT(DISTINCT r.lead_id) AS cnt
        FROM lead_remarks r
        LEFT JOIN leads l ON r.lead_id = l.id
        WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) " . ($selected_branch !== null ? "AND l.company_branch = ?" : "") . "
        GROUP BY DATE(r.created_at)
    ");
    if ($selected_branch !== null) $q_dt_dia->bind_param("s", $selected_branch);
    $q_dt_dia->execute();
    $res_dt_dia = $q_dt_dia->get_result();
    while ($r = $res_dt_dia->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) $daily_trend[$r['cdate']]['dialed'] = (int)$r['cnt'];
    }

    // Scheduled per day
    $q_dt_sch = $conn->prepare("
        SELECT DATE(updated_at) AS cdate, COUNT(*) AS cnt
        FROM leads
        WHERE $scheduled_cond AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) $b_filter_leads
        GROUP BY DATE(updated_at)
    ");
    if ($selected_branch !== null) $q_dt_sch->bind_param("s", $selected_branch);
    $q_dt_sch->execute();
    $res_dt_sch = $q_dt_sch->get_result();
    while ($r = $res_dt_sch->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) $daily_trend[$r['cdate']]['scheduled'] = (int)$r['cnt'];
    }

    // Hired per day
    $q_dt_hi = $conn->prepare("
        SELECT DATE(updated_at) AS cdate, COUNT(*) AS cnt
        FROM leads
        WHERE $hired_cond AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) $b_filter_leads
        GROUP BY DATE(updated_at)
    ");
    if ($selected_branch !== null) $q_dt_hi->bind_param("s", $selected_branch);
    $q_dt_hi->execute();
    $res_dt_hi = $q_dt_hi->get_result();
    while ($r = $res_dt_hi->fetch_assoc()) {
        if (isset($daily_trend[$r['cdate']])) $daily_trend[$r['cdate']]['hired'] = (int)$r['cnt'];
    }

    // Lead Source Breakdown
    $q_src = $conn->prepare("
        SELECT COALESCE(NULLIF(source, ''), 'Direct / Other') AS src, COUNT(*) AS cnt
        FROM leads
        " . ($selected_branch !== null ? "WHERE company_branch = ?" : "") . "
        GROUP BY src
        ORDER BY cnt DESC
        LIMIT 6
    ");
    if ($selected_branch !== null) $q_src->bind_param("s", $selected_branch);
    $q_src->execute();
    $source_breakdown = $q_src->get_result()->fetch_all(MYSQLI_ASSOC);

    // Recent Activity Feed
    $act_sql = "
        SELECT a.action, a.new_value, a.notes, a.created_at, u.full_name as user_name, l.full_name as lead_name, l.id as lead_id, l.company_branch
        FROM lead_audit a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN leads l ON a.lead_id = l.id
        " . ($selected_branch !== null ? "WHERE l.company_branch = ?" : "") . "
        ORDER BY a.created_at DESC
        LIMIT 10
    ";
    $act_stmt = $conn->prepare($act_sql);
    if ($selected_branch !== null) $act_stmt->bind_param("s", $selected_branch);
    $act_stmt->execute();
    $activity = $act_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Upcoming Interviews
    $up_sql = "
        SELECT l.id, l.full_name, l.phone, l.interview_date, l.current_stage, l.company_branch, u.full_name AS recruiter_name
        FROM leads l
        LEFT JOIN users u ON l.assigned_recruiter_id = u.id
        WHERE l.interview_date >= CURDATE() AND l.current_stage = 'interview_scheduled'
        " . ($selected_branch !== null ? "AND l.company_branch = ?" : "") . "
        ORDER BY l.interview_date ASC
        LIMIT 6
    ";
    $up_stmt = $conn->prepare($up_sql);
    if ($selected_branch !== null) $up_stmt->bind_param("s", $selected_branch);
    $up_stmt->execute();
    $upcoming = $up_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Priority Leads (Assigned but not called, or called > 3 days ago)
    $pri_sql = "
        SELECT l.id, l.full_name, l.phone, l.current_stage, l.call_count, l.last_call_date, u.full_name AS recruiter_name
        FROM leads l
        LEFT JOIN users u ON l.assigned_recruiter_id = u.id
        WHERE l.assigned_recruiter_id IS NOT NULL 
          AND l.current_stage NOT IN ('hired','deployed','selected','rejected','left','mock_rejected','training')
          AND (l.call_count = 0 OR l.call_count IS NULL OR l.last_call_date < DATE_SUB(NOW(), INTERVAL 3 DAY))
          " . ($selected_branch !== null ? "AND l.company_branch = ?" : "") . "
        ORDER BY l.call_count ASC, l.updated_at ASC
        LIMIT 6
    ";
    $pri_stmt = $conn->prepare($pri_sql);
    if ($selected_branch !== null) $pri_stmt->bind_param("s", $selected_branch);
    $pri_stmt->execute();
    $priority = $pri_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    respond(true, [
        'selected_branch'     => $selected_branch ?? 'all',
        'branch_label'        => $selected_branch ? company_branch_label($selected_branch) : 'All Branches',
        'available_branches'  => COMPANY_BRANCHES,
        'total_leads'         => $kpis['total_leads'],
        'unassigned_leads'    => $kpis['unassigned_leads'],
        'assigned_leads'      => $kpis['assigned_leads'],
        'dialed_leads'        => $kpis['dialed_leads'],
        'remaining_leads'     => $kpis['remaining_leads'],
        'pending_leads'       => $kpis['remaining_leads'], // backward compatibility
        'scheduled_leads'     => $kpis['scheduled_leads'],
        'appeared_leads'      => $kpis['appeared_leads'],
        'hired_leads'         => $kpis['hired_leads'],
        'today_leads'         => (int)($today_row['today_leads'] ?? 0),
        'today_assigned'      => (int)($today_row['today_assigned'] ?? 0),
        'today_dialed'        => (int)($today_row['today_dialed'] ?? 0),
        'today_scheduled'     => (int)($today_row['today_scheduled'] ?? 0),
        'today_appeared'      => (int)($today_row['today_appeared'] ?? 0),
        'today_hired'         => (int)($today_row['today_hired'] ?? 0),
        'active_recruiters'   => (int)($rec_cnt_row['active_count'] ?? 0),
        'inactive_recruiters' => (int)($rec_cnt_row['inactive_count'] ?? 0),
        'funnel'              => [
            'assigned'  => $kpis['assigned_leads'],
            'dialed'    => $kpis['dialed_leads'],
            'scheduled' => $kpis['scheduled_leads'],
            'appeared'  => $kpis['appeared_leads'],
            'hired'     => $kpis['hired_leads']
        ],
        'daily_trend'         => array_values($daily_trend),
        'source_breakdown'    => $source_breakdown,
        'recruiter_breakdown' => $recruiter_breakdown,
        'recent_activity'     => $activity,
        'upcoming_interviews' => $upcoming,
        'priority_leads'      => $priority
    ]);
}
?>