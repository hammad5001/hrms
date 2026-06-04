<?php
// download_team_data.php - Complete Attendance Downloader
// Downloads attendance data with both Team-based and Full Attendance options

date_default_timezone_set('Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// =====================================================
// Configuration
// =====================================================
$start_date = '2026-03-10';
$end_date = date('Y-m-d'); // Today's date

// Shift configuration - Check if already defined
if (!defined('SHIFT_START')) {
    define('SHIFT_START', '18:00:00'); // 6:00 PM
}
if (!defined('SHIFT_END')) {
    define('SHIFT_END', '04:00:00');   // 4:00 AM next day
}
if (!defined('GRACE_MINUTES')) {
    define('GRACE_MINUTES', 10); // 10 minutes grace period
}

// =====================================================
// Helper Functions
// =====================================================
function getShiftWindows($date) {
    $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
    
    return [
        'checkin_start' => $date . ' 14:00:00',
        'checkin_end'   => $date . ' 23:59:59',
        'checkout_start' => $next_date . ' 00:00:00',
        'checkout_end'   => $next_date . ' 11:59:59'
    ];
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

// =====================================================
// Function to load team data from CSV
// =====================================================
function loadTeamDataFromCSV() {
    $csv_file = __DIR__ . '/Present Employee Data - Sheet4.csv';
    $employee_teams = [];
    
    if (!file_exists($csv_file)) {
        $csv_file = __DIR__ . '/python-script/Present Employee Data - Sheet4.csv';
        if (!file_exists($csv_file)) {
            return ['error' => 'CSV file not found. Please ensure Present Employee Data - Sheet4.csv is in the correct location.'];
        }
    }
    
    $file = fopen($csv_file, 'r');
    if (!$file) {
        return ['error' => 'Cannot open CSV file'];
    }
    
    fgetcsv($file);
    
    while (($row = fgetcsv($file)) !== FALSE) {
        if (!empty($row[0])) {
            $emp_id = trim($row[0]);
            $team = isset($row[2]) ? trim($row[2]) : 'No Team';
            $department = isset($row[3]) ? trim($row[3]) : 'General';
            $designation = isset($row[4]) ? trim($row[4]) : 'Employee';
            $branch = isset($row[5]) ? trim($row[5]) : 'Main';
            $name = isset($row[1]) ? trim($row[1]) : '';
            
            $employee_teams[$emp_id] = [
                'team' => $team,
                'department' => $department,
                'designation' => $designation,
                'branch' => $branch,
                'name' => $name
            ];
        }
    }
    
    fclose($file);
    return $employee_teams;
}

// =====================================================
// Function to get ALL employees from CSV and Database
// =====================================================
function getAllEmployeesFromCSV() {
    $csv_file = __DIR__ . '/Present Employee Data - Sheet4.csv';
    $all_employees = [];
    
    if (!file_exists($csv_file)) {
        $csv_file = __DIR__ . '/python-script/Present Employee Data - Sheet4.csv';
        if (!file_exists($csv_file)) {
            return [];
        }
    }
    
    $file = fopen($csv_file, 'r');
    if (!$file) {
        return [];
    }
    
    fgetcsv($file);
    
    while (($row = fgetcsv($file)) !== FALSE) {
        if (!empty($row[0])) {
            $emp_id = trim($row[0]);
            $name = isset($row[1]) ? trim($row[1]) : '';
            $team = isset($row[2]) ? trim($row[2]) : 'No Team';
            $department = isset($row[3]) ? trim($row[3]) : 'General';
            $designation = isset($row[4]) ? trim($row[4]) : 'Employee';
            $branch = isset($row[5]) ? trim($row[5]) : 'Main';
            
            $all_employees[$emp_id] = [
                'id' => $emp_id,
                'name' => $name,
                'team' => $team,
                'department' => $department,
                'designation' => $designation,
                'branch' => $branch
            ];
        }
    }
    
    fclose($file);
    return $all_employees;
}

// =====================================================
// Function to get accurate daily attendance for an employee
// =====================================================
function getAccurateEmployeeAttendance($conn, $user_id, $date, $employee_info) {
    $windows = getShiftWindows($date);
    $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
    
    // Get check-in for this date (2PM to midnight)
    $checkin_query = $conn->query("
        SELECT timestamp FROM attendance_raw 
        WHERE user_id = '$user_id' 
        AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
        ORDER BY timestamp ASC LIMIT 1
    ");
    
    // Get check-out for this date's shift (midnight to noon next day)
    $checkout_query = $conn->query("
        SELECT timestamp FROM attendance_raw 
        WHERE user_id = '$user_id' 
        AND timestamp BETWEEN '{$windows['checkout_start']}' AND '{$windows['checkout_end']}'
        ORDER BY timestamp DESC LIMIT 1
    ");
    
    $checkin = ($checkin_query && $checkin_query->num_rows > 0) ? $checkin_query->fetch_assoc()['timestamp'] : null;
    $checkout = ($checkout_query && $checkout_query->num_rows > 0) ? $checkout_query->fetch_assoc()['timestamp'] : null;
    
    // Also check if there's any punch in the next day's check-in window (for night shifts that extend)
    if (!$checkout) {
        $next_windows = getShiftWindows($next_date);
        $next_checkout_query = $conn->query("
            SELECT timestamp FROM attendance_raw 
            WHERE user_id = '$user_id' 
            AND timestamp BETWEEN '{$next_windows['checkout_start']}' AND '{$next_windows['checkout_end']}'
            ORDER BY timestamp DESC LIMIT 1
        ");
        if ($next_checkout_query && $next_checkout_query->num_rows > 0) {
            $checkout = $next_checkout_query->fetch_assoc()['timestamp'];
        }
    }
    
    $status = 'Absent';
    $late_minutes = 0;
    $working_hours = 0;
    $in_time_display = '--:--';
    $out_time_display = '--:--';
    
    if ($checkin) {
        $in_time_display = date('h:i A', strtotime($checkin));
        list($is_late, $minutes) = isLate($checkin, $date);
        if ($is_late) {
            $status = 'Late';
            $late_minutes = $minutes;
        } else {
            $status = 'Present';
        }
    }
    
    if ($checkout) {
        $out_time_display = date('h:i A', strtotime($checkout));
    }
    
    if ($checkin && $checkout) {
        $working_hours = calculateWorkingHours($checkin, $checkout);
    }
    
    // Get name from database or CSV
    $emp_info = $conn->query("SELECT full_name FROM employees WHERE employee_code = '$user_id'")->fetch_assoc();
    $db_name = $emp_info['full_name'] ?? '';
    $employee_name = $employee_info['name'] ?: $db_name ?: 'Unknown';
    
    return [
        'date' => $date,
        'employee_id' => $user_id,
        'employee_name' => $employee_name,
        'department' => $employee_info['department'] ?? 'General',
        'designation' => $employee_info['designation'] ?? 'Employee',
        'branch' => $employee_info['branch'] ?? 'Main',
        'team' => $employee_info['team'] ?? 'No Team',
        'in_time' => $in_time_display,
        'out_time' => $out_time_display,
        'hours' => $working_hours > 0 ? number_format($working_hours, 2) . ' hrs' : '0 hrs',
        'status' => $status,
        'late_minutes' => $late_minutes,
        'has_check_in' => $checkin ? true : false,
        'has_check_out' => $checkout ? true : false
    ];
}

// =====================================================
// Function to get FULL ATTENDANCE for all employees
// =====================================================
function getFullAttendance($conn, $start_date, $end_date) {
    $all_employees = getAllEmployeesFromCSV();
    
    if (empty($all_employees)) {
        return ['error' => 'No employee data found in CSV'];
    }
    
    $attendance_data = [];
    $date_range = [];
    $current = $start_date;
    while ($current <= $end_date) {
        $date_range[] = $current;
        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }
    
    foreach ($all_employees as $emp_id => $emp_info) {
        foreach ($date_range as $date) {
            $record = getAccurateEmployeeAttendance($conn, $emp_id, $date, $emp_info);
            $attendance_data[] = $record;
        }
    }
    
    // Sort by date then employee ID
    usort($attendance_data, function($a, $b) {
        if ($a['date'] == $b['date']) {
            return strcmp($a['employee_id'], $b['employee_id']);
        }
        return strcmp($a['date'], $b['date']);
    });
    
    return $attendance_data;
}

// =====================================================
// Function to get attendance data with team grouping
// =====================================================
function getAttendanceDataWithTeams($conn, $start_date, $end_date) {
    $employee_teams = loadTeamDataFromCSV();
    
    if (isset($employee_teams['error'])) {
        return ['error' => $employee_teams['error']];
    }
    
    $team_data = [];
    $all_employees = getAllEmployeesFromCSV();
    $date_range = [];
    $current = $start_date;
    while ($current <= $end_date) {
        $date_range[] = $current;
        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }
    
    foreach ($all_employees as $emp_id => $emp_info) {
        $team_name = $emp_info['team'] ?: 'No Team';
        
        foreach ($date_range as $date) {
            $record = getAccurateEmployeeAttendance($conn, $emp_id, $date, $emp_info);
            
            if ($record['has_check_in'] || $record['has_check_out']) {
                if (!isset($team_data[$team_name])) {
                    $team_data[$team_name] = [];
                }
                $team_data[$team_name][] = $record;
            }
        }
    }
    
    // Sort records by date for each team
    foreach ($team_data as $team_name => &$records) {
        usort($records, function($a, $b) {
            if ($a['date'] == $b['date']) {
                return strcmp($a['employee_id'], $b['employee_id']);
            }
            return strcmp($a['date'], $b['date']);
        });
    }
    
    return $team_data;
}

// =====================================================
// Handle Date Range Selection
// =====================================================
if (isset($_GET['custom_start']) && isset($_GET['custom_end'])) {
    $custom_start = $_GET['custom_start'];
    $custom_end = $_GET['custom_end'];
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end)) {
        $start_date = $custom_start;
        $end_date = $custom_end;
    }
}

if (isset($_GET['weekly']) && $_GET['weekly'] == '1') {
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-7 days'));
}

if (isset($_GET['monthly']) && $_GET['monthly'] == '1') {
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-30 days'));
}

if (!isset($_GET['custom_start']) && !isset($_GET['weekly']) && !isset($_GET['monthly'])) {
    $start_date = '2026-03-10';
    $end_date = date('Y-m-d');
}

// =====================================================
// Handle FULL ATTENDANCE Download
// =====================================================
if (isset($_GET['download_full'])) {
    $attendance_data = getFullAttendance($conn, $start_date, $end_date);
    
    if (isset($attendance_data['error'])) {
        die("<script>
            alert('Error: " . addslashes($attendance_data['error']) . "');
            window.location.href = 'download_team_data.php';
        </script>");
    }
    
    if (empty($attendance_data)) {
        die("<script>
            alert('No attendance data found for the specified date range.');
            window.location.href = 'download_team_data.php';
        </script>");
    }
    
    $filename = 'full_attendance_' . $start_date . '_to_' . $end_date . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'Date', 'ID', 'Name', 'Department', 'Designation', 'Branch', 'Team',
        'In Time', 'Out Time', 'Hours', 'Status', 'Late Minutes'
    ]);
    
    foreach ($attendance_data as $record) {
        fputcsv($output, [
            $record['date'],
            $record['employee_id'],
            $record['employee_name'],
            $record['department'],
            $record['designation'],
            $record['branch'],
            $record['team'],
            $record['in_time'],
            $record['out_time'],
            $record['hours'],
            $record['status'],
            $record['late_minutes']
        ]);
    }
    
    fclose($output);
    exit;
}

// =====================================================
// Handle Single Team Download
// =====================================================
if (isset($_GET['team']) && !empty($_GET['team'])) {
    $team_name = urldecode($_GET['team']);
    $team_data = getAttendanceDataWithTeams($conn, $start_date, $end_date);
    
    if (isset($team_data['error'])) {
        die("<script>
            alert('Error: " . addslashes($team_data['error']) . "');
            window.location.href = 'download_team_data.php';
        </script>");
    }
    
    if (isset($team_data[$team_name]) && !empty($team_data[$team_name])) {
        $safe_filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $team_name);
        $filename = $safe_filename . '_attendance_' . $start_date . '_to_' . $end_date . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, [
            'Date', 'ID', 'Name', 'Department', 'Designation', 'Branch', 'Team',
            'In Time', 'Out Time', 'Hours', 'Status', 'Late Minutes'
        ]);
        
        foreach ($team_data[$team_name] as $record) {
            fputcsv($output, [
                $record['date'],
                $record['employee_id'],
                $record['employee_name'],
                $record['department'],
                $record['designation'],
                $record['branch'],
                $record['team'],
                $record['in_time'],
                $record['out_time'],
                $record['hours'],
                $record['status'],
                $record['late_minutes']
            ]);
        }
        
        fclose($output);
        exit;
    } else {
        $error = "No attendance data found for team: " . htmlspecialchars($team_name);
    }
}

// =====================================================
// Handle Download All Teams as ZIP
// =====================================================
if (isset($_GET['download_all_teams'])) {
    $team_data = getAttendanceDataWithTeams($conn, $start_date, $end_date);
    
    if (isset($team_data['error'])) {
        die("<script>
            alert('Error: " . addslashes($team_data['error']) . "');
            window.location.href = 'download_team_data.php';
        </script>");
    }
    
    if (empty($team_data)) {
        die("<script>
            alert('No attendance data found for the specified date range.');
            window.location.href = 'download_team_data.php';
        </script>");
    }
    
    $temp_dir = __DIR__ . '/temp_export_' . time();
    if (!mkdir($temp_dir)) {
        die("Failed to create temporary directory");
    }
    
    $files_created = 0;
    
    foreach ($team_data as $team_name => $records) {
        if (!empty($records)) {
            $safe_filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $team_name);
            $filename = $temp_dir . '/' . $safe_filename . '_attendance_' . $start_date . '_to_' . $end_date . '.csv';
            
            $fp = fopen($filename, 'w');
            if ($fp) {
                fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
                
                fputcsv($fp, [
                    'Date', 'ID', 'Name', 'Department', 'Designation', 'Branch', 'Team',
                    'In Time', 'Out Time', 'Hours', 'Status', 'Late Minutes'
                ]);
                
                foreach ($records as $record) {
                    fputcsv($fp, [
                        $record['date'],
                        $record['employee_id'],
                        $record['employee_name'],
                        $record['department'],
                        $record['designation'],
                        $record['branch'],
                        $record['team'],
                        $record['in_time'],
                        $record['out_time'],
                        $record['hours'],
                        $record['status'],
                        $record['late_minutes']
                    ]);
                }
                
                fclose($fp);
                $files_created++;
            }
        }
    }
    
    if ($files_created === 0) {
        rmdir($temp_dir);
        die("<script>
            alert('No attendance data found to export.');
            window.location.href = 'download_team_data.php';
        </script>");
    }
    
    $zip_filename = 'all_teams_attendance_' . $start_date . '_to_' . $end_date . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("Failed to create ZIP file");
    }
    
    $files = scandir($temp_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $zip->addFile($temp_dir . '/' . $file, $file);
        }
    }
    
    $zip->close();
    
    array_map('unlink', glob("$temp_dir/*"));
    rmdir($temp_dir);
    
    if (file_exists($zip_filename)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_filename));
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($zip_filename);
        unlink($zip_filename);
        exit;
    } else {
        die("ZIP file not found");
    }
}

// =====================================================
// Display the download interface
// =====================================================
$team_data = getAttendanceDataWithTeams($conn, $start_date, $end_date);
$csv_error = isset($team_data['error']) ? $team_data['error'] : null;
if ($csv_error) {
    $team_data = [];
}

$total_records = 0;
$total_teams = count($team_data);
if (!isset($team_data['error'])) {
    foreach ($team_data as $records) {
        $total_records += count($records);
    }
}

// Get full attendance count for summary
$full_attendance = getFullAttendance($conn, $start_date, $end_date);
$total_full_records = is_array($full_attendance) ? count($full_attendance) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balitech · Attendance Export Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-glow: rgba(249,115,22,0.4);
            --secondary: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --dark: #0a0c15;
            --glass: rgba(255, 255, 255, 0.07);
            --glass-border: rgba(255, 255, 255, 0.1);
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at 20% 30%, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background: linear-gradient(125deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
        }

        .animated-bg::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            top: -50%;
            left: -50%;
            background: radial-gradient(circle, rgba(249,115,22,0.15) 0%, transparent 70%);
            animation: slowRotate 30s linear infinite;
        }

        @keyframes slowRotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(249,115,22,0.3);
            border-radius: 50%;
            animation: floatParticle linear infinite;
        }

        @keyframes floatParticle {
            0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 10px; }

        .container { max-width: 1400px; margin: 0 auto; padding: 24px; position: relative; z-index: 1; }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            padding: 12px 28px;
            border-radius: 50px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 28px;
        }

        .back-btn:hover { background: rgba(255,255,255,0.15); transform: translateX(-5px); border-color: var(--primary); }

        .header {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            padding: 40px;
            margin-bottom: 32px;
            border: 1px solid rgba(255,255,255,0.08);
            position: relative;
            overflow: hidden;
        }

        .header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 80%;
            height: 200%;
            background: radial-gradient(circle, rgba(249,115,22,0.1), transparent);
            animation: shimmer 8s ease infinite;
        }

        @keyframes shimmer {
            0%, 100% { transform: translateX(-20%) translateY(-20%) rotate(45deg); opacity: 0.5; }
            50% { transform: translateX(20%) translateY(20%) rotate(45deg); opacity: 1; }
        }

        .header h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }

        .header p { color: rgba(255,255,255,0.7); font-size: 15px; position: relative; z-index: 1; }

        .date-range {
            background: rgba(0,0,0,0.3);
            padding: 15px 25px;
            border-radius: 20px;
            display: inline-block;
            margin-top: 20px;
            border: 1px solid rgba(249,115,22,0.2);
            position: relative;
            z-index: 1;
        }

        .date-range i { color: var(--primary); margin-right: 10px; }
        .date-range strong { color: white; }
        .date-range span { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; padding: 4px 12px; border-radius: 30px; font-size: 12px; margin-left: 12px; }

        /* Export Options Section */
        .export-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 32px;
        }

        .export-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 28px;
            border: 1px solid rgba(255,255,255,0.08);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .export-card:hover {
            transform: translateY(-5px);
            border-color: rgba(249,115,22,0.4);
            background: rgba(255,255,255,0.05);
        }

        .export-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .export-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
        }

        .export-card-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: white;
        }

        .export-card p {
            color: rgba(255,255,255,0.6);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .export-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .export-stat {
            text-align: center;
        }

        .export-stat .number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
        }

        .export-stat .label {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
        }

        .export-btn-large {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .export-btn-large:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(249,115,22,0.5);
        }

        .export-btn-secondary {
            background: linear-gradient(135deg, var(--purple), #6d28d9);
        }

        /* Date Range Selector */
        .date-range-selector {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 28px;
            margin-bottom: 32px;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .date-range-selector h3 {
            color: white;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-range-selector h3 i { color: var(--primary); font-size: 24px; }

        .range-options {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .range-btn {
            padding: 12px 28px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: rgba(255,255,255,0.7);
        }

        .range-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: transparent;
            box-shadow: 0 8px 20px -5px rgba(249,115,22,0.4);
        }

        .range-btn:hover:not(.active) {
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
        }

        .custom-range {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .custom-range input {
            padding: 12px 20px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            font-size: 14px;
            color: white;
        }

        .custom-range input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .apply-btn {
            padding: 12px 32px;
            background: linear-gradient(135deg, var(--purple), #6d28d9);
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .apply-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(139,92,246,0.4); }

        /* Teams Grid */
        .teams-section {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 28px;
            margin-top: 32px;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .teams-section h3 {
            color: white;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .teams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .team-card {
            background: rgba(255,255,255,0.05);
            border-radius: 20px;
            padding: 20px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .team-card:hover {
            transform: translateY(-4px);
            border-color: rgba(249,115,22,0.3);
        }

        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .team-name {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .team-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .team-name h4 { color: white; font-size: 16px; font-weight: 600; }
        .team-count { background: rgba(249,115,22,0.2); padding: 4px 12px; border-radius: 20px; font-size: 12px; color: var(--primary); }

        .team-stats {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
            padding: 10px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .team-stat { text-align: center; }
        .team-stat .num { font-size: 20px; font-weight: 700; color: white; }
        .team-stat .lab { font-size: 11px; color: rgba(255,255,255,0.5); }

        .download-team-btn {
            width: 100%;
            padding: 10px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 13px;
        }

        .download-team-btn:hover {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: transparent;
            transform: translateY(-2px);
        }

        .error-message {
            background: rgba(239,68,68,0.1);
            backdrop-filter: blur(10px);
            color: #f87171;
            padding: 20px 25px;
            border-radius: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--danger);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 25px;
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            color: rgba(255,255,255,0.6);
        }

        @media (max-width: 768px) {
            .export-options { grid-template-columns: 1fr; }
            .container { padding: 16px; }
            .header h1 { font-size: 28px; }
            .range-options { flex-direction: column; }
            .range-btn { width: 100%; justify-content: center; }
            .custom-range { flex-direction: column; align-items: stretch; }
            .custom-range input { width: 100%; }
            .apply-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>
    <div class="particles" id="particles"></div>

    <div class="container">
        <a href="attendance-dashboard.html" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="header">
            <h1>BALI<span style="background: linear-gradient(135deg, #fff, var(--primary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">TECH</span> · Attendance Export Hub</h1>
            <p>Export attendance data with complete accuracy for all employees and teams</p>
            
            <div class="date-range">
                <i class="fas fa-calendar-alt"></i>
                <strong>Date Range:</strong> <?php echo $start_date; ?> to <?php echo $end_date; ?>
                <span><i class="fas fa-clock"></i> <?php $days = (strtotime($end_date) - strtotime($start_date)) / (60*60*24) + 1; echo $days . ' days'; ?></span>
            </div>
        </div>

        <div class="date-range-selector">
            <h3><i class="fas fa-calendar-week"></i> Select Date Range</h3>
            <div class="range-options">
                <button class="range-btn <?php echo (!isset($_GET['weekly']) && !isset($_GET['monthly']) && !isset($_GET['custom_start'])) ? 'active' : ''; ?>" onclick="setRange('default')">
                    <i class="fas fa-calendar-alt"></i> March 10 - Today
                </button>
                <button class="range-btn <?php echo isset($_GET['weekly']) ? 'active' : ''; ?>" onclick="setRange('weekly')">
                    <i class="fas fa-calendar-week"></i> Last 7 Days (Weekly)
                </button>
                <button class="range-btn <?php echo isset($_GET['monthly']) ? 'active' : ''; ?>" onclick="setRange('monthly')">
                    <i class="fas fa-calendar-month"></i> Last 30 Days (Monthly)
                </button>
            </div>
            <div class="custom-range">
                <input type="date" id="custom_start" value="<?php echo $start_date; ?>">
                <span><i class="fas fa-arrow-right"></i></span>
                <input type="date" id="custom_end" value="<?php echo $end_date; ?>">
                <button class="apply-btn" onclick="applyCustomRange()"><i class="fas fa-check"></i> Apply Custom Range</button>
            </div>
        </div>

        <?php if ($csv_error): ?>
            <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?php echo $csv_error; ?></div>
        <?php endif; ?>

        <!-- Export Options -->
        <div class="export-options">
            <div class="export-card">
                <div class="export-card-header">
                    <div class="export-icon"><i class="fas fa-file-alt"></i></div>
                    <h3>Full Attendance Export</h3>
                </div>
                <p>Download complete attendance data for ALL employees. Includes all dates, check-in/out times, working hours, and status for every employee in the system.</p>
                <div class="export-stats">
                    <div class="export-stat"><div class="number"><?php echo $total_full_records; ?></div><div class="label">Total Records</div></div>
                    <div class="export-stat"><div class="number"><?php echo count(getAllEmployeesFromCSV()); ?></div><div class="label">Employees</div></div>
                    <div class="export-stat"><div class="number"><?php echo $days; ?></div><div class="label">Days</div></div>
                </div>
                <a href="?download_full=1<?php echo isset($_GET['custom_start']) ? '&custom_start=' . $start_date . '&custom_end=' . $end_date : (isset($_GET['weekly']) ? '&weekly=1' : (isset($_GET['monthly']) ? '&monthly=1' : '')); ?>" class="export-btn-large" onclick="return confirm('Download full attendance report? This may take a moment.')">
                    <i class="fas fa-download"></i> Download Full Attendance (CSV)
                </a>
            </div>

            <div class="export-card">
                <div class="export-card-header">
                    <div class="export-icon"><i class="fas fa-file-zipper"></i></div>
                    <h3>All Teams Export (ZIP)</h3>
                </div>
                <p>Download separate CSV files for each team, all bundled in a ZIP archive. Perfect for team-wise analysis and reporting.</p>
                <div class="export-stats">
                    <div class="export-stat"><div class="number"><?php echo $total_teams; ?></div><div class="label">Teams</div></div>
                    <div class="export-stat"><div class="number"><?php echo $total_records; ?></div><div class="label">Records</div></div>
                    <div class="export-stat"><div class="number"><?php echo $days; ?></div><div class="label">Days</div></div>
                </div>
                <a href="?download_all_teams=1<?php echo isset($_GET['custom_start']) ? '&custom_start=' . $start_date . '&custom_end=' . $end_date : (isset($_GET['weekly']) ? '&weekly=1' : (isset($_GET['monthly']) ? '&monthly=1' : '')); ?>" class="export-btn-large export-btn-secondary" onclick="return confirm('Download all teams as ZIP file? This may take a moment.')">
                    <i class="fas fa-file-zipper"></i> Download All Teams (ZIP)
                </a>
            </div>
        </div>

        <?php if (!empty($team_data) && !$csv_error): ?>
        <div class="teams-section">
            <h3><i class="fas fa-users"></i> Individual Team Downloads</h3>
            <div class="teams-grid">
                <?php foreach ($team_data as $team_name => $records): 
                    $unique_employees = [];
                    foreach ($records as $record) { $unique_employees[$record['employee_id']] = true; }
                    $employee_count = count($unique_employees);
                    $present_count = count(array_filter($records, function($r) { return $r['status'] == 'Present'; }));
                    $late_count = count(array_filter($records, function($r) { return $r['status'] == 'Late'; }));
                    
                    $download_url = '?team=' . urlencode($team_name);
                    if (isset($_GET['custom_start']) && isset($_GET['custom_end'])) {
                        $download_url .= '&custom_start=' . $start_date . '&custom_end=' . $end_date;
                    } elseif (isset($_GET['weekly'])) {
                        $download_url .= '&weekly=1';
                    } elseif (isset($_GET['monthly'])) {
                        $download_url .= '&monthly=1';
                    }
                ?>
                <div class="team-card">
                    <div class="team-header">
                        <div class="team-name">
                            <div class="team-icon"><i class="fas fa-users"></i></div>
                            <h4><?php echo htmlspecialchars($team_name); ?></h4>
                        </div>
                        <span class="team-count"><?php echo count($records); ?> records</span>
                    </div>
                    <div class="team-stats">
                        <div class="team-stat"><div class="num"><?php echo $employee_count; ?></div><div class="lab">Employees</div></div>
                        <div class="team-stat"><div class="num" style="color: #10b981;"><?php echo $present_count; ?></div><div class="lab">Present</div></div>
                        <div class="team-stat"><div class="num" style="color: #f59e0b;"><?php echo $late_count; ?></div><div class="lab">Late</div></div>
                    </div>
                    <a href="<?php echo $download_url; ?>" class="download-team-btn">
                        <i class="fas fa-download"></i> Download CSV
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!$csv_error && empty($team_data)): ?>
            <div class="error-message"><i class="fas fa-exclamation-triangle"></i> No attendance data found for the selected date range.</div>
        <?php endif; ?>

        <footer class="footer">
            <p>⚡ BALITECH NEXUS · Attendance Export System v4.0</p>
            <p style="font-size: 12px; margin-top: 8px;"><a href="attendance-dashboard.html" style="color: rgba(255,255,255,0.6);"><i class="fas fa-chart-line"></i> Back to Dashboard</a></p>
        </footer>
    </div>

    <script>
        function createParticles() {
            const container = document.getElementById('particles');
            for (let i = 0; i < 40; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                const size = Math.random() * 4 + 2;
                particle.style.width = size + 'px';
                particle.style.height = size + 'px';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDuration = Math.random() * 10 + 10 + 's';
                particle.style.animationDelay = Math.random() * 5 + 's';
                container.appendChild(particle);
            }
        }
        createParticles();

        function setRange(type) {
            let url = 'download_team_data.php?';
            if (type === 'weekly') url += 'weekly=1';
            else if (type === 'monthly') url += 'monthly=1';
            else url = 'download_team_data.php';
            window.location.href = url;
        }
        
        function applyCustomRange() {
            const startDate = document.getElementById('custom_start').value;
            const endDate = document.getElementById('custom_end').value;
            if (!startDate || !endDate) { alert('Please select both start and end dates'); return; }
            if (startDate > endDate) { alert('Start date cannot be after end date'); return; }
            window.location.href = 'download_team_data.php?custom_start=' + startDate + '&custom_end=' + endDate;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('weekly')) {
                document.querySelectorAll('.range-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.range-btn')[1].classList.add('active');
            } else if (urlParams.has('monthly')) {
                document.querySelectorAll('.range-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.range-btn')[2].classList.add('active');
            } else if (urlParams.has('custom_start')) {
                document.querySelectorAll('.range-btn').forEach(btn => btn.classList.remove('active'));
            } else {
                document.querySelectorAll('.range-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.range-btn')[0].classList.add('active');
            }
        });
    </script>
</body>
</html>