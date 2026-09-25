<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: HR and Super Admin only');
}

$active_branch = get_active_company_branch();
$recruiter_id  = isset($_GET['recruiter_id']) ? intval($_GET['recruiter_id']) : 0;
$hr_id         = isset($_GET['hr_id']) ? intval($_GET['hr_id']) : 0;
$limit         = min(intval($_GET['limit'] ?? 100), 500);
$offset        = intval($_GET['offset'] ?? 0);
$search        = trim($_GET['search'] ?? '');

$branch_req    = trim($_GET['branch'] ?? '');

$where = [];
$params = [];
$types  = "";

if (isGlobalSuperAdmin()) {
    if ($branch_req && $branch_req !== 'all') {
        $where[] = "d.company_branch = ?";
        $params[] = $branch_req;
        $types .= "s";
    }
} else {
    $where[] = "d.company_branch = ?";
    $params[] = $active_branch;
    $types .= "s";
}

if ($recruiter_id) {
    $where[] = "d.assigned_to_user_id = ?";
    $params[] = $recruiter_id;
    $types .= "i";
}

if ($hr_id) {
    $where[] = "d.assigned_by_user_id = ?";
    $params[] = $hr_id;
    $types .= "i";
}

if ($search) {
    $where[] = "(l.full_name LIKE ? OR l.phone LIKE ? OR d.assigned_by_name LIKE ? OR d.assigned_to_name LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "ssss";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Count
$count_stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM lead_distribution_logs d
    LEFT JOIN leads l ON d.lead_id = l.id
    $where_sql
");
bindParams($count_stmt, $types, $params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Logs with live progress from lead
$data_params = $params;
$data_types  = $types . "ii";
$data_params[] = $limit;
$data_params[] = $offset;

$sql = "
    SELECT 
        d.id,
        d.lead_id,
        d.assigned_by_user_id,
        d.assigned_by_name,
        d.assigned_to_user_id,
        d.assigned_to_name,
        d.distribution_mode,
        d.company_branch,
        d.notes,
        d.created_at,
        l.full_name as lead_name,
        l.phone as lead_phone,
        l.position_applied,
        l.current_stage,
        l.call_count,
        l.last_call_date,
        l.interview_date,
        (SELECT COUNT(*) FROM lead_remarks r WHERE r.lead_id = d.lead_id) AS total_remarks_count,
        (SELECT remark FROM lead_remarks r WHERE r.lead_id = d.lead_id ORDER BY created_at DESC LIMIT 1) AS latest_remark
    FROM lead_distribution_logs d
    LEFT JOIN leads l ON d.lead_id = l.id
    $where_sql
    ORDER BY d.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
bindParams($stmt, $data_types, $data_params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Overall distribution summary for current branch
$sum_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT d.id) as total_distributions,
        COUNT(DISTINCT d.assigned_to_user_id) as total_recruiters_assigned,
        SUM(l.call_count) as total_calls_on_assigned,
        SUM(l.current_stage = 'interview_scheduled') as scheduled_count,
        SUM(l.current_stage IN ('hired', 'selected', 'deployed')) as hired_count
    FROM lead_distribution_logs d
    LEFT JOIN leads l ON d.lead_id = l.id
    WHERE d.company_branch = ?
");
$sum_stmt->bind_param("s", $active_branch);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

respond(true, [
    'logs'    => $logs,
    'total'   => $total,
    'summary' => $summary,
    'limit'   => $limit,
    'offset'  => $offset
]);
