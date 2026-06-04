<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = intval($data['lead_id'] ?? 0);
$status = $conn->real_escape_string($data['status'] ?? '');
$remarks = $conn->real_escape_string($data['remarks'] ?? '');
$assigned_recruiter_id = isset($data['assigned_recruiter_id']) ? intval($data['assigned_recruiter_id']) : null;
$interview_date = $conn->real_escape_string($data['interview_date'] ?? '');
$call_notes = $conn->real_escape_string($data['call_notes'] ?? '');

if (!$lead_id) {
    respond(false, null, 'Lead ID is required');
}

$user_id = $_SESSION['user_id'];
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';

// Verify access - regular recruiters can only update their own leads
if ($recruiter_type !== 'super') {
    $check = $conn->prepare("SELECT assigned_recruiter_id, current_stage FROM leads WHERE id = ?");
    $check->bind_param("i", $lead_id);
    $check->execute();
    $check_result = $check->get_result();
    
    if ($check_result->num_rows === 0) {
        respond(false, null, 'Lead not found');
    }
    
    $lead_data = $check_result->fetch_assoc();
    if ($lead_data['assigned_recruiter_id'] != $user_id) {
        respond(false, null, 'You can only update leads assigned to you');
    }
    
    // Don't allow status change from final stages back to previous stages
    if (in_array($lead_data['current_stage'], ['deployed', 'hired', 'rejected', 'left', 'mock_rejected'])) {
        respond(false, null, 'Cannot update a lead that is already ' . $lead_data['current_stage']);
    }
}

// Get old status for audit
$old_status_query = $conn->prepare("SELECT current_stage, assigned_recruiter_id FROM leads WHERE id = ?");
$old_status_query->bind_param("i", $lead_id);
$old_status_query->execute();
$old_result = $old_status_query->get_result();
$old_data = $old_result->fetch_assoc();
$old_status = $old_data['current_stage'] ?? '';
$old_recruiter_id = $old_data['assigned_recruiter_id'] ?? null;

// Start transaction
$conn->begin_transaction();

try {
    // Build update query dynamically based on what's provided
    $update_fields = [];
    $update_params = [];
    $types = "";
    
    if ($status !== '') {
        $update_fields[] = "current_stage = ?";
        $update_params[] = $status;
        $types .= "s";
    }
    
    if ($assigned_recruiter_id !== null) {
        $update_fields[] = "assigned_recruiter_id = ?";
        $update_params[] = $assigned_recruiter_id;
        $types .= "i";
        
        // If lead is being assigned, update assignment date
        if ($old_recruiter_id === null) {
            $update_fields[] = "assigned_at = NOW()";
        }
    }
    
    if ($interview_date !== '') {
        $update_fields[] = "interview_date = ?";
        $update_params[] = $interview_date;
        $types .= "s";
    }
    
    // Add last call date and call count if this is a call update
    if ($call_notes !== '' || $remarks !== '') {
        $update_fields[] = "last_call_date = NOW()";
        $update_fields[] = "call_count = call_count + 1";
    }
    
    // Always update updated_at
    $update_fields[] = "updated_at = NOW()";
    
    if (!empty($update_fields)) {
        $update_params[] = $lead_id;
        $types .= "i";
        
        $update_sql = "UPDATE leads SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $update = $conn->prepare($update_sql);
        $update->bind_param($types, ...$update_params);
        $update->execute();
    }
    
    // Add remark if provided
    if ($remarks !== '' || $call_notes !== '') {
        $remark_text = $remarks !== '' ? $remarks : $call_notes;
        $added_by_role = $recruiter_type === 'super' ? 'Super Admin' : 'Recruiter';
        
        $remark_stmt = $conn->prepare("
            INSERT INTO lead_remarks (lead_id, added_by, added_by_role, remark, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $remark_stmt->bind_param("isss", $lead_id, $user_id, $added_by_role, $remark_text);
        $remark_stmt->execute();
    }
    
    // Update recruiter stats if status changed to hired/deployed
    if (($status === 'hired' || $status === 'deployed') && ($old_status !== 'hired' && $old_status !== 'deployed')) {
        $recruiter_id = $assigned_recruiter_id !== null ? $assigned_recruiter_id : $user_id;
        $update_stats = $conn->prepare("
            UPDATE recruiters SET total_hired = total_hired + 1 
            WHERE user_id = ?
        ");
        $update_stats->bind_param("i", $recruiter_id);
        $update_stats->execute();
    }
    
    // Update recruiter stats if status changed to rejected/left/mock_rejected
    if (in_array($status, ['rejected', 'left', 'mock_rejected']) && !in_array($old_status, ['rejected', 'left', 'mock_rejected'])) {
        $recruiter_id = $assigned_recruiter_id !== null ? $assigned_recruiter_id : $user_id;
        $update_stats = $conn->prepare("
            UPDATE recruiters SET total_rejected = total_rejected + 1 
            WHERE user_id = ?
        ");
        $update_stats->bind_param("i", $recruiter_id);
        $update_stats->execute();
    }
    
    // Log audit
    $audit_notes = [];
    if ($status !== '' && $status !== $old_status) {
        $audit_notes[] = "Status changed from $old_status to $status";
    }
    if ($assigned_recruiter_id !== null && $assigned_recruiter_id != $old_recruiter_id) {
        // Get recruiter name
        $rec_name_query = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $rec_name_query->bind_param("i", $assigned_recruiter_id);
        $rec_name_query->execute();
        $rec_name_result = $rec_name_query->get_result();
        $rec_name = $rec_name_result->num_rows > 0 ? $rec_name_result->fetch_assoc()['full_name'] : "Recruiter #$assigned_recruiter_id";
        $audit_notes[] = "Assigned to: $rec_name";
    }
    if ($remarks !== '') {
        $audit_notes[] = "Remark: $remarks";
    }
    
    if (!empty($audit_notes)) {
        $audit_stmt = $conn->prepare("
            INSERT INTO lead_audit (lead_id, user_id, action, old_value, new_value, notes, created_at) 
            VALUES (?, ?, 'update', ?, ?, ?, NOW())
        ");
        $old_val = $old_status;
        $new_val = $status ?: $old_status;
        $notes = implode(" | ", $audit_notes);
        $audit_stmt->bind_param("iisss", $lead_id, $user_id, $old_val, $new_val, $notes);
        $audit_stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Prepare response message
    $message = [];
    if ($status !== '' && $status !== $old_status) {
        $message[] = "Status updated to " . ucfirst(str_replace('_', ' ', $status));
    }
    if ($assigned_recruiter_id !== null && $assigned_recruiter_id != $old_recruiter_id) {
        $message[] = "Lead assigned successfully";
    }
    if ($remarks !== '') {
        $message[] = "Remark added";
    }
    
    $response_message = !empty($message) ? implode(" & ", $message) : "Lead updated successfully";
    
    respond(true, [
        'message' => $response_message,
        'lead_id' => $lead_id,
        'old_status' => $old_status,
        'new_status' => $status ?: $old_status
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    respond(false, null, 'Database error: ' . $e->getMessage());
}

$conn->close();
?>