<?php
// =====================================================
// BALITECH HRMS - REQUEST HUB API
// Handles Tickets, Department Routing, Multi-Tagging & Remarks Trail
// =====================================================

require_once __DIR__ . '/../attendance/config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Current logged in user info (fallbacks if testing/direct session)
$current_emp_id = $_SESSION['user_id'] ?? $_SESSION['employee_id'] ?? 0;
$current_emp_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Employee';
$current_emp_role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'Employee';

// Ensure upload directory exists
$upload_dir = __DIR__ . '/../uploads/request_hub/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_taggable_users':
        getTaggedUsersList($conn);
        break;

    case 'get_auto_tags':
        getAutoTags($conn, $current_emp_id);
        break;

    case 'create_request':
        createRequest($conn, $current_emp_id, $current_emp_name);
        break;

    case 'get_requests':
        getRequests($conn, $current_emp_id);
        break;

    case 'get_request_details':
        getRequestDetails($conn, $current_emp_id);
        break;

    case 'add_remark':
        addRemark($conn, $current_emp_id, $current_emp_name, $current_emp_role);
        break;

    case 'update_status':
        updateStatus($conn, $current_emp_id, $current_emp_name, $current_emp_role);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
        break;
}

// =====================================================
// FUNCTIONS
// =====================================================

function getTaggedUsersList($conn) {
    // Fetch active users and employees with portal_role and branch info
    $query = "SELECT u.id, u.employee_code, u.full_name, u.email, u.portal_role, u.department, u.designation, u.team, u.company_branch
              FROM users u
              WHERE u.status = 'active' OR u.status IS NULL
              ORDER BY u.full_name ASC";
    $res = $conn->query($query);
    $users = [];
    $seenIds = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $seenIds[] = (int)$row['id'];
            $users[] = [
                'id' => (int)$row['id'],
                'code' => $row['employee_code'] ?: '',
                'name' => $row['full_name'],
                'role' => $row['designation'] ?: (portal_role_label_clean($row['portal_role']) ?: ($row['team'] ?: 'Staff')),
                'portal_role' => $row['portal_role'] ?: 'user',
                'department' => $row['department'] ?: 'General',
                'branch' => $row['company_branch'] ?: 'main'
            ];
        }
    }

    // Also include any employees from employees table if not present in users
    $empRes = $conn->query("SELECT id, employee_code, full_name, department, designation, team, branch FROM employees WHERE (status = 'active' OR is_active = 1 OR status IS NULL) ORDER BY full_name ASC");
    if ($empRes) {
        while ($er = $empRes->fetch_assoc()) {
            // Only add if not matched by employee_code
            $code = $er['employee_code'] ?: '';
            $found = false;
            foreach ($users as $u) {
                if ($code && $u['code'] === $code) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $users[] = [
                    'id' => (int)$er['id'],
                    'code' => $code,
                    'name' => $er['full_name'],
                    'role' => $er['designation'] ?: ($er['team'] ?: 'Staff'),
                    'portal_role' => 'user',
                    'department' => $er['department'] ?: 'General',
                    'branch' => $er['branch'] ?: 'main'
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'data' => $users]);
    exit;
}

function portal_role_label_clean(?string $role): string {
    if (!$role) return 'Staff';
    $map = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'hr' => 'HR Officer',
        'recruiter' => 'Recruiter',
        'management' => 'Management',
        'training' => 'Training',
        'receptionist' => 'Receptionist',
        'team_lead' => 'Team Lead',
        'floor_manager' => 'Floor Manager',
        'finance' => 'Finance Officer',
        'developer' => 'IT / Developer',
        'it' => 'IT Support',
        'trainer' => 'Trainer',
        'dialer' => 'Dialer Ops',
        'attendance' => 'Attendance Admin',
        'user' => 'Staff'
    ];
    return $map[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function getAutoTags($conn, $current_emp_id) {
    $emp_id = (int)($_GET['employee_id'] ?? $current_emp_id);
    $department = trim($_GET['department'] ?? 'IT');
    $branch = strtolower(trim($_GET['branch'] ?? 'main'));

    if ($branch === 'v2' || $branch === 'branch 2.0') $branch = 'v2';
    elseif ($branch === 'v3' || $branch === 'branch 3.0') $branch = 'v3';
    elseif (strpos($branch, 'commercial') !== false) $branch = 'commercial';
    elseif (strpos($branch, 'wfh') !== false || strpos($branch, 'workfromhome') !== false) $branch = 'workfromhome';
    else $branch = 'main';

    $autoTags = [];
    $taggedIds = [];

    // Helper to add unique tag
    $addTag = function($id, $name, $role, $type, $badge) use (&$autoTags, &$taggedIds, $emp_id) {
        $id = (int)$id;
        if ($id > 0 && $id !== $emp_id && !in_array($id, $taggedIds)) {
            $autoTags[] = [
                'id' => $id,
                'name' => $name,
                'role' => $role,
                'tag_type' => $type,
                'tag_badge' => $badge
            ];
            $taggedIds[] = $id;
            return true;
        }
        return false;
    };

    // 1. MUST: Tag Reporting Manager / Team Lead for the employee
    if ($emp_id > 0) {
        $mgrStmt = $conn->prepare("
            SELECT er.manager_user_id, er.manager_name, u.portal_role, u.designation, u.department, u.company_branch
            FROM employee_reporting er
            LEFT JOIN users u ON u.id = er.manager_user_id
            WHERE er.employee_user_id = ?
            LIMIT 1
        ");
        if ($mgrStmt) {
            $mgrStmt->bind_param("i", $emp_id);
            $mgrStmt->execute();
            $mgrRes = $mgrStmt->get_result();
            if ($mgrRes && $mgrRow = $mgrRes->fetch_assoc()) {
                $mgrId = (int)$mgrRow['manager_user_id'];
                $mgrRole = $mgrRow['designation'] ?: (portal_role_label_clean($mgrRow['portal_role'] ?? 'team_lead'));
                $addTag($mgrId, $mgrRow['manager_name'] ?: 'Team Lead / Manager', $mgrRole, 'manager', 'Reporting Manager');
            }
        }
    }

    // 2. Tag Head of Department (HOD) / Management
    $hodQuery = "
        SELECT id, full_name, designation, portal_role 
        FROM users 
        WHERE (portal_role = 'management' OR designation LIKE '%Head of%' OR designation LIKE '%HOD%' OR designation LIKE '%Director%')
        AND (status = 'active' OR status IS NULL)
        AND portal_role NOT IN ('recruiter', 'user')
        AND designation NOT LIKE '%Recruit%'
        AND (company_branch = ? OR company_branch = 'main' OR company_branch IS NULL)
        ORDER BY CASE WHEN company_branch = ? THEN 1 ELSE 2 END ASC, id ASC
        LIMIT 1
    ";
    $hodStmt = $conn->prepare($hodQuery);
    if ($hodStmt) {
        $hodStmt->bind_param("ss", $branch, $branch);
        $hodStmt->execute();
        $hodRes = $hodStmt->get_result();
        if ($hodRes && $hodRow = $hodRes->fetch_assoc()) {
            $addTag($hodRow['id'], $hodRow['full_name'], $hodRow['designation'] ?: 'Head of Department', 'hod', 'Head of Dept');
        }
    }

    // 3. Tag Branch HR (or Global HR)
    $hrQuery = "
        SELECT id, full_name, designation, portal_role 
        FROM users 
        WHERE (portal_role = 'hr' OR department LIKE '%HR%' OR designation LIKE '%HR%')
        AND company_branch = ?
        AND (status = 'active' OR status IS NULL)
        ORDER BY id ASC
        LIMIT 2
    ";
    $hrStmt = $conn->prepare($hrQuery);
    if ($hrStmt) {
        $hrStmt->bind_param("s", $branch);
        $hrStmt->execute();
        $hrRes = $hrStmt->get_result();
        $hrAdded = false;
        while ($hRow = $hrRes->fetch_assoc()) {
            if ($addTag($hRow['id'], $hRow['full_name'], $hRow['designation'] ?: 'HR Officer', 'hr', 'Branch HR')) {
                $hrAdded = true;
            }
        }
        // Fallback to global HR if branch HR not found
        if (!$hrAdded) {
            $fbackRes = $conn->query("SELECT id, full_name, portal_role, designation FROM users WHERE (portal_role = 'hr' OR department LIKE '%HR%') AND (status = 'active' OR status IS NULL) LIMIT 1");
            if ($fbackRes && $fbRow = $fbackRes->fetch_assoc()) {
                $addTag($fbRow['id'], $fbRow['full_name'], $fbRow['designation'] ?: 'HR Officer', 'hr', 'HR Team');
            }
        }
    }

    // 4. Department-Specific Specialists
    if (strcasecmp($department, 'IT') === 0) {
        // IT / Tech: Strictly IT Support & IT Managers (Excludes Recruiters, Developers, Sales, HR)
        $itQuery = "
            SELECT id, full_name, portal_role, designation, department, company_branch
            FROM users
            WHERE (
                portal_role = 'it'
                OR (
                    (
                        designation LIKE '%IT Support%' 
                        OR designation LIKE '%IT Manager%' 
                        OR designation LIKE '%IT Executive%' 
                        OR designation LIKE '%IT Officer%'
                        OR designation LIKE '%Network Admin%'
                        OR designation LIKE '%Network Engineer%'
                        OR designation LIKE '%Hardware%'
                        OR designation LIKE '%System Admin%'
                        OR (department = 'IT' AND designation NOT LIKE '%Recruit%' AND designation NOT LIKE '%Develop%')
                    )
                    AND designation NOT LIKE '%Recruit%'
                    AND designation NOT LIKE '%Developer%'
                    AND designation NOT LIKE '%Software%'
                    AND designation NOT LIKE '%Frontend%'
                    AND designation NOT LIKE '%Backend%'
                    AND designation NOT LIKE '%Full Stack%'
                    AND portal_role NOT IN ('recruiter', 'developer', 'hr', 'sales', 'user')
                )
            )
            AND company_branch = ?
            AND (status = 'active' OR status IS NULL)
            ORDER BY CASE WHEN designation LIKE '%Manager%' THEN 1 ELSE 2 END ASC
            LIMIT 2
        ";
        $itStmt = $conn->prepare($itQuery);
        if ($itStmt) {
            $itStmt->bind_param("s", $branch);
            $itStmt->execute();
            $itRes = $itStmt->get_result();
            $itAdded = false;
            while ($itRow = $itRes->fetch_assoc()) {
                if ($addTag($itRow['id'], $itRow['full_name'], $itRow['designation'] ?: 'IT Support', 'it', 'IT Support')) {
                    $itAdded = true;
                }
            }
            // Fallback to global IT Support / Manager if none found in this branch
            if (!$itAdded) {
                $fbackIt = $conn->query("
                    SELECT id, full_name, portal_role, designation 
                    FROM users 
                    WHERE (
                        portal_role = 'it'
                        OR (
                            (
                                designation LIKE '%IT Support%' 
                                OR designation LIKE '%IT Manager%' 
                                OR designation LIKE '%IT Executive%'
                                OR designation LIKE '%Network%'
                                OR designation LIKE '%Hardware%'
                                OR designation LIKE '%System Admin%'
                            )
                            AND designation NOT LIKE '%Recruit%'
                            AND designation NOT LIKE '%Develop%'
                            AND designation NOT LIKE '%Software%'
                            AND portal_role NOT IN ('recruiter', 'developer', 'hr', 'sales')
                        )
                    ) 
                    AND (status = 'active' OR status IS NULL)
                    ORDER BY CASE WHEN designation LIKE '%Manager%' THEN 1 ELSE 2 END ASC
                    LIMIT 2
                ");
                if ($fbackIt) {
                    while ($fitRow = $fbackIt->fetch_assoc()) {
                        $addTag($fitRow['id'], $fitRow['full_name'], $fitRow['designation'] ?: 'IT Support', 'it', 'IT Support');
                    }
                }
            }
        }
    } elseif (strcasecmp($department, 'Finance') === 0) {
        // Finance Team
        $finQuery = "
            SELECT id, full_name, portal_role, designation, department, company_branch
            FROM users
            WHERE (portal_role = 'finance' 
                   OR department LIKE '%Finance%' 
                   OR designation LIKE '%Finance%' 
                   OR designation LIKE '%Account%'
                   OR designation LIKE '%Cashier%')
            AND (status = 'active' OR status IS NULL)
            ORDER BY id ASC
            LIMIT 2
        ";
        $finRes = $conn->query($finQuery);
        if ($finRes) {
            while ($fRow = $finRes->fetch_assoc()) {
                $addTag($fRow['id'], $fRow['full_name'], $fRow['designation'] ?: 'Finance Officer', 'finance', 'Finance Team');
            }
        }
    } elseif (strcasecmp($department, 'Operations') === 0) {
        // Operations: Floor Manager / Team Leads in employee's branch
        $opsQuery = "
            SELECT id, full_name, portal_role, designation, department, company_branch
            FROM users
            WHERE (portal_role IN ('floor_manager', 'team_lead') OR department LIKE '%Operations%')
            AND company_branch = ?
            AND (status = 'active' OR status IS NULL)
            LIMIT 2
        ";
        $opsStmt = $conn->prepare($opsQuery);
        if ($opsStmt) {
            $opsStmt->bind_param("s", $branch);
            $opsStmt->execute();
            $opsRes = $opsStmt->get_result();
            while ($opRow = $opsRes->fetch_assoc()) {
                $addTag($opRow['id'], $opRow['full_name'], $opRow['designation'] ?: portal_role_label_clean($opRow['portal_role']), 'ops', 'Operations');
            }
        }
    }

    // 5. Tag Super Admin (for oversight & monitoring)
    $saRes = $conn->query("
        SELECT id, full_name, designation, portal_role 
        FROM users 
        WHERE portal_role = 'super_admin' 
        AND (status = 'active' OR status IS NULL) 
        ORDER BY id ASC 
        LIMIT 1
    ");
    if ($saRes && $saRow = $saRes->fetch_assoc()) {
        $addTag($saRow['id'], $saRow['full_name'], 'Super Admin', 'super_admin', 'Super Admin');
    }

    echo json_encode([
        'success' => true,
        'department' => $department,
        'branch' => $branch,
        'auto_tags' => $autoTags
    ]);
    exit;
}

function createRequest($conn, $current_emp_id, $current_emp_name) {
    $emp_id = (int)($_POST['employee_id'] ?? $current_emp_id);
    $emp_name = trim($_POST['employee_name'] ?? $current_emp_name);
    
    if (!$emp_id && !empty($_POST['employee_name'])) {
        // try to lookup employee ID by name
        $name_esc = $conn->real_escape_string($emp_name);
        $lookup = $conn->query("SELECT id FROM employees WHERE full_name = '$name_esc' LIMIT 1");
        if ($lookup && $row = $lookup->fetch_assoc()) {
            $emp_id = (int)$row['id'];
        }
    }

    $department = trim($_POST['department'] ?? 'IT');
    $request_type = trim($_POST['request_type'] ?? 'General Issue');
    $priority = strtolower(trim($_POST['priority'] ?? 'normal'));
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
        $priority = 'normal';
    }
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tagged_users_raw = $_POST['tagged_users'] ?? '[]';

    if (empty($subject) || empty($description)) {
        echo json_encode(['success' => false, 'error' => 'Subject and Description are required.']);
        exit;
    }

    // Generate unique Ticket Code: REQ-YYYY-XXXX
    $year = date('Y');
    $resCount = $conn->query("SELECT COUNT(*) as c FROM portal_requests WHERE YEAR(created_at) = '$year'");
    $count = ($resCount && $row = $resCount->fetch_assoc()) ? (int)$row['c'] + 1 : 1;
    $ticket_code = sprintf("REQ-%s-%04d", $year, $count);

    // Double check unique ticket code
    $chk = $conn->query("SELECT id FROM portal_requests WHERE ticket_code = '$ticket_code' LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        $ticket_code = sprintf("REQ-%s-%04d-%d", $year, $count, rand(10, 99));
    }

    // Handle File Attachment if present
    $attachment_path = null;
    $attachment_name = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx', 'xlsx', 'txt', 'zip'];
        if (in_array($ext, $allowed)) {
            $newFileName = 'req_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destination = __DIR__ . '/../uploads/request_hub/' . $newFileName;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $attachment_path = 'uploads/request_hub/' . $newFileName;
                $attachment_name = $file['name'];
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO portal_requests (ticket_code, employee_id, employee_name, department, request_type, priority, subject, description, attachment_path, attachment_name, tagged_users, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("sisssssssss", $ticket_code, $emp_id, $emp_name, $department, $request_type, $priority, $subject, $description, $attachment_path, $attachment_name, $tagged_users_raw);

    if ($stmt->execute()) {
        $request_id = $stmt->insert_id;
        
        // Add initial system remark
        $init_remark = "Ticket created by {$emp_name} under {$department} department.";
        $init_role = "System / Creator";
        $stmtRemark = $conn->prepare("INSERT INTO portal_request_remarks (request_id, author_id, author_name, author_role, remark, status_change, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmtRemark->bind_param("iisss", $request_id, $emp_id, $emp_name, $init_role, $init_remark);
        $stmtRemark->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Request ticket submitted successfully!',
            'ticket_code' => $ticket_code,
            'request_id' => $request_id
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    }
    exit;
}

function getRequests($conn, $current_emp_id) {
    $emp_id = (int)($_GET['employee_id'] ?? $current_emp_id);
    $filter_tab = $_GET['tab'] ?? 'all'; // 'my', 'tagged', 'all'
    $department = $_GET['department'] ?? '';
    $status = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');

    // Check user's portal role for Super Admin / Admin override
    $user_role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
    if (empty($user_role) && $emp_id > 0) {
        $rStmt = $conn->prepare("SELECT portal_role FROM users WHERE id = ? LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param("i", $emp_id);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            if ($rRes && $rRow = $rRes->fetch_assoc()) {
                $user_role = $rRow['portal_role'] ?? '';
            }
        }
    }

    $is_super_admin = in_array(strtolower($user_role), ['super_admin', 'admin']);

    $where = [];

    // PRIVACY & PERMISSION RULE:
    // If not super_admin/admin, user ONLY sees tickets where:
    // 1) They are the creator (employee_id = $emp_id), OR
    // 2) They are tagged in the ticket (tagged_users contains their ID)
    if (!$is_super_admin) {
        if ($emp_id > 0) {
            $emp_id_str = '"id":' . $emp_id;
            $emp_id_str2 = '"id":"' . $emp_id . '"';
            $where[] = "(employee_id = $emp_id OR tagged_users LIKE '%$emp_id_str%' OR tagged_users LIKE '%$emp_id_str2%')";
        } else {
            // Guest or unknown without session sees nothing
            $where[] = "1=0";
        }
    }

    // Specific Tab Sub-Filtering:
    if ($filter_tab === 'my' && $emp_id > 0) {
        $where[] = "employee_id = $emp_id";
    } elseif ($filter_tab === 'tagged' && $emp_id > 0) {
        $emp_id_str = '"id":' . $emp_id;
        $emp_id_str2 = '"id":"' . $emp_id . '"';
        $where[] = "(tagged_users LIKE '%$emp_id_str%' OR tagged_users LIKE '%$emp_id_str2%')";
    }

    if ($department && $department !== 'all') {
        $dept_esc = $conn->real_escape_string($department);
        $where[] = "department = '$dept_esc'";
    }

    if ($status && $status !== 'all') {
        $status_esc = $conn->real_escape_string($status);
        $where[] = "status = '$status_esc'";
    }

    if ($search !== '') {
        $s_esc = $conn->real_escape_string($search);
        $where[] = "(ticket_code LIKE '%$s_esc%' OR subject LIKE '%$s_esc%' OR employee_name LIKE '%$s_esc%' OR description LIKE '%$s_esc%')";
    }

    $where_sql = !empty($where) ? implode(" AND ", $where) : "1=1";
    $query = "SELECT r.*, 
              (SELECT COUNT(*) FROM portal_request_remarks rm WHERE rm.request_id = r.id) as remarks_count,
              (SELECT created_at FROM portal_request_remarks rm WHERE rm.request_id = r.id ORDER BY id DESC LIMIT 1) as last_activity
              FROM portal_requests r 
              WHERE $where_sql 
              ORDER BY r.created_at DESC LIMIT 200";

    $res = $conn->query($query);
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['tagged_users_list'] = json_decode($row['tagged_users'] ?? '[]', true) ?: [];
            $list[] = $row;
        }
    }

    echo json_encode(['success' => true, 'data' => $list]);
    exit;
}

function getRequestDetails($conn, $current_emp_id) {
    $id = (int)($_GET['id'] ?? 0);
    $ticket_code = trim($_GET['ticket_code'] ?? '');
    $emp_id = (int)($_GET['employee_id'] ?? $current_emp_id);

    if ($id <= 0 && empty($ticket_code)) {
        echo json_encode(['success' => false, 'error' => 'Invalid ticket identifier.']);
        exit;
    }

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT * FROM portal_requests WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM portal_requests WHERE ticket_code = ? LIMIT 1");
        $stmt->bind_param("s", $ticket_code);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Request not found.']);
        exit;
    }

    $ticket = $res->fetch_assoc();
    $tagged = json_decode($ticket['tagged_users'] ?? '[]', true) ?: [];
    $ticket['tagged_users_list'] = $tagged;

    // Verify view permissions:
    $user_role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
    if (empty($user_role) && $emp_id > 0) {
        $rStmt = $conn->prepare("SELECT portal_role FROM users WHERE id = ? LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param("i", $emp_id);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            if ($rRes && $rRow = $rRes->fetch_assoc()) {
                $user_role = $rRow['portal_role'] ?? '';
            }
        }
    }
    $is_super_admin = in_array(strtolower($user_role), ['super_admin', 'admin']);
    $is_creator = ((int)$ticket['employee_id'] === $emp_id);
    $is_tagged = false;
    foreach ($tagged as $tg) {
        if (isset($tg['id']) && (int)$tg['id'] === $emp_id) {
            $is_tagged = true;
            break;
        }
    }

    // Check if user's actual designation or portal role matches the ticket's department handler
    $user_desig = '';
    if ($emp_id > 0) {
        $uInfoStmt = $conn->prepare("SELECT portal_role, designation FROM users WHERE id = ? LIMIT 1");
        if ($uInfoStmt) {
            $uInfoStmt->bind_param("i", $emp_id);
            $uInfoStmt->execute();
            $uInfoRes = $uInfoStmt->get_result();
            if ($uInfoRes && $uRow = $uInfoRes->fetch_assoc()) {
                $user_role = $uRow['portal_role'] ?? $user_role;
                $user_desig = $uRow['designation'] ?? '';
            }
        }
    }

    $dept = strtolower(trim($ticket['department'] ?? ''));
    $u_role_clean = strtolower($user_role);
    $u_desig_clean = strtolower($user_desig);
    $is_dept_handler = false;

    // RULE: Strictly Department Specialists can change status/close ticket:
    // IT tickets -> IT Support / IT Manager (strictly NOT developers, NOT reporting managers, NOT super admins)
    // HR tickets -> HR Officer / HR Manager
    // Finance tickets -> Finance Officer / Accountant
    // Operations tickets -> Operations Lead / Floor Manager
    if ($dept === 'it') {
        if ($u_role_clean === 'it' || (strpos($u_desig_clean, 'it support') !== false || strpos($u_desig_clean, 'it manager') !== false || strpos($u_desig_clean, 'network') !== false)) {
            // Ensure not a developer
            if (strpos($u_desig_clean, 'developer') === false && $u_role_clean !== 'developer') {
                $is_dept_handler = true;
            }
        }
    } elseif ($dept === 'hr') {
        if ($u_role_clean === 'hr' || strpos($u_desig_clean, 'hr') !== false) {
            $is_dept_handler = true;
        }
    } elseif ($dept === 'finance') {
        if ($u_role_clean === 'finance' || strpos($u_desig_clean, 'finance') !== false || strpos($u_desig_clean, 'account') !== false || strpos($u_desig_clean, 'cashier') !== false) {
            $is_dept_handler = true;
        }
    } elseif ($dept === 'operations') {
        if (in_array($u_role_clean, ['floor_manager', 'operations']) || strpos($u_desig_clean, 'operations') !== false || strpos($u_desig_clean, 'floor manager') !== false) {
            $is_dept_handler = true;
        }
    }

    // Also verify if the user was tagged with tag_type matching the department
    if ($is_tagged && !$is_dept_handler) {
        foreach ($tagged as $tg) {
            if (isset($tg['id']) && (int)$tg['id'] === $emp_id) {
                $tgType = strtolower($tg['tag_type'] ?? '');
                if ($tgType === $dept || ($dept === 'operations' && $tgType === 'ops')) {
                    $is_dept_handler = true;
                    break;
                }
            }
        }
    }

    // ONLY the assigned department specialist can change/resolve status (Super Admin / HOD / Reporting Manager can add remarks only)
    $can_change_status = $is_dept_handler;

    // Fetch remarks thread
    $req_id = (int)$ticket['id'];
    $rRes = $conn->query("SELECT * FROM portal_request_remarks WHERE request_id = $req_id ORDER BY created_at ASC");
    $remarks = [];
    if ($rRes) {
        while ($r = $rRes->fetch_assoc()) {
            $remarks[] = $r;
        }
    }

    // Required handler role display string
    $dept_handler_labels = [
        'it' => 'IT Support / IT Manager',
        'hr' => 'Branch HR Officer',
        'finance' => 'Finance Team',
        'operations' => 'Operations Lead',
        'general' => 'Administrative Desk'
    ];
    $required_handler_label = $dept_handler_labels[$dept] ?? 'Department Specialist';

    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'remarks' => $remarks,
        'permissions' => [
            'can_change_status' => $can_change_status,
            'is_dept_handler' => $is_dept_handler,
            'is_super_admin' => in_array(strtolower($user_role), ['super_admin', 'admin']),
            'is_creator' => $is_creator,
            'is_tagged' => $is_tagged,
            'required_handler_label' => $required_handler_label
        ]
    ]);
    exit;
}

function addRemark($conn, $current_emp_id, $current_emp_name, $current_emp_role) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $author_id = (int)($_POST['author_id'] ?? $current_emp_id);
    $author_name = trim($_POST['author_name'] ?? $current_emp_name);
    $author_role = trim($_POST['author_role'] ?? $current_emp_role);
    $remark = trim($_POST['remark'] ?? '');
    $status_change = trim($_POST['status_change'] ?? '');

    if ($request_id <= 0 || empty($remark)) {
        echo json_encode(['success' => false, 'error' => 'Request ID and remark message are required.']);
        exit;
    }

    // Check attachment
    $attachment_path = null;
    $attachment_name = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx', 'xlsx', 'txt', 'zip'];
        if (in_array($ext, $allowed)) {
            $newFileName = 'rem_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destination = __DIR__ . '/../uploads/request_hub/' . $newFileName;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $attachment_path = 'uploads/request_hub/' . $newFileName;
                $attachment_name = $file['name'];
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO portal_request_remarks (request_id, author_id, author_name, author_role, remark, attachment_path, attachment_name, status_change, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iissssss", $request_id, $author_id, $author_name, $author_role, $remark, $attachment_path, $attachment_name, $status_change);

    if ($stmt->execute()) {
        // If status changed along with remark, verify permissions
        if (!empty($status_change) && in_array($status_change, ['pending', 'in_progress', 'resolved', 'rejected', 'closed'])) {
            // Check ticket department and user authorization
            $tCheck = $conn->query("SELECT department, tagged_users FROM portal_requests WHERE id = $request_id LIMIT 1");
            $canChange = false;
            
            // Get accurate user designation and role
            $user_role_clean = strtolower($_SESSION['portal_role'] ?? $_SESSION['role'] ?? '');
            $user_desig_clean = '';
            if ($author_id > 0) {
                $uStmt = $conn->prepare("SELECT portal_role, designation FROM users WHERE id = ? LIMIT 1");
                if ($uStmt) {
                    $uStmt->bind_param("i", $author_id);
                    $uStmt->execute();
                    $uRes = $uStmt->get_result();
                    if ($uRes && $uRow = $uRes->fetch_assoc()) {
                        $user_role_clean = strtolower($uRow['portal_role'] ?? $user_role_clean);
                        $user_desig_clean = strtolower($uRow['designation'] ?? '');
                    }
                }
            }

            if ($tCheck && $tRow = $tCheck->fetch_assoc()) {
                $dept = strtolower(trim($tRow['department'] ?? ''));
                if ($dept === 'it') {
                    if (($user_role_clean === 'it' || strpos($user_desig_clean, 'it support') !== false || strpos($user_desig_clean, 'it manager') !== false || strpos($user_desig_clean, 'network') !== false) && strpos($user_desig_clean, 'developer') === false && $user_role_clean !== 'developer') {
                        $canChange = true;
                    }
                } elseif ($dept === 'hr') {
                    if ($user_role_clean === 'hr' || strpos($user_desig_clean, 'hr') !== false) {
                        $canChange = true;
                    }
                } elseif ($dept === 'finance') {
                    if ($user_role_clean === 'finance' || strpos($user_desig_clean, 'finance') !== false || strpos($user_desig_clean, 'account') !== false || strpos($user_desig_clean, 'cashier') !== false) {
                        $canChange = true;
                    }
                } elseif ($dept === 'operations') {
                    if (in_array($user_role_clean, ['floor_manager', 'operations']) || strpos($user_desig_clean, 'operations') !== false || strpos($user_desig_clean, 'floor manager') !== false) {
                        $canChange = true;
                    }
                }

                // Check if tagged explicitly with matching department tag_type
                if (!$canChange) {
                    $tTags = json_decode($tRow['tagged_users'] ?? '[]', true) ?: [];
                    foreach ($tTags as $tg) {
                        if (isset($tg['id']) && (int)$tg['id'] === $author_id) {
                            $tgType = strtolower($tg['tag_type'] ?? '');
                            if ($tgType === $dept || ($dept === 'operations' && $tgType === 'ops')) {
                                $canChange = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($canChange) {
                $is_resolved = in_array($status_change, ['resolved', 'closed']);
                $resolved_by = $is_resolved ? $author_name : null;
                $resolved_at = $is_resolved ? date('Y-m-d H:i:s') : null;

                $uStmt = $conn->prepare("UPDATE portal_requests SET status = ?, resolved_by = COALESCE(?, resolved_by), resolved_at = COALESCE(?, resolved_at), updated_at = NOW() WHERE id = ?");
                $uStmt->bind_param("sssi", $status_change, $resolved_by, $resolved_at, $request_id);
                $uStmt->execute();
            }
        }

        echo json_encode(['success' => true, 'message' => 'Remark posted successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    }
    exit;
}

function updateStatus($conn, $current_emp_id, $current_emp_name, $current_emp_role) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $user_name = trim($_POST['user_name'] ?? $current_emp_name);
    $user_id = (int)($_POST['user_id'] ?? $current_emp_id);
    $user_role = trim($_POST['user_role'] ?? $current_emp_role);

    if ($request_id <= 0 || !in_array($status, ['pending', 'in_progress', 'resolved', 'rejected', 'closed'])) {
        echo json_encode(['success' => false, 'error' => 'Valid request ID and status required.']);
        exit;
    }

    // Strict validation: Only department specialist can update status directly
    $tCheck = $conn->query("SELECT department, tagged_users FROM portal_requests WHERE id = $request_id LIMIT 1");
    if (!$tCheck || $tCheck->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found.']);
        exit;
    }
    $tRow = $tCheck->fetch_assoc();
    $dept = strtolower(trim($tRow['department'] ?? ''));

    // Check user role and designation
    $uRole = strtolower($user_role);
    $uDesig = '';
    if ($user_id > 0) {
        $uInfoStmt = $conn->prepare("SELECT portal_role, designation FROM users WHERE id = ? LIMIT 1");
        if ($uInfoStmt) {
            $uInfoStmt->bind_param("i", $user_id);
            $uInfoStmt->execute();
            $uInfoRes = $uInfoStmt->get_result();
            if ($uInfoRes && $uRow = $uInfoRes->fetch_assoc()) {
                $uRole = strtolower($uRow['portal_role'] ?? $uRole);
                $uDesig = strtolower($uRow['designation'] ?? '');
            }
        }
    }

    $isAuthorized = false;
    if ($dept === 'it') {
        if (($uRole === 'it' || strpos($uDesig, 'it support') !== false || strpos($uDesig, 'it manager') !== false || strpos($uDesig, 'network') !== false) && strpos($uDesig, 'developer') === false && $uRole !== 'developer') {
            $isAuthorized = true;
        }
    } elseif ($dept === 'hr') {
        if ($uRole === 'hr' || strpos($uDesig, 'hr') !== false) {
            $isAuthorized = true;
        }
    } elseif ($dept === 'finance') {
        if ($uRole === 'finance' || strpos($uDesig, 'finance') !== false || strpos($uDesig, 'account') !== false || strpos($uDesig, 'cashier') !== false) {
            $isAuthorized = true;
        }
    } elseif ($dept === 'operations') {
        if (in_array($uRole, ['floor_manager', 'operations']) || strpos($uDesig, 'operations') !== false || strpos($uDesig, 'floor manager') !== false) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        $tTags = json_decode($tRow['tagged_users'] ?? '[]', true) ?: [];
        foreach ($tTags as $tg) {
            if (isset($tg['id']) && (int)$tg['id'] === $user_id) {
                $tgType = strtolower($tg['tag_type'] ?? '');
                if ($tgType === $dept || ($dept === 'operations' && $tgType === 'ops')) {
                    $isAuthorized = true;
                    break;
                }
            }
        }
    }

    if (!$isAuthorized) {
        echo json_encode([
            'success' => false, 
            'error' => "Action not permitted. Only the assigned {$tRow['department']} department specialists can modify this case status."
        ]);
        exit;
    }

    $is_resolved = in_array($status, ['resolved', 'closed']);
    $resolved_by = $is_resolved ? $user_name : null;
    $resolved_at = $is_resolved ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("UPDATE portal_requests SET status = ?, resolution_notes = ?, resolved_by = COALESCE(?, resolved_by), resolved_at = COALESCE(?, resolved_at), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssssi", $status, $notes, $resolved_by, $resolved_at, $request_id);

    if ($stmt->execute()) {
        // Add audit remark
        $status_label = ucfirst(str_replace('_', ' ', $status));
        $action_remark = "Status changed to '{$status_label}' by {$user_name}" . (!empty($notes) ? " — Notes: {$notes}" : "");
        $stmtRemark = $conn->prepare("INSERT INTO portal_request_remarks (request_id, author_id, author_name, author_role, remark, status_change, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmtRemark->bind_param("iissss", $request_id, $user_id, $user_name, $user_role, $action_remark, $status);
        $stmtRemark->execute();

        echo json_encode(['success' => true, 'message' => "Status updated to {$status_label} successfully."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    }
    exit;
}
?>
