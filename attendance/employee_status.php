<?php
require_once 'config.php';

// =====================================================
// AUTO-INACTIVE: Mark employees inactive if no punch in last 50 days
// =====================================================
function autoMarkInactiveEmployees($conn) {
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-50 days'));
    $today = date('Y-m-d');
    
    // Find employees with no attendance in last 50 days
    // But only consider shift dates (accounting for night shift)
    $query = "
        SELECT DISTINCT e.id, e.employee_code, e.full_name, 
               MAX(ar.timestamp) as last_punch
        FROM employees e
        LEFT JOIN attendance_raw ar ON e.employee_code = ar.user_id
        WHERE e.is_active = 1 
        AND e.status != 'inactive'
        AND e.status != 'vacation'
        GROUP BY e.id
        HAVING last_punch IS NULL OR last_punch < '$cutoff_date'
    ";
    
    $inactive_employees = $conn->query($query);
    $count = 0;
    
    if ($inactive_employees && $inactive_employees->num_rows > 0) {
        while ($emp = $inactive_employees->fetch_assoc()) {
            $last_punch = $emp['last_punch'] ? date('Y-m-d', strtotime($emp['last_punch'])) : 'Never';
            $days_absent = $emp['last_punch'] ? floor((time() - strtotime($emp['last_punch'])) / (60 * 60 * 24)) : 50;
            
            $remarks = "Auto-inactivated: No attendance for {$days_absent} days. Last punch: {$last_punch}";
            
            // Update employee status
            $update = $conn->prepare("
                UPDATE employees SET 
                    status = 'inactive',
                    is_active = 0,
                    status_remarks = ?,
                    status_updated_at = NOW()
                WHERE id = ?
            ");
            $update->bind_param("si", $remarks, $emp['id']);
            $update->execute();
            
            // Log the status change
            $log = $conn->prepare("
                INSERT INTO employee_status_log (employee_id, status, remarks, created_at)
                VALUES (?, 'inactive', ?, NOW())
            ");
            $log->bind_param("is", $emp['id'], $remarks);
            $log->execute();
            
            $count++;
        }
    }
    
    return $count;
}

// =====================================================
// Get date range for reports (default: current month)
// =====================================================
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Run auto-inactive check (can be disabled with ?auto=0)
$auto_inactive_count = 0;
if (!isset($_GET['auto']) || $_GET['auto'] != '0') {
    $auto_inactive_count = autoMarkInactiveEmployees($conn);
}

// Handle manual status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
        $employee_id = intval($_POST['employee_id']);
        $status = $conn->real_escape_string($_POST['status']);
        $remarks = $conn->real_escape_string($_POST['remarks']);
        
        // Update employee status
        $conn->query("
            UPDATE employees SET 
                status = '$status',
                status_remarks = '$remarks',
                status_updated_at = NOW(),
                is_active = " . ($status == 'active' ? 1 : 0) . "
            WHERE id = $employee_id
        ");
        
        // Log status change
        $conn->query("
            INSERT INTO employee_status_log (employee_id, status, remarks, created_at)
            VALUES ($employee_id, '$status', '$remarks', NOW())
        ");
        
        header('Location: employee_status.php?msg=updated&auto=0');
        exit;
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$department = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';

// Build query
$where = [];
if ($status_filter != 'all') {
    $where[] = "e.status = '$status_filter'";
}
if ($search) {
    $where[] = "(e.employee_code LIKE '%$search%' OR e.full_name LIKE '%$search%')";
}
if ($department) {
    $where[] = "e.department = '$department'";
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get employees with last punch date
$employees = $conn->query("
    SELECT 
        e.id, 
        e.employee_code, 
        e.full_name, 
        e.department, 
        e.status, 
        e.status_remarks as last_remark,
        e.status_updated_at as last_status_change,
        e.is_active,
        MAX(ar.timestamp) as last_punch_date,
        COUNT(ar.id) as total_punches
    FROM employees e
    LEFT JOIN attendance_raw ar ON e.employee_code = ar.user_id
    $where_clause
    GROUP BY e.id
    ORDER BY 
        CASE e.status 
            WHEN 'active' THEN 1
            WHEN 'vacation' THEN 2
            WHEN 'inactive' THEN 3
            ELSE 4
        END,
        e.full_name ASC
");

// Get counts
$active_count = $conn->query("SELECT COUNT(*) as c FROM employees WHERE status = 'active'")->fetch_assoc()['c'] ?: 0;
$vacation_count = $conn->query("SELECT COUNT(*) as c FROM employees WHERE status = 'vacation'")->fetch_assoc()['c'] ?: 0;
$inactive_count = $conn->query("SELECT COUNT(*) as c FROM employees WHERE status = 'inactive'")->fetch_assoc()['c'] ?: 0;
$total_count = $conn->query("SELECT COUNT(*) as c FROM employees")->fetch_assoc()['c'] ?: 0;

// Get departments for filter
$departments = $conn->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balitech · Employee Status Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #8b5cf6;
            --info-light: #ede9fe;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
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
            padding: 20px 30px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .logo-text h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .logo-text span {
            color: var(--primary);
        }

        .logo-text p {
            font-size: 12px;
            color: var(--gray-500);
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
            border: none;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
            border: none;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-info h3 {
            font-size: 14px;
            color: var(--gray-500);
            margin-bottom: 5px;
        }

        .stat-info .number {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-icon.active { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-icon.vacation { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-icon.inactive { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .stat-icon.total { background: linear-gradient(135deg, #6366f1, #4f46e5); }
        .stat-icon.auto { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

        /* Alert Banner */
        .alert-banner {
            background: var(--info-light);
            border-left: 4px solid var(--info);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-content i {
            font-size: 24px;
            color: var(--info);
        }

        .alert-content span {
            color: var(--gray-700);
        }

        /* Control Panel */
        .control-panel {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            position: relative;
            min-width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid var(--gray-200);
            border-radius: 40px;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-select {
            padding: 12px 20px;
            border: 2px solid var(--gray-200);
            border-radius: 40px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }

        .status-tabs {
            display: flex;
            gap: 5px;
            background: var(--gray-100);
            padding: 4px;
            border-radius: 40px;
        }

        .status-tab {
            padding: 8px 20px;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            background: transparent;
            color: var(--gray-600);
            text-decoration: none;
            display: inline-block;
        }

        .status-tab.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            border-bottom: 2px solid var(--gray-200);
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-200);
            color: var(--gray-700);
        }

        tr:hover td {
            background: var(--gray-50);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-badge.active {
            background: var(--success-light);
            color: #059669;
        }

        .status-badge.vacation {
            background: var(--warning-light);
            color: #d97706;
        }

        .status-badge.inactive {
            background: var(--danger-light);
            color: #dc2626;
        }

        .last-punch {
            font-size: 12px;
            color: var(--gray-500);
        }

        .last-punch.warning {
            color: var(--warning);
            font-weight: 600;
        }

        .last-punch.danger {
            color: var(--danger);
            font-weight: 600;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }

        .action-btn.edit {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .action-btn.edit:hover {
            background: var(--primary);
            color: white;
        }

        .action-btn.reactivate {
            background: var(--success-light);
            color: #059669;
        }

        .action-btn.reactivate:hover {
            background: var(--success);
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--primary);
            border-radius: 20px 20px 0 0;
        }

        .modal-header h2 {
            color: white;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            color: white;
            background: rgba(255,255,255,0.2);
        }

        .close-btn:hover {
            background: var(--danger);
        }

        .modal-body { padding: 20px; }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        /* Message */
        .message {
            background: var(--success-light);
            color: #059669;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters {
                flex-direction: column;
            }
            
            .search-box, .filter-select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="logo-text">
                    <h1>BALI<span>TECH</span></h1>
                    <p>Employee Status Management</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="attendance-dashboard.html" class="btn">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button onclick="runAutoInactive()" class="btn btn-warning">
                    <i class="fas fa-robot"></i> Run Auto Check
                </button>
            </div>
        </div>

        <!-- Auto-inactive Alert -->
        <?php if ($auto_inactive_count > 0): ?>
            <div class="alert-banner">
                <div class="alert-content">
                    <i class="fas fa-robot"></i>
                    <span><strong><?php echo $auto_inactive_count; ?> employees</strong> were automatically marked as inactive due to 50+ days without attendance.</span>
                </div>
                <a href="employee_status.php?auto=0" class="btn">Dismiss</a>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
            <div class="message">
                <i class="fas fa-check-circle"></i> Employee status updated successfully!
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Active</h3>
                    <div class="number"><?php echo $active_count; ?></div>
                </div>
                <div class="stat-icon active">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Vacation</h3>
                    <div class="number"><?php echo $vacation_count; ?></div>
                </div>
                <div class="stat-icon vacation">
                    <i class="fas fa-umbrella-beach"></i>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Inactive</h3>
                    <div class="number"><?php echo $inactive_count; ?></div>
                </div>
                <div class="stat-icon inactive">
                    <i class="fas fa-user-slash"></i>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total</h3>
                    <div class="number"><?php echo $total_count; ?></div>
                </div>
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Auto-Inactive</h3>
                    <div class="number">50+ Days</div>
                </div>
                <div class="stat-icon auto">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>

        <!-- Control Panel -->
        <div class="control-panel">
            <form method="GET" class="filters" id="filterForm">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search by ID or name..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <select name="department" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php if ($departments): ?>
                        <?php while($dept = $departments->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo $department == $dept['department'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
                
                <div class="status-tabs">
                    <a href="?status=all<?php echo $search ? '&search='.$search : ''; ?><?php echo $department ? '&department='.$department : ''; ?>" class="status-tab <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
                    <a href="?status=active<?php echo $search ? '&search='.$search : ''; ?><?php echo $department ? '&department='.$department : ''; ?>" class="status-tab <?php echo $status_filter == 'active' ? 'active' : ''; ?>">Active</a>
                    <a href="?status=vacation<?php echo $search ? '&search='.$search : ''; ?><?php echo $department ? '&department='.$department : ''; ?>" class="status-tab <?php echo $status_filter == 'vacation' ? 'active' : ''; ?>">Vacation</a>
                    <a href="?status=inactive<?php echo $search ? '&search='.$search : ''; ?><?php echo $department ? '&department='.$department : ''; ?>" class="status-tab <?php echo $status_filter == 'inactive' ? 'active' : ''; ?>">Inactive</a>
                </div>
            </form>
        </div>

        <!-- Employee Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Last Punch</th>
                        <th>Total Punches</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($employees && $employees->num_rows > 0): ?>
                        <?php while($emp = $employees->fetch_assoc()): 
                            $days_absent = $emp['last_punch_date'] ? floor((time() - strtotime($emp['last_punch_date'])) / (60 * 60 * 24)) : null;
                            $punch_class = '';
                            if ($days_absent !== null && $days_absent > 30) {
                                $punch_class = 'danger';
                            } elseif ($days_absent !== null && $days_absent > 14) {
                                $punch_class = 'warning';
                            }
                        ?>
                            <tr>
                                <td><?php echo $emp['employee_code']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($emp['full_name'] ?: 'Unknown'); ?></strong>
                                </td>
                                <td><?php echo !empty($emp['department']) ? htmlspecialchars($emp['department']) : '-'; ?></td>
                                <td>
                                    <?php if ($emp['last_punch_date']): ?>
                                        <span class="last-punch <?php echo $punch_class; ?>">
                                            <?php echo date('Y-m-d', strtotime($emp['last_punch_date'])); ?>
                                            <?php if ($days_absent !== null && $days_absent > 0): ?>
                                                (<?php echo $days_absent; ?> days ago)
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="last-punch danger">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $emp['total_punches'] ?: 0; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $emp['status'] ?: 'active'; ?>">
                                        <i class="fas fa-<?php 
                                            echo $emp['status'] == 'active' ? 'check-circle' : 
                                                ($emp['status'] == 'vacation' ? 'umbrella-beach' : 'user-slash'); 
                                        ?>"></i>
                                        <?php echo ucfirst($emp['status'] ?: 'active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn edit" onclick="editStatus(<?php echo $emp['id']; ?>, '<?php echo $emp['status']; ?>', '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if ($emp['status'] == 'inactive'): ?>
                                        <button class="action-btn reactivate" onclick="reactivateEmployee(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>')">
                                            <i class="fas fa-sync-alt"></i> Reactivate
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                <i class="fas fa-users-slash" style="font-size: 48px; color: var(--gray-300); margin-bottom: 15px;"></i>
                                <p style="color: var(--gray-500);">No employees found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Status Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-edit"></i> Update Status</h2>
                <div class="close-btn" onclick="closeModal('editModal')">&times;</div>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="employee_id" id="employee_id">
                    
                    <div class="form-group">
                        <label>Employee</label>
                        <input type="text" id="employee_name" readonly style="background: var(--gray-50);">
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status" required>
                            <option value="active">🟢 Active</option>
                            <option value="vacation">🌴 On Vacation</option>
                            <option value="inactive">⚫ Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea name="remarks" id="remarks" placeholder="Reason for status change..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editStatus(id, currentStatus, name) {
            document.getElementById('employee_id').value = id;
            document.getElementById('employee_name').value = name;
            document.getElementById('status').value = currentStatus || 'active';
            document.getElementById('remarks').value = '';
            document.getElementById('editModal').classList.add('active');
        }

        function reactivateEmployee(id, name) {
            document.getElementById('employee_id').value = id;
            document.getElementById('employee_name').value = name;
            document.getElementById('status').value = 'active';
            document.getElementById('remarks').value = 'Reactivated by admin - Employee will now appear on dashboard';
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function runAutoInactive() {
            if (confirm('Run auto-inactive check? This will mark employees with no attendance in last 50 days as inactive.')) {
                window.location.href = 'employee_status.php';
            }
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Auto-submit on search timeout
        let searchTimeout;
        document.querySelector('.search-box input')?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });
    </script>
</body>
</html>