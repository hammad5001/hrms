<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}
require_once 'config.php';

$user_id = $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();
$employee_code = $user['employee_code'] ?? null;

// Function to calculate working hours (matches attendance-api.php)
function calculateWorkingHours($check_in, $check_out) {
    if (!$check_in || !$check_out) return 0;
    
    $in = strtotime($check_in);
    $out = strtotime($check_out);
    
    // If out time is less than in time, it means next day
    if ($out < $in) {
        $out = strtotime(date('Y-m-d', strtotime($check_out . ' +1 day')) . ' ' . date('H:i:s', strtotime($check_out)));
    }
    
    $hours = ($out - $in) / 3600;
    return round($hours, 2);
}

// Function to check if employee is late (matches attendance-api.php isLate function)
function isLate($punch_time, $shift_date) {
    $shift_start = strtotime($shift_date . ' 18:00:00'); // 6:00 PM
    $punch = strtotime($punch_time);
    $minutes_late = ($punch - $shift_start) / 60;
    
    if ($minutes_late <= 0) {
        return [false, 0];
    }
    
    if ($minutes_late > 10) { // 10 minutes grace period
        return [true, round($minutes_late)];
    }
    
    return [false, 0];
}

// Get attendance for the user with correct shift grouping (matches Python process_shifts)
$attendance = [];
if ($employee_code) {
    $emp_code = $conn->real_escape_string($employee_code);
    
    // Get all attendance records for last 30 days
    $result = $conn->query("
        SELECT timestamp 
        FROM attendance_raw 
        WHERE user_id = '$emp_code' 
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY timestamp ASC
    ");
    
    // Group punches by shift date (matches Python logic)
    $shifts = [];
    
    while ($row = $result->fetch_assoc()) {
        $timestamp = $row['timestamp'];
        $hour = date('H', strtotime($timestamp));
        $date = date('Y-m-d', strtotime($timestamp));
        
        // Determine shift date (matches Python process_shifts)
        if ($hour >= 14) { // After 2PM - belongs to current day's shift
            $shift_date = $date;
        } elseif ($hour < 12) { // Before noon - belongs to previous day's shift
            $shift_date = date('Y-m-d', strtotime($date . ' -1 day'));
        } else { // 12PM-2PM - belongs to current day's shift (early check-in)
            $shift_date = $date;
        }
        
        if (!isset($shifts[$shift_date])) {
            $shifts[$shift_date] = [];
        }
        $shifts[$shift_date][] = $timestamp;
    }
    
    // Process each shift
    foreach ($shifts as $shift_date => $punches) {
        sort($punches);
        
        // First punch is check-in (should be evening/night)
        $check_in = $punches[0];
        
        // Last punch is check-out (if more than one punch)
        $check_out = count($punches) > 1 ? end($punches) : null;
        
        // Format times
        $in_time_display = date('h:i A', strtotime($check_in));
        $out_time_display = $check_out ? date('h:i A', strtotime($check_out)) : '---';
        
        // Determine status using isLate function
        list($is_late_status, $late_minutes) = isLate($check_in, $shift_date);
        $status = $is_late_status ? 'late' : 'present';
        
        // Calculate working hours
        $working_hours = 0;
        $hours_display = '0 hrs';
        if ($check_out) {
            $working_hours = calculateWorkingHours($check_in, $check_out);
            $hours_display = number_format($working_hours, 2) . ' hrs';
        }
        
        $attendance[] = [
            'date' => $shift_date,
            'in_time' => $in_time_display,
            'out_time' => $out_time_display,
            'hours' => $hours_display,
            'status' => $status,
            'check_in_raw' => $check_in,
            'check_out_raw' => $check_out,
            'late_minutes' => $late_minutes
        ];
    }
    
    // Sort by date descending (newest first)
    usort($attendance, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile · Balitech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body {
            background: radial-gradient(circle at 20% 30%, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            padding: 30px;
            position: relative;
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
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: rgba(10,12,21,0.6);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 20px 32px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .logo h1 {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .profile-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .profile-header {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
        }
        .info h2 { color: white; font-size: 24px; margin-bottom: 8px; }
        .info p { color: rgba(255,255,255,0.6); display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .info-item {
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 16px;
        }
        .info-item label { color: rgba(255,255,255,0.5); font-size: 12px; display: block; margin-bottom: 4px; }
        .info-item .value { color: white; font-weight: 600; font-size: 16px; }
        .section-title {
            color: white;
            font-size: 20px;
            margin: 24px 0 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        .attendance-table th {
            text-align: left;
            padding: 12px;
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.7);
        }
        .attendance-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.9);
        }
        .attendance-table tr:hover td {
            background: rgba(255,255,255,0.03);
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-present { background: rgba(16,185,129,0.2); color: #10b981; }
        .status-late { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .status-absent { background: rgba(239,68,68,0.2); color: #ef4444; }
        .footer {
            text-align: center;
            margin-top: 28px;
            padding: 20px;
            color: rgba(255,255,255,0.5);
            font-size: 12px;
        }
        .late-badge {
            background: rgba(245,158,11,0.2);
            color: #f59e0b;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 8px;
        }
        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
            .profile-header { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>
    <div class="container">
        <div class="header">
            <div class="logo"><h1>BALI<span>TECH</span></h1></div>
            <div>
                <a href="<?php echo $_SESSION['portal_role'] === 'admin' ? 'admin-dashboard.html' : 'user-dashboard.html'; ?>" class="btn"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <div class="profile-card">
            <div class="profile-header">
                <div class="avatar"><i class="fas fa-user"></i></div>
                <div class="info">
                    <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                    <p><i class="fas fa-tag"></i> Role: <?php echo ucfirst($user['portal_role']); ?></p>
                </div>
            </div>
            <div class="info-grid">
                <div class="info-item"><label>Employee ID</label><div class="value"><?php echo $user['employee_code'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Phone</label><div class="value"><?php echo $user['phone'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Department</label><div class="value"><?php echo $user['department'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Designation</label><div class="value"><?php echo $user['designation'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Branch</label><div class="value"><?php echo $user['branch'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Team</label><div class="value"><?php echo $user['team'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Joined Date</label><div class="value"><?php echo $user['joined_date'] ?: '—'; ?></div></div>
                <div class="info-item"><label>Status</label><div class="value"><span class="status-badge status-<?php echo $user['status'] ?: 'active'; ?>"><?php echo ucfirst($user['status'] ?: 'active'); ?></span></div></div>
            </div>
        </div>

        <div class="profile-card">
            <div class="section-title"><i class="fas fa-clock"></i> Recent Attendance (Last 30 Days)</div>
            <?php if (empty($attendance)): ?>
                <p style="color: rgba(255,255,255,0.6); text-align: center; padding: 40px;">No attendance records found for this employee.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Hours</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance as $a): ?>
                                <tr>
                                    <td><?php echo $a['date']; ?></td>
                                    <td>
                                        <?php echo $a['in_time']; ?>
                                        <?php if ($a['late_minutes'] > 0): ?>
                                            <span class="late-badge"><?php echo $a['late_minutes']; ?> min late</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $a['out_time']; ?></td>
                                    <td><?php echo $a['hours']; ?></td>
                                    <td><span class="status-badge status-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p><i class="fas fa-shield-alt"></i> Secure Profile · Real-time Attendance Sync</p>
        </div>
    </div>
</body>
</html>