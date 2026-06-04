<?php
require_once __DIR__.'/config.php';

// Ensure request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Invalid request method');
}

// Get raw input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['lead_id']) || !isset($input['scheduled_at'])) {
    respond(false, null, 'Missing required parameters');
}

$lead_id = (int)$input['lead_id'];
$scheduled_at = $input['scheduled_at']; // Expected format: YYYY-MM-DD HH:MM

// Split datetime into date and time for the DB
$parts = explode(' ', $scheduled_at);
$scheduled_date = $parts[0] ?? date('Y-m-d');
$scheduled_time = $parts[1] ?? date('H:i');
$location = trim((string)($input['location'] ?? 'Reception'));
$interviewer = trim((string)($input['interviewer_name'] ?? 'HR Manager'));
$notes = trim((string)($input['notes'] ?? ''));
$branch = get_active_company_branch();

// Validate lead exists
$stmt = $conn->prepare('SELECT id FROM leads WHERE id = ?');
$stmt->bind_param('i', $lead_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    respond(false, null, 'Lead not found');
}
$stmt->close();

// Insert interview record matching the actual schema:
// Field: id | Field: lead_id | Field: scheduled_by | Field: scheduled_date | Field: scheduled_time | Field: status
$status = 'scheduled';
$scheduled_by = getCurrentUserId();

$stmt = $conn->prepare('INSERT INTO interviews (lead_id, scheduled_by, scheduled_date, scheduled_time, location, interviewer_name, notes, status, company_branch, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');

if (!$stmt) {
    respond(false, null, 'Prepare failed: ' . $conn->error);
}

$stmt->bind_param('iisssssss', $lead_id, $scheduled_by, $scheduled_date, $scheduled_time, $location, $interviewer, $notes, $status, $branch);

if ($stmt->execute()) {
    $interview_id = $stmt->insert_id;
    
    // Also update lead status and interview_date for backward compatibility
    $lead_stmt = $conn->prepare('UPDATE leads SET current_stage = ?, interview_date = ?, company_branch = COALESCE(NULLIF(TRIM(company_branch), \'\'), ?), updated_at = NOW() WHERE id = ?');
    $lead_stage = 'interview_scheduled';
    $lead_stmt->bind_param('sssi', $lead_stage, $scheduled_date, $branch, $lead_id);
    $lead_stmt->execute();
    $lead_stmt->close();
    
    respond(true, ['interview_id' => $interview_id], 'Interview scheduled successfully');
} else {
    respond(false, null, 'Database error while creating interview: ' . $stmt->error);
}
$stmt->close();
?>
