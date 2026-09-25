<?php
/**
 * API to fetch comprehensive candidate interview & pipeline records
 * Supports filtering by Stage/Status, Branch, Source, Date Range, and Search term
 */
require_once 'config.php';
require_once __DIR__ . '/../includes/pipeline_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$stage_filter  = trim($_GET['stage'] ?? '');
$source_filter = trim($_GET['source'] ?? '');
$branch_req    = trim($_GET['branch'] ?? '');
$search        = trim($_GET['search'] ?? '');
$start_date    = trim($_GET['start_date'] ?? '');
$end_date      = trim($_GET['end_date'] ?? '');
$limit         = min(1000, max(1, (int)($_GET['limit'] ?? 100)));
$offset        = max(0, (int)($_GET['offset'] ?? 0));

$where = ["1=1"];
$params = [];
$types  = "";

// Branch filtering
$active_branch = get_active_company_branch();
if (isGlobalSuperAdmin()) {
    if ($branch_req && $branch_req !== 'all') {
        $where[] = "l.company_branch = ?";
        $params[] = $branch_req;
        $types .= "s";
    }
} else {
    $where[] = "(l.company_branch = ? OR l.company_branch IS NULL OR TRIM(l.company_branch) = '' OR l.company_branch = 'main')";
    $params[] = $active_branch;
    $types .= "s";
}

// Stage filtering
if ($stage_filter && $stage_filter !== 'all') {
    if ($stage_filter === 'pending_hr') {
        $where[] = "l.current_stage IN ('interview_conducted', 'receptionist')";
    } elseif ($stage_filter === 'passed_hr') {
        $where[] = "l.current_stage IN ('hr_passed', 'gm_passed', 'selected', 'hired', 'training', 'deployed')";
    } elseif ($stage_filter === 'rejected_hr') {
        $where[] = "l.current_stage IN ('hr_rejected', 'rejected', 'gm_rejected')";
    } elseif ($stage_filter === 'reception_queue') {
        $where[] = "l.current_stage IN ('interview_scheduled', 'receptionist', 'not_appeared')";
    } else {
        $where[] = "l.current_stage = ?";
        $params[] = canonical_stage($stage_filter);
        $types .= "s";
    }
}

// Source filtering
if ($source_filter && $source_filter !== 'all') {
    if ($source_filter === 'walkin') {
        $where[] = "(l.source LIKE '%walk%' OR l.referred_by = 'Walk-in')";
    } elseif ($source_filter === 'mobile') {
        $where[] = "l.source = 'mobile'";
    } elseif ($source_filter === 'recruiter') {
        $where[] = "(l.source = 'recruiter' OR l.assigned_recruiter_id IS NOT NULL)";
    }
}

// Date Range filtering on created_at or updated_at
if ($start_date) {
    $where[] = "DATE(l.created_at) >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if ($end_date) {
    $where[] = "DATE(l.created_at) <= ?";
    $params[] = $end_date;
    $types .= "s";
}

// Search term
if ($search) {
    $where[] = "(l.full_name LIKE ? OR l.phone LIKE ? OR l.cnic LIKE ? OR l.position_applied LIKE ? OR l.city LIKE ? OR u.full_name LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "ssssss";
}

$where_sql = implode(" AND ", $where);

// 1. Overall Pipeline Count Stats
$stats_sql = "
    SELECT 
        COUNT(*) as total_all,
        SUM(CASE WHEN l.current_stage IN ('interview_scheduled', 'receptionist') THEN 1 ELSE 0 END) as total_reception_queue,
        SUM(CASE WHEN l.current_stage = 'interview_conducted' THEN 1 ELSE 0 END) as total_pending_hr,
        SUM(CASE WHEN l.current_stage IN ('hr_passed', 'gm_passed', 'selected', 'hired', 'training', 'deployed') THEN 1 ELSE 0 END) as total_approved_hr,
        SUM(CASE WHEN l.current_stage IN ('hr_rejected', 'rejected', 'gm_rejected') THEN 1 ELSE 0 END) as total_rejected_hr,
        SUM(CASE WHEN l.source = 'walkin' OR l.referred_by = 'Walk-in' THEN 1 ELSE 0 END) as total_walkins,
        SUM(CASE WHEN l.source = 'mobile' THEN 1 ELSE 0 END) as total_mobile_forms
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    WHERE $where_sql
";
$stats_stmt = $conn->prepare($stats_sql);
if (!empty($types)) {
    $stats_stmt->bind_param($types, ...$params);
}
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// 2. Total filtered count for pagination
$count_sql = "
    SELECT COUNT(*) as total_count
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    WHERE $where_sql
";
$count_stmt = $conn->prepare($count_sql);
if (!empty($types)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_count = (int)($count_stmt->get_result()->fetch_assoc()['total_count'] ?? 0);

// 3. Paginated Data Fetch with latest remarks & audit trail info
$data_types = $types . "ii";
$data_params = $params;
$data_params[] = $limit;
$data_params[] = $offset;

$sql = "
    SELECT 
        l.id,
        l.full_name,
        l.father_name,
        l.phone,
        l.email,
        l.cnic,
        l.city,
        l.dob,
        l.education,
        l.position_applied,
        l.referred_by,
        l.source,
        l.company_branch,
        l.current_stage,
        l.interview_date,
        l.created_at,
        l.updated_at,
        u.full_name AS recruiter_name,
        i.id AS interview_id,
        i.scheduled_date,
        i.scheduled_time,
        i.location AS interview_location,
        i.interviewer_name,
        (
            SELECT CONCAT(added_by_name, ' (', added_by_role, '): ', remark)
            FROM lead_remarks r
            WHERE r.lead_id = l.id
            ORDER BY r.created_at DESC
            LIMIT 1
        ) AS latest_remark,
        (
            SELECT MAX(created_at)
            FROM lead_remarks r
            WHERE r.lead_id = l.id
        ) AS latest_remark_date,
        (
            SELECT COUNT(*)
            FROM lead_remarks r
            WHERE r.lead_id = l.id
        ) AS total_remarks
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    LEFT JOIN interviews i ON i.lead_id = l.id AND i.status = 'scheduled'
    WHERE $where_sql
    ORDER BY l.updated_at DESC, l.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($data_types, ...$data_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$records = [];
foreach ($rows as $r) {
    $stage = canonical_stage((string)$r['current_stage']);
    
    // Status Badge & Human-readable stage
    $status_label = pipeline_stage_label($stage);
    $badge_color = '#3b82f6';
    if (in_array($stage, ['hr_passed', 'gm_passed', 'selected', 'hired', 'deployed', 'training'], true)) {
        $badge_color = '#10b981'; // green / approved
    } elseif (in_array($stage, ['hr_rejected', 'rejected', 'gm_rejected', 'mock_rejected'], true)) {
        $badge_color = '#ef4444'; // red / rejected
    } elseif ($stage === 'interview_conducted') {
        $badge_color = '#f59e0b'; // orange / pending HR review
    } elseif ($stage === 'receptionist') {
        $badge_color = '#8b5cf6'; // purple / checked in
    }

    $records[] = [
        'id'               => (int)$r['id'],
        'fullName'         => $r['full_name'],
        'fatherName'       => $r['father_name'] ?: '—',
        'phone'            => $r['phone'],
        'email'            => $r['email'] ?: '—',
        'cnic'             => $r['cnic'] ?: '—',
        'city'             => $r['city'] ?: '—',
        'dob'              => $r['dob'] ?: '—',
        'qualification'    => $r['education'] ?: '—',
        'position'         => $r['position_applied'] ?: '—',
        'referredBy'       => $r['referred_by'] ?: 'Walk-in',
        'source'           => $r['source'] ?: 'walkin',
        'branch'           => company_branch_label($r['company_branch']),
        'rawBranch'        => $r['company_branch'],
        'currentStage'     => $stage,
        'stageLabel'       => $status_label,
        'badgeColor'       => $badge_color,
        'recruiterName'    => $r['recruiter_name'] ?: 'Reception Desk / Walk-in',
        'registeredAt'     => $r['created_at'],
        'lastUpdatedAt'    => $r['updated_at'],
        'interviewDateTime'=> trim(($r['scheduled_date'] ?? '') . ' ' . ($r['scheduled_time'] ?? '')),
        'interviewer'      => $r['interviewer_name'] ?: 'HR Manager',
        'location'         => $r['interview_location'] ?: 'Main Office',
        'latestRemark'     => $r['latest_remark'] ?: 'No remarks recorded yet',
        'latestRemarkDate' => $r['latest_remark_date'] ?: '',
        'totalRemarks'     => (int)$r['total_remarks']
    ];
}

respond(true, [
    'records'     => $records,
    'total'       => $total_count,
    'stats'       => $stats,
    'limit'       => $limit,
    'offset'      => $offset
]);
