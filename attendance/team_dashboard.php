<?php
// team_dashboard.php - Standalone Team Dashboard for Sheet4
// This file works independently without modifying your existing code

date_default_timezone_set('Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
require_once 'config.php';

// =====================================================
// Load Team Data from Sheet4 CSV
// =====================================================
function loadTeamDataFromSheet4() {
    $csv_file = __DIR__ . '/Present Employee Data - Sheet4.csv';
    $team_data = [];
    
    if (!file_exists($csv_file)) {
        return ['error' => 'CSV file not found: Present Employee Data - Sheet4.csv'];
    }
    
    $file = fopen($csv_file, 'r');
    if (!$file) {
        return ['error' => 'Cannot open CSV file'];
    }
    
    // Read header row
    $headers = fgetcsv($file);
    $headers = array_map('trim', $headers);
    
    $team_stats = [];
    $employee_teams = [];
    
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
            
            // Store employee team mapping
            $employee_teams[$b_id] = [
                'id' => $b_id,
                'name' => $name,
                'team' => $team ?: 'No Team',
                'department' => $department ?: 'General',
                'designation' => $designation ?: 'Employee',
                'branch' => $branch ?: 'Main'
            ];
            
            // Count team statistics
            $team_name = $team ?: 'No Team';
            if (!isset($team_stats[$team_name])) {
                $team_stats[$team_name] = [
                    'total' => 0,
                    'employees' => []
                ];
            }
            $team_stats[$team_name]['total']++;
            $team_stats[$team_name]['employees'][] = $b_id;
        }
    }
    
    fclose($file);
    
    return [
        'teams' => $team_stats,
        'employees' => $employee_teams,
        'total_employees' => count($employee_teams)
    ];
}

// =====================================================
// Get Today's Attendance from Database
// =====================================================
function getTodayAttendance($conn) {
    $today = date('Y-m-d');
    $attendance = [];
    
    // Define shift windows
    $next_date = date('Y-m-d', strtotime($today . ' +1 day'));
    $checkin_start = $today . ' 14:00:00';
    $checkin_end = $today . ' 23:59:59';
    $checkout_start = $next_date . ' 00:00:00';
    $checkout_end = $next_date . ' 11:59:59';
    
    // Get all active employees
    $employees = $conn->query("
        SELECT id, employee_code, full_name 
        FROM employees 
        WHERE is_active = 1 
        ORDER BY CAST(employee_code AS UNSIGNED)
    ");
    
    if (!$employees) {
        return ['error' => $conn->error];
    }
    
    while ($emp = $employees->fetch_assoc()) {
        $emp_code = $conn->real_escape_string($emp['employee_code']);
        
        // Get check-in
        $checkin = $conn->query("
            SELECT timestamp FROM attendance_raw 
            WHERE user_id = '$emp_code' 
            AND timestamp BETWEEN '$checkin_start' AND '$checkin_end'
            ORDER BY timestamp LIMIT 1
        ");
        
        // Get check-out
        $checkout = $conn->query("
            SELECT timestamp FROM attendance_raw 
            WHERE user_id = '$emp_code' 
            AND timestamp BETWEEN '$checkout_start' AND '$checkout_end'
            ORDER BY timestamp DESC LIMIT 1
        ");
        
        $in_time = null;
        $out_time = null;
        $status = 'absent';
        
        if ($checkin && $checkin->num_rows > 0) {
            $row = $checkin->fetch_assoc();
            $in_time = $row['timestamp'];
            $status = 'present';
        }
        
        if ($checkout && $checkout->num_rows > 0) {
            $row = $checkout->fetch_assoc();
            $out_time = $row['timestamp'];
        }
        
        $attendance[$emp['employee_code']] = [
            'employee_id' => $emp['id'],
            'employee_code' => $emp['employee_code'],
            'employee_name' => $emp['full_name'],
            'in_time' => $in_time,
            'out_time' => $out_time,
            'status' => $status
        ];
    }
    
    return $attendance;
}

// Load team data
$team_data = loadTeamDataFromSheet4();
$attendance_data = getTodayAttendance($conn);

// Combine data
$team_attendance = [];
$team_present_counts = [];
$team_late_counts = [];
$team_absent_counts = [];

if (!isset($team_data['error']) && isset($team_data['teams'])) {
    foreach ($team_data['teams'] as $team_name => $team_info) {
        $team_attendance[$team_name] = [
            'total' => $team_info['total'],
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'employees' => []
        ];
        
        $team_present_counts[$team_name] = 0;
        $team_late_counts[$team_name] = 0;
        $team_absent_counts[$team_name] = 0;
    }
    
    // Calculate attendance by team
    foreach ($team_data['employees'] as $emp_id => $emp_info) {
        $team_name = $emp_info['team'];
        
        if (isset($attendance_data[$emp_id])) {
            $status = $attendance_data[$emp_id]['status'];
            
            if ($status == 'present') {
                $team_attendance[$team_name]['present']++;
                $team_present_counts[$team_name]++;
            } else {
                $team_attendance[$team_name]['absent']++;
                $team_absent_counts[$team_name]++;
            }
            
            // Store employee attendance
            $team_attendance[$team_name]['employees'][] = [
                'id' => $emp_id,
                'name' => $emp_info['name'],
                'department' => $emp_info['department'],
                'designation' => $emp_info['designation'],
                'branch' => $emp_info['branch'],
                'status' => $status,
                'in_time' => $attendance_data[$emp_id]['in_time'] ?? null,
                'out_time' => $attendance_data[$emp_id]['out_time'] ?? null
            ];
        }
    }
}

// Sort teams by total employees
uksort($team_attendance, function($a, $b) use ($team_attendance) {
    return $team_attendance[$b]['total'] - $team_attendance[$a]['total'];
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balitech · Team Attendance Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-light: #fff7ed;
            --secondary: #10b981;
            --secondary-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --purple: #8b5cf6;
            --purple-light: #ede9fe;
            --dark: #0f172a;
            --gray: #64748b;
            --gray-light: #e2e8f0;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 20px 30px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .logo h1 {
            color: white;
            font-size: 24px;
            font-weight: 700;
        }

        .logo span {
            color: var(--primary);
        }

        .date {
            background: rgba(255,255,255,0.1);
            padding: 10px 20px;
            border-radius: 40px;
            color: white;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.5);
        }

        .summary-title {
            color: var(--gray);
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--dark);
        }

        /* Team Grid */
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .team-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.5);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .team-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px -12px rgba(0,0,0,0.3);
            border-color: var(--primary);
        }

        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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

        .team-name h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }

        .team-count {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
        }

        .team-stats {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
            padding: 15px 0;
            border-top: 2px dashed var(--gray-light);
            border-bottom: 2px dashed var(--gray-light);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            margin-top: 5px;
        }

        .stat-value.present { color: var(--secondary); }
        .stat-value.absent { color: var(--danger); }

        .progress-bar {
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            margin: 15px 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .team-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--gray);
            font-size: 13px;
        }

        .attendance-rate {
            font-weight: 700;
            color: var(--primary);
            background: var(--primary-light);
            padding: 5px 12px;
            border-radius: 30px;
        }

        /* Employee List Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 30px;
            width: 90%;
            max-width: 1000px;
            max-height: 80vh;
            overflow-y: auto;
            padding: 30px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark);
        }

        .close-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--gray-light);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            background: var(--danger);
            color: white;
        }

        .employee-table {
            width: 100%;
            border-collapse: collapse;
        }

        .employee-table th {
            text-align: left;
            padding: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-weight: 600;
            font-size: 13px;
        }

        .employee-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-light);
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-present {
            background: var(--secondary-light);
            color: var(--secondary);
        }

        .status-absent {
            background: var(--danger-light);
            color: var(--danger);
        }

        .time-display {
            font-size: 13px;
            color: var(--dark);
            font-weight: 500;
        }

        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: rgba(255,255,255,0.1);
            color: white;
            text-decoration: none;
            border-radius: 40px;
            font-size: 14px;
            border: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 20px;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Error Message */
        .error-message {
            background: var(--danger-light);
            color: var(--danger);
            padding: 20px;
            border-radius: 16px;
            text-align: center;
            font-size: 16px;
            margin: 20px 0;
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .team-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Back Button -->
        <a href="attendance-dashboard" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Main Dashboard
        </a>

        <!-- Header -->
        <div class="header">
            <div class="logo">
                <h1>BALI<span>TECH</span> · Team Dashboard</h1>
            </div>
            <div class="date">
                <i class="fas fa-calendar-alt"></i>
                <?php echo date('l, F j, Y'); ?>
            </div>
        </div>

        <?php if (isset($team_data['error'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $team_data['error']; ?>
            </div>
        <?php else: ?>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title">Total Teams</div>
                <div class="summary-value"><?php echo count($team_attendance); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Total Employees</div>
                <div class="summary-value"><?php echo $team_data['total_employees']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Present Today</div>
                <div class="summary-value"><?php echo array_sum($team_present_counts); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Absent Today</div>
                <div class="summary-value"><?php echo array_sum($team_absent_counts); ?></div>
            </div>
        </div>

        <!-- Team Grid -->
        <div class="team-grid">
            <?php foreach ($team_attendance as $team_name => $team): ?>
                <?php 
                $attendance_rate = $team['total'] > 0 ? round(($team['present'] / $team['total']) * 100) : 0;
                ?>
                <div class="team-card" onclick="showTeamEmployees('<?php echo htmlspecialchars($team_name); ?>')">
                    <div class="team-header">
                        <div class="team-name">
                            <div class="team-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <h3><?php echo htmlspecialchars($team_name); ?></h3>
                        </div>
                        <span class="team-count"><?php echo $team['total']; ?> members</span>
                    </div>
                    
                    <div class="team-stats">
                        <div class="stat-item">
                            <div class="stat-value present"><?php echo $team['present']; ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value absent"><?php echo $team['absent']; ?></div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $attendance_rate; ?>%</div>
                            <div class="stat-label">Rate</div>
                        </div>
                    </div>

                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $attendance_rate; ?>%"></div>
                    </div>

                    <div class="team-footer">
                        <span><i class="fas fa-clock"></i> <?php echo $team['present']; ?> active now</span>
                        <span class="attendance-rate"><?php echo $attendance_rate; ?>% attendance</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Employee List Modal -->
        <div class="modal" id="employeeModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-users"></i> <span id="modalTeamName"></span> - Team Members</h2>
                    <div class="close-btn" onclick="closeModal()">&times;</div>
                </div>
                <div class="modal-body">
                    <table class="employee-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Employee Name</th>
                                <th>Department</th>
                                <th>Designation</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>In Time</th>
                                <th>Out Time</th>
                            </tr>
                        </thead>
                        <tbody id="employeeList">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        // Team data for modal
        const teamData = <?php echo json_encode($team_attendance); ?>;

        function showTeamEmployees(teamName) {
            const team = teamData[teamName];
            if (!team) return;

            document.getElementById('modalTeamName').textContent = teamName;
            
            let html = '';
            team.employees.forEach(emp => {
                const statusClass = emp.status === 'present' ? 'status-present' : 'status-absent';
                const inTime = emp.in_time ? new Date(emp.in_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '--:--';
                const outTime = emp.out_time ? new Date(emp.out_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '--:--';
                
                html += `
                    <tr>
                        <td>${emp.id}</td>
                        <td><strong>${emp.name}</strong></td>
                        <td>${emp.department}</td>
                        <td>${emp.designation}</td>
                        <td>${emp.branch}</td>
                        <td><span class="status-badge ${statusClass}">${emp.status.toUpperCase()}</span></td>
                        <td class="time-display">${inTime}</td>
                        <td class="time-display">${outTime}</td>
                    </tr>
                `;
            });

            document.getElementById('employeeList').innerHTML = html;
            document.getElementById('employeeModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('employeeModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('employeeModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>