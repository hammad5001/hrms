<?php
/**
 * Unified reception queue: recruiter-scheduled interviews + interview_scheduled leads + form submissions.
 */
require_once __DIR__ . '/config.php';

header('Cache-Control: private, max-age=5');

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$branch = get_active_company_branch();
$portal_role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
$is_super = isGlobalSuperAdmin();
$is_reception = in_array($portal_role, ['receptionist', 'agent', 'admin', 'super_admin'], true);

$branch_clause = "";
$params = [];
$types = "";

if (!$is_super) {
    $branch_clause = " AND (l.company_branch = ? OR l.company_branch IS NULL OR TRIM(l.company_branch) = '' OR l.company_branch = 'main') ";
    $params[] = $branch;
    $types .= "s";
}

$sql = "
    SELECT
        l.id AS lead_id,
        l.full_name,
        l.father_name,
        l.phone,
        l.email,
        l.cnic,
        l.city,
        l.dob,
        l.education,
        l.position_applied,
        l.referred_by,
        l.source,
        l.current_stage,
        l.interview_date,
        l.company_branch,
        l.created_at AS lead_created_at,
        l.updated_at AS lead_updated_at,
        u.full_name AS recruiter_name,
        i.id AS interview_id,
        i.scheduled_date,
        i.scheduled_time,
        i.location AS interview_location,
        i.interviewer_name,
        i.status AS interview_status,
        i.notes AS interview_notes
    FROM leads l
    LEFT JOIN users u ON u.id = l.assigned_recruiter_id
    LEFT JOIN interviews i ON i.lead_id = l.id AND i.status = 'scheduled'
    WHERE (
        l.current_stage = 'interview_scheduled'
        OR i.id IS NOT NULL
        OR (
            l.source IN ('mobile', 'walkin', 'public', 'walk-in')
            AND l.current_stage IN ('new', 'assigned', 'receptionist', 'interview_scheduled')
        )
        OR (
            l.current_stage = 'receptionist'
            AND l.updated_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        )
    )
    $branch_clause
    ORDER BY
        COALESCE(i.scheduled_date, l.interview_date, DATE(l.updated_at)) ASC,
        COALESCE(i.scheduled_time, '23:59') ASC,
        l.updated_at DESC
    LIMIT 500
";

$stmt = $conn->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$seen = [];
$out = [];

foreach ($rows as $r) {
    $leadId = (int)$r['lead_id'];
    if (isset($seen[$leadId])) {
        continue;
    }
    $seen[$leadId] = true;

    $stage = canonical_stage((string)$r['current_stage']);
    $source = strtolower(trim((string)($r['source'] ?? '')));
    $hasInterview = !empty($r['interview_id']);

    if ($hasInterview) {
        $queueType = 'scheduled';
        $badge = 'Interview Scheduled';
    } elseif ($stage === 'interview_scheduled') {
        $queueType = 'scheduled';
        $badge = 'Interview Scheduled';
    } elseif (in_array($source, ['mobile', 'walkin', 'public', 'walk-in'], true)) {
        $queueType = 'form';
        $badge = 'Form Application';
    } elseif ($stage === 'receptionist') {
        $queueType = 'checkin';
        $badge = 'Checked In';
    } else {
        $queueType = 'lead';
        $badge = 'Awaiting Reception';
    }

    $date = $r['scheduled_date'] ?: $r['interview_date'];
    $time = $r['scheduled_time'] ?? '';
    $dateTime = trim(($date ?: '') . ' ' . ($time ?: ''));

    $out[] = [
        'id' => $leadId,
        'leadId' => $leadId,
        'interviewId' => $hasInterview ? (int)$r['interview_id'] : null,
        'name' => $r['full_name'],
        'fullName' => $r['full_name'],
        'fatherName' => $r['father_name'] ?? '',
        'phone' => $r['phone'] ?? '',
        'email' => $r['email'] ?? '',
        'cnic' => $r['cnic'] ?? '',
        'city' => $r['city'] ?? '',
        'dob' => $r['dob'] ?? '',
        'graduation' => $r['education'] ?? '',
        'position' => $r['position_applied'] ?? 'Interview Candidate',
        'referredBy' => $r['referred_by'] ?? 'Walk-in',
        'source' => $source ?: 'recruiter',
        'recruiterName' => $r['recruiter_name'] ?? '',
        'currentStage' => $stage,
        'queueType' => $queueType,
        'badge' => $badge,
        'interviewDateTime' => $dateTime,
        'interviewDate' => $date,
        'interviewTime' => $time,
        'interviewLocation' => $r['interview_location'] ?? 'Main Office',
        'interviewer' => $r['interviewer_name'] ?? ($r['recruiter_name'] ?: 'HR Manager'),
        'notes' => $r['interview_notes'] ?? '',
    ];
}

// Batch Cross-Branch CNIC history lookup for Reception queue
$cnic_map = [];
foreach ($out as $idx => $item) {
    $digits = preg_replace('/[^0-9]/', '', (string)$item['cnic']);
    if (!empty($digits) && strlen($digits) >= 9) {
        $cnic_map[$digits][] = $idx;
    }
}

if (!empty($cnic_map)) {
    $in_keys = array_keys($cnic_map);
    $in_ph = implode(',', array_fill(0, count($in_keys), '?'));
    $in_types = str_repeat('s', count($in_keys));
    
    $c_sql = "
        SELECT l.id, l.cnic, l.company_branch, l.current_stage, l.rejection_reason, l.created_at,
               hr.result AS hr_result, hr.rejection_reason AS hr_rejection_reason,
               gm.result AS gm_result, gm.rejection_reason AS gm_rejection_reason
        FROM leads l
        LEFT JOIN hr_interviews hr ON hr.lead_id = l.id
        LEFT JOIN gm_interviews gm ON gm.lead_id = l.id
        WHERE REPLACE(REPLACE(l.cnic, '-', ''), ' ', '') IN ($in_ph)
        ORDER BY l.created_at DESC
    ";
    
    $c_stmt = $conn->prepare($c_sql);
    if ($c_stmt) {
        bindParams($c_stmt, $in_types, $in_keys);
        $c_stmt->execute();
        $c_res = $c_stmt->get_result();
        
        $history_by_cnic = [];
        $rej_stages = ['hr_rejected', 'gm_rejected', 'rejected', 'mock_rejected', 'left', 'not_appeared'];
        
        while ($h = $c_res->fetch_assoc()) {
            $digits = preg_replace('/[^0-9]/', '', (string)$h['cnic']);
            if (!isset($history_by_cnic[$digits])) {
                $history_by_cnic[$digits] = [];
            }
            $history_by_cnic[$digits][] = $h;
        }
        
        foreach ($cnic_map as $digits => $indexes) {
            $past_rows = $history_by_cnic[$digits] ?? [];
            foreach ($indexes as $idx) {
                $curr_id = (int)$out[$idx]['id'];
                $curr_branch = normalize_company_branch($branch);
                
                $prev_rejections = [];
                $past_branches = [];
                
                foreach ($past_rows as $p) {
                    $pid = (int)$p['id'];
                    if ($pid === $curr_id) continue;
                    
                    $p_branch = normalize_company_branch($p['company_branch'] ?: 'main');
                    $p_branch_label = company_branch_label($p_branch);
                    $past_branches[$p_branch_label] = true;
                    
                    $p_stage = canonical_stage((string)$p['current_stage']);
                    $p_rej = in_array($p_stage, $rej_stages, true) || 
                             ($p['hr_result'] === 'rejected') || 
                             ($p['gm_result'] === 'rejected') || 
                             !empty($p['rejection_reason']);
                             
                    if ($p_rej) {
                        $date_str = $p['created_at'] ? date('d-M-Y', strtotime($p['created_at'])) : '';
                        $reason = $p['rejection_reason'] ?: ($p['hr_rejection_reason'] ?: ($p['gm_rejection_reason'] ?: 'Rejected previously'));
                        $prev_rejections[] = [
                            'lead_id' => $pid,
                            'branch' => $p_branch_label,
                            'branch_key' => $p_branch,
                            'date' => $date_str,
                            'stage' => $p_stage,
                            'reason' => $reason,
                            'summary' => "Prev. Rejected at $p_branch_label ($date_str)"
                        ];
                    }
                }
                
                $out[$idx]['hasPreviousHistory'] = (count($past_rows) > 1);
                $out[$idx]['hasPreviousRejection'] = !empty($prev_rejections);
                $out[$idx]['previousRejections'] = $prev_rejections;
                $out[$idx]['previousRejectionSummary'] = !empty($prev_rejections) ? $prev_rejections[0]['summary'] : '';
                $out[$idx]['previousBranches'] = array_keys($past_branches);
            }
        }
    }
}

respond(true, $out);

