<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = intval($data['lead_id'] ?? 0);
$status = $conn->real_escape_string($data['status'] ?? '');
$remarks = $conn->real_escape_string($data['remarks'] ?? '');

if (!$lead_id || !$status) {
    respond(false, null, 'Lead ID and status are required');
}

$user_id = $_SESSION['user_id'];
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

// Verify access - regular recruiters can only update their own leads
if ($recruiter_type !== 'super') {
    $check = $conn->prepare("SELECT id FROM leads WHERE id = ? AND assigned_recruiter_id = ?");
    $check->bind_param("ii", $lead_id, $user_id);
    $check->execute();
    $check_result = $check->get_result();
    
    if ($check_result->num_rows === 0) {
        respond(false, null, 'You can only update leads assigned to you');
    }
}

// Get old status for audit
$old_status_query = $conn->prepare("SELECT current_stage FROM leads WHERE id = ?");
$old_status_query->bind_param("i", $lead_id);
$old_status_query->execute();
$old_result = $old_status_query->get_result();
$old_status = $old_result->fetch_assoc()['current_stage'] ?? '';

// Update lead status
$update = $conn->prepare("
    UPDATE leads 
    SET current_stage = ?, 
        updated_at = NOW(),
        last_call_date = IF(? IN ('contacted', 'interested', 'callback'), NOW(), last_call_date)
    WHERE id = ?
");
$update->bind_param("ssi", $status, $status, $lead_id);
$update->execute();

// Log remark if provided
if ($remarks) {
    $user_name = $_SESSION['full_name'] ?? 'System';
    $user_role = $_SESSION['portal_role'] ?? 'recruiter';
    
    $remark_stmt = $conn->prepare("
        INSERT INTO lead_remarks (lead_id, added_by, added_by_role, remark, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $remark_stmt->bind_param("isss", $lead_id, $user_id, $user_role, $remarks);
    $remark_stmt->execute();
}

// Update recruiter stats if status changed to hired
if ($status === 'hired' && $old_status !== 'hired') {
    $update_stats = $conn->prepare("
        UPDATE recruiters SET total_hired = total_hired + 1 
        WHERE user_id = (SELECT assigned_recruiter_id FROM leads WHERE id = ?)
    ");
    $update_stats->bind_param("i", $lead_id);
    $update_stats->execute();
}

// Log audit
$audit_stmt = $conn->prepare("
    INSERT INTO lead_audit (lead_id, user_id, action, old_value, new_value, notes, created_at) 
    VALUES (?, ?, 'status_change', ?, ?, ?, NOW())
");
$audit_notes = "Status changed from $old_status to $status";
$audit_stmt->bind_param("iisss", $lead_id, $user_id, $old_status, $status, $audit_notes);
$audit_stmt->execute();

respond(true, ['message' => 'Status updated successfully']);
?>