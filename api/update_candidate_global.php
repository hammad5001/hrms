<?php
require_once 'config.php';
require_once __DIR__ . '/../includes/sheets_mirror_helper.php';

$data = json_decode(file_get_contents('php://input'), true);

$id = isset($data['id']) ? intval($data['id']) : 0;
$phone = isset($data['phone']) ? preg_replace('/[^0-9]/', '', $data['phone']) : '';
$new_stage = canonical_stage((string)($data['new_stage'] ?? ''));
$remark = $data['remark'] ?? '';
$action = $data['action'] ?? 'update'; // e.g., 'agent_checkin', 'hr_pass', 'hired'

// Additional fields for walk-ins or full updates
$full_name = $data['fullName'] ?? 'Unknown';
$father_name = $data['fatherName'] ?? '';
$email = $data['email'] ?? '';
$cnic = $data['cnic'] ?? '';
$city = $data['city'] ?? '';
$dob = $data['dob'] ?? '';
$education = $data['graduation'] ?? '';
$position = $data['position'] ?? 'Unknown';
$referred_by = $data['referredBy'] ?? 'Walk-in';

// If it's a walk-in from agent, we might not have ID, but we have phone
if (!$id && !$phone) {
    respond(false, null, 'ID or Phone is required');
}

// Find candidate
if ($id) {
    $stmt = $conn->prepare("SELECT id, current_stage FROM leads WHERE id = ?");
    $stmt->bind_param("i", $id);
} else {
    $stmt = $conn->prepare("SELECT id, current_stage FROM leads WHERE phone = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("s", $phone);
}
$stmt->execute();
$res = $stmt->get_result();

$user_id = getCurrentUserId() ?: 0;
$user_name = getCurrentUserName() ?: 'System';

if ($res->num_rows > 0) {
    // Update existing lead
    $lead = $res->fetch_assoc();
    $lead_id = $lead['id'];
    $old_stage = $lead['current_stage'];
    
    if ($new_stage) {
        $new_stage = canonical_stage($new_stage);
        if (!stage_transition_allowed($old_stage, $new_stage)) {
            respond(false, ['from' => $old_stage, 'to' => $new_stage], 'Invalid stage transition');
        }
        $branch = get_active_company_branch();
        $upd = $conn->prepare("UPDATE leads SET current_stage = ?, company_branch = ?, updated_at = NOW() WHERE id = ?");
        $upd->bind_param("ssi", $new_stage, $branch, $lead_id);
        $upd->execute();
        
        $audit = $conn->prepare("INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Updated via Portal Sync', NOW())");
        $audit->bind_param("iissss", $lead_id, $user_id, $user_name, $action, $old_stage, $new_stage);
        $audit->execute();
    }
} else {
    // Walk-in candidate not found in Recruiter DB. Create it!
    $actionName = (string)($data['action'] ?? '');
    if ($actionName === 'mobile_apply') {
        $source = 'mobile';
        if (!$new_stage) {
            $new_stage = 'interview_scheduled';
        }
    } else {
        $source = !empty($data['source']) ? (string)$data['source'] : 'walkin';
        if (!$new_stage) {
            $new_stage = 'receptionist';
        }
    }
    
    $branch = get_active_company_branch();
    $ins = $conn->prepare("INSERT INTO leads (full_name, father_name, phone, email, cnic, city, dob, education, position_applied, referred_by, source, company_branch, current_stage, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $ins->bind_param("sssssssssssss", $full_name, $father_name, $phone, $email, $cnic, $city, $dob, $education, $position, $referred_by, $source, $branch, $new_stage);
    $ins->execute();
    $lead_id = $conn->insert_id;
    
    $audit = $conn->prepare("INSERT INTO lead_audit (lead_id, user_id, user_name, action, new_value, notes, created_at) VALUES (?, ?, ?, 'create', ?, 'Walk-in registration', NOW())");
    $audit->bind_param("iiss", $lead_id, $user_id, $user_name, $new_stage);
    $audit->execute();
}

// Add remark if provided
if ($remark) {
    $role = $data['user_role'] ?? 'Agent/HR';
    $rem = $conn->prepare("INSERT INTO lead_remarks (lead_id, added_by, added_by_name, added_by_role, remark, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $rem->bind_param("iisss", $lead_id, $user_id, $user_name, $role, $remark);
    $rem->execute();
}

// Mirror latest state to Sheets (best effort, non-blocking)
try {
    $s = $conn->prepare("SELECT id, full_name, father_name, phone, email, cnic, city, dob, education, position_applied, referred_by, current_stage, company_branch
                         FROM leads WHERE id = ? LIMIT 1");
    $s->bind_param('i', $lead_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    if ($row) {
        $row['status'] = canonical_stage((string)$row['current_stage']);
        mirror_candidate_update_to_sheets($row, $action ?: 'update');
    }
} catch (Throwable $e) {
    // ignore mirror errors, DB remains source of truth
}

respond(true, ['lead_id' => $lead_id, 'status' => $new_stage], 'Candidate updated successfully');
?>
