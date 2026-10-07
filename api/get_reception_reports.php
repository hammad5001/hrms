<?php
/**
 * Reception Reports & Analytics API
 * Returns aggregated statistics and detailed candidate records for selected date ranges, status filters, and branches.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$branch = get_active_company_branch();
$portal_role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
$is_super = isGlobalSuperAdmin();

$startDate = isset($_GET['start_date']) && !empty($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01');
$endDate = isset($_GET['end_date']) && !empty($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
$filterStage = isset($_GET['stage']) ? trim($_GET['stage']) : 'all';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedBranch = isset($_GET['branch']) ? trim($_GET['branch']) : '';

// Validate Dates (format: YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}

$whereClauses = [];
$params = [];
$types = "";

// Date range filtering on interview date or creation/update date
$whereClauses[] = "(
    (i.scheduled_date IS NOT NULL AND i.scheduled_date BETWEEN ? AND ?)
    OR (l.interview_date IS NOT NULL AND l.interview_date BETWEEN ? AND ?)
    OR (DATE(l.created_at) BETWEEN ? AND ?)
    OR (DATE(l.updated_at) BETWEEN ? AND ?)
)";
$params[] = $startDate;
$params[] = $endDate;
$params[] = $startDate;
$params[] = $endDate;
$params[] = $startDate;
$params[] = $endDate;
$params[] = $startDate;
$params[] = $endDate;
$types .= "ssssssss";

// Reception Filter: Must be an actual Scheduled Interview, Walk-in, or Candidate who reached Reception
$whereClauses[] = "(
    l.current_stage IN ('interview_scheduled', 'receptionist', 'appeared', 'checked_in', 'not_appeared', 'left', 'hr_interview', 'hired', 'rejected', 'second_interview', 'selected', 'training')
    OR i.id IS NOT NULL
    OR (l.source IN ('walkin', 'walk-in', 'mobile') AND (l.referred_by LIKE '%walk%' OR l.source LIKE '%walk%' OR l.current_stage IN ('receptionist', 'interview_scheduled', 'not_appeared', 'left')))
)";

// Branch security / filter
if (!$is_super) {
    if ($branch === 'main') {
        $whereClauses[] = "(l.company_branch = 'main' OR l.company_branch IS NULL OR TRIM(l.company_branch) = '')";
    } else {
        $whereClauses[] = "l.company_branch = ?";
        $params[] = $branch;
        $types .= "s";
    }
} else if (!empty($selectedBranch) && $selectedBranch !== 'all') {
    if ($selectedBranch === 'main') {
        $whereClauses[] = "(l.company_branch = 'main' OR l.company_branch IS NULL OR TRIM(l.company_branch) = '')";
    } else {
        $whereClauses[] = "l.company_branch = ?";
        $params[] = $selectedBranch;
        $types .= "s";
    }
}

// Stage filtering
if (!empty($filterStage) && $filterStage !== 'all') {
    if ($filterStage === 'appeared') {
        $whereClauses[] = "l.current_stage IN ('receptionist', 'appeared', 'checked_in')";
    } else if ($filterStage === 'scheduled') {
        $whereClauses[] = "l.current_stage IN ('interview_scheduled', 'assigned', 'new')";
    } else if ($filterStage === 'not_appeared') {
        $whereClauses[] = "l.current_stage = 'not_appeared'";
    } else if ($filterStage === 'left') {
        $whereClauses[] = "l.current_stage = 'left'";
    } else if ($filterStage === 'sent_to_hr') {
        $whereClauses[] = "l.current_stage IN ('hr_interview', 'hired', 'rejected', 'second_interview', 'selected', 'training')";
    } else {
        $whereClauses[] = "l.current_stage = ?";
        $params[] = $filterStage;
        $types .= "s";
    }
}

// Search text (Name, CNIC, Phone, Position)
if (!empty($searchQuery)) {
    $whereClauses[] = "(l.full_name LIKE ? OR l.cnic LIKE ? OR l.phone LIKE ? OR l.position_applied LIKE ?)";
    $like = "%" . $searchQuery . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$whereSql = implode(" AND ", $whereClauses);

$query = "
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
        l.created_at,
        l.updated_at,
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
    LEFT JOIN interviews i ON i.lead_id = l.id
    WHERE $whereSql
    ORDER BY COALESCE(i.scheduled_date, l.interview_date, DATE(l.updated_at)) DESC, l.updated_at DESC
    LIMIT 3000
";

$stmt = $conn->prepare($query);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$allLeads = [];
$counts = [
    'total' => 0,
    'scheduled' => 0,
    'appeared' => 0,
    'not_appeared' => 0,
    'left' => 0,
    'sent_to_hr' => 0,
    'walkin' => 0
];

$seen = [];
while ($row = $result->fetch_assoc()) {
    $leadId = (int)$row['lead_id'];
    if (isset($seen[$leadId])) {
        continue;
    }
    $seen[$leadId] = true;

    $rawStage = strtolower(trim((string)$row['current_stage']));
    
    // Categorization
    $category = 'scheduled';
    if (in_array($rawStage, ['receptionist', 'appeared', 'checked_in'], true)) {
        $category = 'appeared';
        $counts['appeared']++;
    } else if ($rawStage === 'not_appeared') {
        $category = 'not_appeared';
        $counts['not_appeared']++;
    } else if ($rawStage === 'left') {
        $category = 'left';
        $counts['left']++;
    } else if (in_array($rawStage, ['hr_interview', 'hired', 'rejected', 'second_interview', 'selected', 'training'], true)) {
        $category = 'sent_to_hr';
        $counts['sent_to_hr']++;
    } else {
        $category = 'scheduled';
        $counts['scheduled']++;
    }

    $src = strtolower(trim((string)$row['source']));
    if (strpos($src, 'walk') !== false || $src === 'mobile') {
        $counts['walkin']++;
    }

    $counts['total']++;

    $displayDate = $row['scheduled_date'] ?: ($row['interview_date'] ?: '');
    if (empty($displayDate) && in_array($category, ['appeared', 'not_appeared', 'left', 'sent_to_hr'], true)) {
        $displayDate = substr((string)$row['updated_at'], 0, 10);
    }
    $displayTime = $row['scheduled_time'] ?: '';

    $allLeads[] = [
        'id' => $leadId,
        'name' => $row['full_name'] ?: 'N/A',
        'cnic' => $row['cnic'] ?: '',
        'phone' => $row['phone'] ?: '',
        'email' => $row['email'] ?: '',
        'city' => $row['city'] ?: '',
        'position' => $row['position_applied'] ?: 'N/A',
        'branch' => $row['company_branch'] ?: 'Main Office',
        'source' => $row['source'] ?: 'walkin',
        'referredBy' => $row['referred_by'] ?: '',
        'recruiterName' => $row['recruiter_name'] ?: '',
        'category' => $category,
        'rawStage' => $rawStage,
        'interviewDate' => $displayDate ?: 'Walk-in / Arrival',
        'interviewTime' => $displayTime,
        'interviewLocation' => $row['interview_location'] ?: 'Main Office',
        'interviewer' => $row['interviewer_name'] ?: '',
        'updatedAt' => $row['updated_at'],
        'createdAt' => $row['created_at']
    ];
}

// Filter records by stage for table view
$filteredLeads = [];
if (!empty($filterStage) && $filterStage !== 'all') {
    foreach ($allLeads as $lead) {
        if ($filterStage === 'appeared' && $lead['category'] === 'appeared') {
            $filteredLeads[] = $lead;
        } else if ($filterStage === 'scheduled' && $lead['category'] === 'scheduled') {
            $filteredLeads[] = $lead;
        } else if ($filterStage === 'not_appeared' && $lead['category'] === 'not_appeared') {
            $filteredLeads[] = $lead;
        } else if ($filterStage === 'left' && $lead['category'] === 'left') {
            $filteredLeads[] = $lead;
        } else if ($filterStage === 'sent_to_hr' && $lead['category'] === 'sent_to_hr') {
            $filteredLeads[] = $lead;
        } else if ($lead['rawStage'] === $filterStage) {
            $filteredLeads[] = $lead;
        }
    }
} else {
    $filteredLeads = $allLeads;
}

respond(true, [
    'dateRange' => [
        'startDate' => $startDate,
        'endDate' => $endDate
    ],
    'counts' => $counts,
    'totalRecords' => count($filteredLeads),
    'records' => $filteredLeads
]);
