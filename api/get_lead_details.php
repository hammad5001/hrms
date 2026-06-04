<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$lead_id = intval($_GET['lead_id'] ?? 0);
if (!$lead_id) {
    respond(false, null, 'lead_id required');
}

$user_id = getCurrentUserId();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

// Verify access
if ($recruiter_type !== 'super') {
    $access = $conn->prepare("SELECT id FROM leads WHERE id = ? AND assigned_recruiter_id = ?");
    $access->bind_param("ii", $lead_id, $user_id);
    $access->execute();
    if ($access->get_result()->num_rows === 0) {
        respond(false, null, 'Access denied');
    }
}

// Get lead details
$stmt = $conn->prepare("
    SELECT l.*,
           u.full_name AS recruiter_name,
           u.email AS recruiter_email
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    WHERE l.id = ?
");
$stmt->bind_param("i", $lead_id);
$stmt->execute();
$lead = $stmt->get_result()->fetch_assoc();

if (!$lead) {
    respond(false, null, 'Lead not found');
}

// Get remarks/call history
$remarks_stmt = $conn->prepare("
    SELECT lr.*, u.full_name as author_name
    FROM lead_remarks lr
    LEFT JOIN users u ON lr.added_by = u.id
    WHERE lr.lead_id = ?
    ORDER BY lr.created_at DESC
");
$remarks_stmt->bind_param("i", $lead_id);
$remarks_stmt->execute();
$remarks_result = $remarks_stmt->get_result();
$remarks = [];
while ($row = $remarks_result->fetch_assoc()) {
    $remarks[] = $row;
}

$lead['remarks'] = $remarks;
$lead['remarks_count'] = count($remarks);

respond(true, $lead);
?>
