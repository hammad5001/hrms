<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$full_name = trim($data['full_name'] ?? '');
$phone     = preg_replace('/[^0-9]/', '', trim($data['phone'] ?? ''));
$position  = trim($data['position_applied'] ?? '');
$city      = trim($data['city'] ?? '');
$recruiter_id = isset($data['assigned_recruiter_id']) ? intval($data['assigned_recruiter_id']) : null;

if (!$full_name || !$phone) {
    respond(false, null, 'Name and Phone are required');
}

// Check for duplicates
$check = $conn->prepare("SELECT id FROM leads WHERE phone = ?");
$check->bind_param("s", $phone);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    respond(false, null, 'A lead with this phone number already exists');
}

$user_id = getCurrentUserId();
$is_super = isSuperRecruiter();
$company_branch = get_active_company_branch();

// Determine assigned recruiter
$assigned_to = null;
$stage = 'new';
$assigned_at = null;

if ($is_super && $recruiter_id) {
    $assigned_to = $recruiter_id;
    $stage = 'assigned';
    $assigned_at = 'NOW()';
} elseif (!$is_super) {
    // Recruiters who add their own leads get them auto-assigned
    $assigned_to = $user_id;
    $stage = 'assigned';
    $assigned_at = 'NOW()';
}

if ($assigned_at === 'NOW()') {
    $stmt = $conn->prepare("
        INSERT INTO leads (full_name, phone, position_applied, city, source, company_branch, current_stage, assigned_recruiter_id, assigned_at, created_at)
        VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param("ssssssi", $full_name, $phone, $position, $city, $company_branch, $stage, $assigned_to);
} else {
    $stmt = $conn->prepare("
        INSERT INTO leads (full_name, phone, position_applied, city, source, company_branch, current_stage, created_at)
        VALUES (?, ?, ?, ?, 'manual', ?, 'new', NOW())
    ");
    $stmt->bind_param("sssss", $full_name, $phone, $position, $city, $company_branch);
}

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    
    // Add an audit trail
    $user_name = getCurrentUserName();
    $audit = $conn->prepare("INSERT INTO lead_audit (lead_id, user_id, user_name, action, new_value, notes, created_at) VALUES (?, ?, ?, 'create', 'new', 'Manually added', NOW())");
    $audit->bind_param("iis", $new_id, $user_id, $user_name);
    $audit->execute();
    
    if ($assigned_to) {
        $stats_upd = $conn->prepare("UPDATE recruiters SET total_leads = total_leads + 1 WHERE user_id = ?");
        $stats_upd->bind_param("i", $assigned_to);
        $stats_upd->execute();
    }
    
    respond(true, ['lead_id' => $new_id], 'Lead added successfully');
} else {
    respond(false, null, 'Database error: ' . $conn->error);
}
?>
