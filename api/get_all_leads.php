<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: Super Admin only');
}

// Filters
$search = trim($_GET['search'] ?? '');
$limit  = min(intval($_GET['limit'] ?? 200), 500);
$offset = intval($_GET['offset'] ?? 0);
$stage  = $_GET['stage'] ?? '';
$rec_id = isset($_GET['recruiter_id']) ? intval($_GET['recruiter_id']) : null;

$active_branch = get_active_company_branch();
$where = ["l.company_branch = ?"];
$params = [$active_branch];
$types = "s";

if ($search) {
    $where[] = "(l.full_name LIKE ? OR l.phone LIKE ? OR l.position_applied LIKE ? OR l.city LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "ssss";
}
if ($stage) {
    $where[] = "l.current_stage = ?";
    $params[] = $stage;
    $types .= "s";
}
if ($rec_id) {
    $where[] = "l.assigned_recruiter_id = ?";
    $params[] = $rec_id;
    $types .= "i";
}

$where_sql = "WHERE " . implode(" AND ", $where);

$count_stmt   = $conn->prepare("SELECT COUNT(*) as total FROM leads l $where_sql");
if ($types) {
    bindParams($count_stmt, $types, $params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];

// Data
$data_params  = $params;
$data_types   = $types . "ii";
$data_params[] = $limit;
$data_params[] = $offset;

$data_stmt = $conn->prepare("
    SELECT l.id, l.full_name, l.phone, l.email, l.city, l.cnic,
           l.position_applied, l.current_stage, l.call_count,
           l.last_call_date, l.interview_date, l.created_at, l.updated_at, l.assigned_at,
           u.full_name AS recruiter_name, u.id AS recruiter_user_id,
           (SELECT remark FROM lead_remarks WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) AS latest_remark
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    $where_sql
    ORDER BY l.created_at DESC
    LIMIT ? OFFSET ?
");

bindParams($data_stmt, $data_types, $data_params);
$data_stmt->execute();
$leads = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

respond(true, ['leads' => $leads, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
?>
