<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: Super Admin only');
}

$data = json_decode(file_get_contents('php://input'), true);
$recruiter_user_id = intval($data['recruiter_id'] ?? 0);
$status = $data['status'] ?? '';

if (!$recruiter_user_id || !in_array($status, ['active', 'inactive'])) {
    respond(false, null, 'recruiter_id and valid status required');
}

// Cannot deactivate yourself
if ($recruiter_user_id === getCurrentUserId()) {
    respond(false, null, 'Cannot change your own status');
}

// Update user status
$stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->bind_param("si", $status, $recruiter_user_id);

if ($stmt->execute()) {
    $name_q = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $name_q->bind_param("i", $recruiter_user_id);
    $name_q->execute();
    $name_data = $name_q->get_result()->fetch_assoc();
    respond(true, ['status' => $status, 'name' => $name_data['full_name'] ?? ''], 
            "Recruiter " . ($name_data['full_name'] ?? '') . " set to $status");
} else {
    respond(false, null, 'Database error: ' . $conn->error);
}
?>
