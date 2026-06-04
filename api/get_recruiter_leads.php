<?php
require_once 'config.php';

header('Cache-Control: private, max-age=8');

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$user_id = getCurrentUserId();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

// Only super admin can view other recruiters' leads
$target_recruiter_id = isset($_GET['recruiter_id']) ? intval($_GET['recruiter_id']) : null;

if ($target_recruiter_id && $recruiter_type !== 'super') {
    respond(false, null, 'Access denied');
}

$filter_id = $target_recruiter_id ?? $user_id;

// Filters
$stage  = $_GET['stage'] ?? '';
$search = trim($_GET['search'] ?? '');
$limit  = min(intval($_GET['limit'] ?? 100), 500);
$offset = intval($_GET['offset'] ?? 0);

$active_branch = get_active_company_branch();
$where_clauses = ["l.assigned_recruiter_id = ?", "l.company_branch = ?"];
$params = [$filter_id, $active_branch];
$types  = "is";

if ($stage) {
    $where_clauses[] = "l.current_stage = ?";
    $params[] = $stage;
    $types .= "s";
}
if ($search) {
    $where_clauses[] = "(l.full_name LIKE ? OR l.phone LIKE ? OR l.position_applied LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "sss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM leads l $where_sql");
if ($types) {
    bindParams($count_stmt, $types, $params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];

// Data
$data_stmt = $conn->prepare("
    SELECT l.id, l.full_name, l.phone, l.email, l.city, l.position_applied,
           l.current_stage, l.call_count, l.last_call_date, l.interview_date,
           l.created_at, l.updated_at, l.assigned_at,
           (SELECT remark FROM lead_remarks WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) AS latest_remark,
           (SELECT created_at FROM lead_remarks WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) AS latest_remark_at
    FROM leads l
    $where_sql
    ORDER BY l.updated_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $limit; $types .= "i";
$params[] = $offset; $types .= "i";
bindParams($data_stmt, $types, $params);
$data_stmt->execute();
$leads = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats for this recruiter
$stats_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(current_stage IN ('new','assigned','outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg')) AS pending,
        SUM(current_stage IN ('outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg')) AS contacted,
        SUM(current_stage = 'pending') AS interested,
        SUM(current_stage = 'interview_scheduled') AS scheduled,
        SUM(current_stage IN ('hired','deployed')) AS hired,
        SUM(current_stage IN ('rejected','left','mock_rejected','hr_rejected','gm_rejected')) AS rejected
    FROM leads WHERE assigned_recruiter_id = ?
");
$stats_stmt->bind_param("i", $filter_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

respond(true, [
    'leads'  => $leads,
    'total'  => $total,
    'stats'  => $stats,
    'limit'  => $limit,
    'offset' => $offset
]);
?>
