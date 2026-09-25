<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: Super Admin only');
}

$user_id = getCurrentUserId();
$user_name = getCurrentUserName();
$active_branch = get_active_company_branch();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return unassigned leads with candidate info for the active branch
    $stmt = $conn->prepare("
        SELECT id, external_lead_id, source, cv_file_url, full_name, phone, email, city, position_applied, current_stage, created_at
        FROM leads
        WHERE assigned_recruiter_id IS NULL AND current_stage = 'new' AND company_branch = ?
        ORDER BY created_at DESC
        LIMIT 300
    ");
    $stmt->bind_param("s", $active_branch);
    $stmt->execute();
    $unassigned_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    respond(true, [
        'unassigned_leads' => $unassigned_list,
        'total' => count($unassigned_list)
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);
$mode = $data['mode'] ?? 'count';

if ($mode === 'count') {
    // Assign N unassigned leads to a specific recruiter
    $recruiter_id = intval($data['recruiter_id'] ?? 0);
    $count        = intval($data['count'] ?? 0);

    if (!$recruiter_id || $count < 1) {
        respond(false, null, 'recruiter_id and count are required');
    }

    $active_branch = get_active_company_branch();
    // Verify recruiter is active and belongs to the same branch
    $check = $conn->prepare("
        SELECT u.id, u.full_name FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        WHERE u.id = ? AND u.status = 'active' AND r.recruiter_type = 'regular' AND u.company_branch = ?
    ");
    $check->bind_param("is", $recruiter_id, $active_branch);
    $check->execute();
    $rec_result = $check->get_result();
    if ($rec_result->num_rows === 0) {
        respond(false, null, 'Recruiter not found or inactive in this branch');
    }
    $rec = $rec_result->fetch_assoc();

    // Get N unassigned leads for this branch
    $leads_stmt = $conn->prepare("
        SELECT id FROM leads 
        WHERE assigned_recruiter_id IS NULL AND current_stage = 'new' AND company_branch = ?
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $leads_stmt->bind_param("si", $active_branch, $count);
    $leads_stmt->execute();
    $leads_result = $leads_stmt->get_result();

    $assigned = 0;
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("
            UPDATE leads 
            SET assigned_recruiter_id = ?, current_stage = 'assigned', assigned_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $audit = $conn->prepare("
            INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at)
            VALUES (?, ?, ?, 'assign', 'new', 'assigned', ?, NOW())
        ");
        $note = "Assigned to {$rec['full_name']} by $user_name";

        $dist_log = $conn->prepare("
            INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at)
            VALUES (?, ?, ?, ?, ?, 'count', ?, ?, NOW())
        ");

        while ($row = $leads_result->fetch_assoc()) {
            $lid = $row['id'];
            $upd->bind_param("ii", $recruiter_id, $lid);
            $upd->execute();
            $audit->bind_param("iiss", $lid, $user_id, $user_name, $note);
            $audit->execute();
            $dist_log->bind_param("iisisss", $lid, $user_id, $user_name, $recruiter_id, $rec['full_name'], $active_branch, $note);
            $dist_log->execute();
            $assigned++;
        }

        // Update recruiter stats
        $stats_upd = $conn->prepare("UPDATE recruiters SET total_leads = total_leads + ? WHERE user_id = ?");
        $stats_upd->bind_param("ii", $assigned, $recruiter_id);
        $stats_upd->execute();

        $conn->commit();
        respond(true, ['assigned' => $assigned, 'recruiter_name' => $rec['full_name']],
                "$assigned leads assigned to {$rec['full_name']}");
    } catch (Exception $e) {
        $conn->rollback();
        respond(false, null, 'Assignment failed: ' . $e->getMessage());
    }

} elseif ($mode === 'manual') {
    // Assign specific lead IDs to specific recruiters
    $assignments = $data['assignments'] ?? [];
    if (empty($assignments)) {
        respond(false, null, 'No assignments provided');
    }

    $active_branch = get_active_company_branch();
    $assigned = 0;
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("
            UPDATE leads 
            SET assigned_recruiter_id = ?, current_stage = 'assigned', assigned_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $audit = $conn->prepare("
            INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at)
            VALUES (?, ?, ?, 'assign', 'new', 'assigned', ?, NOW())
        ");
        $dist_log = $conn->prepare("
            INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at)
            VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, NOW())
        ");

        // Cache recruiter names
        $rec_names = [];

        foreach ($assignments as $a) {
            $lead_id = intval($a['lead_id'] ?? 0);
            $rec_id  = intval($a['recruiter_id'] ?? 0);
            if (!$lead_id || !$rec_id) continue;

            if (!isset($rec_names[$rec_id])) {
                $q = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                $q->bind_param("i", $rec_id);
                $q->execute();
                $rname = $q->get_result()->fetch_assoc()['full_name'] ?? 'Recruiter';
                $rec_names[$rec_id] = $rname;
            }
            $target_name = $rec_names[$rec_id];

            $upd->bind_param("ii", $rec_id, $lead_id);
            $upd->execute();

            $note = "Manually assigned to $target_name by $user_name";
            $audit->bind_param("iiss", $lead_id, $user_id, $user_name, $note);
            $audit->execute();

            $dist_log->bind_param("iisisss", $lead_id, $user_id, $user_name, $rec_id, $target_name, $active_branch, $note);
            $dist_log->execute();

            $assigned++;
        }

        $conn->commit();
        respond(true, ['assigned' => $assigned], "$assigned leads assigned successfully");
    } catch (Exception $e) {
        $conn->rollback();
        respond(false, null, 'Assignment failed: ' . $e->getMessage());
    }

} elseif ($mode === 'equal') {
    $active_branch = get_active_company_branch();
    // Distribute all unassigned leads equally among all active recruiters in the branch
    $active_recs = $conn->prepare("
        SELECT u.id, u.full_name FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        WHERE u.status = 'active' AND r.recruiter_type = 'regular' AND u.company_branch = ?
        ORDER BY u.full_name ASC
    ");
    $active_recs->bind_param("s", $active_branch);
    $active_recs->execute();
    $rec_result = $active_recs->get_result();
    $rec_list = [];
    while ($r = $rec_result->fetch_assoc()) $rec_list[] = $r;

    if (empty($rec_list)) {
        respond(false, null, 'No active recruiters found in this branch');
    }

    $unassigned = $conn->prepare("SELECT id FROM leads WHERE assigned_recruiter_id IS NULL AND current_stage = 'new' AND company_branch = ? ORDER BY created_at ASC");
    $unassigned->bind_param("s", $active_branch);
    $unassigned->execute();
    $unassigned_result = $unassigned->get_result();
    $lead_ids = [];
    while ($row = $unassigned_result->fetch_assoc()) $lead_ids[] = $row['id'];

    if (empty($lead_ids)) {
        respond(false, null, 'No unassigned leads to distribute');
    }

    $conn->begin_transaction();
    try {
        $upd   = $conn->prepare("UPDATE leads SET assigned_recruiter_id=?, current_stage='assigned', assigned_at=NOW(), updated_at=NOW() WHERE id=?");
        $audit = $conn->prepare("
            INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at)
            VALUES (?, ?, ?, 'assign', 'new', 'assigned', ?, NOW())
        ");
        $dist_log = $conn->prepare("
            INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at)
            VALUES (?, ?, ?, ?, ?, 'equal', ?, ?, NOW())
        ");

        $idx   = 0;
        $counts = array_fill(0, count($rec_list), 0);

        foreach ($lead_ids as $lid) {
            $rec_idx = $idx % count($rec_list);
            $rid = $rec_list[$rec_idx]['id'];
            $rname = $rec_list[$rec_idx]['full_name'];

            $upd->bind_param("ii", $rid, $lid);
            $upd->execute();

            $note = "Auto-distributed to $rname by $user_name";
            $audit->bind_param("iiss", $lid, $user_id, $user_name, $note);
            $audit->execute();

            $dist_log->bind_param("iisisss", $lid, $user_id, $user_name, $rid, $rname, $active_branch, $note);
            $dist_log->execute();

            $counts[$rec_idx]++;
            $idx++;
        }

        // Update stats
        $stats_upd = $conn->prepare("UPDATE recruiters SET total_leads = total_leads + ? WHERE user_id = ?");
        foreach ($rec_list as $i => $rec) {
            if ($counts[$i] > 0) {
                $stats_upd->bind_param("ii", $counts[$i], $rec['id']);
                $stats_upd->execute();
            }
        }

        $conn->commit();
        respond(true, ['total_distributed' => count($lead_ids), 'recruiter_count' => count($rec_list)],
                count($lead_ids) . " leads distributed equally among " . count($rec_list) . " recruiters");
    } catch (Exception $e) {
        $conn->rollback();
        respond(false, null, 'Distribution failed: ' . $e->getMessage());
    }
} else {
    respond(false, null, 'Invalid mode. Use: count, manual, or equal');
}
?>
