<?php
// =====================================================
// ATTENDANCE API - FIXED VERSION (March 2026)
// Fixed: Check-outs no longer appear in check-in column
// Added: CSV Employee Data Matching for Department, Designation, Branch, Team
// UPDATED: Shift timing changed to 6:00 PM - 4:00 AM with 10 minutes grace period
// =====================================================

date_default_timezone_set('Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';


// =====================================================
// ATTENDANCE BRANCH READ ACCESS
// Super Admin + Finance can VIEW Main + Commercial.
// Writes continue using TABLE_ATTENDANCE/current branch.
// =====================================================
function attendanceCanViewAllBranches(): bool {
    $role = strtolower(trim((string)($_SESSION['portal_role'] ?? '')));
    return in_array($role, ['super_admin', 'finance'], true);
}

function attendanceReadTableForBranch($branch): string {
    if (!attendanceCanViewAllBranches()) {
        return TABLE_ATTENDANCE;
    }

    $branch = strtolower(trim((string)$branch));

    if (strpos($branch, 'commercial') !== false) {
        return 'attendance_commercial_raw';
    }

    return 'attendance_raw';
}

function attendanceBulkReadSource(): string {
    if (!attendanceCanViewAllBranches()) {
        return TABLE_ATTENDANCE;
    }

    return "(SELECT user_id, timestamp FROM attendance_raw
             UNION ALL
             SELECT user_id, timestamp FROM attendance_commercial_raw) AS attendance_read";
}


// =====================================================
// NEW: Load Employee Data from CSV (Sheet4)
// =====================================================
function loadEmployeeDataFromCSV() {
    global $conn;
    $employees = [];
    
    // 1. Fetch primary employee data directly from User Management (users & employee_payroll_meta tables)
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $sql = "
            SELECT 
                u.employee_code, 
                u.full_name, 
                COALESCE(NULLIF(u.team, ''), '') as team,
                COALESCE(NULLIF(u.department, ''), '') as department,
                COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), 'Employee') as designation,
                COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), 'Main') as branch
            FROM users u
            LEFT JOIN employee_payroll_meta m ON (u.employee_code IS NOT NULL AND u.employee_code != '' AND u.employee_code = m.employee_code)
            WHERE u.status = 'active' AND u.employee_code IS NOT NULL AND u.employee_code != ''
        ";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $b_id = trim($row['employee_code']);
                if ($b_id === '') continue;
                $employees[$b_id] = [
                    'id' => $b_id,
                    'name' => $row['full_name'],
                    'designation' => $row['designation'] ?? 'Employee',
                    'department' => trim($row['department'] ?? ''),
                    'branch' => $row['branch'] ?? 'Main',
                    'team' => $row['team'] ?? ''
                ];
            }
        }
    }

    // 2. CSV fallback for historical BIDs not yet in users table
    $csv_file = __DIR__ . '/Present Employee Data - Sheet4.csv';
    if (file_exists($csv_file)) {
        $file = fopen($csv_file, 'r');
        if ($file) {
            fgetcsv($file);
            while (($row = fgetcsv($file)) !== FALSE) {
                if (empty(array_filter($row))) continue;
                $row = array_map('trim', $row);
                if (!empty($row[0])) {
                    $b_id = $row[0];
                    if (!isset($employees[$b_id])) {
                        $employees[$b_id] = [
                            'id' => $b_id,
                            'name' => $row[1] ?? '',
                            'team' => $row[2] ?? '',
                            'department' => trim($row[3] ?? ''),
                            'designation' => $row[4] ?? '',
                            'branch' => $row[5] ?? ''
                        ];
                    }
                }
            }
            fclose($file);
        }
    }
    
    return $employees;
}

// =====================================================
// NEW: Get Employee Details from CSV by ID
// =====================================================
function getEmployeeDetailsFromCSV($employee_id) {
    static $csv_data = null;
    
    // Load CSV data only once
    if ($csv_data === null) {
        $csv_data = loadEmployeeDataFromCSV();
    }
    
    return $csv_data[$employee_id] ?? null;
}

// =====================================================
// NEW: Get All Departments from CSV
// =====================================================
function getDepartmentsFromCSV() {
    static $csv_data = null;
    
    if ($csv_data === null) {
        $csv_data = loadEmployeeDataFromCSV();
    }
    
    $departments = [];
    foreach ($csv_data as $emp) {
        if (!empty($emp['department'])) {
            $departments[$emp['department']] = true;
        }
    }
    
    return array_keys($departments);
}

// =====================================================
// NEW: Auto Sync Active Users from User Management to Attendance Tables
// =====================================================
function syncActiveUsersToAttendanceTables(mysqli $conn): void {
    static $synced = false;
    if ($synced) return;
    $synced = true;

    $sql = "
        SELECT 
            u.employee_code, 
            u.full_name, 
            COALESCE(NULLIF(u.team, ''), '') as team,
            COALESCE(NULLIF(u.department, ''), '') as department,
            COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), 'Employee') as designation,
            COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), 'Main') as branch
        FROM users u
        LEFT JOIN employee_payroll_meta m ON (u.employee_code IS NOT NULL AND u.employee_code != '' AND u.employee_code = m.employee_code)
        WHERE u.status = 'active' AND u.employee_code IS NOT NULL AND u.employee_code != ''
    ";
    $res = $conn->query($sql);
    if (!$res) return;

    while ($row = $res->fetch_assoc()) {
        $code = trim($row['employee_code']);
        if ($code === '') continue;

        $name = $row['full_name'];
        $dept = !empty($row['department']) ? $row['department'] : 'General';
        $desig = !empty($row['designation']) ? $row['designation'] : 'Employee';
        $team = $row['team'] ?? '';
        $branch = !empty($row['branch']) ? $row['branch'] : 'Main';

        // 1. Sync to employees table
        $chk1 = $conn->prepare("SELECT id FROM employees WHERE employee_code = ? LIMIT 1");
        if ($chk1) {
            $chk1->bind_param("s", $code);
            $chk1->execute();
            $r1 = $chk1->get_result();
            if ($r1 && $r1->num_rows > 0) {
                $u1 = $conn->prepare("UPDATE employees SET full_name = ?, department = ?, designation = ?, team = ?, branch = ?, is_active = 1 WHERE employee_code = ?");
                if ($u1) {
                    $u1->bind_param("ssssss", $name, $dept, $desig, $team, $branch, $code);
                    $u1->execute();
                }
            } else {
                $i1 = $conn->prepare("INSERT INTO employees (employee_code, full_name, department, designation, team, branch, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                if ($i1) {
                    $i1->bind_param("ssssss", $code, $name, $dept, $desig, $team, $branch);
                    $i1->execute();
                }
            }
        }

        // 2. Sync to employees_commercial table if exists
        $chk2 = $conn->prepare("SELECT id FROM employees_commercial WHERE employee_code = ? LIMIT 1");
        if ($chk2) {
            $chk2->bind_param("s", $code);
            $chk2->execute();
            $r2 = $chk2->get_result();
            if ($r2 && $r2->num_rows > 0) {
                $u2 = $conn->prepare("UPDATE employees_commercial SET full_name = ?, department = ?, designation = ?, team = ?, branch = ?, is_active = 1 WHERE employee_code = ?");
                if ($u2) {
                    $u2->bind_param("ssssss", $name, $dept, $desig, $team, $branch, $code);
                    $u2->execute();
                }
            } else {
                $i2 = $conn->prepare("INSERT INTO employees_commercial (employee_code, full_name, department, designation, team, branch, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                if ($i2) {
                    $i2->bind_param("ssssss", $code, $name, $dept, $desig, $team, $branch);
                    $i2->execute();
                }
            }
        }
    }
}

// =====================================================
// NEW: Get All Branches from CSV
// =====================================================
function getBranchesFromCSV() {
    static $csv_data = null;
    
    if ($csv_data === null) {
        $csv_data = loadEmployeeDataFromCSV();
    }
    
    $branches = [];
    foreach ($csv_data as $emp) {
        if (!empty($emp['branch'])) {
            $branches[$emp['branch']] = true;
        }
    }
    
    return array_keys($branches);
}

// =====================================================
// NEW: Get All Designations from CSV
// =====================================================
function getDesignationsFromCSV() {
    static $csv_data = null;
    
    if ($csv_data === null) {
        $csv_data = loadEmployeeDataFromCSV();
    }
    
    $designations = [];
    foreach ($csv_data as $emp) {
        if (!empty($emp['designation'])) {
            $designations[$emp['designation']] = true;
        }
    }
    
    return array_keys($designations);
}

// =====================================================
// NEW: Get All Teams from DB and CSV
// =====================================================
function getTeamsFromCSV() {
    global $conn;
    static $teams_cached = null;
    if ($teams_cached !== null) return $teams_cached;
    
    $teams = [];
    
    // Fetch from users table
    if ($conn) {
        $res = $conn->query("SELECT DISTINCT team FROM users WHERE team IS NOT NULL AND team != ''");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $t = trim($row['team']);
                if ($t !== '') $teams[$t] = true;
            }
        }
        
        $res2 = $conn->query("SELECT DISTINCT team FROM employees WHERE team IS NOT NULL AND team != ''");
        if ($res2) {
            while ($row = $res2->fetch_assoc()) {
                $t = trim($row['team']);
                if ($t !== '') $teams[$t] = true;
            }
        }
    }
    
    // Also include CSV teams
    $csv_data = loadEmployeeDataFromCSV();
    foreach ($csv_data as $emp) {
        if (!empty($emp['team'])) {
            $teams[trim($emp['team'])] = true;
        }
    }
    
    $teams_cached = array_keys($teams);
    sort($teams_cached);
    return $teams_cached;
}

// ===== SHIFT WINDOW CONFIGURATION - UPDATED =====
// Shift: 6:00 PM to 4:00 AM
if (!defined('SHIFT_START')) {
    define('SHIFT_START', '18:00:00'); // 6:00 PM (UPDATED from 19:00:00)
}
if (!defined('SHIFT_END')) {
    define('SHIFT_END', '04:00:00');   // 4:00 AM next day (UPDATED from 05:00:00)
}
if (!defined('GRACE_MINUTES')) {
    define('GRACE_MINUTES', 10); // 10 minutes grace period (UPDATED from 15)
}

if (!function_exists('sendJSON')) {
    function sendJSON($success, $data = null, $message = '') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'data'    => $data,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}

/**
 * Get shift windows - SEPARATE check-in and check-out windows
 * Check-in: 2PM to midnight of the date
 * Check-out: Midnight to noon of next day
 */
function getShiftWindows($date) {
    $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
    
    return [
        'checkin_start' => $date . ' 14:00:00',
        'checkin_end'   => $date . ' 23:59:59',
        'checkout_start' => $next_date . ' 00:00:00',
        'checkout_end'   => $next_date . ' 11:59:59'
    ];
}

function calculateWorkingHours($check_in, $check_out) {
    if (!$check_in || !$check_out) return 0;
    
    $in = strtotime($check_in);
    $out = strtotime($check_out);
    
    if ($out < $in) {
        $out = strtotime(date('Y-m-d', strtotime($check_out . ' +1 day')) . ' ' . date('H:i:s', strtotime($check_out)));
    }
    
    $hours = ($out - $in) / 3600;
    return round($hours, 2);
}

function isLate($punch_time, $shift_date, $team = '') {
    global $conn;
    require_once __DIR__ . '/../includes/attendance_shift.php';
    $start_time = ess_get_team_shift_start($conn, $team);
    $shift_start = strtotime($shift_date . ' ' . $start_time);
    $punch = strtotime($punch_time);
    
    if ($punch > $shift_start) {
        $minutes_late = round(($punch - $shift_start) / 60);
        return [true, max(1, (int)$minutes_late)];
    }
    
    return [false, 0];
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // =================================================
    // 0. GET WEEKLY HISTORY FOR EMPLOYEE PORTAL
    // =================================================
    case 'weekly':
        $emp_code = isset($_GET['employee_code']) ? trim($_GET['employee_code']) : '';
        if (empty($emp_code) && isset($_SESSION['employee_code'])) {
            $emp_code = $_SESSION['employee_code'];
        }
        if (empty($emp_code)) {
            sendJSON(false, null, 'Employee code required');
        }

        $emp_code = $conn->real_escape_string($emp_code);
        $emp_query = $conn->query("
            SELECT e.*, COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') as resolved_team 
            FROM " . TABLE_EMPLOYEES . " e 
            LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE e.employee_code = '$emp_code' LIMIT 1
        ");
        $employee = $emp_query ? $emp_query->fetch_assoc() : null;

        $records = [];
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $windows = getShiftWindows($date);

            // Get all punches for this shift date window (2PM to next day 12PM)
            $punches_result = $conn->query("
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkout_end']}'
                ORDER BY timestamp ASC
            ");
            
            $punches = [];
            if ($punches_result && $punches_result->num_rows > 0) {
                while ($p = $punches_result->fetch_assoc()) {
                    $punches[] = $p['timestamp'];
                }
            }

            $first_in = null;
            $last_out = null;
            $status = 'absent';
            $working_hours = 0;

            if (count($punches) > 0) {
                $first_in = $punches[0];
                
                list($is_late, $minutes) = isLate($first_in, $date, $employee['resolved_team'] ?? $employee['team'] ?? '');
                $status = $is_late ? 'late' : 'present';

                if (count($punches) >= 2) {
                    $last_out = $punches[count($punches) - 1];
                }
            }

            if ($first_in && $last_out) {
                $working_hours = calculateWorkingHours($first_in, $last_out);
            }

            $in_display = $first_in ? date('h:i A', strtotime($first_in)) : '---';
            $out_display = $last_out ? date('h:i A', strtotime($last_out)) : '---';

            $records[] = [
                'date'          => $date,
                'day'           => date('l', strtotime($date)),
                'in_time'       => $in_display,
                'out_time'      => $out_display,
                'working_hrs'   => $working_hours ? number_format($working_hours, 2) . ' hrs' : '0.00 hrs',
                'work_hours'    => $working_hours ? number_format($working_hours, 2) : '0.00',
                'status'        => $status
            ];
        }

        sendJSON(true, $records);
        break;

    // =================================================
    // 1. GET LIVE ATTENDANCE - UPDATED with CSV data
    // =================================================
    case 'getLiveAttendance':
        syncActiveUsersToAttendanceTables($conn);

        $selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            sendJSON(false, null, 'Invalid date format. Use YYYY-MM-DD');
        }
        
        $windows = getShiftWindows($selected_date);

        $employees = $conn->query("
            SELECT e.id, e.employee_code, e.full_name, e.department, COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') as team 
            FROM " . TABLE_EMPLOYEES . " e
            LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE e.is_active = 1 
            ORDER BY CAST(e.employee_code AS UNSIGNED)
        ");
        
        if (!$employees) {
            sendJSON(false, null, "DB error: " . $conn->error);
        }

        // Load CSV / User Management employee data once
        $csv_employees = loadEmployeeDataFromCSV();

        $attendance = [];
        $stats = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
        
        // New stats arrays for department/branch/designation/team
        $department_stats = [];
        $branch_stats = [];
        $designation_stats = [];
        $team_stats = []; // NEW: Added team stats

        while ($emp = $employees->fetch_assoc()) {
            $stats['total']++;
            $emp_code = $conn->real_escape_string($emp['employee_code']);
            
            // Get employee details from User Management / CSV
            $csv_emp = $csv_employees[$emp_code] ?? null;
            
            // Set name, department, designation, branch, team from User Management (with CSV fallback)
            $full_name = !empty($csv_emp['name']) ? $csv_emp['name'] : $emp['full_name'];
            $department = !empty($csv_emp['department']) ? $csv_emp['department'] : ($emp['department'] ?: 'General');
            $designation = !empty($csv_emp['designation']) ? $csv_emp['designation'] : 'Employee';
            $branch = !empty($csv_emp['branch']) ? $csv_emp['branch'] : ($active_branch === 'commercial' ? 'Commercial' : ($active_branch === 'workfromhome' ? 'workfromhome' : 'Main'));
            $attendance_read_table = attendanceReadTableForBranch($branch);
            $team = !empty($csv_emp['team']) ? $csv_emp['team'] : (!empty($emp['team']) ? $emp['team'] : '');
            
            // Initialize stats counters for department
            if (!isset($department_stats[$department])) {
                $department_stats[$department] = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
            }
            $department_stats[$department]['total']++;
            
            // Initialize stats counters for branch
            if (!isset($branch_stats[$branch])) {
                $branch_stats[$branch] = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
            }
            $branch_stats[$branch]['total']++;
            
            // Initialize stats counters for designation
            if (!isset($designation_stats[$designation])) {
                $designation_stats[$designation] = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
            }
            $designation_stats[$designation]['total']++;

            // NEW: Initialize stats counters for team
            if (!empty($team)) {
                if (!isset($team_stats[$team])) {
                    $team_stats[$team] = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
                }
                $team_stats[$team]['total']++;
            }

            // Get check-in punches (2PM to midnight of selected date)
            $checkin_punches = $conn->query("
                SELECT timestamp FROM " . $attendance_read_table . "
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
                ORDER BY timestamp
            ");

            // Get check-out punches (midnight to noon of next day)
            $checkout_punches = $conn->query("
                SELECT timestamp FROM " . $attendance_read_table . "
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkout_start']}' AND '{$windows['checkout_end']}'
                ORDER BY timestamp
            ");

            $checkins = [];
            $checkouts = [];
            
            if ($checkin_punches && $checkin_punches->num_rows > 0) {
                while ($p = $checkin_punches->fetch_assoc()) {
                    $checkins[] = $p['timestamp'];
                }
            }
            
            if ($checkout_punches && $checkout_punches->num_rows > 0) {
                while ($p = $checkout_punches->fetch_assoc()) {
                    $checkouts[] = $p['timestamp'];
                }
            }

            $first_in = !empty($checkins) ? $checkins[0] : null;
            $last_out = !empty($checkouts) ? $checkouts[count($checkouts)-1] : null;
            
            $punch_count = count($checkins) + count($checkouts);
            $status = 'absent';
            $late_minutes = 0;

            if ($first_in) {
                $stats['present']++;
                $department_stats[$department]['present']++;
                $branch_stats[$branch]['present']++;
                $designation_stats[$designation]['present']++;
                if (!empty($team)) {
                    $team_stats[$team]['present']++;
                }
                
                list($is_late, $minutes) = isLate($first_in, $selected_date, $team);
                if ($is_late) {
                    $status = 'late';
                    $stats['late']++;
                    $department_stats[$department]['late']++;
                    $branch_stats[$branch]['late']++;
                    $designation_stats[$designation]['late']++;
                    if (!empty($team)) {
                        $team_stats[$team]['late']++;
                    }
                    $late_minutes = $minutes;
                } else {
                    $status = 'present';
                }
            } elseif ($last_out) {
                // Has check-out but no check-in (unusual, but possible)
                $stats['present']++;
                $department_stats[$department]['present']++;
                $branch_stats[$branch]['present']++;
                $designation_stats[$designation]['present']++;
                if (!empty($team)) {
                    $team_stats[$team]['present']++;
                }
                $status = 'present';
            } else {
                $stats['absent']++;
                $department_stats[$department]['absent']++;
                $branch_stats[$branch]['absent']++;
                $designation_stats[$designation]['absent']++;
                if (!empty($team)) {
                    $team_stats[$team]['absent']++;
                }
            }

            $working_hours = 0;
            if ($first_in && $last_out) {
                $working_hours = calculateWorkingHours($first_in, $last_out);
            }

            $attendance[] = [
                'id'            => (int)$emp['id'],
                'code'          => $emp['employee_code'],
                'name'          => $full_name,
                'department'    => $department,
                'designation'   => $designation,
                'branch'        => $branch,
                'team'          => $team,
                'in_time'       => $first_in ? date('h:i A', strtotime($first_in)) : '--:--',
                'out_time'      => $last_out ? date('h:i A', strtotime($last_out)) : '--:--',
                'working_hrs'   => $working_hours,
                'status'        => $status,
                'late_minutes'  => $late_minutes,
                'punch_count'   => $punch_count,
                'has_check_out' => !empty($checkouts)
            ];
        }

        sendJSON(true, [
            'attendance' => $attendance,
            'stats'      => $stats,
            'department_stats' => $department_stats,
            'branch_stats' => $branch_stats,
            'designation_stats' => $designation_stats,
            'team_stats' => $team_stats, // NEW: Added team stats
            'date'       => $selected_date
        ]);
        break;

    // =================================================
    // 2. GET EMPLOYEE HISTORY - FIXED (CRITICAL FIX)
    // =================================================
    case 'getEmployeeHistory':
        $emp_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
        $month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

        if (!$emp_id) {
            sendJSON(false, null, 'Employee ID required');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            sendJSON(false, null, 'Invalid month format. Use YYYY-MM');
        }

        $emp = $conn->query("SELECT * FROM " . TABLE_EMPLOYEES . " WHERE id = $emp_id");
        if (!$emp || $emp->num_rows == 0) {
            sendJSON(false, null, 'Employee not found');
        }
        $employee = $emp->fetch_assoc();
        $emp_code = $conn->real_escape_string($employee['employee_code']);
        
        // Get CSV data for this employee
        $csv_emp = getEmployeeDetailsFromCSV($emp_code);

        $start_date = $month . '-01';
        $end_date = date('Y-m-t', strtotime($month . '-01'));

        $records = [];
        $summary = ['present' => 0, 'late' => 0, 'absent' => 0];

        $current = $start_date;
        while ($current <= $end_date) {
            $windows = getShiftWindows($current);
            
            // Get all punches for this shift date window (2PM to next day 12PM)
            $punches_result = $conn->query("
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkout_end']}'
                ORDER BY timestamp ASC
            ");
            
            $punches = [];
            if ($punches_result && $punches_result->num_rows > 0) {
                while ($p = $punches_result->fetch_assoc()) {
                    $punches[] = $p['timestamp'];
                }
            }

            $first_in = null;
            $last_out = null;
            $status = 'absent';
            $late_minutes = 0;
            $working_hours = 0;
            $has_check_out = false;

            if (count($punches) > 0) {
                $first_in = $punches[0];
                $summary['present']++;
                
                list($is_late, $minutes) = isLate($first_in, $current, $csv_emp['team'] ?? $employee['team'] ?? '');
                if ($is_late) {
                    $status = 'late';
                    $summary['late']++;
                    $late_minutes = $minutes;
                } else {
                    $status = 'present';
                }

                if (count($punches) >= 2) {
                    $last_out = $punches[count($punches) - 1];
                    $has_check_out = true;
                }
            }

            if ($first_in && $last_out) {
                $working_hours = calculateWorkingHours($first_in, $last_out);
            }

            // Format the display with proper indicators
            $in_display = $first_in ? date('h:i A', strtotime($first_in)) : '--:--';
            $out_display = $last_out ? date('h:i A', strtotime($last_out)) . ' out' : '--:--';
            
            // Add late minutes indicator
            if ($late_minutes > 0) {
                $in_display .= " <span class='late-badge'>($late_minutes min)</span>";
            }

            $records[] = [
                'date'          => $current,
                'day'           => date('l', strtotime($current)),
                'in_time'       => $in_display,
                'out_time'      => $out_display,
                'working_hrs'   => $working_hours ? number_format($working_hours, 2) . ' hrs' : '0 hrs',
                'status'        => $status,
                'late_minutes'  => $late_minutes,
                'has_check_out' => $has_check_out
            ];

            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        sendJSON(true, [
            'employee' => [
                'id' => (int)$employee['id'],
                'employee_code' => $employee['employee_code'],
                'full_name' => $employee['full_name'],
                'department' => $csv_emp['department'] ?? $employee['department'] ?: 'General',
                'designation' => $csv_emp['designation'] ?? 'Employee',
                'branch' => $csv_emp['branch'] ?? ($active_branch === 'commercial' ? 'Commercial' : ($active_branch === 'workfromhome' ? 'workfromhome' : 'Main')),
                'team' => $csv_emp['team'] ?? '' // NEW: Added team
            ],
            'records'  => $records,
            'summary'  => $summary
        ]);
        break;

    // =================================================
    // 3. GET ATTENDANCE FOR HR PORTAL
    // =================================================
    case 'getAttendanceForHR':
        $selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            sendJSON(false, null, 'Invalid date format. Use YYYY-MM-DD');
        }
        
        $windows = getShiftWindows($selected_date);

        $employees = $conn->query("
            SELECT e.id, e.employee_code, e.full_name, e.department, COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') as team 
            FROM " . TABLE_EMPLOYEES . " e
            LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE e.is_active = 1 
            ORDER BY CAST(e.employee_code AS UNSIGNED)
        ");

        if (!$employees) {
            sendJSON(false, null, "DB error: " . $conn->error);
        }
        
        $csv_employees = loadEmployeeDataFromCSV();

        $attendance = [];
        $stats = ['present' => 0, 'late' => 0, 'absent' => 0];

        while ($emp = $employees->fetch_assoc()) {
            $emp_code = $conn->real_escape_string($emp['employee_code']);
            $csv_emp = $csv_employees[$emp_code] ?? null;
            $team_name = !empty($csv_emp['team']) ? $csv_emp['team'] : (!empty($emp['team']) ? $emp['team'] : '');
            $employee_branch = !empty($csv_emp['branch']) ? $csv_emp['branch'] : ($active_branch === 'commercial' ? 'Commercial' : 'Main');
            $attendance_read_table = attendanceReadTableForBranch($employee_branch);

            // Get first check-in of the shift
            $punch = $conn->query("
                SELECT timestamp FROM " . $attendance_read_table . "
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
                ORDER BY timestamp LIMIT 1
            ");

            $status = 'absent';
            $in_time = '--:--';
            $late_minutes = 0;

            if ($punch && $punch->num_rows > 0) {
                $punch_data = $punch->fetch_assoc();
                $in_time = date('h:i A', strtotime($punch_data['timestamp']));
                
                list($is_late, $minutes) = isLate($punch_data['timestamp'], $selected_date, $team_name);
                if ($is_late) {
                    $status = 'late';
                    $stats['late']++;
                    $late_minutes = $minutes;
                } else {
                    $status = 'present';
                    $stats['present']++;
                }
            } else {
                $stats['absent']++;
            }

            $attendance[] = [
                'employee_id'   => (int)$emp['id'],
                'employee_name' => $emp['full_name'],
                'department'    => $csv_emp['department'] ?? $emp['department'] ?: 'General',
                'designation'   => $csv_emp['designation'] ?? 'Employee',
                'branch'        => $csv_emp['branch'] ?? ($active_branch === 'commercial' ? 'Commercial' : ($active_branch === 'workfromhome' ? 'workfromhome' : 'Main')),
                'team'          => $team_name,
                'date'          => $selected_date,
                'status'        => $status,
                'in_time'       => $in_time,
                'late_minutes'  => $late_minutes
            ];
        }

        sendJSON(true, $attendance);
        break;

    // =================================================
    // 4. GET DATE RANGE REPORT
    // =================================================
    case 'getDateRange':
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
        $department = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            sendJSON(false, null, 'Invalid date format. Use YYYY-MM-DD');
        }
        
        $whereE = $department ? "AND e.department = '$department'" : '';
        $whereEC = $department ? "AND ec.department = '$department'" : '';

        $employees = $conn->query("
            SELECT 
                e.employee_code COLLATE utf8mb4_unicode_ci as employee_code, 
                e.id, 
                e.full_name COLLATE utf8mb4_unicode_ci as full_name, 
                e.department COLLATE utf8mb4_unicode_ci as department, 
                COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') COLLATE utf8mb4_unicode_ci as team,
                COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), NULLIF(e.branch, ''), 'Main') COLLATE utf8mb4_unicode_ci as branch,
                COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), NULLIF(e.designation, ''), 'Employee') COLLATE utf8mb4_unicode_ci as designation
            FROM employees e
            LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            LEFT JOIN employee_payroll_meta m ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE e.is_active = 1 $whereE

            UNION

            SELECT 
                ec.employee_code COLLATE utf8mb4_unicode_ci as employee_code, 
                ec.id, 
                ec.full_name COLLATE utf8mb4_unicode_ci as full_name, 
                ec.department COLLATE utf8mb4_unicode_ci as department, 
                COALESCE(NULLIF(u.team, ''), NULLIF(ec.team, ''), '') COLLATE utf8mb4_unicode_ci as team,
                COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), NULLIF(ec.branch, ''), 'Commercial') COLLATE utf8mb4_unicode_ci as branch,
                COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), NULLIF(ec.designation, ''), 'Employee') COLLATE utf8mb4_unicode_ci as designation
            FROM employees_commercial ec
            LEFT JOIN users u ON (ec.employee_code IS NOT NULL AND ec.employee_code != '' AND ec.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            LEFT JOIN employee_payroll_meta m ON (ec.employee_code IS NOT NULL AND ec.employee_code != '' AND ec.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE ec.is_active = 1 $whereEC
            ORDER BY CAST(employee_code AS UNSIGNED)
        ");

        if (!$employees) {
            sendJSON(false, null, "Database error: " . $conn->error);
        }
        
        $csv_employees = loadEmployeeDataFromCSV();

        // Fetch ALL punches for the entire range for ALL employees in ONE query to be efficient
        $all_punches_query = $conn->query("
            SELECT user_id, timestamp 
            FROM " . attendanceBulkReadSource() . "
            WHERE timestamp BETWEEN '$start_date 14:00:00' AND '" . date('Y-m-d', strtotime($end_date . ' +1 day')) . " 12:00:00'
            ORDER BY timestamp ASC
        ");
        
        $punches_by_user = [];
        if ($all_punches_query) {
            while ($p = $all_punches_query->fetch_assoc()) {
                $punches_by_user[$p['user_id']][] = $p['timestamp'];
            }
        }

        $report = [];
        $total_days = (strtotime($end_date) - strtotime($start_date)) / (60*60*24) + 1;
        $seen_codes = [];

        while ($emp = $employees->fetch_assoc()) {
            $emp_code = $conn->real_escape_string($emp['employee_code']);
            if (empty($emp_code) || isset($seen_codes[$emp_code])) {
                continue;
            }
            $seen_codes[$emp_code] = true;

            $csv_emp = $csv_employees[$emp_code] ?? null;
            $team_name = !empty($csv_emp['team']) ? $csv_emp['team'] : (!empty($emp['team']) ? $emp['team'] : '');
            
            $present_days = 0;
            $late_days = 0;
            
            $user_punches = $punches_by_user[$emp_code] ?? [];
            
            $current = $start_date;
            while ($current <= $end_date) {
                $windows = getShiftWindows($current);
                
                // Find if employee had any punch in this shift (between checkin_start and checkout_end)
                $first_in = null;
                foreach ($user_punches as $p) {
                    if ($p >= $windows['checkin_start'] && $p <= $windows['checkout_end']) {
                        $first_in = $p;
                        break;
                    }
                }
                
                if ($first_in) {
                    $present_days++;
                    
                    // Check if first checkin punch (2PM to midnight of shift date) was late
                    $checkin_punch = null;
                    foreach ($user_punches as $p) {
                        if ($p >= $windows['checkin_start'] && $p <= $windows['checkin_end']) {
                            $checkin_punch = $p;
                            break;
                        }
                    }
                    if ($checkin_punch) {
                        list($is_late,) = isLate($checkin_punch, $current, $team_name);
                        if ($is_late) {
                            $late_days++;
                        }
                    }
                }
                
                $current = date('Y-m-d', strtotime($current . ' +1 day'));
            }
            
            $attendance_rate = $total_days > 0 ? round(($present_days / $total_days) * 100, 1) : 0;
            
            $report[] = [
                'code'            => $emp['employee_code'],
                'name'            => $emp['full_name'],
                'department'      => $csv_emp['department'] ?? $emp['department'] ?: 'General',
                'designation'     => $csv_emp['designation'] ?? 'Employee',
                'branch'          => !empty($csv_emp['branch']) ? $csv_emp['branch'] : (!empty($emp['branch']) ? $emp['branch'] : 'Main'),
                'team'            => $team_name,
                'present'         => $present_days,
                'late'            => $late_days,
                'absent'          => $total_days - $present_days,
                'total_days'      => (int)$total_days,
                'attendance_rate' => $attendance_rate
            ];
        }

        sendJSON(true, [
            'report' => $report,
            'period' => [
                'start' => $start_date,
                'end'   => $end_date,
                'total_days' => (int)$total_days
            ]
        ]);
        break;

    // =================================================
    // 5. IMPORT FROM PYTHON CSV
    // =================================================
    case 'importFromPython':
        $csv_file = __DIR__ . '/python-script/' . CSV_MASTER;
        
        $imported = 0;
        $skipped = 0;
        
        if (file_exists($csv_file)) {
            $file = fopen($csv_file, 'r');
            if ($file) {
                $headers = fgetcsv($file);
                
                $conn->begin_transaction();
                
                try {
                    while (($row = fgetcsv($file)) !== FALSE) {
                        $user_id = isset($row[0]) ? trim($row[0]) : '';
                        $name = isset($row[1]) ? trim($row[1]) : '';
                        $timestamp = isset($row[2]) ? trim($row[2]) : '';
                        $date = isset($row[3]) ? trim($row[3]) : '';
                        $time = isset($row[4]) ? trim($row[4]) : '';
                        
                        if (empty($user_id) || empty($timestamp)) {
                            $skipped++;
                            continue;
                        }
                        
                        $emp_check = $conn->query("SELECT id FROM " . TABLE_EMPLOYEES . " WHERE employee_code = '$user_id'");
                        
                        if (!$emp_check || $emp_check->num_rows == 0) {
                            $full_name = $name ? "'$name'" : "'User_$user_id'";
                            $conn->query("
                                INSERT INTO " . TABLE_EMPLOYEES . " (employee_code, full_name, department) 
                                VALUES ('$user_id', $full_name, 'General')
                            ");
                        }
                        
                        $check = $conn->query("
                            SELECT id FROM " . TABLE_ATTENDANCE . " 
                            WHERE user_id = '$user_id' 
                            AND timestamp = '$timestamp'
                        ");
                        
                        if ($check && $check->num_rows == 0) {
                            $conn->query("
                                INSERT INTO " . TABLE_ATTENDANCE . " (user_id, name, timestamp, date, time, sync_status) 
                                VALUES ('$user_id', '$name', '$timestamp', '$date', '$time', 'synced')
                            ");
                            $imported++;
                        } else {
                            $skipped++;
                        }
                    }
                    
                    $conn->commit();
                    fclose($file);
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    sendJSON(false, null, 'Import failed: ' . $e->getMessage());
                }
            }
        }
        
        sendJSON(true, [
            'imported' => $imported,
            'skipped' => $skipped
        ], "✅ Imported $imported new records, skipped $skipped duplicates");
        break;

    // =================================================
    // 6. SEARCH EMPLOYEES - UPDATED with CSV data
    // =================================================
    case 'searchEmployees':
        $query = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
        
        if (strlen($query) < 2) {
            sendJSON(true, []);
        }
        
        $result = $conn->query("
            SELECT id, employee_code, full_name, department 
            FROM " . TABLE_EMPLOYEES . " 
            WHERE is_active = 1 
            AND (employee_code LIKE '%$query%' OR full_name LIKE '%$query%' OR department LIKE '%$query%')
            ORDER BY CAST(employee_code AS UNSIGNED)
            LIMIT 20
        ");
        
        if (!$result) {
            sendJSON(false, null, "Search failed: " . $conn->error);
        }
        
        $csv_employees = loadEmployeeDataFromCSV();
        $employees = [];
        
        while ($row = $result->fetch_assoc()) {
            $csv_emp = $csv_employees[$row['employee_code']] ?? null;
            
            $employees[] = [
                'id' => (int)$row['id'],
                'employee_code' => $row['employee_code'],
                'full_name' => $row['full_name'],
                'department' => $csv_emp['department'] ?? $row['department'] ?: 'General',
                'designation' => $csv_emp['designation'] ?? 'Employee',
                'branch' => $csv_emp['branch'] ?? ($active_branch === 'commercial' ? 'Commercial' : ($active_branch === 'workfromhome' ? 'workfromhome' : 'Main')),
                'team' => $csv_emp['team'] ?? '' // NEW: Added team
            ];
        }
        
        sendJSON(true, $employees);
        break;

    // =================================================
    // 7. MANUAL PUNCH ENTRY
    // =================================================
    case 'manualPunch':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJSON(false, null, 'Manual punch requires POST method');
        }
        
        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $punch_time = isset($_POST['punch_time']) ? $_POST['punch_time'] : '';
        
        if (!$employee_id || !$punch_time) {
            sendJSON(false, null, 'Employee ID and punch time required');
        }
        
        if (!strtotime($punch_time)) {
            sendJSON(false, null, 'Invalid punch time format');
        }
        
        $emp_result = $conn->query("SELECT employee_code, full_name FROM " . TABLE_EMPLOYEES . " WHERE id = $employee_id");
        
        if (!$emp_result || $emp_result->num_rows == 0) {
            sendJSON(false, null, 'Employee not found');
        }
        
        $emp = $emp_result->fetch_assoc();
        
        $date = date('Y-m-d', strtotime($punch_time));
        $time = date('H:i:s', strtotime($punch_time));
        $timestamp = date('Y-m-d H:i:s', strtotime($punch_time));
        
        $insert = $conn->query("
            INSERT INTO " . TABLE_ATTENDANCE . " (user_id, name, timestamp, date, time, sync_status)
            VALUES ('{$emp['employee_code']}', '{$emp['full_name']}', '$timestamp', '$date', '$time', 'manual')
        ");
        
        if (!$insert) {
            sendJSON(false, null, 'Failed to insert punch: ' . $conn->error);
        }
        
        sendJSON(true, null, '✅ Punch recorded successfully');
        break;

    // =================================================
    // 7.1 BULK UPLOAD MONTHLY ATTENDANCE SHEET (MATRIX / ROW FORMAT - XLSX, XLS, CSV)
    // =================================================
    case 'bulkUploadMonthlyAttendance':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJSON(false, null, 'Bulk upload requires POST method');
        }

        if (!isset($_FILES['sheet_file']) || $_FILES['sheet_file']['error'] !== UPLOAD_ERR_OK) {
            sendJSON(false, null, 'Please select a valid Excel (.xlsx / .xls) or CSV (.csv) sheet file to upload');
        }

        $fileTmp = $_FILES['sheet_file']['tmp_name'];
        $fileName = $_FILES['sheet_file']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt', 'xlsx', 'xls'])) {
            sendJSON(false, null, 'Supported formats: Excel (.xlsx, .xls) and CSV (.csv). Please upload a supported file format.');
        }

        require_once __DIR__ . '/SimpleSpreadsheetReader.php';

        try {
            $allRows = SimpleSpreadsheetReader::parse($fileTmp, $ext);
        } catch (Exception $ex) {
            sendJSON(false, null, 'Failed to parse sheet: ' . $ex->getMessage());
        }

        if (empty($allRows)) {
            sendJSON(false, null, 'The uploaded sheet contains no data');
        }

        $monthYear = isset($_POST['month_year']) && preg_match('/^\d{4}-\d{2}$/', $_POST['month_year']) 
            ? $_POST['month_year'] 
            : date('Y-m');

        $defaultInTime = isset($_POST['default_in_time']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $_POST['default_in_time'])
            ? (strlen($_POST['default_in_time']) === 5 ? $_POST['default_in_time'] . ':00' : $_POST['default_in_time'])
            : '09:00:00';

        $defaultOutTime = isset($_POST['default_out_time']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $_POST['default_out_time'])
            ? (strlen($_POST['default_out_time']) === 5 ? $_POST['default_out_time'] . ':00' : $_POST['default_out_time'])
            : '18:00:00';

        // Preload employee map: employee_code => full_name
        $empMap = [];
        $empQuery = $conn->query("SELECT employee_code, full_name FROM " . TABLE_EMPLOYEES);
        if ($empQuery) {
            while ($er = $empQuery->fetch_assoc()) {
                $codeKey = trim(strval($er['employee_code']));
                if ($codeKey !== '') {
                    $empMap[$codeKey] = $er['full_name'];
                    $empMap[ltrim($codeKey, '0')] = $er['full_name']; // also without leading zeros
                }
            }
        }

        $insertedCount = 0;
        $skippedCount = 0;
        $unmappedCodes = [];
        $processedRows = 0;

        // Intelligent header search: find the row that contains 'biometric', 'emp', 'user_id', 'code', or 'sr no'
        $headerRowIdx = -1;
        $bioColIdx = -1;
        $nameColIdx = -1;
        $statusColIdx = -1;
        $checkInColIdx = -1;
        $checkOutColIdx = -1;
        $dateColIdx = -1;
        $dayCols = []; // day_number => col_index

        foreach ($allRows as $rIdx => $r) {
            if ($rIdx > 15) break; // header should be within first 15 rows
            
            $tempBio = -1;
            $tempName = -1;
            $tempStatus = -1;
            $tempIn = -1;
            $tempOut = -1;
            $tempDate = -1;
            $tempDays = [];

            foreach ($r as $cIdx => $cell) {
                $cClean = trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strval($cell)));
                $cLower = strtolower($cClean);

                if ($tempBio === -1 && (
                    stripos($cLower, 'biometric') !== false ||
                    stripos($cLower, 'bio_id') !== false ||
                    stripos($cLower, 'employee_code') !== false ||
                    stripos($cLower, 'emp_code') !== false ||
                    stripos($cLower, 'user_id') !== false ||
                    $cLower === 'code' ||
                    $cLower === 'id' ||
                    $cLower === 'emp id' ||
                    $cLower === 'bio id'
                )) {
                    $tempBio = $cIdx;
                }

                if ($tempName === -1 && (
                    stripos($cLower, 'name') !== false ||
                    stripos($cLower, 'employee') !== false ||
                    stripos($cLower, 'agent') !== false
                )) {
                    $tempName = $cIdx;
                }

                if ($tempStatus === -1 && (
                    $cLower === 'status' ||
                    stripos($cLower, 'attendance') !== false ||
                    stripos($cLower, 'att status') !== false
                )) {
                    $tempStatus = $cIdx;
                }

                if ($tempIn === -1 && (
                    stripos($cLower, 'check in') !== false ||
                    stripos($cLower, 'in time') !== false ||
                    stripos($cLower, 'time in') !== false ||
                    $cLower === 'in'
                )) {
                    $tempIn = $cIdx;
                }

                if ($tempOut === -1 && (
                    stripos($cLower, 'check out') !== false ||
                    stripos($cLower, 'out time') !== false ||
                    stripos($cLower, 'time out') !== false ||
                    $cLower === 'out'
                )) {
                    $tempOut = $cIdx;
                }

                if ($tempDate === -1 && (
                    $cLower === 'date' ||
                    stripos($cLower, 'att date') !== false ||
                    stripos($cLower, 'attendance date') !== false
                )) {
                    $tempDate = $cIdx;
                }

                // Detect Day matrix columns: "1", "2", ... "31", "Day 1", "01", "2026-08-01"
                if (preg_match('/^(?:day\s*)?([1-9]|[12]\d|3[01])$/i', $cClean, $m)) {
                    $tempDays[intval($m[1])] = $cIdx;
                } elseif (preg_match('/^\d{4}-\d{2}-([0-3]\d)$/', $cClean, $m)) {
                    $tempDays[intval($m[1])] = $cIdx;
                }
            }

            // If we found a biometric ID column OR multiple day columns, this is our header row!
            if ($tempBio !== -1 || count($tempDays) >= 2) {
                $headerRowIdx = $rIdx;
                $bioColIdx = $tempBio;
                $nameColIdx = $tempName;
                $statusColIdx = $tempStatus;
                $checkInColIdx = $tempIn;
                $checkOutColIdx = $tempOut;
                $dateColIdx = $tempDate;
                $dayCols = $tempDays;
                break;
            }
        }

        if ($headerRowIdx === -1) {
            // Default fallback to first row
            $headerRowIdx = 0;
            $bioColIdx = 0;
        }

        // Data rows are everything after the detected header row
        $dataRows = array_slice($allRows, $headerRowIdx + 1);

        if (empty($dataRows)) {
            sendJSON(false, null, 'The uploaded sheet contains no data rows below the header');
        }

        $batchId = 'BATCH_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        // Prepare insert statement with bulk_batch_id
        $insertStmt = $conn->prepare("
            INSERT INTO " . TABLE_ATTENDANCE . " (user_id, name, timestamp, date, time, sync_status, bulk_batch_id)
            VALUES (?, ?, ?, ?, ?, 'manual', ?)
        ");

        if (!$insertStmt) {
            sendJSON(false, null, 'Database prepare error: ' . $conn->error);
        }

        $conn->begin_transaction();

        try {
            foreach ($dataRows as $row) {
                if (empty($row)) continue;

                $rawBioId = isset($row[$bioColIdx]) ? trim(strval($row[$bioColIdx])) : '';
                
                // If cell is empty or header repetition, check if any column has a valid numeric ID
                if ($rawBioId === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $rawBioId) || strtolower($rawBioId) === 'biometric id') {
                    // Try to search for ID in first 3 columns
                    $foundBio = '';
                    for ($ci = 0; $ci < min(4, count($row)); $ci++) {
                        $cand = trim(strval($row[$ci]));
                        if (is_numeric($cand) && intval($cand) > 0 && intval($cand) < 999999) {
                            $foundBio = $cand;
                            break;
                        }
                    }
                    if ($foundBio !== '') {
                        $rawBioId = $foundBio;
                    } else {
                        $skippedCount++;
                        continue;
                    }
                }

                $processedRows++;

                // Resolve employee name (from map or sheet)
                $sheetName = ($nameColIdx !== -1 && isset($row[$nameColIdx])) ? trim(strval($row[$nameColIdx])) : '';
                $empName = $empMap[$rawBioId] ?? ($empMap[ltrim($rawBioId, '0')] ?? ($sheetName ?: 'Employee ' . $rawBioId));
                
                if (!isset($empMap[$rawBioId]) && !isset($empMap[ltrim($rawBioId, '0')])) {
                    if (!in_array($rawBioId, $unmappedCodes)) {
                        $unmappedCodes[] = $rawBioId;
                    }
                }

                // ── CASE 1: MATRIX FORMAT (Columns for Day 1..Day 31) ──
                if (!empty($dayCols)) {
                    foreach ($dayCols as $dayNum => $colIdx) {
                        if (!isset($row[$colIdx])) continue;
                        $cellVal = trim(strval($row[$colIdx]));
                        if ($cellVal === '' || $cellVal === '-' || $cellVal === '0') continue;

                        $dayFormatted = sprintf('%02d', $dayNum);
                        $targetDate = $monthYear . '-' . $dayFormatted;

                        // Check valid date for the month (e.g. Feb 30 guard)
                        if (!checkdate(intval(substr($monthYear, 5, 2)), $dayNum, intval(substr($monthYear, 0, 4)))) {
                            continue;
                        }

                        $vLower = strtolower($cellVal);

                        // If status is Present / P / Late / L / Yes / 1
                        if (in_array($vLower, ['p', 'present', '1', 'yes', 'y', 'late', 'l', 'half day', 'hd'])) {
                            // Insert IN punch
                            $inTimestamp = $targetDate . ' ' . $defaultInTime;
                            $insertStmt->bind_param("ssssss", $rawBioId, $empName, $inTimestamp, $targetDate, $defaultInTime, $batchId);
                            $insertStmt->execute();
                            $insertedCount++;

                            // Insert OUT punch
                            $outTimestamp = $targetDate . ' ' . $defaultOutTime;
                            $insertStmt->bind_param("ssssss", $rawBioId, $empName, $outTimestamp, $targetDate, $defaultOutTime, $batchId);
                            $insertStmt->execute();
                            $insertedCount++;
                        }
                        // If cell contains explicit time (e.g. "09:15" or "09:15, 18:30")
                        elseif (preg_match_all('/(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]m)?)/i', $cellVal, $timeMatches)) {
                            foreach ($timeMatches[1] as $tm) {
                                $parsedTime = date('H:i:s', strtotime($tm));
                                $fullTimestamp = $targetDate . ' ' . $parsedTime;
                                $insertStmt->bind_param("ssssss", $rawBioId, $empName, $fullTimestamp, $targetDate, $parsedTime, $batchId);
                                $insertStmt->execute();
                                $insertedCount++;
                            }
                        }
                    }
                } 
                // ── CASE 2: ROSTER / ROW-LIST / DAILY REPORT FORMAT ──
                else {
                    // Extract date (if date column exists, otherwise use monthYear + today or 1st)
                    $rowDate = '';
                    if ($dateColIdx !== -1 && isset($row[$dateColIdx]) && trim($row[$dateColIdx]) !== '') {
                        $rawDate = trim($row[$dateColIdx]);
                        if (strtotime($rawDate)) {
                            $rowDate = date('Y-m-d', strtotime($rawDate));
                        }
                    }

                    if (!$rowDate) {
                        // Default to current date if in same month, or first day of selected month
                        $rowDate = (substr(date('Y-m-d'), 0, 7) === $monthYear) ? date('Y-m-d') : ($monthYear . '-01');
                    }

                    $statusVal = ($statusColIdx !== -1 && isset($row[$statusColIdx])) ? strtolower(trim($row[$statusColIdx])) : '';
                    $checkInVal = ($checkInColIdx !== -1 && isset($row[$checkInColIdx])) ? trim($row[$checkInColIdx]) : '';
                    $checkOutVal = ($checkOutColIdx !== -1 && isset($row[$checkOutColIdx])) ? trim($row[$checkOutColIdx]) : '';

                    // Clean check-in / out values (remove "--:--" or "missing")
                    if (stripos($checkInVal, 'missing') !== false || stripos($checkInVal, '--') !== false) $checkInVal = '';
                    if (stripos($checkOutVal, 'missing') !== false || stripos($checkOutVal, '--') !== false) $checkOutVal = '';

                    $hasSpecificTimes = false;

                    // If explicit Check In time exists
                    if ($checkInVal && strtotime($checkInVal)) {
                        $parsedIn = date('H:i:s', strtotime($checkInVal));
                        $inTs = $rowDate . ' ' . $parsedIn;
                        $insertStmt->bind_param("ssssss", $rawBioId, $empName, $inTs, $rowDate, $parsedIn, $batchId);
                        $insertStmt->execute();
                        $insertedCount++;
                        $hasSpecificTimes = true;
                    }

                    // If explicit Check Out time exists
                    if ($checkOutVal && strtotime($checkOutVal)) {
                        $parsedOut = date('H:i:s', strtotime($checkOutVal));
                        $outTs = $rowDate . ' ' . $parsedOut;
                        $insertStmt->bind_param("ssssss", $rawBioId, $empName, $outTs, $rowDate, $parsedOut, $batchId);
                        $insertStmt->execute();
                        $insertedCount++;
                        $hasSpecificTimes = true;
                    }

                    // If no explicit times, but status is Present or Late
                    if (!$hasSpecificTimes && (in_array($statusVal, ['present', 'p', 'late', 'l', 'half day', 'hd', '1', 'yes']))) {
                        // Insert standard In punch
                        $inTs = $rowDate . ' ' . $defaultInTime;
                        $insertStmt->bind_param("ssssss", $rawBioId, $empName, $inTs, $rowDate, $defaultInTime, $batchId);
                        $insertStmt->execute();
                        $insertedCount++;

                        // Insert standard Out punch
                        $outTs = $rowDate . ' ' . $defaultOutTime;
                        $insertStmt->bind_param("ssssss", $rawBioId, $empName, $outTs, $rowDate, $defaultOutTime, $batchId);
                        $insertStmt->execute();
                        $insertedCount++;
                    }
                }
            }

            // Record upload batch in tracking table
            $logStmt = $conn->prepare("
                INSERT INTO bulk_attendance_batches (batch_id, file_name, month_year, total_rows, punches_inserted, status)
                VALUES (?, ?, ?, ?, ?, 'active')
            ");
            if ($logStmt) {
                $logStmt->bind_param("sssii", $batchId, $fileName, $monthYear, $processedRows, $insertedCount);
                $logStmt->execute();
                $logStmt->close();
            }

            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            sendJSON(false, null, 'Error during bulk insertion: ' . $ex->getMessage());
        }

        $insertStmt->close();

        sendJSON(true, [
            'batch_id' => $batchId,
            'file_name' => $fileName,
            'total_rows' => $processedRows,
            'punches_inserted' => $insertedCount,
            'month_year' => $monthYear,
            'unmapped_count' => count($unmappedCodes),
            'unmapped_biometric_ids' => array_slice($unmappedCodes, 0, 10)
        ], "✅ Bulk attendance uploaded successfully! $insertedCount attendance punches recorded for $processedRows employees.");
        break;

    // =================================================
    // 7.2 GET RECENT BULK UPLOAD BATCHES
    // =================================================
    case 'getBulkAttendanceBatches':
        $res = $conn->query("
            SELECT id, batch_id, file_name, month_year, total_rows, punches_inserted, status, reverted_at, created_at
            FROM bulk_attendance_batches
            ORDER BY id DESC
            LIMIT 15
        ");
        $batches = [];
        if ($res) {
            while ($b = $res->fetch_assoc()) {
                $batches[] = $b;
            }
        }
        sendJSON(true, ['batches' => $batches]);
        break;

    // =================================================
    // 7.3 REVERT BULK ATTENDANCE BATCH
    // =================================================
    case 'revertBulkAttendanceBatch':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJSON(false, null, 'Revert requires POST method');
        }

        $batchId = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';
        if (!$batchId) {
            sendJSON(false, null, 'Batch ID required to revert attendance sheet');
        }

        // Check if batch exists
        $bCheck = $conn->prepare("SELECT id, status, punches_inserted, file_name FROM bulk_attendance_batches WHERE batch_id = ? LIMIT 1");
        $bCheck->bind_param("s", $batchId);
        $bCheck->execute();
        $batchRes = $bCheck->get_result();
        $batch = $batchRes ? $batchRes->fetch_assoc() : null;
        $bCheck->close();

        if (!$batch) {
            sendJSON(false, null, 'Batch record not found');
        }

        if ($batch['status'] === 'reverted') {
            sendJSON(false, null, 'This bulk sheet has already been reverted');
        }

        $conn->begin_transaction();

        try {
            // Delete all attendance punches created by this batch
            $delStmt = $conn->prepare("DELETE FROM " . TABLE_ATTENDANCE . " WHERE bulk_batch_id = ?");
            $delStmt->bind_param("s", $batchId);
            $delStmt->execute();
            $deletedCount = $delStmt->affected_rows;
            $delStmt->close();

            // Mark batch as reverted
            $updStmt = $conn->prepare("UPDATE bulk_attendance_batches SET status = 'reverted', reverted_at = NOW() WHERE batch_id = ?");
            $updStmt->bind_param("s", $batchId);
            $updStmt->execute();
            $updStmt->close();

            $conn->commit();

            sendJSON(true, [
                'batch_id' => $batchId,
                'deleted_punches' => $deletedCount
            ], "✅ Successfully reverted attendance sheet '{$batch['file_name']}'! ($deletedCount punches removed).");
        } catch (Exception $ex) {
            $conn->rollback();
            sendJSON(false, null, 'Failed to revert batch: ' . $ex->getMessage());
        }
        break;
    
    // =================================================
    // 8. GET STATISTICS
    // =================================================
    case 'getStatistics':
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            sendJSON(false, null, 'Invalid date format. Use YYYY-MM-DD');
        }
        
        $total_result = $conn->query("SELECT COUNT(*) as count FROM " . TABLE_EMPLOYEES . " WHERE is_active = 1");
        if (!$total_result) {
            sendJSON(false, null, "Failed to get employee count");
        }
        $total = $total_result->fetch_assoc()['count'];
        
        $avg_query = $conn->query("
            SELECT 
                COUNT(DISTINCT DATE(timestamp)) as days,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(*) as total_punches
            FROM " . attendanceBulkReadSource() . "
            WHERE DATE(timestamp) BETWEEN '$start_date' AND '$end_date'
        ");
        
        if (!$avg_query) {
            sendJSON(false, null, "Failed to get statistics");
        }
        
        $avg_data = $avg_query->fetch_assoc();
        $days = $avg_data['days'] ?: 1;
        $avg_daily = $days > 0 ? round($avg_data['unique_users'] / $days, 1) : 0;
        
        $active_day_result = $conn->query("
            SELECT 
                DATE(timestamp) as date,
                COUNT(*) as punches
            FROM " . attendanceBulkReadSource() . "
            WHERE DATE(timestamp) BETWEEN '$start_date' AND '$end_date'
            GROUP BY DATE(timestamp)
            ORDER BY punches DESC
            LIMIT 1
        ");
        
        $active_day = $active_day_result ? $active_day_result->fetch_assoc() : null;
        
        sendJSON(true, [
            'total_employees' => (int)$total,
            'total_records' => (int)$avg_data['total_punches'],
            'avg_daily_attendance' => $avg_daily,
            'most_active_day' => $active_day ? $active_day['date'] : 'N/A',
            'period' => [
                'start' => $start_date,
                'end' => $end_date
            ]
        ]);
        break;
    
    // =================================================
    // 9. NEW: GET FILTER OPTIONS FROM CSV (UPDATED with Teams)
    // =================================================
    case 'getFilterOptions':
        sendJSON(true, [
            'departments' => getDepartmentsFromCSV(),
            'branches' => getBranchesFromCSV(),
            'designations' => getDesignationsFromCSV(),
            'teams' => getTeamsFromCSV() // NEW: Added teams
        ]);
        break;
    
    // =================================================
    // 10. NEW: SEARCH EMPLOYEES IN CSV (UPDATED with Team)
    // =================================================
    case 'searchEmployeesCSV':
        $query = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';
        
        if (strlen($query) < 2) {
            sendJSON(true, []);
        }
        
        $csv_employees = loadEmployeeDataFromCSV();
        $results = [];
        
        foreach ($csv_employees as $emp) {
            if (strpos(strtolower($emp['id']), $query) !== false ||
                strpos(strtolower($emp['name']), $query) !== false ||
                strpos(strtolower($emp['department']), $query) !== false ||
                strpos(strtolower($emp['designation']), $query) !== false ||
                strpos(strtolower($emp['branch']), $query) !== false ||
                strpos(strtolower($emp['team']), $query) !== false) { // NEW: Added team to search
                $results[] = $emp;
            }
        }
        
        sendJSON(true, array_slice($results, 0, 50)); // Limit to 50 results
        break;
    
    // =================================================
    // 11. NEW: GET TEAM STATS
    // =================================================
    case 'getTeamStats':
        $csv_employees = loadEmployeeDataFromCSV();
        $team_stats = [];
        
        foreach ($csv_employees as $emp) {
            $team = $emp['team'] ?: 'No Team';
            if (!isset($team_stats[$team])) {
                $team_stats[$team] = 0;
            }
            $team_stats[$team]++;
        }
        
        arsort($team_stats);
        sendJSON(true, $team_stats);
        break;
    
    // =================================================
    // 12. NEW: GET MONTHLY GRID FOR REPORT
    // =================================================
    case 'getMonthlyGrid':
        $month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            sendJSON(false, null, 'Invalid month format. Use YYYY-MM');
        }
        
        $start_date = $month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $days_in_month = (int)date('t', strtotime($start_date));
        
        $employees = $conn->query("
            SELECT 
                e.employee_code COLLATE utf8mb4_unicode_ci as employee_code, 
                e.id, 
                e.full_name COLLATE utf8mb4_unicode_ci as full_name, 
                e.department COLLATE utf8mb4_unicode_ci as department, 
                COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') COLLATE utf8mb4_unicode_ci as team,
                COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), NULLIF(e.branch, ''), 'Main') COLLATE utf8mb4_unicode_ci as branch,
                COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), NULLIF(e.designation, ''), 'Employee') COLLATE utf8mb4_unicode_ci as designation
            FROM employees e
            LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            LEFT JOIN employee_payroll_meta m ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE e.is_active = 1

            UNION

            SELECT 
                ec.employee_code COLLATE utf8mb4_unicode_ci as employee_code, 
                ec.id, 
                ec.full_name COLLATE utf8mb4_unicode_ci as full_name, 
                ec.department COLLATE utf8mb4_unicode_ci as department, 
                COALESCE(NULLIF(u.team, ''), NULLIF(ec.team, ''), '') COLLATE utf8mb4_unicode_ci as team,
                COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), NULLIF(ec.branch, ''), 'Commercial') COLLATE utf8mb4_unicode_ci as branch,
                COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), NULLIF(ec.designation, ''), 'Employee') COLLATE utf8mb4_unicode_ci as designation
            FROM employees_commercial ec
            LEFT JOIN users u ON (ec.employee_code IS NOT NULL AND ec.employee_code != '' AND ec.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
            LEFT JOIN employee_payroll_meta m ON (ec.employee_code IS NOT NULL AND ec.employee_code != '' AND ec.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci)
            WHERE ec.is_active = 1
            ORDER BY CAST(employee_code AS UNSIGNED)
        ");
        
        if (!$employees) {
            sendJSON(false, null, "Database error: " . $conn->error);
        }
        
        $csv_employees = loadEmployeeDataFromCSV();
        $grid = [];
        
        // Fetch ALL punches for the entire month for ALL employees in ONE query to be efficient
        $all_punches_query = $conn->query("
            SELECT user_id, timestamp 
            FROM " . attendanceBulkReadSource() . "
            WHERE timestamp BETWEEN '$start_date 14:00:00' AND '" . date('Y-m-d', strtotime($end_date . ' +1 day')) . " 12:00:00'
            ORDER BY timestamp
        ");
        
        $punches_by_user = [];
        if ($all_punches_query) {
            while ($p = $all_punches_query->fetch_assoc()) {
                $punches_by_user[$p['user_id']][] = $p['timestamp'];
            }
        }

        $seen_codes = [];
        while ($emp = $employees->fetch_assoc()) {
            $code = $emp['employee_code'];
            if (empty($code) || isset($seen_codes[$code])) {
                continue;
            }
            $seen_codes[$code] = true;

            $csv_emp = $csv_employees[$code] ?? null;
            $full_name = !empty($csv_emp['name']) ? $csv_emp['name'] : $emp['full_name'];
            $team_name = !empty($csv_emp['team']) ? $csv_emp['team'] : (!empty($emp['team']) ? $emp['team'] : '');
            $branch_name = !empty($csv_emp['branch']) ? $csv_emp['branch'] : (!empty($emp['branch']) ? $emp['branch'] : 'Main');
            
            $emp_grid = [
                'id' => $emp['id'],
                'code' => $code,
                'name' => $full_name,
                'department' => !empty($csv_emp['department']) ? $csv_emp['department'] : ($emp['department'] ?: 'General'),
                'designation' => !empty($csv_emp['designation']) ? $csv_emp['designation'] : 'Employee',
                'branch' => $branch_name,
                'team' => $team_name,
                'attendance' => []
            ];
            
            $user_punches = $punches_by_user[$code] ?? [];
            
            // Stats for this employee
            $present_count = 0;
            $late_count = 0;
            $absent_count = 0;
            
            $current = $start_date;
            while ($current <= $end_date) {
                $windows = getShiftWindows($current);
                $day_num = (int)date('d', strtotime($current));
                
                $first_in = null;
                foreach ($user_punches as $p) {
                    if ($p >= $windows['checkin_start'] && $p <= $windows['checkin_end']) {
                        $first_in = $p;
                        break;
                    }
                }
                
                if ($first_in) {
                    $emp_grid['attendance'][$day_num] = date('H:i', strtotime($first_in));
                    $present_count++;
                    list($is_late, ) = isLate($first_in, $current, $emp_grid['team']);
                    if ($is_late) $late_count++;
                } else {
                    $emp_grid['attendance'][$day_num] = '--:--';
                    $absent_count++;
                }
                
                $current = date('Y-m-d', strtotime($current . ' +1 day'));
            }
            
            $emp_grid['summary'] = [
                'present' => $present_count,
                'late' => $late_count,
                'absent' => $absent_count,
                'leave' => 0 // Leaves are handled via localStorage in frontend currently
            ];
            
            $grid[] = $emp_grid;
        }
        
        sendJSON(true, [
            'month' => $month,
            'days_in_month' => $days_in_month,
            'grid' => $grid
        ]);
        break;

    case 'fetchAttendanceNow':
        // Super Admin only
        if (empty($_SESSION['portal_role']) || $_SESSION['portal_role'] !== 'super_admin') {
            sendJSON(false, null, 'Access denied. Only Super Admin can fetch attendance.');
        }

        $mainCheckpoint = __DIR__ . '/python-script/last_sync_main_branch.txt';
        $commercialCheckpoint = __DIR__ . '/python-script/last_sync_commercial_branch.txt';

        $oldMain = file_exists($mainCheckpoint) ? trim((string)file_get_contents($mainCheckpoint)) : '';
        $oldCommercial = file_exists($commercialCheckpoint) ? trim((string)file_get_contents($commercialCheckpoint)) : '';

        $output = [];
        $exitCode = 0;

        exec(
            'sudo -n /usr/bin/systemctl restart hrms-attendance-sync.service 2>&1',
            $output,
            $exitCode
        );

        if ($exitCode !== 0) {
            sendJSON(false, [
                'logs' => implode("\n", $output)
            ], 'Unable to start attendance synchronization.');
        }

        // Wait for both device checkpoint files to update.
        $deadline = time() + 25;
        $newMain = $oldMain;
        $newCommercial = $oldCommercial;

        while (time() < $deadline) {
            clearstatcache(true, $mainCheckpoint);
            clearstatcache(true, $commercialCheckpoint);

            if (file_exists($mainCheckpoint)) {
                $newMain = trim((string)file_get_contents($mainCheckpoint));
            }

            if (file_exists($commercialCheckpoint)) {
                $newCommercial = trim((string)file_get_contents($commercialCheckpoint));
            }

            $mainUpdated = ($newMain !== '' && $newMain !== $oldMain);
            $commercialUpdated = ($newCommercial !== '' && $newCommercial !== $oldCommercial);

            if ($mainUpdated && $commercialUpdated) {
                sendJSON(true, [
                    'main_branch' => $newMain,
                    'commercial_branch' => $newCommercial
                ], 'Attendance fetched successfully from both devices.');
            }

            usleep(500000);
        }

        // Service was started, but device fetch did not finish within browser wait time.
        sendJSON(true, [
            'main_branch' => $newMain,
            'commercial_branch' => $newCommercial,
            'processing' => true
        ], 'Attendance synchronization started. Data is still processing.');
        break;

    case 'fetchDevices':
        // Check permissions: only super_admin allowed
        if (empty($_SESSION['portal_role']) || $_SESSION['portal_role'] !== 'super_admin') {
            sendJSON(false, null, 'Access denied. Only Super Admin is permitted to run live synchronization.');
        }

        $cmd = isset($_GET['cmd']) ? trim($_GET['cmd']) : 'sync-today-all';
        $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
        $month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

        // Map safe command prefixes
        $allowed_commands = [
            'sync-today-main' => '--sync-today-main',
            'sync-today-commercial' => '--sync-today-commercial',
            'sync-today-all' => '--sync-today-all',
            'sync-users-main' => '--sync-users-main',
            'sync-users-commercial' => '--sync-users-commercial',
            'sync-users-all' => '--sync-users-all',
            'test-connections' => '--test-connections',
            'sync-month-main' => '--sync-month-main',
            'sync-month-commercial' => '--sync-month-commercial'
        ];

        if (!isset($allowed_commands[$cmd])) {
            sendJSON(false, null, 'Invalid ZKTeco command selected.');
        }

        $venvPython = __DIR__ . '/python-script/venv/Scripts/python.exe';
        $pythonScript = __DIR__ . '/python-script/' . PYTHON_SCRIPT;

        if (!file_exists($venvPython)) {
            sendJSON(false, null, 'Virtual environment Python not found at: ' . $venvPython);
        }
        if (!file_exists($pythonScript)) {
            sendJSON(false, null, 'Python script not found at: ' . $pythonScript);
        }

        $arg = $allowed_commands[$cmd];
        $extra_args = '';
        if ($cmd === 'sync-month-main' || $cmd === 'sync-month-commercial') {
            if ($year < 2020 || $year > 2035 || $month < 1 || $month > 12) {
                sendJSON(false, null, 'Please select a valid Year and Month.');
            }
            $extra_args = ' ' . escapeshellarg($year) . ' ' . escapeshellarg($month);
        }

        // Run python script with arguments
        $command = escapeshellarg($venvPython) . ' ' . escapeshellarg($pythonScript) . ' ' . $arg . $extra_args . ' 2>&1';
        $output = shell_exec($command);

        if ($output === null) {
            sendJSON(false, null, 'Failed to execute command on the server.');
        }

        // Check if there's any error in python output
        if (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) {
            sendJSON(false, ['logs' => $output], 'Command executed but returned some errors. Check terminal output.');
        } else {
            sendJSON(true, ['logs' => $output], 'ZKTeco action completed successfully!');
        }
        break;

    // =================================================
    // DEFAULT: Invalid action
    // =================================================
    default:
        sendJSON(false, null, 'Invalid action. Available actions: weekly, getLiveAttendance, getEmployeeHistory, getAttendanceForHR, getDateRange, importFromPython, searchEmployees, manualPunch, bulkUploadMonthlyAttendance, getStatistics, getFilterOptions, searchEmployeesCSV, getTeamStats, fetchDevices');
}
?>