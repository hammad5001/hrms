<?php
// =====================================================
// ATTENDANCE EXPORT - IMAGE FORMAT (March 2026)
// Generates attendance data exactly like the image shows
// =====================================================

date_default_timezone_set('Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// =====================================================
// Load Employee Data from CSV
// =====================================================
function loadEmployeeDataFromCSV() {
    $csv_file = __DIR__ . '/Present Employee Data - Sheet1.csv';
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
    
    $current_team = '';
    $current_department = '';
    $current_branch = '';
    
    while (($row = fgetcsv($file)) !== FALSE) {
        // Skip empty rows
        if (empty(array_filter($row))) continue;
        
        $row = array_map('trim', $row);
        
        // Check if this is a team header row
        if (empty($row[0]) && !empty($row[1]) && strpos($row[1], 'Team') !== false) {
            $current_team = $row[1];
            continue;
        }
        
        // Check if this row has B-ID (employee data)
        if (!empty($row[0]) && is_numeric($row[0])) {
            $b_id = $row[0];
            $name = $row[1] ?? '';
            $designation = $row[2] ?? '';
            $department = !empty($row[3]) ? $row[3] : $current_department;
            $branch = !empty($row[4]) ? $row[4] : $current_branch;
            
            // If department is empty, try to get from team name
            if (empty($department) && !empty($current_team)) {
                if (preg_match('/^([A-Za-z]+)/', $current_team, $matches)) {
                    $department = $matches[1];
                } else {
                    $department = $current_team;
                }
            }
            
            $employees[$b_id] = [
                'id' => $b_id,
                'name' => $name,
                'designation' => $designation,
                'department' => $department,
                'branch' => $branch,
                'team' => $current_team,
                'designatic_branch' => $designation . ' ' . $branch // Combined as in image
            ];
        }
        
        // Update current department if this row has department info
        if (!empty($row[3]) && empty($row[0])) {
            $current_department = $row[3];
        }
        
        // Update current branch if this row has branch info
        if (!empty($row[4]) && empty($row[0])) {
            $current_branch = $row[4];
        }
    }
    
    fclose($file);
    return $employees;
}

// =====================================================
// Get shift windows
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

// =====================================================
// Calculate working hours
// =====================================================
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
// Check if late (after 7:00 PM)
// =====================================================
function isLate($punch_time, $shift_date) {
    $shift_start = strtotime($shift_date . ' 19:00:00'); // 7:00 PM
    $punch = strtotime($punch_time);
    $minutes_late = ($punch - $shift_start) / 60;
    
    if ($minutes_late <= 15) { // 15 minutes grace period
        return [false, 0];
    }
    
    if ($minutes_late > 15) {
        return [true, round($minutes_late)];
    }
    
    return [false, 0];
}

// =====================================================
// Generate attendance in image format
// =====================================================
function generateAttendanceInImageFormat($conn, $date) {
    $windows = getShiftWindows($date);
    
    // Get all employees
    $employees_result = $conn->query("
        SELECT id, employee_code, full_name, department 
        FROM " . TABLE_EMPLOYEES . " 
        WHERE is_active = 1 
        ORDER BY CAST(employee_code AS UNSIGNED)
    ");
    
    if (!$employees_result) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    
    // Load CSV employee data
    $csv_employees = loadEmployeeDataFromCSV();
    
    $attendance = [];
    
    while ($emp = $employees_result->fetch_assoc()) {
        $emp_code = $emp['employee_code'];
        $csv_emp = $csv_employees[$emp_code] ?? null;
        
        // Get department and designation/branch
        $department = $csv_emp['department'] ?? $emp['department'] ?: 'General';
        $designation = $csv_emp['designation'] ?? 'Employee';
        $branch = $csv_emp['branch'] ?? 'Main';
        
        // Create Designatic Branch as shown in image
        $designatic_branch = $designation . ' ' . $branch;
        
        // Get check-in for this shift
        $checkin_result = $conn->query("
            SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
            WHERE user_id = '$emp_code' 
            AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
            ORDER BY timestamp LIMIT 1
        ");
        
        // Get check-out for this shift
        $checkout_result = $conn->query("
            SELECT timestamp FROM " . TABLE_ATTENDANCE . " 
            WHERE user_id = '$emp_code' 
            AND timestamp BETWEEN '{$windows['checkout_start']}' AND '{$windows['checkout_end']}'
            ORDER BY timestamp DESC LIMIT 1
        ");
        
        $check_in = null;
        $check_out = null;
        $status = 'absent';
        
        if ($checkin_result && $checkin_result->num_rows > 0) {
            $row = $checkin_result->fetch_assoc();
            $check_in = $row['timestamp'];
            
            list($is_late, $late_minutes) = isLate($check_in, $date);
            if ($is_late) {
                $status = 'late';
            } else {
                $status = 'present';
            }
        }
        
        if ($checkout_result && $checkout_result->num_rows > 0) {
            $row = $checkout_result->fetch_assoc();
            $check_out = $row['timestamp'];
        }
        
        // Calculate hours
        $hours = 0;
        if ($check_in && $check_out) {
            $hours = calculateWorkingHours($check_in, $check_out);
        }
        
        // Format times exactly like the image
        $in_time = $check_in ? date('h:i A', strtotime($check_in)) : '---:--';
        $out_time = $check_out ? date('h:i A', strtotime($check_out)) : '---:--';
        $hours_display = $hours > 0 ? $hours . ' hrs' : '0 hrs';
        
        // Create row exactly like the image
        $attendance[] = [
            'ID' => $emp_code,
            'Name' => $emp['full_name'],
            'Department' => $department,
            'Designatic Branch' => $designatic_branch,
            'In Time' => $in_time,
            'Out Time' => $out_time,
            'Hours' => $hours_display,
            'Status' => $status
        ];
    }
    
    return [
        'success' => true,
        'data' => $attendance,
        'date' => $date
    ];
}

// =====================================================
// Export to CSV in image format
// =====================================================
function exportToCSV($data, $date) {
    $filename = 'attendance_' . $date . '_format.csv';
    
    $file = fopen($filename, 'w');
    
    // Write headers exactly as in the image
    fputcsv($file, ['ID', 'Name', 'Department', 'Designatic Branch', 'In Time', 'Out Time', 'Hours', 'Status']);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($file, [
            $row['ID'],
            $row['Name'],
            $row['Department'],
            $row['Designatic Branch'],
            $row['In Time'],
            $row['Out Time'],
            $row['Hours'],
            $row['Status']
        ]);
    }
    
    fclose($file);
    
    return $filename;
}

// =====================================================
// Handle API request
// =====================================================
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    
    // =================================================
    // Get attendance for a specific date in image format
    // =================================================
    case 'getAttendanceImageFormat':
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD'
            ]);
            exit;
        }
        
        $result = generateAttendanceInImageFormat($conn, $date);
        
        header('Content-Type: application/json');
        echo json_encode($result);
        break;
    
    // =================================================
    // Download CSV for a specific date in image format
    // =================================================
    case 'downloadCSV':
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            die('Invalid date format. Use YYYY-MM-DD');
        }
        
        $result = generateAttendanceInImageFormat($conn, $date);
        
        if (!$result['success']) {
            die('Error: ' . $result['message']);
        }
        
        $filename = exportToCSV($result['data'], $date);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($filename);
        
        // Optional: Delete file after download
        // unlink($filename);
        break;
    
    // =================================================
    // Download range of dates (March 1 to today)
    // =================================================
    case 'downloadMarchRange':
        $start_date = '2026-03-01';
        $end_date = date('Y-m-d'); // Today
        
        $all_attendance = [];
        
        $current = $start_date;
        while ($current <= $end_date) {
            $result = generateAttendanceInImageFormat($conn, $current);
            
            if ($result['success']) {
                // Add date column to each row
                foreach ($result['data'] as $row) {
                    $row['Date'] = $current;
                    $all_attendance[] = $row;
                }
            }
            
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }
        
        // Generate filename
        $filename = 'attendance_march_1_to_' . $end_date . '.csv';
        
        $file = fopen($filename, 'w');
        
        // Write headers with Date column
        fputcsv($file, ['Date', 'ID', 'Name', 'Department', 'Designatic Branch', 'In Time', 'Out Time', 'Hours', 'Status']);
        
        // Write data
        foreach ($all_attendance as $row) {
            fputcsv($file, [
                $row['Date'],
                $row['ID'],
                $row['Name'],
                $row['Department'],
                $row['Designatic Branch'],
                $row['In Time'],
                $row['Out Time'],
                $row['Hours'],
                $row['Status']
            ]);
        }
        
        fclose($file);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($filename);
        
        // Optional: Delete file after download
        // unlink($filename);
        break;
    
    // =================================================
    // Default: Show HTML interface
    // =================================================
    default:
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Attendance Export - Image Format</title>
            <style>
                body { font-family: Arial; margin: 20px; background: #f5f5f5; }
                .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { color: #333; }
                .date-picker { margin: 20px 0; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input[type=date] { padding: 8px; width: 200px; border: 1px solid #ddd; border-radius: 4px; }
                button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px; }
                button:hover { background: #0056b3; }
                .preview { margin-top: 30px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #007bff; color: white; padding: 10px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                .present { color: green; }
                .absent { color: red; }
                .late { color: orange; }
                .info { background: #e7f3ff; padding: 10px; border-radius: 4px; margin: 10px 0; }
                .btn-group { margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>📊 Attendance Export - Image Format</h1>
                
                <div class="info">
                    <strong>March 2026 Data Available</strong><br>
                    Python script has fetched data from March 1 to <?php echo date('F j, Y'); ?>
                </div>
                
                <div class="btn-group">
                    <button onclick="downloadMarchRange()">📥 Download March 1 - Today (All Dates)</button>
                </div>
                
                <div class="date-picker">
                    <label>Select Date:</label>
                    <input type="date" id="attendanceDate" value="<?php echo date('Y-m-d'); ?>">
                    <button onclick="fetchAttendance()">🔍 Show Attendance</button>
                    <button onclick="downloadCSV()">📥 Download CSV</button>
                </div>
                
                <div class="preview" id="preview">
                    <h3>Preview (Click "Show Attendance" to load)</h3>
                    <table id="attendanceTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Designatic Branch</th>
                                <th>In Time</th>
                                <th>Out Time</th>
                                <th>Hours</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="8" style="text-align: center;">Enter a date and click Show Attendance</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <script>
                function fetchAttendance() {
                    const date = document.getElementById('attendanceDate').value;
                    
                    fetch(`?action=getAttendanceImageFormat&date=${date}`)
                        .then(response => response.json())
                        .then(data => {
                            const tbody = document.querySelector('#attendanceTable tbody');
                            tbody.innerHTML = '';
                            
                            if (data.success && data.data.length > 0) {
                                data.data.forEach(row => {
                                    const tr = document.createElement('tr');
                                    
                                    let statusClass = '';
                                    if (row.Status === 'present') statusClass = 'present';
                                    else if (row.Status === 'absent') statusClass = 'absent';
                                    else if (row.Status === 'late') statusClass = 'late';
                                    
                                    tr.innerHTML = `
                                        <td>${row.ID}</td>
                                        <td>${row.Name}</td>
                                        <td>${row.Department}</td>
                                        <td>${row['Designatic Branch']}</td>
                                        <td>${row['In Time']}</td>
                                        <td>${row['Out Time']}</td>
                                        <td>${row.Hours}</td>
                                        <td class="${statusClass}">${row.Status}</td>
                                    `;
                                    tbody.appendChild(tr);
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No attendance data for this date</td></tr>';
                            }
                        })
                        .catch(error => {
                            alert('Error fetching attendance: ' + error);
                        });
                }
                
                function downloadCSV() {
                    const date = document.getElementById('attendanceDate').value;
                    window.location.href = `?action=downloadCSV&date=${date}`;
                }
                
                function downloadMarchRange() {
                    window.location.href = '?action=downloadMarchRange';
                }
                
                // Load today's attendance by default
                window.onload = function() {
                    fetchAttendance();
                };
            </script>
        </body>
        </html>
        <?php
        break;
}
?>