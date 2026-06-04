<?php
// team_attendance.php - Team-wise attendance dashboard
// Reads from CSV, no database storage

require_once 'config.php';
require_once 'employee_data.php';

// Initialize employee data
$employeeData = new EmployeeData();

// =====================================================
// Shift configuration
// =====================================================
define('SHIFT_START', '19:00:00'); // 7:00 PM
define('SHIFT_END', '05:00:00');   // 5:00 AM next day
define('GRACE_MINUTES', 15);

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
// Check if employee is late
// =====================================================
function isLate($punch_time, $shift_date) {
    $shift_start = strtotime($shift_date . ' ' . SHIFT_START);
    $punch = strtotime($punch_time);
    $minutes_late = ($punch - $shift_start) / 60;
    
    if ($minutes_late <= GRACE_MINUTES) return [false, 0];
    if ($minutes_late > GRACE_MINUTES) return [true, round($minutes_late)];
    return [false, 0];
}

// =====================================================
// Get attendance for employees
// =====================================================
function getEmployeeAttendance($conn, $employeeIds, $date) {
    if (empty($employeeIds)) return [];
    
    $windows = getShiftWindows($date);
    $attendance = [];
    
    foreach ($employeeIds as $emp_code => $emp_name) {
        // Get check-in
        $checkin_result = $conn->query("
            SELECT timestamp FROM attendance_raw 
            WHERE user_id = '$emp_code' 
            AND timestamp BETWEEN '{$windows['checkin_start']}' AND '{$windows['checkin_end']}'
            ORDER BY timestamp LIMIT 1
        ");
        
        // Get check-out
        $checkout_result = $conn->query("
            SELECT timestamp FROM attendance_raw 
            WHERE user_id = '$emp_code' 
            AND timestamp BETWEEN '{$windows['checkout_start']}' AND '{$windows['checkout_end']}'
            ORDER BY timestamp DESC LIMIT 1
        ");
        
        $check_in = null;
        $check_out = null;
        $status = 'absent';
        $late_minutes = 0;
        
        if ($checkin_result && $checkin_result->num_rows > 0) {
            $row = $checkin_result->fetch_assoc();
            $check_in = $row['timestamp'];
            
            list($is_late, $late_minutes) = isLate($check_in, $date);
            $status = $is_late ? 'late' : 'present';
        }
        
        if ($checkout_result && $checkout_result->num_rows > 0) {
            $row = $checkout_result->fetch_assoc();
            $check_out = $row['timestamp'];
        }
        
        $attendance[$emp_code] = [
            'id' => $emp_code,
            'name' => $emp_name,
            'check_in' => $check_in ? date('h:i A', strtotime($check_in)) : '--:--',
            'check_out' => $check_out ? date('h:i A', strtotime($check_out)) : '--:--',
            'status' => $status,
            'late_minutes' => $late_minutes
        ];
    }
    
    return $attendance;
}

// =====================================================
// Calculate team stats
// =====================================================
function calculateTeamStats($attendance) {
    $stats = [
        'present' => 0,
        'late' => 0,
        'absent' => 0,
        'total' => count($attendance)
    ];
    
    foreach ($attendance as $emp) {
        if ($emp['status'] == 'present') $stats['present']++;
        elseif ($emp['status'] == 'late') $stats['late']++;
        else $stats['absent']++;
    }
    
    $stats['attendance_rate'] = $stats['total'] > 0 
        ? round((($stats['present'] + $stats['late']) / $stats['total']) * 100, 1)
        : 0;
    
    return $stats;
}

// =====================================================
// Handle search
// =====================================================
$search_type = isset($_GET['type']) ? $_GET['type'] : 'team';
$search_term = isset($_GET['term']) ? trim($_GET['term']) : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$teams = $employeeData->getTeams();
$selected_team = null;
$team_members = [];
$team_attendance = [];
$team_stats = null;

if (!empty($search_term)) {
    if ($search_type == 'team') {
        $team_members = $employeeData->getTeamMembers($search_term);
        $selected_team = [
            'name' => $search_term,
            'team_lead' => $employeeData->getTeamLead($search_term)
        ];
    } elseif ($search_type == 'team_lead') {
        // Search by team lead
        $all_teams = $employeeData->getTeams();
        foreach ($all_teams as $team) {
            if (stripos($team['team_lead'], $search_term) !== false) {
                $team_members = $employeeData->getTeamMembers($team['name']);
                $selected_team = $team;
                $search_term = $team['name'];
                break;
            }
        }
    } elseif ($search_type == 'department') {
        // Get all employees in department
        $dept_employees = $employeeData->getEmployeesByDepartment($search_term);
        $team_members = [];
        foreach ($dept_employees as $emp) {
            $team_members[$emp['id']] = $emp['name'];
        }
        $selected_team = ['name' => $search_term . ' Department', 'team_lead' => 'Various'];
    }
    
    // Get attendance for these employees
    if (!empty($team_members)) {
        $team_attendance = getEmployeeAttendance($conn, $team_members, $selected_date);
        $team_stats = calculateTeamStats($team_attendance);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Team-wise Attendance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
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
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .header h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            margin-bottom: 10px;
        }

        .header h1 i {
            color: #667eea;
        }

        /* Search Section */
        .search-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .search-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 15px;
        }

        .search-tab {
            padding: 10px 25px;
            border: none;
            background: #f0f0f0;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s ease;
        }

        .search-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .search-tab i {
            margin-right: 8px;
        }

        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-form input[type="text"] {
            flex: 1;
            min-width: 300px;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 40px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .search-form input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-form input[type="date"] {
            padding: 13px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 40px;
            font-size: 16px;
        }

        .search-form button {
            padding: 15px 35px;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        /* Team List */
        .team-list {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }

        .team-list h3 {
            margin-bottom: 15px;
            color: #555;
        }

        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .team-card {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .team-card:hover {
            transform: translateY(-2px);
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .team-card .team-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .team-card .team-lead {
            font-size: 13px;
            color: #666;
        }

        .team-card .member-count {
            font-size: 12px;
            color: #667eea;
            margin-top: 8px;
        }

        /* Team Header */
        .team-header {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .team-header h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .team-header .team-lead {
            color: #666;
            font-size: 16px;
            margin-bottom: 20px;
        }

        .team-header .team-lead i {
            color: #667eea;
            margin-right: 8px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-card.present { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-card.late { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-card.absent { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .stat-card.rate { background: linear-gradient(135deg, #6366f1, #4f46e5); }

        .stat-card .number {
            font-size: 36px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .stat-card .label {
            color: rgba(255,255,255,0.9);
            font-size: 14px;
            font-weight: 500;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 20px;
            padding: 25px;
            overflow-x: auto;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            color: #444;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-present {
            background: #d1fae5;
            color: #059669;
        }

        .status-late {
            background: #fef3c7;
            color: #d97706;
        }

        .status-absent {
            background: #fee2e2;
            color: #dc2626;
        }

        .info-box {
            background: #e0f2fe;
            color: #0369a1;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .loading {
            text-align: center;
            padding: 40px;
        }

        .loading i {
            font-size: 40px;
            color: #667eea;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-users"></i>
                Team-wise Attendance Dashboard
            </h1>
            <p style="color: #666;">Search by Team, Team Lead, or Department - All data from CSV, no database storage</p>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <div class="search-tabs">
                <button class="search-tab <?php echo $search_type == 'team' ? 'active' : ''; ?>" onclick="setSearchType('team')">
                    <i class="fas fa-users"></i> By Team
                </button>
                <button class="search-tab <?php echo $search_type == 'team_lead' ? 'active' : ''; ?>" onclick="setSearchType('team_lead')">
                    <i class="fas fa-user-tie"></i> By Team Lead
                </button>
                <button class="search-tab <?php echo $search_type == 'department' ? 'active' : ''; ?>" onclick="setSearchType('department')">
                    <i class="fas fa-building"></i> By Department
                </button>
            </div>

            <form method="GET" class="search-form">
                <input type="hidden" name="type" id="searchType" value="<?php echo $search_type; ?>">
                <input type="text" name="term" placeholder="Search..." value="<?php echo htmlspecialchars($search_term); ?>">
                <input type="date" name="date" value="<?php echo $selected_date; ?>">
                <button type="submit">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>

            <!-- Quick Team List -->
            <div class="team-list">
                <h3><i class="fas fa-list"></i> Quick Select Team</h3>
                <div class="team-grid">
                    <?php foreach ($teams as $team): ?>
                        <div class="team-card" onclick="selectTeam('<?php echo htmlspecialchars($team['name']); ?>')">
                            <div class="team-name"><?php echo htmlspecialchars($team['name']); ?></div>
                            <div class="team-lead">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($team['team_lead']); ?>
                            </div>
                            <div class="member-count">
                                <i class="fas fa-users"></i> <?php echo $team['member_count']; ?> members
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($search_term) && !empty($team_members)): ?>
            <!-- Team Header -->
            <div class="team-header">
                <h2>
                    <i class="fas fa-users"></i>
                    <?php echo htmlspecialchars($selected_team['name'] ?? $search_term); ?>
                </h2>
                <div class="team-lead">
                    <i class="fas fa-user-tie"></i>
                    Team Lead: <?php echo htmlspecialchars($selected_team['team_lead'] ?? 'N/A'); ?>
                </div>
                <div style="color: #666;">
                    <i class="fas fa-calendar"></i> Date: <?php echo date('F j, Y', strtotime($selected_date)); ?>
                    | <i class="fas fa-users"></i> Total Members: <?php echo count($team_members); ?>
                </div>
            </div>

            <!-- Stats -->
            <?php if ($team_stats): ?>
            <div class="stats-grid">
                <div class="stat-card present">
                    <div class="number"><?php echo $team_stats['present']; ?></div>
                    <div class="label">PRESENT</div>
                </div>
                <div class="stat-card late">
                    <div class="number"><?php echo $team_stats['late']; ?></div>
                    <div class="label">LATE</div>
                </div>
                <div class="stat-card absent">
                    <div class="number"><?php echo $team_stats['absent']; ?></div>
                    <div class="label">ABSENT</div>
                </div>
                <div class="stat-card rate">
                    <div class="number"><?php echo $team_stats['attendance_rate']; ?>%</div>
                    <div class="label">ATTENDANCE RATE</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attendance Table -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>BID</th>
                            <th>Name</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($team_attendance)): ?>
                            <?php foreach ($team_attendance as $emp): ?>
                                <tr>
                                    <td><strong><?php echo $emp['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                    <td><?php echo $emp['check_in']; ?></td>
                                    <td><?php echo $emp['check_out']; ?></td>
                                    <td>
                                        <?php if ($emp['status'] == 'present'): ?>
                                            <span class="status-badge status-present">
                                                <i class="fas fa-check-circle"></i> Present
                                            </span>
                                        <?php elseif ($emp['status'] == 'late'): ?>
                                            <span class="status-badge status-late">
                                                <i class="fas fa-clock"></i> Late (<?php echo $emp['late_minutes']; ?> min)
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-absent">
                                                <i class="fas fa-times-circle"></i> Absent
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users-slash" style="font-size: 48px; color: #ccc;"></i>
                                    <p style="color: #999; margin-top: 15px;">No employees found in this team</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!empty($search_term) && empty($team_members)): ?>
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                No team found matching "<?php echo htmlspecialchars($search_term); ?>"
            </div>
        <?php endif; ?>
    </div>

    <script>
        function setSearchType(type) {
            document.getElementById('searchType').value = type;
            
            // Update active tab
            document.querySelectorAll('.search-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
        }
        
        function selectTeam(teamName) {
            document.getElementById('searchType').value = 'team';
            document.querySelector('input[name="term"]').value = teamName;
            document.querySelector('.search-form').submit();
        }
        
        // Update active tab on load
        document.querySelectorAll('.search-tab').forEach(tab => {
            if (tab.textContent.includes('<?php echo $search_type == 'team' ? 'Team' : ($search_type == 'team_lead' ? 'Lead' : 'Department'); ?>')) {
                tab.classList.add('active');
            }
        });
    </script>
</body>
</html>