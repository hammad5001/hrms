<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$user_id = getCurrentUserId();
$user_name = getCurrentUserName();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';
$portal_role = strtolower(trim((string)($_SESSION['portal_role'] ?? '')));
$is_admin_or_super = ($recruiter_type === 'super' || in_array($portal_role, ['super_admin', 'admin', 'hr'], true));
$is_global_super = isGlobalSuperAdmin();
$active_branch = get_active_company_branch();

// Branch scoping
$branch_req = trim($_GET['branch'] ?? $_POST['branch'] ?? '');
if ($is_admin_or_super) {
    if ($branch_req === 'all') {
        $selected_branch = null;
    } elseif ($branch_req !== '' && is_valid_company_branch($branch_req)) {
        $selected_branch = normalize_company_branch($branch_req);
    } else {
        $selected_branch = $active_branch;
    }
} else {
    $selected_branch = $active_branch;
}

// SQL conditions
$dialed_cond = "(call_count > 0 OR current_stage IN ('outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg','not_answered','callback') OR last_call_date IS NOT NULL)";
$remaining_cond = "(assigned_recruiter_id IS NOT NULL AND (call_count = 0 OR call_count IS NULL) AND current_stage IN ('new', 'assigned'))";
$scheduled_cond = "(current_stage = 'interview_scheduled')";
$appeared_cond = "(current_stage IN ('receptionist','interview_conducted','hr_passed','hr_rejected','selected','training','deployed','hired'))";
$hired_cond = "(current_stage IN ('training','deployed','hired'))";
$stale_cond = "(assigned_recruiter_id IS NOT NULL AND current_stage NOT IN ('hired','deployed','selected','rejected','left','mock_rejected','training') AND (call_count = 0 OR call_count IS NULL OR last_call_date < DATE_SUB(NOW(), INTERVAL 3 DAY)))";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Branch WHERE clauses
    $b_filter_leads = $selected_branch !== null ? "WHERE company_branch = ?" : "";
    $b_filter_users = $selected_branch !== null ? "AND u.company_branch = ?" : "";

    // 1. Unassigned leads pool count
    $q_un = $conn->prepare("
        SELECT COUNT(*) AS cnt 
        FROM leads 
        WHERE assigned_recruiter_id IS NULL AND current_stage = 'new' " . ($selected_branch !== null ? "AND company_branch = ?" : "") . "
    ");
    if ($selected_branch !== null) $q_un->bind_param("s", $selected_branch);
    $q_un->execute();
    $unassigned_count = (int)($q_un->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 2. Total stale leads across team
    $q_stale_tot = $conn->prepare("
        SELECT COUNT(*) AS cnt 
        FROM leads 
        WHERE $stale_cond " . ($selected_branch !== null ? "AND company_branch = ?" : "") . "
    ");
    if ($selected_branch !== null) $q_stale_tot->bind_param("s", $selected_branch);
    $q_stale_tot->execute();
    $total_stale_count = (int)($q_stale_tot->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 3. Recruiters detailed performance query
    // If regular recruiter, only their own row is returned
    $user_id_filter = !$is_admin_or_super ? " AND u.id = " . (int)$user_id : "";

    $sql_rec = "
        SELECT 
            u.id, 
            u.full_name, 
            u.status, 
            u.company_branch,
            COUNT(l.id) AS assigned,
            SUM(CASE WHEN l.id IS NOT NULL AND $dialed_cond THEN 1 ELSE 0 END) AS dialed,
            SUM(CASE WHEN l.id IS NOT NULL AND $remaining_cond THEN 1 ELSE 0 END) AS remaining,
            SUM(CASE WHEN l.id IS NOT NULL AND $scheduled_cond THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN l.id IS NOT NULL AND $appeared_cond THEN 1 ELSE 0 END) AS appeared,
            SUM(CASE WHEN l.id IS NOT NULL AND $hired_cond THEN 1 ELSE 0 END) AS hired,
            SUM(CASE WHEN l.id IS NOT NULL AND $stale_cond THEN 1 ELSE 0 END) AS stale_leads,
            MAX(COALESCE(rem.max_rem, aud.max_aud)) AS last_active
        FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        LEFT JOIN leads l ON l.assigned_recruiter_id = u.id " . ($selected_branch !== null ? "AND l.company_branch = u.company_branch" : "") . "
        LEFT JOIN (
            SELECT added_by, MAX(created_at) AS max_rem FROM lead_remarks GROUP BY added_by
        ) rem ON rem.added_by = u.id
        LEFT JOIN (
            SELECT user_id, MAX(created_at) AS max_aud FROM lead_audit GROUP BY user_id
        ) aud ON aud.user_id = u.id
        WHERE r.recruiter_type = 'regular' $b_filter_users $user_id_filter
        GROUP BY u.id
        ORDER BY hired DESC, scheduled DESC, dialed DESC, assigned DESC
    ";

    $stmt_rec = $conn->prepare($sql_rec);
    if ($selected_branch !== null) {
        $stmt_rec->bind_param("s", $selected_branch);
    }
    $stmt_rec->execute();
    $raw_recruiters = $stmt_rec->get_result()->fetch_all(MYSQLI_ASSOC);

    $recruiters = [];
    $total_assigned = 0;
    $total_hired = 0;
    $active_count = 0;
    $inactive_count = 0;

    foreach ($raw_recruiters as $r) {
        $ass = (int)($r['assigned'] ?? 0);
        $hi = (int)($r['hired'] ?? 0);
        $dia = (int)($r['dialed'] ?? 0);
        $rem = (int)($r['remaining'] ?? 0);
        $sch = (int)($r['scheduled'] ?? 0);
        $app = (int)($r['appeared'] ?? 0);
        $stale = (int)($r['stale_leads'] ?? 0);
        $conv = $ass > 0 ? round(($hi / $ass) * 100, 1) : 0;
        $dial_rate = $ass > 0 ? round(($dia / $ass) * 100, 1) : 0;

        if ($r['status'] === 'active') $active_count++;
        else $inactive_count++;

        $total_assigned += $ass;
        $total_hired += $hi;

        $recruiters[] = [
            'id'              => (int)$r['id'],
            'full_name'       => $r['full_name'],
            'status'          => $r['status'],
            'company_branch'  => $r['company_branch'],
            'branch_label'    => company_branch_label($r['company_branch']),
            'assigned'        => $ass,
            'dialed'          => $dia,
            'remaining'       => $rem,
            'scheduled'       => $sch,
            'appeared'        => $app,
            'hired'           => $hi,
            'stale_leads'     => $stale,
            'conversion_rate' => $conv,
            'dial_rate'       => $dial_rate,
            'last_active'     => $r['last_active']
        ];
    }

    $avg_conversion = $total_assigned > 0 ? round(($total_hired / $total_assigned) * 100, 1) : 0;

    respond(true, [
        'selected_branch'    => $selected_branch ?? 'all',
        'branch_label'       => $selected_branch ? company_branch_label($selected_branch) : 'All Branches',
        'available_branches' => COMPANY_BRANCHES,
        'summary'            => [
            'total_recruiters'   => count($recruiters),
            'active_recruiters'  => $active_count,
            'inactive_recruiters'=> $inactive_count,
            'unassigned_pool'    => $unassigned_count,
            'stale_pool'         => $total_stale_count,
            'avg_conversion'     => $avg_conversion
        ],
        'recruiters'         => $recruiters
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only Admin / Super / HR can perform distribution / reassignments
    if (!$is_admin_or_super) {
        respond(false, null, 'Unauthorized action');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    if ($action === 'assign_leads') {
        $recruiter_id = (int)($input['recruiter_id'] ?? 0);
        $count = (int)($input['count'] ?? 0);
        $branch = $input['branch'] ?? $active_branch;
        if ($branch === 'all' || !is_valid_company_branch($branch)) $branch = $active_branch;

        if (!$recruiter_id || $count <= 0) {
            respond(false, null, 'Invalid recruiter ID or count');
        }

        // Fetch recruiter info
        $q_rec = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? AND status = 'active'");
        $q_rec->bind_param("i", $recruiter_id);
        $q_rec->execute();
        $target_rec = $q_rec->get_result()->fetch_assoc();
        if (!$target_rec) {
            respond(false, null, 'Recruiter not found or inactive');
        }

        // Fetch unassigned leads
        $q_leads = $conn->prepare("
            SELECT id FROM leads 
            WHERE assigned_recruiter_id IS NULL AND current_stage = 'new' AND company_branch = ?
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $q_leads->bind_param("si", $branch, $count);
        $q_leads->execute();
        $lead_rows = $q_leads->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($lead_rows)) {
            respond(false, null, 'No unassigned leads found in this branch');
        }

        $assigned = 0;
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE leads SET assigned_recruiter_id = ?, current_stage = 'assigned', assigned_at = NOW(), updated_at = NOW() WHERE id = ?");
            $aud = $conn->prepare("INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at) VALUES (?, ?, ?, 'assign', 'new', 'assigned', ?, NOW())");
            $dist = $conn->prepare("INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at) VALUES (?, ?, ?, ?, ?, 'count', ?, ?, NOW())");
            $note = "Assigned {$target_rec['full_name']} by {$user_name}";

            foreach ($lead_rows as $row) {
                $lid = (int)$row['id'];
                $upd->bind_param("ii", $recruiter_id, $lid);
                $upd->execute();
                $aud->bind_param("iiss", $lid, $user_id, $user_name, $note);
                $aud->execute();
                $dist->bind_param("iisisss", $lid, $user_id, $user_name, $recruiter_id, $target_rec['full_name'], $branch, $note);
                $dist->execute();
                $assigned++;
            }

            // Update recruiter table
            $upd_rec = $conn->prepare("UPDATE recruiters SET total_leads = total_leads + ? WHERE user_id = ?");
            $upd_rec->bind_param("ii", $assigned, $recruiter_id);
            $upd_rec->execute();

            $conn->commit();
            respond(true, ['assigned' => $assigned, 'recruiter_name' => $target_rec['full_name']], "$assigned leads assigned to {$target_rec['full_name']}");
        } catch (Exception $e) {
            $conn->rollback();
            respond(false, null, 'Assignment error: ' . $e->getMessage());
        }

    } elseif ($action === 'reassign_stale') {
        // Reassign leads that haven't been dialed for > 3 days
        $target_recruiter_id = (int)($input['target_recruiter_id'] ?? 0); // optional specific recruiter, or equal distribution
        $from_recruiter_id = (int)($input['from_recruiter_id'] ?? 0); // optional specific source recruiter
        $branch = $input['branch'] ?? $active_branch;
        if ($branch === 'all' || !is_valid_company_branch($branch)) $branch = $active_branch;

        $from_filter = $from_recruiter_id > 0 ? "AND assigned_recruiter_id = $from_recruiter_id" : "";

        // Find stale leads
        $sql_stale = "
            SELECT id, assigned_recruiter_id FROM leads
            WHERE $stale_cond AND company_branch = ? $from_filter
            ORDER BY created_at ASC
            LIMIT 200
        ";
        $stmt_stale = $conn->prepare($sql_stale);
        $stmt_stale->bind_param("s", $branch);
        $stmt_stale->execute();
        $stale_leads = $stmt_stale->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($stale_leads)) {
            respond(false, null, 'No stale leads found to reassign in this branch');
        }

        // Active recruiters in branch
        $stmt_act = $conn->prepare("
            SELECT u.id, u.full_name FROM users u
            INNER JOIN recruiters r ON u.id = r.user_id
            WHERE u.status = 'active' AND r.recruiter_type = 'regular' AND u.company_branch = ?
            ORDER BY u.full_name ASC
        ");
        $stmt_act->bind_param("s", $branch);
        $stmt_act->execute();
        $active_rec_list = $stmt_act->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($active_rec_list)) {
            respond(false, null, 'No active recruiters found in this branch');
        }

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE leads SET assigned_recruiter_id = ?, current_stage = 'assigned', assigned_at = NOW(), updated_at = NOW() WHERE id = ?");
            $aud = $conn->prepare("INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at) VALUES (?, ?, ?, 'reassign_stale', 'stale', 'assigned', ?, NOW())");
            $dist = $conn->prepare("INSERT INTO lead_distribution_logs (lead_id, assigned_by_user_id, assigned_by_name, assigned_to_user_id, assigned_to_name, distribution_mode, company_branch, notes, created_at) VALUES (?, ?, ?, ?, ?, 'reassign_stale', ?, ?, NOW())");

            $reassigned = 0;
            $idx = 0;

            foreach ($stale_leads as $lead) {
                $lid = (int)$lead['id'];
                if ($target_recruiter_id > 0) {
                    $rid = $target_recruiter_id;
                    $rname = 'Selected Recruiter';
                } else {
                    $rec_item = $active_rec_list[$idx % count($active_rec_list)];
                    $rid = (int)$rec_item['id'];
                    $rname = $rec_item['full_name'];
                    $idx++;
                }

                $note = "Reassigned stale lead to $rname by $user_name";
                $upd->bind_param("ii", $rid, $lid);
                $upd->execute();
                $aud->bind_param("iiss", $lid, $user_id, $user_name, $note);
                $aud->execute();
                $dist->bind_param("iisisss", $lid, $user_id, $user_name, $rid, $rname, $branch, $note);
                $dist->execute();
                $reassigned++;
            }

            $conn->commit();
            respond(true, ['reassigned' => $reassigned], "$reassigned stale leads successfully reassigned!");
        } catch (Exception $e) {
            $conn->rollback();
            respond(false, null, 'Reassignment error: ' . $e->getMessage());
        }

    } else {
        respond(false, null, 'Invalid action specified');
    }
}
?>
