<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=8');

$stages_param = $_GET['stages'] ?? '';
$light = isset($_GET['light']) && $_GET['light'] === '1';
$limit = min(500, max(1, (int)($_GET['limit'] ?? 300)));
if (!$stages_param) {
    respond(false, null, 'No stages specified');
}

// Split by comma and clean
$stages = array_filter(array_map('trim', explode(',', $stages_param)));
if (empty($stages)) {
    respond(false, null, 'Invalid stages');
}
$stages = array_values(array_map('canonical_stage', $stages));

$placeholders = implode(',', array_fill(0, count($stages), '?'));
$types = str_repeat('s', count($stages));
$params = $stages;

$branch_sql = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$active_branch = get_active_company_branch();
$branch_req    = trim($_GET['branch'] ?? '');

if (isGlobalSuperAdmin()) {
    if ($branch_req === 'all') {
        $branch_sql = ''; // Global Super Admin can see all branches
    } elseif ($branch_req !== '' && is_valid_company_branch($branch_req)) {
        $branch_sql = ' AND l.company_branch = ?';
        $types .= 's';
        $params[] = normalize_company_branch($branch_req);
    } else {
        $branch_sql = ' AND l.company_branch = ?';
        $types .= 's';
        $params[] = $active_branch;
    }
} else {
    // Other roles strictly scoped to active branch
    $branch_sql = ' AND l.company_branch = ?';
    $types .= 's';
    $params[] = $active_branch;
}

if ($light) {
    $sql = "
        SELECT l.id, l.full_name, l.father_name, l.phone, l.email, l.cnic, l.city, l.dob,
               l.education, l.position_applied, l.referred_by, l.current_stage, l.created_at, l.updated_at,
               l.interview_date, u.full_name AS recruiter_name,
               i.id AS interview_id, i.scheduled_date, i.scheduled_time,
               i.location AS interview_location, i.interviewer_name
        FROM leads l
        LEFT JOIN users u ON l.assigned_recruiter_id = u.id
        LEFT JOIN interviews i ON i.lead_id = l.id AND i.status = 'scheduled'
        WHERE l.current_stage IN ($placeholders) $branch_sql
        ORDER BY l.updated_at DESC
        LIMIT $limit
    ";
} else {
    $sql = "
        SELECT l.id, l.full_name, l.father_name, l.phone, l.email, l.cnic, l.city, l.dob,
               l.education, l.position_applied, l.referred_by, l.current_stage, l.created_at, l.updated_at,
               l.interview_date, l.company_branch,
               u.full_name AS recruiter_name,
               lr_latest.remark AS latest_remark,
               lr_latest.created_at AS latest_remark_at,
               i.id AS interview_id, i.scheduled_date, i.scheduled_time,
               i.location AS interview_location, i.interviewer_name
        FROM leads l
        LEFT JOIN users u ON l.assigned_recruiter_id = u.id
        LEFT JOIN (
            SELECT x.lead_id, x.remark, x.created_at
            FROM lead_remarks x
            INNER JOIN (
                SELECT lead_id, MAX(created_at) AS max_created
                FROM lead_remarks
                GROUP BY lead_id
            ) m ON m.lead_id = x.lead_id AND m.max_created = x.created_at
        ) lr_latest ON lr_latest.lead_id = l.id
        LEFT JOIN interviews i ON i.lead_id = l.id AND i.status = 'scheduled'
        WHERE l.current_stage IN ($placeholders) $branch_sql
        ORDER BY l.updated_at DESC
        LIMIT $limit
    ";
}

$stmt = $conn->prepare($sql);
bindParams($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();

$candidates = [];
while ($row = $result->fetch_assoc()) {
    $status = canonical_stage((string)$row['current_stage']);
    $interviewDateTime = null;
    if (!empty($row['scheduled_date'])) {
        $interviewDateTime = trim(($row['scheduled_date'] ?? '') . ' ' . ($row['scheduled_time'] ?? ''));
    } elseif (!empty($row['interview_date'])) {
        $interviewDateTime = $row['interview_date'];
    }
    // Map to the JS structure expected by Legacy Portals
    $candidates[] = [
        'id' => $row['id'], // We'll use the DB ID
        'fullName' => $row['full_name'],
        'fatherName' => $row['father_name'] ?? '',
        'phone' => $row['phone'],
        'email' => $row['email'] ?? '',
        'cnic' => $row['cnic'] ?? '',
        'city' => $row['city'] ?? '',
        'dob' => $row['dob'] ?? '',
        'graduation' => $row['education'] ?? '',
        'position' => $row['position_applied'],
        'referredBy' => $row['referred_by'] ?? 'Walk-in',
        'status' => $status,
        'stageGroup' => stage_group($status),
        'interviewLevel' => getInterviewLevel($status),
        'hrStatus' => getHrStatus($status),
        'gmStatus' => getGmStatus($status),
        'trainingStatus' => getTrainingStatus($status),
        'timestamp' => $row['created_at'],
        'recruiterName' => $row['recruiter_name'],
        'interviewId' => $row['interview_id'] ? (int)$row['interview_id'] : null,
        'interviewDateTime' => $interviewDateTime,
        'interviewLocation' => $row['interview_location'] ?? '',
        'interviewer' => $row['interviewer_name'] ?? '',
        'remarks' => !empty($row['latest_remark']) ? [[
            'text' => $row['latest_remark'],
            'timestamp' => $row['latest_remark_at'],
            'addedBy' => 'System'
        ]] : [],
    ];
}

// Batch Cross-Branch CNIC history lookup
$cnic_map = [];
$clean_to_raw = [];
foreach ($candidates as $idx => $c) {
    $cnic_digits = preg_replace('/[^0-9]/', '', (string)$c['cnic']);
    if (!empty($cnic_digits) && strlen($cnic_digits) >= 9) {
        $cnic_map[$cnic_digits][] = $idx;
    }
}

if (!empty($cnic_map)) {
    $in_keys = array_keys($cnic_map);
    $in_ph = implode(',', array_fill(0, count($in_keys), '?'));
    $in_types = str_repeat('s', count($in_keys));
    
    $c_sql = "
        SELECT l.id, l.cnic, l.company_branch, l.current_stage, l.rejection_reason, l.created_at,
               hr.result AS hr_result, hr.rejection_reason AS hr_rejection_reason, hr.remarks AS hr_remarks,
               gm.result AS gm_result, gm.rejection_reason AS gm_rejection_reason, gm.remarks AS gm_remarks
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
        
        foreach ($cnic_map as $digits => $candidate_indexes) {
            $past_rows = $history_by_cnic[$digits] ?? [];
            foreach ($candidate_indexes as $idx) {
                $curr_id = (int)$candidates[$idx]['id'];
                $curr_branch = normalize_company_branch($candidates[$idx]['branch'] ?? get_active_company_branch());
                
                $prev_rejections = [];
                $past_branches = [];
                $has_cross_branch = false;
                
                foreach ($past_rows as $p) {
                    $pid = (int)$p['id'];
                    if ($pid === $curr_id) continue;
                    
                    $p_branch = normalize_company_branch($p['company_branch'] ?: 'main');
                    $p_branch_label = company_branch_label($p_branch);
                    $past_branches[$p_branch_label] = true;
                    
                    if ($p_branch !== $curr_branch) {
                        $has_cross_branch = true;
                    }
                    
                    $p_stage = canonical_stage((string)$p['current_stage']);
                    $p_rej = in_array($p_stage, $rej_stages, true) || 
                             ($p['hr_result'] === 'rejected') || 
                             ($p['gm_result'] === 'rejected') || 
                             !empty($p['rejection_reason']);
                             
                    if ($p_rej) {
                        $date_str = $p['created_at'] ? date('d-M-Y', strtotime($p['created_at'])) : '';
                        $reason = $p['rejection_reason'] ?: ($p['hr_rejection_reason'] ?: ($p['gm_rejection_reason'] ?: 'Rejected in previous interview'));
                        $prev_rejections[] = [
                            'lead_id' => $pid,
                            'branch' => $p_branch_label,
                            'branch_key' => $p_branch,
                            'date' => $date_str,
                            'stage' => $p_stage,
                            'reason' => $reason,
                            'summary' => "Rejected at $p_branch_label ($date_str) - $reason"
                        ];
                    }
                }
                
                $candidates[$idx]['hasPreviousHistory'] = (count($past_rows) > 1);
                $candidates[$idx]['hasPreviousRejection'] = !empty($prev_rejections);
                $candidates[$idx]['previousRejections'] = $prev_rejections;
                $candidates[$idx]['previousRejectionSummary'] = !empty($prev_rejections) ? $prev_rejections[0]['summary'] : '';
                $candidates[$idx]['previousBranches'] = array_keys($past_branches);
            }
        }
    }
}

// Helper to map DB stage to JS interviewLevel
function getInterviewLevel($stage) {
    if (in_array($stage, ['hr_passed', 'gm_passed', 'gm_rejected', 'hired', 'training', 'deployed'], true)) return 'gm';
    return 'hr';
}
function getHrStatus($stage) {
    if ($stage === 'hr_passed' || in_array($stage, ['gm_passed', 'gm_rejected', 'training', 'hired', 'deployed'], true)) return 'passed';
    if (in_array($stage, ['hr_rejected', 'rejected'], true)) return 'rejected';
    if ($stage === 'interview_conducted') return 'queue';
    if (in_array($stage, ['selected', 'pending'], true)) return 'outcome';
    return 'pending';
}
function getGmStatus($stage) {
    if ($stage === 'gm_passed' || in_array($stage, ['training', 'hired', 'deployed'], true)) return 'passed';
    if ($stage === 'gm_rejected') return 'rejected';
    return 'pending';
}
function getTrainingStatus($stage) {
    if ($stage === 'deployed') return 'completed';
    if (in_array($stage, ['mock_rejected', 'left', 'rejected'], true)) return 'failed';
    if ($stage === 'training') return 'in-progress';
    if (in_array($stage, ['selected', 'pending', 'hr_passed', 'gm_passed', 'hired'], true)) return 'pending';
    if ($stage === 'not_appeared') return 'no-show';
    return 'pending';
}

respond(true, $candidates);
?>
