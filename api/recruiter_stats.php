<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$user_id = getCurrentUserId();
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

if ($recruiter_type !== 'super') {
    // Regular recruiter — own stats only
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_leads,
            SUM(current_stage IN ('new','assigned','outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg')) AS pending_leads,
            SUM(current_stage IN ('outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg')) AS contacted_leads,
            SUM(current_stage = 'pending') AS interested_leads,
            SUM(current_stage = 'interview_scheduled') AS scheduled_leads,
            SUM(current_stage IN ('hired','deployed','selected')) AS hired_leads,
            SUM(current_stage IN ('rejected','left','mock_rejected','hr_rejected','gm_rejected')) AS rejected_leads
        FROM leads WHERE assigned_recruiter_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();

    // Calls today
    $today_stmt = $conn->prepare("
        SELECT COUNT(*) AS calls_today FROM lead_remarks
        WHERE added_by = ? AND DATE(created_at) = CURDATE()
    ");
    $today_stmt->bind_param("i", $user_id);
    $today_stmt->execute();
    $today = $today_stmt->get_result()->fetch_assoc();
    $stats['calls_today'] = (int)($today['calls_today'] ?? 0);

    respond(true, $stats);
} else {
    // Super Admin — global stats
    $total        = $conn->query("SELECT COUNT(*) as c FROM leads")->fetch_assoc()['c'];
    $unassigned   = $conn->query("SELECT COUNT(*) as c FROM leads WHERE assigned_recruiter_id IS NULL")->fetch_assoc()['c'];
    $assigned     = $conn->query("SELECT COUNT(*) as c FROM leads WHERE assigned_recruiter_id IS NOT NULL")->fetch_assoc()['c'];
    $pending      = $conn->query("SELECT COUNT(*) as c FROM leads WHERE current_stage IN ('new','assigned','outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg')")->fetch_assoc()['c'];
    $scheduled    = $conn->query("SELECT COUNT(*) as c FROM leads WHERE current_stage = 'interview_scheduled'")->fetch_assoc()['c'];
    $hired        = $conn->query("SELECT COUNT(*) as c FROM leads WHERE current_stage IN ('hired','deployed','selected')")->fetch_assoc()['c'];
    $hired_month  = $conn->query("SELECT COUNT(*) as c FROM leads WHERE current_stage IN ('hired','deployed','selected') AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())")->fetch_assoc()['c'];
    $active_recs  = $conn->query("SELECT COUNT(*) as c FROM users u INNER JOIN recruiters r ON u.id=r.user_id WHERE u.status='active' AND r.recruiter_type='regular'")->fetch_assoc()['c'];
    $inactive_recs = $conn->query("SELECT COUNT(*) as c FROM users u INNER JOIN recruiters r ON u.id=r.user_id WHERE u.status='inactive' AND r.recruiter_type='regular'")->fetch_assoc()['c'];

    // Per-recruiter breakdown
    $breakdown = $conn->query("
        SELECT u.id, u.full_name, u.status,
               COUNT(l.id) AS total,
               SUM(l.current_stage IN ('new','assigned','outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg')) AS pending,
               SUM(l.current_stage = 'interview_scheduled') AS scheduled,
               SUM(l.current_stage IN ('hired','deployed','selected')) AS hired
        FROM users u
        INNER JOIN recruiters r ON u.id = r.user_id
        LEFT JOIN leads l ON l.assigned_recruiter_id = u.id
        WHERE r.recruiter_type = 'regular'
        GROUP BY u.id
        ORDER BY total DESC
    ")->fetch_all(MYSQLI_ASSOC);

    // Recent Activity Feed
    $activity = $conn->query("
        SELECT a.action, a.new_value, a.notes, a.created_at, u.full_name as user_name, l.full_name as lead_name, l.id as lead_id
        FROM lead_audit a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN leads l ON a.lead_id = l.id
        ORDER BY a.created_at DESC
        LIMIT 8
    ")->fetch_all(MYSQLI_ASSOC);

    // Upcoming Interviews
    $upcoming = $conn->query("
        SELECT id, full_name, phone, interview_date, current_stage
        FROM leads 
        WHERE interview_date >= CURDATE() AND current_stage = 'interview_scheduled'
        ORDER BY interview_date ASC
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);

    // Priority Leads (Assigned but not called, or called long ago)
    $priority = $conn->query("
        SELECT l.id, l.full_name, l.phone, l.current_stage, l.call_count, l.last_call_date, u.full_name as recruiter_name
        FROM leads l
        LEFT JOIN users u ON l.assigned_recruiter_id = u.id
        WHERE l.assigned_recruiter_id IS NOT NULL 
        AND l.current_stage NOT IN ('hired','deployed','selected','rejected','left','mock_rejected')
        AND (l.call_count = 0 OR l.last_call_date < DATE_SUB(NOW(), INTERVAL 3 DAY))
        ORDER BY l.call_count ASC, l.updated_at ASC
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);

    respond(true, [
        'total_leads'      => (int)$total,
        'unassigned_leads' => (int)$unassigned,
        'assigned_leads'   => (int)$assigned,
        'pending_leads'    => (int)$pending,
        'scheduled_leads'  => (int)$scheduled,
        'hired_leads'      => (int)$hired,
        'hired_this_month' => (int)$hired_month,
        'active_recruiters'=> (int)$active_recs,
        'inactive_recruiters'=> (int)$inactive_recs,
        'recruiter_breakdown' => $breakdown,
        'recent_activity'     => $activity,
        'upcoming_interviews' => $upcoming,
        'priority_leads'      => $priority
    ]);
}
?>