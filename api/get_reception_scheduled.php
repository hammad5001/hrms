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
$is_reception = in_array($portal_role, ['receptionist', 'agent', 'admin'], true);

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
    " . ($is_reception ? '' : " AND (l.company_branch = ? OR l.company_branch IS NULL OR TRIM(l.company_branch) = '') ") . "
    ORDER BY
        COALESCE(i.scheduled_date, l.interview_date, DATE(l.updated_at)) ASC,
        COALESCE(i.scheduled_time, '23:59') ASC,
        l.updated_at DESC
    LIMIT 500
";

$stmt = $conn->prepare($sql);
if (!$is_reception) {
    $stmt->bind_param('s', $branch);
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
        'registeredAt' => $r['lead_created_at'],
    ];
}

respond(true, $out);
