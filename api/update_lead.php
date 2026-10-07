<?php
require_once 'config.php';
require_once __DIR__ . '/../includes/company_branches.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = intval($data['lead_id'] ?? 0);
if (!$lead_id) respond(false, null, 'lead_id required');

$user_id        = getCurrentUserId();
$user_name      = getCurrentUserName();
$is_admin_or_super = isSuperRecruiter();
$active_branch  = get_active_company_branch();

// Access check for regular recruiters
if (!$is_admin_or_super) {
    $access = $conn->prepare("SELECT current_stage FROM leads WHERE id = ? AND assigned_recruiter_id = ? AND company_branch = ?");
    $access->bind_param("iis", $lead_id, $user_id, $active_branch);
    $access->execute();
    $access_result = $access->get_result();
    if ($access_result->num_rows === 0) {
        respond(false, null, 'Access denied: lead not assigned to you in your branch');
    }
    $current_data = $access_result->fetch_assoc();
    // Block re-editing final statuses
    if (in_array($current_data['current_stage'], ['hired', 'gm_passed', 'hr_passed'])) {
        respond(false, null, 'Cannot edit a lead that is already ' . $current_data['current_stage']);
    }
} elseif (!isGlobalSuperAdmin()) {
    // Branch Admin / HR restricted to their branch
    $access = $conn->prepare("SELECT current_stage FROM leads WHERE id = ? AND company_branch = ?");
    $access->bind_param("is", $lead_id, $active_branch);
    $access->execute();
    if ($access->get_result()->num_rows === 0) {
        respond(false, null, 'Access denied: lead belongs to another branch');
    }
}

// Get old state for audit
$old_stmt = $conn->prepare("SELECT current_stage, assigned_recruiter_id, company_branch FROM leads WHERE id = ?");
$old_stmt->bind_param("i", $lead_id);
$old_stmt->execute();
$old_data = $old_stmt->get_result()->fetch_assoc();
$old_status     = $old_data['current_stage'] ?? '';
$old_recruiter  = $old_data['assigned_recruiter_id'] ?? null;
$old_branch     = $old_data['company_branch'] ?? get_active_company_branch();

// Build dynamic update (Personal data is protected and immutable)
$fields   = [];
$params   = [];
$types    = "";
$audit    = [];

// Non-sensitive/workflow fields allowed to be updated
$allowed_fields = ['rejection_reason', 'next_callback_date', 'notes'];
foreach ($allowed_fields as $field) {
    if (isset($data[$field])) {
        $fields[] = "$field = ?";
        $params[]  = $data[$field];
        $types    .= "s";
    }
}

$new_stage = null;
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

// Branch Referral / Transfer
if (!empty($data['target_branch']) && is_valid_company_branch($data['target_branch'])) {
    $target_branch = normalize_company_branch($data['target_branch']);
    if ($target_branch !== $old_branch) {
        $fields[] = "company_branch = ?";
        $params[] = $target_branch;
        $types   .= "s";

        // Unassign current recruiter so target branch management can distribute
        $fields[] = "assigned_recruiter_id = NULL";
        $fields[] = "assigned_at = NULL";
        $audit[]  = "Branch Referred: $old_branch → $target_branch";

        $b_old_lbl = company_branch_label($old_branch);
        $b_new_lbl = company_branch_label($target_branch);
        $dist_note = "Branch referral from $b_old_lbl to $b_new_lbl by $user_name";
        if (!empty($data['remark'])) {
            $dist_note .= " | Note: " . trim($data['remark']);
        }
        $dlog = $conn->prepare("
            INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at)
            VALUES (?, ?, ?, NULL, 'Target Branch Pool', 'branch_referral', ?, ?, NOW())
        ");
        $dlog->bind_param("iisss", $lead_id, $user_id, $user_name, $target_branch, $dist_note);
        $dlog->execute();
    }
}

if ($is_admin_or_super && isset($data['assigned_recruiter_id'])) {
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

// Track outreach call count & timestamp
$is_call_update = !empty($data['remark']) || !empty($data['call_notes']);
$is_outreach_stage = in_array($new_stage ?? '', ['outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'not_answered', 'callback'], true);

if ($is_call_update || $is_outreach_stage) {
    $fields[] = "last_call_date = NOW()";
    $fields[] = "call_count = call_count + 1";
    if ($is_outreach_stage) {
        $fields[] = "last_call_status = ?";
        $params[] = $new_stage;
        $types   .= "s";
    }
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
        $final_st  = $new_stage ?? $old_status;
        $aud_stmt  = $conn->prepare("
            INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at)
            VALUES (?, ?, ?, 'update', ?, ?, ?, NOW())
        ");
        $aud_stmt->bind_param("iissss", $lead_id, $user_id, $user_name, $old_status, $final_st, $notes);
        $aud_stmt->execute();
    }

    // Auto-create or ensure scheduled interview record in interviews table for Reception Portal
    $effective_stage = canonical_stage((string)($new_stage ?? $old_status));
    if ($effective_stage === 'interview_scheduled') {
        $int_date = !empty($data['interview_date']) ? $data['interview_date'] : date('Y-m-d');
        $int_time = !empty($data['interview_time']) ? $data['interview_time'] : '10:00';
        $active_b = !empty($data['target_branch']) ? normalize_company_branch($data['target_branch']) : ($old_branch ?? get_active_company_branch());
        
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
        } else {
            $ex_row = $int_res->fetch_assoc();
            $upd_int = $conn->prepare("UPDATE interviews SET scheduled_date = ?, scheduled_time = ?, company_branch = ?, updated_at = NOW() WHERE id = ?");
            $upd_int->bind_param("sssi", $int_date, $int_time, $active_b, $ex_row['id']);
            $upd_int->execute();
        }
    }

    // Update recruiter hired/rejected stats
    if ($new_stage === 'hired' && $old_status !== 'hired') {
        $r_id = $data['assigned_recruiter_id'] ?? $old_recruiter ?? $user_id;
        $s = $conn->prepare("UPDATE recruiters SET total_hired = total_hired + 1 WHERE user_id = ?");
        $s->bind_param("i", $r_id);
        $s->execute();
    }

    // Auto-sync status, remarks, and rejection to website intake & sheets
    try {
        require_once __DIR__ . '/../includes/sheets_mirror_helper.php';
        $sync_st = $conn->prepare("SELECT id, external_lead_id, full_name, father_name, phone, email, cnic, city, dob, education, position_applied, referred_by, current_stage, company_branch, rejection_reason FROM leads WHERE id = ? LIMIT 1");
        $sync_st->bind_param("i", $lead_id);
        $sync_st->execute();
        $sync_row = $sync_st->get_result()->fetch_assoc();
        if ($sync_row) {
            $sync_row['remark'] = $remark_text;
            mirror_candidate_update_to_sheets($sync_row, 'lead_update');
        }
    } catch (Throwable $e) {
        // Non-blocking background sync
    }

    $conn->commit();
    respond(true, ['lead_id' => $lead_id, 'message' => 'Lead updated successfully']);
} catch (Exception $e) {
    $conn->rollback();
    respond(false, null, 'Update failed: ' . $e->getMessage());
}
?>
