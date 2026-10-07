<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/pipeline_helpers.php';

if (!isAuthenticated()) {
    http_response_code(401);
    die('Unauthorized access');
}

$user_id = getCurrentUserId();
$user_name = getCurrentUserName();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';
$portal_role = strtolower(trim((string)($_SESSION['portal_role'] ?? '')));
$is_admin_or_super = ($recruiter_type === 'super' || in_array($portal_role, ['super_admin', 'admin', 'hr'], true));
$active_branch = get_active_company_branch();

// Parameters
$mode = trim($_GET['mode'] ?? 'team'); // 'team' or 'individual'
$target_recruiter_id = isset($_GET['recruiter_id']) ? (int)$_GET['recruiter_id'] : 0;
$branch_req = trim($_GET['branch'] ?? 'all');
$range = trim($_GET['range'] ?? 'all_time');
$from_date = trim($_GET['from'] ?? '');
$to_date = trim($_GET['to'] ?? '');

// Permission check: regular recruiter can ONLY export their own report
if (!$is_admin_or_super) {
    $mode = 'individual';
    $target_recruiter_id = $user_id;
    $selected_branch = $active_branch;
} else {
    if (isGlobalSuperAdmin()) {
        if ($branch_req === 'all' || $branch_req === '') {
            $selected_branch = null;
        } elseif (is_valid_company_branch($branch_req)) {
            $selected_branch = normalize_company_branch($branch_req);
        } else {
            $selected_branch = null;
        }
    } else {
        // HR and Branch Admin restricted to their active branch
        $selected_branch = $active_branch;
    }
}

// Date Range SQL Filter for leads
$date_sql = "";
$date_label = "All Time";
if ($range === 'today') {
    $date_sql = " AND DATE(l.created_at) = CURDATE()";
    $date_label = "Today (" . date('d M Y') . ")";
} elseif ($range === 'this_week') {
    $date_sql = " AND YEARWEEK(l.created_at, 1) = YEARWEEK(CURDATE(), 1)";
    $date_label = "This Week";
} elseif ($range === 'this_month') {
    $date_sql = " AND MONTH(l.created_at) = MONTH(CURDATE()) AND YEAR(l.created_at) = YEAR(CURDATE())";
    $date_label = "This Month (" . date('F Y') . ")";
} elseif ($range === 'custom' && $from_date && $to_date) {
    $safe_from = date('Y-m-d', strtotime($from_date));
    $safe_to = date('Y-m-d', strtotime($to_date));
    $date_sql = " AND DATE(l.created_at) BETWEEN '$safe_from' AND '$safe_to'";
    $date_label = "$safe_from to $safe_to";
}

// SQL Condition Snippets
$dialed_cond = "(l.call_count > 0 OR l.current_stage IN ('outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg','not_answered','callback') OR l.last_call_date IS NOT NULL)";
$remaining_cond = "(l.assigned_recruiter_id IS NOT NULL AND (l.call_count = 0 OR l.call_count IS NULL) AND l.current_stage IN ('new', 'assigned'))";
$scheduled_cond = "(l.current_stage = 'interview_scheduled')";
$appeared_cond = "(l.current_stage IN ('receptionist','interview_conducted','hr_passed','hr_rejected','selected','training','deployed','hired'))";
$hired_cond = "(l.current_stage IN ('training','deployed','hired'))";
$stale_cond = "(l.assigned_recruiter_id IS NOT NULL AND l.current_stage NOT IN ('hired','deployed','selected','rejected','left','mock_rejected','training') AND (l.call_count = 0 OR l.call_count IS NULL OR l.last_call_date < DATE_SUB(NOW(), INTERVAL 3 DAY)))";

// Helper to write CSV rows safely with explicit escape parameter (PHP 8.4+ compatible)
function write_csv_row($handle, array $fields) {
    fputcsv($handle, $fields, ',', '"', "\\");
}

// Output buffer & headers setup
$timestamp = date('Y-m-d_Hi');
$branch_suffix = $selected_branch ? "_branch_" . $selected_branch : "_all_branches";

if ($mode === 'individual') {
    // ---------------------------------------------------------
    // INDIVIDUAL RECRUITER PERFORMANCE & PIPELINE REPORT
    // ---------------------------------------------------------
    if (!$target_recruiter_id && $is_admin_or_super) {
        $target_recruiter_id = $user_id;
    }

    // Fetch recruiter user details
    $u_stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.employee_code, u.status, u.company_branch 
        FROM users u 
        WHERE u.id = ?
    ");
    $u_stmt->bind_param("i", $target_recruiter_id);
    $u_stmt->execute();
    $rec_user = $u_stmt->get_result()->fetch_assoc();

    if (!$rec_user) {
        http_response_code(404);
        die('Recruiter not found');
    }

    $rec_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rec_user['full_name']);
    $filename = "Balitech_Performance_{$rec_name_clean}_{$timestamp}.csv";

    // Clean any prior output buffer so CSV is pristine
    error_reporting(0);
    ini_set('display_errors', '0');
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Report Meta Section
    write_csv_row($out, ['BALITECH NEXUS - INDIVIDUAL RECRUITER PERFORMANCE REPORT']);
    write_csv_row($out, ['Recruiter Name', $rec_user['full_name']]);
    write_csv_row($out, ['Employee BID', $rec_user['employee_code'] ?: 'N/A']);
    write_csv_row($out, ['Branch', company_branch_label($rec_user['company_branch'])]);
    write_csv_row($out, ['Email / Contact', ($rec_user['email'] ?: '-') . ' | ' . ($rec_user['phone'] ?: '-')]);
    write_csv_row($out, ['Account Status', strtoupper($rec_user['status'])]);
    write_csv_row($out, ['Date Filter Scope', $date_label]);
    write_csv_row($out, ['Report Generated On', date('d M Y, h:i A') . ' by ' . $user_name]);
    write_csv_row($out, []); // Blank separator

    // Calculate overall KPIs for this recruiter
    $kpi_sql = "
        SELECT 
            COUNT(l.id) AS total_assigned,
            SUM(CASE WHEN $dialed_cond THEN 1 ELSE 0 END) AS total_dialed,
            SUM(CASE WHEN $remaining_cond THEN 1 ELSE 0 END) AS total_remaining,
            SUM(CASE WHEN $scheduled_cond THEN 1 ELSE 0 END) AS total_scheduled,
            SUM(CASE WHEN $appeared_cond THEN 1 ELSE 0 END) AS total_appeared,
            SUM(CASE WHEN $hired_cond THEN 1 ELSE 0 END) AS total_hired,
            SUM(CASE WHEN $stale_cond THEN 1 ELSE 0 END) AS total_stale
        FROM leads l
        WHERE l.assigned_recruiter_id = ? $date_sql
    ";
    $kpi_stmt = $conn->prepare($kpi_sql);
    $kpi_stmt->bind_param("i", $target_recruiter_id);
    $kpi_stmt->execute();
    $kpis = $kpi_stmt->get_result()->fetch_assoc() ?: [];

    $t_ass = (int)($kpis['total_assigned'] ?? 0);
    $t_dia = (int)($kpis['total_dialed'] ?? 0);
    $t_rem = (int)($kpis['total_remaining'] ?? 0);
    $t_sch = (int)($kpis['total_scheduled'] ?? 0);
    $t_app = (int)($kpis['total_appeared'] ?? 0);
    $t_hi  = (int)($kpis['total_hired'] ?? 0);
    $t_sta = (int)($kpis['total_stale'] ?? 0);

    $dial_pct = $t_ass > 0 ? round(($t_dia / $t_ass) * 100, 1) . '%' : '0.0%';
    $sch_pct  = $t_dia > 0 ? round(($t_sch / $t_dia) * 100, 1) . '%' : '0.0%';
    $hire_pct = $t_ass > 0 ? round(($t_hi / $t_ass) * 100, 1) . '%' : '0.0%';

    write_csv_row($out, ['--- PERFORMANCE SUMMARY DASHBOARD ---']);
    write_csv_row($out, ['Metric', 'Count / Value', 'Benchmark / Note']);
    write_csv_row($out, ['Total Assigned Leads', $t_ass, 'Total workload']);
    write_csv_row($out, ['Dialed / Contacted', $t_dia, "Dial Rate: $dial_pct"]);
    write_csv_row($out, ['Remaining (Uncalled)', $t_rem, 'Awaiting initial contact']);
    write_csv_row($out, ['Interviews Scheduled', $t_sch, "Schedule Rate: $sch_pct of dialed"]);
    write_csv_row($out, ['Candidates Appeared', $t_app, 'Checked in at Reception']);
    write_csv_row($out, ['Hired / Deployed', $t_hi, "Overall Conversion: $hire_pct"]);
    write_csv_row($out, ['Stale Leads (3D+ Uncalled)', $t_sta, $t_sta > 0 ? 'Urgent action required' : 'Clean pipeline']);
    write_csv_row($out, []); // Blank separator

    // Detail Leads Pipeline Table
    write_csv_row($out, ['--- CANDIDATE-LEVEL PIPELINE BREAKDOWN ---']);
    write_csv_row($out, [
        'Sr#',
        'Lead ID',
        'Candidate Name',
        'Phone Number',
        'CNIC',
        'Position Applied',
        'City',
        'Company Branch',
        'Source',
        'Pipeline Stage',
        'Call Count',
        'Last Call Date',
        'Scheduled Interview Date',
        'Rejection Reason',
        'Latest Remark / Notes',
        'Assigned Date',
        'Lead Created Date'
    ]);

    // Query leads
    $l_sql = "
        SELECT 
            l.id, l.full_name, l.phone, l.cnic, l.position_applied, l.city, 
            l.company_branch, l.source, l.current_stage, l.call_count, 
            l.last_call_date, l.interview_date, l.rejection_reason, 
            l.assigned_at, l.created_at,
            (SELECT remark FROM lead_remarks WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) AS latest_remark
        FROM leads l
        WHERE l.assigned_recruiter_id = ? $date_sql
        ORDER BY l.updated_at DESC, l.id DESC
    ";
    $l_stmt = $conn->prepare($l_sql);
    $l_stmt->bind_param("i", $target_recruiter_id);
    $l_stmt->execute();
    $leads_res = $l_stmt->get_result();

    $sr = 1;
    while ($lead = $leads_res->fetch_assoc()) {
        write_csv_row($out, [
            $sr++,
            '#' . $lead['id'],
            $lead['full_name'],
            $lead['phone'] ?: '-',
            $lead['cnic'] ?: 'Not Provided',
            $lead['position_applied'] ?: 'Dialer',
            $lead['city'] ?: '-',
            company_branch_label($lead['company_branch']),
            ucfirst($lead['source'] ?: 'Manual'),
            humanize_stage($lead['current_stage']),
            (int)($lead['call_count'] ?? 0),
            $lead['last_call_date'] ? date('d M Y, h:i A', strtotime($lead['last_call_date'])) : 'Not Contacted',
            $lead['interview_date'] ? date('d M Y', strtotime($lead['interview_date'])) : '-',
            $lead['rejection_reason'] ?: '-',
            str_replace(["\r", "\n"], ' ', $lead['latest_remark'] ?? 'No remarks logged'),
            $lead['assigned_at'] ? date('d M Y, h:i A', strtotime($lead['assigned_at'])) : '-',
            date('d M Y, h:i A', strtotime($lead['created_at']))
        ]);
    }

    fclose($out);
    exit;

} else {
    // ---------------------------------------------------------
    // ALL RECRUITERS TEAM PERFORMANCE CONSOLIDATED REPORT
    // ---------------------------------------------------------
    $filename = "Balitech_Team_Performance{$branch_suffix}_{$timestamp}.csv";

    // Clean any prior output buffer so CSV is pristine
    error_reporting(0);
    ini_set('display_errors', '0');
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Report Header Information
    $branch_title = $selected_branch ? company_branch_label($selected_branch) : "All Branches Centralized";
    write_csv_row($out, ['BALITECH NEXUS - RECRUITER TEAM PERFORMANCE CONSOLIDATED AUDIT REPORT']);
    write_csv_row($out, ['Branch Scope', $branch_title]);
    write_csv_row($out, ['Date Filter Scope', $date_label]);
    write_csv_row($out, ['Report Generated By', $user_name . ' (' . ucfirst($recruiter_type) . ')']);
    write_csv_row($out, ['Export Date & Time', date('d M Y, h:i:s A')]);
    write_csv_row($out, []); // Blank separator

    // Table Headers
    write_csv_row($out, [
        'Rank',
        'Recruiter Name',
        'Employee BID',
        'Branch',
        'Status',
        'Total Assigned Leads',
        'Dialed / Contacted',
        'Remaining (Uncalled)',
        'Interviews Scheduled',
        'Candidates Appeared',
        'Hired / Deployed',
        'Stale Leads (3D+ No Call)',
        'Dial Rate %',
        'Schedule Rate %',
        'Conversion (Hire) %',
        'Last Activity Date & Time',
        'Account Email',
        'Phone Number'
    ]);

    // SQL query for all regular recruiters
    $b_filter_users = $selected_branch !== null ? "AND u.company_branch = ?" : "";
    $b_filter_leads = $selected_branch !== null ? "AND l.company_branch = u.company_branch" : "";

    $sql_rec = "
        SELECT 
            u.id, 
            u.full_name, 
            u.employee_code,
            u.email,
            u.phone,
            u.status, 
            u.company_branch,
            COUNT(l.id) AS assigned,
            SUM(CASE WHEN l.id IS NOT NULL AND $dialed_cond THEN 1 ELSE 0 END) AS dialed,
            SUM(CASE WHEN l.id IS NOT NULL AND $remaining_cond THEN 1 ELSE 0 END) AS remaining,
            SUM(CASE WHEN l.id IS NOT NULL AND $scheduled_cond THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN l.id IS NOT NULL AND $appeared_cond THEN 1 ELSE 0 END) AS appeared,
            SUM(CASE WHEN l.id IS NOT NULL AND $hired_cond THEN 1 ELSE 0 END) AS hired,
            SUM(CASE WHEN l.id IS NOT NULL AND $stale_cond THEN 1 ELSE 0 END) AS stale_leads,
            MAX(COALESCE(rem.max_rem, aud.max_aud)) AS last_active
        FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        LEFT JOIN leads l ON l.assigned_recruiter_id = u.id $b_filter_leads $date_sql
        LEFT JOIN (
            SELECT added_by, MAX(created_at) AS max_rem FROM lead_remarks GROUP BY added_by
        ) rem ON rem.added_by = u.id
        LEFT JOIN (
            SELECT user_id, MAX(created_at) AS max_aud FROM lead_audit GROUP BY user_id
        ) aud ON aud.user_id = u.id
        WHERE r.recruiter_type = 'regular' $b_filter_users
        GROUP BY u.id
        ORDER BY hired DESC, scheduled DESC, dialed DESC, assigned DESC
    ";

    $stmt_rec = $conn->prepare($sql_rec);
    if ($selected_branch !== null) {
        $stmt_rec->bind_param("s", $selected_branch);
    }
    $stmt_rec->execute();
    $raw_recruiters = $stmt_rec->get_result()->fetch_all(MYSQLI_ASSOC);

    $rank = 1;
    $sum_assigned = 0;
    $sum_dialed = 0;
    $sum_remaining = 0;
    $sum_scheduled = 0;
    $sum_appeared = 0;
    $sum_hired = 0;
    $sum_stale = 0;

    foreach ($raw_recruiters as $r) {
        $ass = (int)($r['assigned'] ?? 0);
        $dia = (int)($r['dialed'] ?? 0);
        $rem = (int)($r['remaining'] ?? 0);
        $sch = (int)($r['scheduled'] ?? 0);
        $app = (int)($r['appeared'] ?? 0);
        $hi  = (int)($r['hired'] ?? 0);
        $sta = (int)($r['stale_leads'] ?? 0);

        $dial_pct = $ass > 0 ? round(($dia / $ass) * 100, 1) : 0;
        $sch_pct  = $dia > 0 ? round(($sch / $dia) * 100, 1) : 0;
        $conv_pct = $ass > 0 ? round(($hi / $ass) * 100, 1) : 0;

        $sum_assigned += $ass;
        $sum_dialed += $dia;
        $sum_remaining += $rem;
        $sum_scheduled += $sch;
        $sum_appeared += $app;
        $sum_hired += $hi;
        $sum_stale += $sta;

        write_csv_row($out, [
            $rank++,
            $r['full_name'],
            $r['employee_code'] ?: 'N/A',
            company_branch_label($r['company_branch']),
            strtoupper($r['status']),
            $ass,
            $dia,
            $rem,
            $sch,
            $app,
            $hi,
            $sta,
            $dial_pct . '%',
            $sch_pct . '%',
            $conv_pct . '%',
            $r['last_active'] ? date('d M Y, h:i A', strtotime($r['last_active'])) : 'No Activity',
            $r['email'] ?: '-',
            $r['phone'] ?: '-'
        ]);
    }

    // Grand Totals & Averages Footer Row
    $overall_dial_pct = $sum_assigned > 0 ? round(($sum_dialed / $sum_assigned) * 100, 1) . '%' : '0.0%';
    $overall_sch_pct  = $sum_dialed > 0 ? round(($sum_scheduled / $sum_dialed) * 100, 1) . '%' : '0.0%';
    $overall_conv_pct = $sum_assigned > 0 ? round(($sum_hired / $sum_assigned) * 100, 1) . '%' : '0.0%';

    write_csv_row($out, []); // Blank separator
    write_csv_row($out, [
        'TOTALS / AVERAGES',
        'Department Overall (' . count($raw_recruiters) . ' Recruiters)',
        '-',
        $branch_title,
        '-',
        $sum_assigned,
        $sum_dialed,
        $sum_remaining,
        $sum_scheduled,
        $sum_appeared,
        $sum_hired,
        $sum_stale,
        $overall_dial_pct,
        $overall_sch_pct,
        $overall_conv_pct,
        '-',
        '-',
        '-'
    ]);

    fclose($out);
    exit;
}

function humanize_stage($stage) {
    $labels = [
        'new'                     => 'Lead Intake (New)',
        'assigned'                => 'Assigned to Recruiter',
        'outreach_phone'          => 'Phone Call',
        'contacted'               => 'Phone Call (Contacted)',
        'outreach_whatsapp_call'  => 'WhatsApp Call',
        'outreach_whatsapp_msg'   => 'Message Dropped (WhatsApp)',
        'message_dropped'         => 'Message Dropped',
        'not_answered'            => 'Not Answered',
        'callback'                => 'Call Back Requested',
        'interview_scheduled'     => 'Interview Scheduled',
        'scheduled'               => 'Interview Scheduled',
        'receptionist'            => 'Appeared at Reception',
        'not_appeared'            => 'Not Appeared',
        'pending'                 => 'Pending Decision',
        'selected'                => 'Selected',
        'rejected'                => 'Rejected',
        'referred_branch'         => 'Referred to Another Branch',
        'training'                => 'In Training',
        'deployed'                => 'Deployed / Joined',
        'hired'                   => 'Hired'
    ];
    return $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
}
