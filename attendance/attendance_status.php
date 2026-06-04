<?php
require_once 'config.php';

// Get today's date
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Handle manual fetch request
$fetch_message = '';
if (isset($_GET['fetch'])) {
    $pythonScript = __DIR__ . '/python-script/attendance_collector.py';
    $venvPython = __DIR__ . '/python-script/venv/Scripts/python.exe';
    
    if (file_exists($venvPython)) {
        $command = escapeshellarg($venvPython) . ' ' . escapeshellarg($pythonScript) . ' 2>&1';
        $output = shell_exec($command);
        $fetch_message = "Data fetch completed at " . date('h:i:s A');
    }
}

// Function to determine which shift a punch belongs to
function getShiftDate($timestamp) {
    $hour = date('H', strtotime($timestamp));
    $date = date('Y-m-d', strtotime($timestamp));
    
    // If punch is between 12:00 AM and 11:59 AM, it belongs to previous day's shift
    if ($hour < 12) {
        return date('Y-m-d', strtotime($date . ' -1 day'));
    }
    // If punch is between 12:00 PM and 11:59 PM, it belongs to current day's shift
    else {
        return $date;
    }
}

// Get all employees
$stats = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
$attendance = [];

$employees = $conn->query("
    SELECT id, employee_code, full_name, department 
    FROM employees 
    WHERE is_active = 1 
    ORDER BY CAST(employee_code AS UNSIGNED)
");

while ($emp = $employees->fetch_assoc()) {
    $stats['total']++;
    $emp_code = $conn->real_escape_string($emp['employee_code']);
    
    // Get ALL punches for this employee
    $all_punches = $conn->query("
        SELECT timestamp FROM attendance_raw 
        WHERE user_id = '$emp_code' 
        ORDER BY timestamp
    ");
    
    $punches_by_shift = [];
    if ($all_punches && $all_punches->num_rows > 0) {
        while ($p = $all_punches->fetch_assoc()) {
            $shift_date = getShiftDate($p['timestamp']);
            $punches_by_shift[$shift_date][] = $p['timestamp'];
        }
    }
    
    // Sort shifts by date
    ksort($punches_by_shift);
    
    // Get today's shift (for display)
    $today_shifts = [];
    foreach ($punches_by_shift as $shift_date => $times) {
        if ($shift_date == $today) {
            $today_shifts = $times;
            break;
        }
    }
    
    // Process today's shift
    $first_in = null;
    $last_out = null;
    $status = 'absent';
    $late_minutes = 0;
    $working_hours = 0;
    $punch_count = count($today_shifts);
    
    if ($punch_count > 0) {
        sort($today_shifts);
        $first_in = $today_shifts[0];
        
        // Check if there are multiple punches (check-out exists)
        if ($punch_count > 1) {
            $last_out = $today_shifts[$punch_count - 1];
        }
        
        $stats['present']++;
        
        // Check if late (after 7:15 PM)
        $punch_time = strtotime($first_in);
        $shift_start = strtotime($today . ' 19:15:00');
        
        if ($punch_time > $shift_start) {
            $status = 'late';
            $stats['late']++;
            $late_minutes = round(($punch_time - strtotime($today . ' 19:00:00')) / 60);
        } else {
            $status = 'present';
        }
        
        // Calculate working hours if checked out
        if ($last_out) {
            $in = strtotime($first_in);
            $out = strtotime($last_out);
            
            // If out time is less than in time, it means next day
            if ($out < $in) {
                $out = strtotime(date('Y-m-d', strtotime($last_out . ' +1 day')) . ' ' . date('H:i:s', strtotime($last_out)));
            }
            
            $working_hours = round(($out - $in) / 3600, 2);
        }
    } else {
        $stats['absent']++;
    }
    
    $attendance[] = [
        'id' => $emp['id'],
        'code' => $emp['employee_code'],
        'name' => $emp['full_name'],
        'department' => $emp['department'] ?: 'General',
        'in_time' => $first_in,
        'out_time' => $last_out,
        'punch_count' => $punch_count,
        'status' => $status,
        'late_minutes' => $late_minutes,
        'working_hours' => $working_hours
    ];
}

// Sort attendance by check-in time (present first, then absent)
usort($attendance, function($a, $b) {
    if ($a['status'] == 'absent' && $b['status'] != 'absent') return 1;
    if ($a['status'] != 'absent' && $b['status'] == 'absent') return -1;
    return 0;
});
?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance System - Correct Night Shift Handling</title>
    <meta http-equiv="refresh" content="60">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
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
        
        .header {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .shift-info {
            background: #f97316;
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            display: inline-block;
            margin: 10px 0;
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.95);
            border-radius: 30px;
            padding: 25px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .stat-title {
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 42px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        
        .fetch-button {
            background: #f97316;
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 25px rgba(249,115,22,0.3);
            text-decoration: none;
            margin-bottom: 30px;
        }
        
        .fetch-button:hover {
            background: #ea580c;
            transform: translateY(-3px);
        }
        
        .table-container {
            background: rgba(255,255,255,0.95);
            border-radius: 30px;
            padding: 25px;
            margin-bottom: 30px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        
        th {
            text-align: left;
            padding: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
        }
        
        th:first-child { border-radius: 15px 0 0 15px; }
        th:last-child { border-radius: 0 15px 15px 0; }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .employee-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .employee-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }
        
        .status-badge {
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-present { 
            background: #10b98120; 
            color: #059669; 
            border: 1px solid #10b981; 
        }
        .status-late { 
            background: #f59e0b20; 
            color: #d97706; 
            border: 1px solid #f59e0b; 
        }
        .status-absent { 
            background: #ef444420; 
            color: #dc2626; 
            border: 1px solid #ef4444; 
        }
        
        .time-badge {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 30px;
            display: inline-block;
            margin-left: 5px;
        }
        
        .badge-checkin {
            background: #3b82f620;
            color: #2563eb;
            border: 1px solid #3b82f6;
        }
        
        .badge-checkout {
            background: #10b98120;
            color: #059669;
            border: 1px solid #10b981;
        }
        
        .badge-nextday {
            background: #8b5cf620;
            color: #6d28d9;
            border: 1px solid #8b5cf6;
        }
        
        .action-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .footer {
            background: rgba(255,255,255,0.1);
            border-radius: 30px;
            padding: 20px;
            color: white;
            text-align: center;
        }
        
        .shift-explanation {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .shift-explanation i {
            color: #f97316;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">📊 Night Shift Attendance System</div>
            <div class="shift-info">
                <i class="fas fa-moon"></i> Current Shift: <?php echo date('F j, Y'); ?> 2:00 PM → <?php echo date('F j, Y', strtotime('+1 day')); ?> 11:59 AM
            </div>
            <div style="margin-top: 10px; opacity: 0.9;">
                <i class="fas fa-clock"></i> <?php echo date('l, F j, Y - h:i:s A'); ?>
            </div>
        </div>
        
        <div style="text-align: center;">
            <a href="?fetch=1" class="fetch-button">
                <i class="fas fa-sync-alt"></i> Fetch Data Now
            </a>
        </div>
        
        <?php if ($fetch_message): ?>
            <div style="background: #10b98120; color: #10b981; padding: 15px; border-radius: 15px; margin-bottom: 20px; text-align: center; border: 1px solid #10b981;">
                <i class="fas fa-check-circle"></i> <?php echo $fetch_message; ?>
            </div>
        <?php endif; ?>
        
        <div class="shift-explanation">
            <i class="fas fa-info-circle"></i> <strong>Night Shift Rules:</strong><br>
            • Check-ins from 2:00 PM to 11:59 PM → Show as IN time for <?php echo date('F j'); ?><br>
            • Check-outs from 12:00 AM to 11:59 AM → Show as OUT time for <?php echo date('F j'); ?> (previous day's shift)<br>
            • Check-ins after 12:00 PM → Start new shift for <?php echo date('F j', strtotime('+1 day')); ?>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Total Employees</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label"><i class="fas fa-users"></i> Active</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-title">Present Today</div>
                <div class="stat-value" style="color: #10b981;"><?php echo $stats['present']; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Checked in</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-title">Late Arrivals</div>
                <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['late']; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> After 7:15 PM</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-title">Absent</div>
                <div class="stat-value" style="color: #ef4444;"><?php echo $stats['absent']; ?></div>
                <div class="stat-label"><i class="fas fa-user-slash"></i> No show</div>
            </div>
        </div>
        
        <div class="table-container">
            <h3 style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-list"></i> Shift: <?php echo date('F j, Y'); ?> (2:00 PM → Next Day 11:59 AM)
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Check In Time</th>
                        <th>Check Out Time</th>
                        <th>Working Hours</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendance as $emp): 
                        $initials = '';
                        $nameParts = explode(' ', $emp['name']);
                        foreach ($nameParts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                        $initials = substr($initials, 0, 2);
                        
                        $statusClass = $emp['status'] === 'present' ? 'status-present' : 
                                      ($emp['status'] === 'late' ? 'status-late' : 'status-absent');
                        $statusIcon = $emp['status'] === 'present' ? 'fa-check-circle' : 
                                     ($emp['status'] === 'late' ? 'fa-clock' : 'fa-user-slash');
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($emp['code']); ?></td>
                        <td>
                            <div class="employee-info">
                                <div class="employee-avatar"><?php echo $initials ?: '?'; ?></div>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($emp['name']); ?></div>
                                    <div style="font-size: 12px; color: #64748b;">ID: <?php echo htmlspecialchars($emp['code']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($emp['department']); ?></td>
                        <td>
                            <?php if ($emp['in_time']): 
                                $in_hour = date('H', strtotime($emp['in_time']));
                                $in_time_display = date('h:i A', strtotime($emp['in_time']));
                            ?>
                                <strong><?php echo $in_time_display; ?></strong>
                                <?php if ($emp['late_minutes'] > 0): ?>
                                    <span class="time-badge badge-checkin"><?php echo $emp['late_minutes']; ?> min late</span>
                                <?php endif; ?>
                                <span class="time-badge badge-checkin"><i class="fas fa-sign-in-alt"></i> checked in</span>
                            <?php else: ?>
                                <span class="time-badge">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($emp['out_time']): 
                                $out_hour = date('H', strtotime($emp['out_time']));
                                $out_time_display = date('h:i A', strtotime($emp['out_time']));
                            ?>
                                <strong><?php echo $out_time_display; ?></strong>
                                <?php if ($out_hour < 12): ?>
                                    <span class="time-badge badge-nextday"><i class="fas fa-moon"></i> next day</span>
                                <?php endif; ?>
                                <span class="time-badge badge-checkout"><i class="fas fa-check-circle"></i> checked out</span>
                            <?php elseif ($emp['punch_count'] > 0): ?>
                                <span class="time-badge badge-checkin"><i class="fas fa-clock"></i> waiting for check-out</span>
                            <?php else: ?>
                                <span class="time-badge">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($emp['working_hours'] > 0): ?>
                                <strong><?php echo number_format($emp['working_hours'], 2); ?> hrs</strong>
                            <?php else: ?>
                                0 hrs
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <i class="fas <?php echo $statusIcon; ?>"></i>
                                <?php echo ucfirst($emp['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="attendance-dashboard.html?employee=<?php echo $emp['id']; ?>" class="action-btn">
                                <i class="fas fa-eye"></i> Details
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="footer">
            <div><i class="fas fa-clock"></i> Auto-refreshes every 60 seconds</div>
            <div style="margin-top: 10px; font-size: 12px; opacity: 0.8;">
                <i class="fas fa-moon"></i> Night shift: Check-ins after 2:00 PM belong to today, check-outs before 12:00 PM belong to yesterday
            </div>
        </div>
    </div>
</body>
</html>