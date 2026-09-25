<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = intval($data['lead_id'] ?? 0);
if (!$lead_id) respond(false, null, 'lead_id required');

$user_id        = getCurrentUserId();
$user_name      = getCurrentUserName();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

// Access check for regular recruiters
if ($recruiter_type !== 'super') {
    $access = $conn->prepare("SELECT current_stage FROM leads WHERE id = ? AND assigned_recruiter_id = ?");
    $access->bind_param("ii", $lead_id, $user_id);
    $access->execute();
    $access_result = $access->get_result();
    if ($access_result->num_rows === 0) {
        respond(false, null, 'Access denied: lead not assigned to you');
    }
    $current_data = $access_result->fetch_assoc();
    // Block re-editing final statuses
    if (in_array($current_data['current_stage'], ['hired', 'gm_passed', 'hr_passed'])) {
        respond(false, null, 'Cannot edit a lead that is already ' . $current_data['current_stage']);
    }
}

// Get old state for audit
$old_stmt = $conn->prepare("SELECT current_stage, assigned_recruiter_id FROM leads WHERE id = ?");
$old_stmt->bind_param("i", $lead_id);
$old_stmt->execute();
$old_data = $old_stmt->get_result()->fetch_assoc();
$old_status     = $old_data['current_stage'] ?? '';
$old_recruiter  = $old_data['assigned_recruiter_id'] ?? null;

// Build dynamic update
$fields   = [];
$params   = [];
$types    = "";
$audit    = [];

$allowed_fields = ['full_name','phone','email','cnic','city','dob','education','position_applied','referred_by'];
foreach ($allowed_fields as $field) {
    if (isset($data[$field])) {
        $fields[] = "$field = ?";
        $params[]  = $data[$field];
        $types    .= "s";
    }
}

if (isset($data['current_stage']) && $data['current_stage'] !== '') {
    $new_stage = canonical_stage((string)$data['current_stage']);
    if ($new_stage !== canonical_stage((string)$old_status) && !stage_transition_allowed((string)$old_status, $new_stage)) {
        respond(false, ['from' => $old_status, 'to' => $new_stage], 'Invalid stage transition');
    }
    $fields[]  = "current_stage = ?";
    $params[]   = $new_stage;
    $types     .= "s";
    if ($new_stage !== canonical_stage((string)$old_status)) {
        $audit[] = "Stage: $old_status → $new_stage";
    }
}

if ($recruiter_type === 'super' && isset($data['assigned_recruiter_id'])) {
    $new_rec_id = $data['assigned_recruiter_id'] ? intval($data['assigned_recruiter_id']) : null;
    $fields[]   = "assigned_recruiter_id = ?";
    $params[]    = $new_rec_id;
    $types      .= "i";
    if ($new_rec_id !== $old_recruiter && $new_rec_id) {
        $fields[] = "assigned_at = NOW()";
        $audit[]  = "Recruiter changed";
        
        // Fetch new recruiter name
        $q_rn = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $q_rn->bind_param("i", $new_rec_id);
        $q_rn->execute();
        $rn_res = $q_rn->get_result()->fetch_assoc();
        $new_r_name = $rn_res['full_name'] ?? 'Recruiter';
        $active_branch = get_active_company_branch();
        $dist_note = "Re-assigned to $new_r_name by $user_name";

        $dist_log = $conn->prepare("
            INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at)
            VALUES (?, ?, ?, ?, ?, 'reassign', ?, ?, NOW())
        ");
        $dist_log->bind_param("iisisss", $lead_id, $user_id, $user_name, $new_rec_id, $new_r_name, $active_branch, $dist_note);
        $dist_log->execute();
    }
}

if (isset($data['interview_date'])) {
    $fields[] = "interview_date = ?";
    $params[]  = $data['interview_date'] ?: null;
    $types    .= "s";
}

$is_call_update = !empty($data['remark']) || !empty($data['call_notes']);
if ($is_call_update) {
    $fields[] = "last_call_date = NOW()";
    $fields[] = "call_count = call_count + 1";
}

$fields[] = "updated_at = NOW()";

$conn->begin_transaction();
try {
    if (!empty(array_filter($fields, fn($f) => strpos($f, 'NOW()') === false || strpos($f, '=') !== false))) {
        if (!empty($params)) {
            $params[]  = $lead_id;
            $types    .= "i";
            $sql       = "UPDATE leads SET " . implode(", ", $fields) . " WHERE id = ?";
            $upd       = $conn->prepare($sql);
            bindParams($upd, $types, $params);
            $upd->execute();
        } else {
            // Only NOW() fields
            $sql = "UPDATE leads SET " . implode(", ", $fields) . " WHERE id = ?";
            $conn->query(str_replace('?', $lead_id, $sql));
        }
    }

    // Add remark if provided
    $remark_text = trim($data['remark'] ?? $data['call_notes'] ?? '');
    if ($remark_text) {
        $role_label = $recruiter_type === 'super' ? 'Super Admin' : 'Recruiter';
        $rem_stmt = $conn->prepare("
            INSERT INTO lead_remarks (lead_id, added_by, added_by_name, added_by_role, remark, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $rem_stmt->bind_param("iisss", $lead_id, $user_id, $user_name, $role_label, $remark_text);
        $rem_stmt->execute();
        $audit[] = "Remark added";
    }

    // Audit log
    if (!empty($audit)) {
        $notes     = implode(" | ", $audit);
        $new_stage = $data['current_stage'] ?? $old_status;
        $aud_stmt  = $conn->prepare("
            INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at)
            VALUES (?, ?, ?, 'update', ?, ?, ?, NOW())
        ");
        $aud_stmt->bind_param("iissss", $lead_id, $user_id, $user_name, $old_status, $new_stage, $notes);
        $aud_stmt->execute();
    }

    // Auto-create or ensure scheduled interview record in interviews table for Reception Portal
    $new_stage = canonical_stage((string)($data['current_stage'] ?? $old_status));
    if ($new_stage === 'interview_scheduled') {
        $int_date = !empty($data['interview_date']) ? $data['interview_date'] : date('Y-m-d');
        $int_time = !empty($data['interview_time']) ? $data['interview_time'] : '10:00';
        $active_b = get_active_company_branch();
        
        $chk_int = $conn->prepare("SELECT id FROM interviews WHERE lead_id = ? AND status = 'scheduled' LIMIT 1");
        $chk_int->bind_param("i", $lead_id);
        $chk_int->execute();
        $int_res = $chk_int->get_result();
        if ($int_res->num_rows === 0) {
            $ins_int = $conn->prepare("
                INSERT INTO interviews (lead_id, scheduled_by, scheduled_date, scheduled_time, location, interviewer_name, status, company_branch, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'Reception', 'HR Manager', 'scheduled', ?, NOW(), NOW())
            ");
            $ins_int->bind_param("iisss", $lead_id, $user_id, $int_date, $int_time, $active_b);
            $ins_int->execute();
        }
    }

    // Update recruiter hired/rejected stats
    if ($new_stage === 'hired' && $old_status !== 'hired') {
        $r_id = $data['assigned_recruiter_id'] ?? $old_recruiter ?? $user_id;
        $s = $conn->prepare("UPDATE recruiters SET total_hired = total_hired + 1 WHERE user_id = ?");
        $s->bind_param("i", $r_id);
        $s->execute();
    }

    $conn->commit();
    respond(true, ['lead_id' => $lead_id, 'message' => 'Lead updated successfully']);
} catch (Exception $e) {
    $conn->rollback();
    respond(false, null, 'Update failed: ' . $e->getMessage());
}
?>
