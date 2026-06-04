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
// NEW: Load Employee Data from CSV (Sheet4)
// =====================================================
function loadEmployeeDataFromCSV() {
    $csv_file = __DIR__ . '/Present Employee Data - Sheet4.csv'; // UPDATED: Changed to Sheet4
    $employees = [];
    
    if (!file_exists($csv_file)) {
        error_log("CSV file not found: " . $csv_file);
        return $employees;
    }
    
    $file = fopen($csv_file, 'r');
    if (!$file) {
        error_log("Cannot open CSV file");
        return $employees;
    }
    
    // Read header row
    $headers = fgetcsv($file);
    $headers = array_map('trim', $headers);
    
    while (($row = fgetcsv($file)) !== FALSE) {
        // Skip empty rows
        if (empty(array_filter($row))) continue;
        
        $row = array_map('trim', $row);
        
        // Check if this row has BID (employee data)
        if (!empty($row[0])) {
            $b_id = $row[0]; // BID
            $name = $row[1] ?? ''; // Name
            $team = $row[2] ?? ''; // Team
            $department = $row[3] ?? ''; // Departments
            $designation = $row[4] ?? ''; // Designations
            $branch = $row[5] ?? ''; // Branch
            
            // Clean up department names
            $department = trim($department);
            
            $employees[$b_id] = [
                'id' => $b_id,
                'name' => $name,
                'designation' => $designation,
                'department' => $department,
                'branch' => $branch,
                'team' => $team
            ];
        }
    }
    
    fclose($file);
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
// NEW: Get All Teams from CSV
// =====================================================
function getTeamsFromCSV() {
    static $csv_data = null;
    
    if ($csv_data === null) {
        $csv_data = loadEmployeeDataFromCSV();
    }
    
    $teams = [];
    foreach ($csv_data as $emp) {
        if (!empty($emp['team'])) {
            $teams[$emp['team']] = true;
        }
    }
    
    return array_keys($teams);
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

function isLate($punch_time, $shift_date) {
    $shift_start = strtotime($shift_date . ' ' . SHIFT_START);
    $punch = strtotime($punch_time);
    $minutes_late = ($punch - $shift_start) / 60;
    
    if ($minutes_late <= 0) {
        return [false, 0];
    }
    
    if ($minutes_late > GRACE_MINUTES) {
        return [true, round($minutes_late)];
    }
    
    return [false, 0];
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // =================================================
    // 1. GET LIVE ATTENDANCE - UPDATED with CSV data
    // =================================================
    case 'getLiveAttendance':
        $selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            sendJSON(false, null, 'Invalid date format. Use YYYY-MM-DD');
        }
        
        $windows = getShiftWindows($selected_date);

        $employees = $conn->query("
            SELECT id, employee_code, full_name, department 
            FROM " . TABLE_EMPLOYEES . " 
            WHERE is_active = 1 
            ORDER BY CAST(employee_code AS UNSIGNED)
        ");
        
        if (!$employees) {
            sendJSON(false, null, "DB error: " . $conn->error);
        }

        // Load CSV employee data once
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
            
            // Get employee details from CSV
            $csv_emp = $csv_employees[$emp_code] ?? null;
            
            // Set department, designation, branch, team from CSV or use defaults
            $department = $csv_emp['department'] ?? $emp['department'] ?: 'General';
            $designation = $csv_emp['designation'] ?? 'Employee';
            $branch = $csv_emp['branch'] ?? 'Head Office';
            $team = $csv_emp['team'] ?? ''; // NEW: Added team
            
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
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
                ORDER BY timestamp
            ");

            // Get check-out punches (midnight to noon of next day)
            $checkout_punches = $conn->query("
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
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
                
                list($is_late, $minutes) = isLate($first_in, $selected_date);
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
                'name'          => $emp['full_name'],
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
            
            // IMPORTANT FIX: Get check-ins for THIS date (2PM to midnight)
            $checkin_result = $conn->query("
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
                ORDER BY timestamp LIMIT 1
            ");
            
            // IMPORTANT FIX: Get check-outs for THIS date's shift (from next day midnight to noon)
            $checkout_result = $conn->query("
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
                WHERE user_id = '$emp_code' 
                AND timestamp BETWEEN '{$windows['checkout_start']}' AND '{$windows['checkout_end']}'
                ORDER BY timestamp DESC LIMIT 1
            ");

            $first_in = null;
            $last_out = null;
            $status = 'absent';
            $late_minutes = 0;
            $working_hours = 0;
            $has_check_out = false;

            if ($checkin_result && $checkin_result->num_rows > 0) {
                $row = $checkin_result->fetch_assoc();
                $first_in = $row['timestamp'];
                
                $summary['present']++;
                list($is_late, $minutes) = isLate($first_in, $current);
                if ($is_late) {
                    $status = 'late';
                    $summary['late']++;
                    $late_minutes = $minutes;
                } else {
                    $status = 'present';
                }
            }

            if ($checkout_result && $checkout_result->num_rows > 0) {
                $row = $checkout_result->fetch_assoc();
                $last_out = $row['timestamp'];
                $has_check_out = true;
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
                'branch' => $csv_emp['branch'] ?? 'Head Office',
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
            SELECT id, employee_code, full_name, department 
            FROM " . TABLE_EMPLOYEES . " 
            WHERE is_active = 1 
            ORDER BY CAST(employee_code AS UNSIGNED)
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

            // Get first check-in of the shift
            $punch = $conn->query("
                SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
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
                
                list($is_late, $minutes) = isLate($punch_data['timestamp'], $selected_date);
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
                'branch'        => $csv_emp['branch'] ?? 'Head Office',
                'team'          => $csv_emp['team'] ?? '', // NEW: Added team
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
        
        $where = $department ? "AND e.department = '$department'" : '';

        $employees = $conn->query("
            SELECT id, employee_code, full_name, department 
            FROM " . TABLE_EMPLOYEES . " 
            WHERE is_active = 1 
            $where
            ORDER BY CAST(employee_code AS UNSIGNED)
        ");

        if (!$employees) {
            sendJSON(false, null, "Database error: " . $conn->error);
        }
        
        $csv_employees = loadEmployeeDataFromCSV();

        $report = [];
        $total_days = (strtotime($end_date) - strtotime($start_date)) / (60*60*24) + 1;

        while ($emp = $employees->fetch_assoc()) {
            $emp_code = $conn->real_escape_string($emp['employee_code']);
            $csv_emp = $csv_employees[$emp_code] ?? null;
            
            $present_days = 0;
            $late_days = 0;
            
            $current = $start_date;
            while ($current <= $end_date) {
                $windows = getShiftWindows($current);
                
                // Check if employee had any punch in this shift
                $check = $conn->query("
                    SELECT COUNT(*) as count FROM " . TABLE_ATTENDANCE . " 
                    WHERE user_id = '$emp_code' 
                    AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkout_end']}'
                ");
                
                if ($check && $check->fetch_assoc()['count'] > 0) {
                    $present_days++;
                    
                    // Check if first punch was late
                    $first_punch = $conn->query("
                        SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
                        WHERE user_id = '$emp_code' 
                        AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
                        ORDER BY timestamp LIMIT 1
                    ");
                    
                    if ($first_punch && $first_punch->num_rows > 0) {
                        $punch = $first_punch->fetch_assoc();
                        list($is_late,) = isLate($punch['timestamp'], $current);
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
                'branch'          => $csv_emp['branch'] ?? 'Head Office',
                'team'            => $csv_emp['team'] ?? '', // NEW: Added team
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
                'branch' => $csv_emp['branch'] ?? 'Head Office',
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
            FROM " . TABLE_ATTENDANCE . " 
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
            FROM " . TABLE_ATTENDANCE . " 
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
            SELECT id, employee_code, full_name, department 
            FROM " . TABLE_EMPLOYEES . " 
            WHERE is_active = 1 
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
            FROM " . TABLE_ATTENDANCE . " 
            WHERE timestamp BETWEEN '$start_date 14:00:00' AND '" . date('Y-m-d', strtotime($end_date . ' +1 day')) . " 12:00:00'
            ORDER BY timestamp
        ");
        
        $punches_by_user = [];
        if ($all_punches_query) {
            while ($p = $all_punches_query->fetch_assoc()) {
                $punches_by_user[$p['user_id']][] = $p['timestamp'];
            }
        }

        while ($emp = $employees->fetch_assoc()) {
            $code = $emp['employee_code'];
            $csv_emp = $csv_employees[$code] ?? null;
            
            $emp_grid = [
                'id' => $emp['id'],
                'code' => $code,
                'name' => $emp['full_name'],
                'department' => $csv_emp['department'] ?? $emp['department'] ?: 'General',
                'designation' => $csv_emp['designation'] ?? 'Employee',
                'branch' => $csv_emp['branch'] ?? 'Head Office',
                'team' => $csv_emp['team'] ?? '',
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
                    list($is_late, ) = isLate($first_in, $current);
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

    // =================================================
    // DEFAULT: Invalid action
    // =================================================
    default:
        sendJSON(false, null, 'Invalid action. Available actions: getLiveAttendance, getEmployeeHistory, getAttendanceForHR, getDateRange, importFromPython, searchEmployees, manualPunch, getStatistics, getFilterOptions, searchEmployeesCSV, getTeamStats');
}
?>