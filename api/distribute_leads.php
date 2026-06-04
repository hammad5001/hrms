<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: Super Admin only');
}

$data = json_decode(file_get_contents('php://input'), true);
$mode = $data['mode'] ?? 'count'; // 'count' = assign N leads, 'manual' = specific lead IDs

$user_id = getCurrentUserId();
$user_name = getCurrentUserName();

if ($mode === 'count') {
    // Assign N unassigned leads to a specific recruiter
    $recruiter_id = intval($data['recruiter_id'] ?? 0);
    $count        = intval($data['count'] ?? 0);

    if (!$recruiter_id || $count < 1) {
        respond(false, null, 'recruiter_id and count are required');
    }

    // Verify recruiter is active
    $check = $conn->prepare("
        SELECT u.id, u.full_name FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        WHERE u.id = ? AND u.status = 'active' AND r.recruiter_type = 'regular'
    ");
    $check->bind_param("i", $recruiter_id);
    $check->execute();
    $rec_result = $check->get_result();
    if ($rec_result->num_rows === 0) {
        respond(false, null, 'Recruiter not found or inactive');
    }
    $rec = $rec_result->fetch_assoc();

    // Get N unassigned leads
    $leads_stmt = $conn->prepare("
        SELECT id FROM leads 
        WHERE assigned_recruiter_id IS NULL AND current_stage = 'new'
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $leads_stmt->bind_param("i", $count);
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

        while ($row = $leads_result->fetch_assoc()) {
            $lid = $row['id'];
            $upd->bind_param("ii", $recruiter_id, $lid);
            $upd->execute();
            $audit->bind_param("iiss", $lid, $user_id, $user_name, $note);
            $audit->execute();
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

        foreach ($assignments as $a) {
            $lead_id = intval($a['lead_id'] ?? 0);
            $rec_id  = intval($a['recruiter_id'] ?? 0);
            if (!$lead_id || !$rec_id) continue;

            $upd->bind_param("ii", $rec_id, $lead_id);
            $upd->execute();

            $note = "Manually assigned by $user_name";
            $audit->bind_param("iiss", $lead_id, $user_id, $user_name, $note);
            $audit->execute();
            $assigned++;
        }

        $conn->commit();
        respond(true, ['assigned' => $assigned], "$assigned leads assigned successfully");
    } catch (Exception $e) {
        $conn->rollback();
        respond(false, null, 'Assignment failed: ' . $e->getMessage());
    }

} elseif ($mode === 'equal') {
    // Distribute all unassigned leads equally among all active recruiters
    $active_recs = $conn->query("
        SELECT u.id, u.full_name FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        WHERE u.status = 'active' AND r.recruiter_type = 'regular'
        ORDER BY u.full_name ASC
    ");
    $rec_list = [];
    while ($r = $active_recs->fetch_assoc()) $rec_list[] = $r;

    if (empty($rec_list)) {
        respond(false, null, 'No active recruiters found');
    }

    $unassigned = $conn->query("SELECT id FROM leads WHERE assigned_recruiter_id IS NULL AND current_stage = 'new' ORDER BY created_at ASC");
    $lead_ids = [];
    while ($row = $unassigned->fetch_assoc()) $lead_ids[] = $row['id'];

    if (empty($lead_ids)) {
        respond(false, null, 'No unassigned leads to distribute');
    }

    $conn->begin_transaction();
    try {
        $upd   = $conn->prepare("UPDATE leads SET assigned_recruiter_id=?, current_stage='assigned', assigned_at=NOW(), updated_at=NOW() WHERE id=?");
        $idx   = 0;
        $counts = array_fill(0, count($rec_list), 0);

        foreach ($lead_ids as $lid) {
            $rec_idx = $idx % count($rec_list);
            $rid = $rec_list[$rec_idx]['id'];
            $upd->bind_param("ii", $rid, $lid);
            $upd->execute();
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
