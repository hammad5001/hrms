<?php
require_once __DIR__ . '/config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$date = trim($_GET['date'] ?? '');
$status = trim($_GET['status'] ?? 'scheduled');
$today = trim($_GET['today'] ?? '');

$active_branch = get_active_company_branch();
$sql = "SELECT i.id, i.lead_id, i.scheduled_date, i.scheduled_time, i.location,
               i.interviewer_name, i.notes, i.status, i.created_at,
               l.full_name AS candidate_name, l.phone, l.email, l.position_applied
        FROM interviews i
        INNER JOIN leads l ON l.id = i.lead_id
        WHERE (
            COALESCE(NULLIF(TRIM(i.company_branch), ''), NULLIF(TRIM(l.company_branch), ''), 'main') = ?
            OR l.company_branch IS NULL
            OR TRIM(l.company_branch) = ''
        )";
$params = [$active_branch];
$types = 's';

if ($status) {
    $sql .= " AND i.status = ?";
    $params[] = $status;
    $types .= 's';
}
if ($date) {
    $sql .= " AND i.scheduled_date = ?";
    $params[] = $date;
    $types .= 's';
}
if ($today === '1') {
    $sql .= " AND i.scheduled_date = CURDATE()";
}

$sql .= " ORDER BY i.scheduled_date ASC, i.scheduled_time ASC LIMIT 500";

$stmt = $conn->prepare($sql);
if ($types) {
    bindParams($stmt, $types, $params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => (int)$r['id'],
        'leadId' => (int)$r['lead_id'],
        'candidateName' => $r['candidate_name'],
        'phone' => $r['phone'],
        'email' => $r['email'] ?? '',
        'position' => $r['position_applied'] ?? '',
        'date' => $r['scheduled_date'],
        'timeSlot' => $r['scheduled_time'],
        'location' => $r['location'] ?? '',
        'interviewer' => $r['interviewer_name'] ?? '',
        'notes' => $r['notes'] ?? '',
        'status' => $r['status'],
        'scheduledAt' => $r['created_at'],
    ];
}

respond(true, $out);
