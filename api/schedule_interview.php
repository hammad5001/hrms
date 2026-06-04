<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = intval($data['lead_id'] ?? 0);
$date = $conn->real_escape_string($data['date'] ?? '');
$time = $conn->real_escape_string($data['time'] ?? '');
$location = $conn->real_escape_string($data['location'] ?? 'Main Office - Ground Floor');
$interviewer = $conn->real_escape_string($data['interviewer'] ?? 'HR Manager');
$notes = $conn->real_escape_string($data['notes'] ?? '');

if (!$lead_id || !$date || !$time) {
    respond(false, null, 'Lead ID, date, and time are required');
}

$user_id = $_SESSION['user_id'];
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

// Verify access - regular recruiters can only schedule for their own leads
if ($recruiter_type !== 'super') {
    $check = $conn->prepare("SELECT id FROM leads WHERE id = ? AND assigned_recruiter_id = ?");
    $check->bind_param("ii", $lead_id, $user_id);
    $check->execute();
    $check_result = $check->get_result();
    
    if ($check_result->num_rows === 0) {
        respond(false, null, 'You can only schedule interviews for leads assigned to you');
    }
}

// Check for existing scheduled interview
$check_exists = $conn->prepare("
    SELECT id FROM interviews 
    WHERE lead_id = ? AND scheduled_date = ? AND status = 'scheduled'
");
$check_exists->bind_param("is", $lead_id, $date);
$check_exists->execute();
$exists_result = $check_exists->get_result();

if ($exists_result->num_rows > 0) {
    respond(false, null, 'An interview is already scheduled for this date');
}

$branch = get_active_company_branch();

// Schedule the interview
$stmt = $conn->prepare("
    INSERT INTO interviews (
        lead_id, scheduled_by, scheduled_date, scheduled_time,
        location, interviewer_name, notes, status, company_branch, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW(), NOW())
");
$stmt->bind_param("iissssss", $lead_id, $user_id, $date, $time, $location, $interviewer, $notes, $branch);
$stmt->execute();

// Update lead status + branch + interview date
$update_lead = $conn->prepare("
    UPDATE leads
    SET current_stage = 'interview_scheduled',
        interview_date = ?,
        company_branch = COALESCE(NULLIF(TRIM(company_branch), ''), ?),
        updated_at = NOW()
    WHERE id = ?
");
$update_lead->bind_param("ssi", $date, $branch, $lead_id);
$update_lead->execute();

// Update recruiter stats
$update_stats = $conn->prepare("
    UPDATE recruiters SET total_calls = total_calls + 1 
    WHERE user_id = ?
");
$update_stats->bind_param("i", $user_id);
$update_stats->execute();

// Log audit
$audit_stmt = $conn->prepare("
    INSERT INTO lead_audit (lead_id, user_id, action, notes, created_at) 
    VALUES (?, ?, 'interview_scheduled', ?, NOW())
");
$audit_notes = "Interview scheduled for $date at $time at $location";
$audit_stmt->bind_param("iis", $lead_id, $user_id, $audit_notes);
$audit_stmt->execute();

respond(true, [
    'message' => 'Interview scheduled successfully',
    'interview_id' => $conn->insert_id
]);
?>