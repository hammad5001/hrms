<?php
require_once 'config.php';
require_once __DIR__ . '/../includes/pipeline_helpers.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$lead_id = (int)($_GET['lead_id'] ?? 0);
if ($lead_id <= 0) {
    respond(false, null, 'lead_id required');
}

$stmt = $conn->prepare('SELECT id, full_name, current_stage, assigned_recruiter_id FROM leads WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $lead_id);
$stmt->execute();
$lead = $stmt->get_result()->fetch_assoc();
if (!$lead) {
    respond(false, null, 'Lead not found');
}

if (isRegularRecruiter()) {
    $uid = getCurrentUserId();
    if ((int)$lead['assigned_recruiter_id'] !== $uid) {
        respond(false, null, 'Access denied');
    }
}

$events = pipeline_get_lead_timeline($conn, $lead_id);

respond(true, [
    'lead_id' => $lead_id,
    'full_name' => $lead['full_name'],
    'current_stage' => canonical_stage((string)$lead['current_stage']),
    'stage_label' => pipeline_stage_label((string)$lead['current_stage']),
    'stage_badge' => pipeline_stage_badge_class((string)$lead['current_stage']),
    'events' => $events,
]);
