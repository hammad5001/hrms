<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: Super Admin only');
}

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? ''; // 'active' or 'inactive'

$where = ["r.recruiter_type = 'regular'"];
$params = [];
$types  = "";

if ($search) {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "sss";
}
if ($status_filter) {
    $where[] = "u.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where);

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.employee_code,
        u.status,
        u.created_at AS joined_at,
        r.recruiter_type,
        r.total_hired,
        r.total_calls,
        COUNT(DISTINCT l.id)  AS total_leads,
        SUM(l.current_stage IN ('new','assigned')) AS pending_leads,
        SUM(l.current_stage = 'interview_scheduled') AS scheduled_leads,
        SUM(l.current_stage = 'hired') AS hired_leads,
        SUM(l.current_stage IN ('rejected','hr_rejected','gm_rejected')) AS rejected_leads,
        MAX(l.updated_at) AS last_activity
    FROM users u
    INNER JOIN recruiters r ON u.id = r.user_id
    LEFT JOIN leads l ON l.assigned_recruiter_id = u.id
    $where_sql
    GROUP BY u.id
    ORDER BY u.status ASC, total_leads DESC, u.full_name ASC
";

$stmt = $conn->prepare($sql);
if ($types) {
    bindParams($stmt, $types, $params);
}
$stmt->execute();
$list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

respond(true, $list);
?>