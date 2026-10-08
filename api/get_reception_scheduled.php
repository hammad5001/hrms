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

$branch_req = trim($_GET['branch'] ?? '');

if (!$is_super) {
    if ($branch === 'main') {
        $branch_clause = " AND (l.company_branch = 'main' OR l.company_branch IS NULL OR TRIM(l.company_branch) = '') ";
    } else {
        $branch_clause = " AND l.company_branch = ? ";
        $params[] = $branch;
        $types .= "s";
    }
} elseif ($branch_req !== '' && $branch_req !== 'all' && is_valid_company_branch($branch_req)) {
    $norm_b = normalize_company_branch($branch_req);
    if ($norm_b === 'main') {
        $branch_clause = " AND (l.company_branch = 'main' OR l.company_branch IS NULL OR TRIM(l.company_branch) = '') ";
    } else {
        $branch_clause = " AND l.company_branch = ? ";
        $params[] = $norm_b;
        $types .= "s";
    }
}

// Automatically mark candidates as 'left' if they checked in (receptionist) > 8 hours ago without interview completion
$auto_left_sql = "
    UPDATE leads 
    SET current_stage = 'left', updated_at = NOW() 
    WHERE current_stage = 'receptionist' 
      AND updated_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)
";
@$conn->query($auto_left_sql);

$sql = "
    SELECT
        l.id AS lead_id,
        l.external_lead_id,
        l.reference_id,
        l.full_name,
        l.father_name,
        l.phone,
        l.email,
        l.cnic,
        l.city,
        l.dob,
        l.education,
        l.experience,
        l.position_applied,
        l.queue_name,
        l.referred_by,
        l.source,
        l.heard_about,
        l.duplicate_flags,
        l.cv_file_url,
        l.applicant_notes,
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
        l.current_stage IN ('interview_scheduled', 'receptionist', 'not_appeared', 'left')
        OR i.id IS NOT NULL
        OR (
            l.source IN ('mobile', 'walkin', 'public', 'walk-in')
            AND l.current_stage IN ('new', 'assigned', 'receptionist', 'interview_scheduled', 'not_appeared', 'left')
        )
    )
    $branch_clause
    ORDER BY
        COALESCE(i.scheduled_date, l.interview_date, DATE(l.updated_at)) DESC,
        COALESCE(i.scheduled_time, '23:59') DESC,
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
    $isWalkin = in_array($source, ['mobile', 'walkin', 'public', 'walk-in'], true) || str_contains(strtolower($r['heard_about'] ?? ''), 'walk');

    if ($stage === 'receptionist') {
        $queueType = 'checkin';
        $badge = 'Appeared';
    } elseif ($stage === 'not_appeared') {
        $queueType = 'not_appeared';
        $badge = 'Not Appeared';
    } elseif ($stage === 'left') {
        $queueType = 'left';
        $badge = 'Left';
    } elseif ($isWalkin) {
        $queueType = 'walkin';
        $badge = 'Walk-in Applicant';
    } elseif ($hasInterview || $stage === 'interview_scheduled') {
        $queueType = 'scheduled';
        $badge = 'Interview Scheduled';
    } else {
        $queueType = 'lead';
        $badge = 'Awaiting Reception';
    }

    $date = $r['scheduled_date'] ?: $r['interview_date'];
    $time = $r['scheduled_time'] ?? '';
    $dateTime = trim(($date ?: '') . ' ' . ($time ?: ''));

    $interviewerLabel = $r['interviewer_name'];
    if (empty($interviewerLabel) || ($isWalkin && $interviewerLabel === 'HR Manager')) {
        $interviewerLabel = $isWalkin ? 'Walk-in Desk' : ($r['recruiter_name'] ?: 'Reception Desk');
    }

    $out[] = [
        'id' => $leadId,
        'leadId' => $leadId,
        'referenceId' => $r['reference_id'] ?? '',
        'externalLeadId' => $r['external_lead_id'] ?? '',
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
        'experience' => $r['experience'] ?? '',
        'position' => $r['position_applied'] ?? 'Interview Candidate',
        'queueName' => $r['queue_name'] ?? 'recruitment',
        'referredBy' => $r['referred_by'] ?? ($isWalkin ? 'Walk-in' : 'Direct'),
        'source' => $source ?: 'recruiter',
        'heardAbout' => $r['heard_about'] ?? '',
        'duplicateFlags' => $r['duplicate_flags'] ?? '',
        'cvFileUrl' => $r['cv_file_url'] ?? '',
        'hasCv' => !empty($r['cv_file_url']),
        'applicantNotes' => $r['applicant_notes'] ?? '',
        'companyBranch' => $r['company_branch'] ?? 'main',
        'recruiterName' => $r['recruiter_name'] ?? '',
        'currentStage' => $stage,
        'queueType' => $queueType,
        'badge' => $badge,
        'isWalkin' => $isWalkin,
        'interviewDateTime' => $dateTime,
        'interviewDate' => $date,
        'interviewTime' => $time,
        'interviewLocation' => $r['interview_location'] ?? ($isWalkin ? 'Reception Desk' : 'Main Office'),
        'interviewer' => $interviewerLabel,
        'notes' => $r['interview_notes'] ?? ($r['applicant_notes'] ?? ''),
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

