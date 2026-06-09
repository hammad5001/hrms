<?php
session_start();
if (!isset($_SESSION['portal_role']) || !in_array($_SESSION['portal_role'], ['admin', 'super_admin'])) {
    header('Location: index.html');
    exit;
}
require_once 'config.php';

// Helper function to send JSON response
function sendJSON($success, $data = null, $message = '') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

$message = '';
$messageType = '';
$current_is_super = (($_SESSION['portal_role'] ?? '') === 'super_admin');
$can_assign_super = can_assign_super_admin_role($_SESSION['portal_role'] ?? '');

// Handle API requests for dashboard
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // GET TOTAL USERS COUNT
    if ($action === 'getTotalUsers') {
        $total_result = $conn->query("SELECT COUNT(*) as total FROM users");
        $total = $total_result->fetch_assoc()['total'] ?? 0;
        sendJSON(true, ['totalUsers' => (int)$total]);
    }
    
    // GET ROLE STATISTICS
    elseif ($action === 'getRoleStats') {
        $stats = [];
        $roles = allowed_portal_roles();
        
        foreach ($roles as $role) {
            $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE portal_role = '$role'");
            $stats[$role] = (int)($result->fetch_assoc()['count'] ?? 0);
        }
        
        sendJSON(true, ['stats' => $stats]);
    }
    
    // GET RECENT USERS
    elseif ($action === 'getRecentUsers') {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
        $result = $conn->query("
            SELECT full_name as name, portal_role as role, DATE(created_at) as date 
            FROM users 
            ORDER BY id DESC 
            LIMIT $limit
        ");
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        sendJSON(true, ['users' => $users]);
    }
    
    // GET USER BY ID
    elseif ($action === 'getUserById') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) {
            sendJSON(false, null, 'User ID required');
        }
        
        $result = $conn->query("SELECT * FROM users WHERE id = $id");
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            sendJSON(true, ['user' => $user]);
        } else {
            sendJSON(false, null, 'User not found');
        }
    }
}

// Handle user creation (POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $employee_code = trim($_POST['employee_code']);
            $full_name = trim($_POST['full_name']);
            $email_prefix = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $portal_role = $_POST['portal_role'] ?? 'user';
            if (!empty($_POST['create_as_super_admin'])) {
                $portal_role = 'super_admin';
            }
            $department = trim($_POST['department']);
            $designation = trim($_POST['designation']);
            $branch = trim($_POST['branch']);
            $company_branch = normalize_company_branch($_POST['company_branch'] ?? 'main');
            $team = trim($_POST['team']);
            $joined_date = $_POST['joined_date'];
            $password = $_POST['password'];
            
            // Enforce balitech.org by extracting prefix and appending
            if (strpos($email_prefix, '@') !== false) {
                $parts = explode('@', $email_prefix);
                $email_prefix = $parts[0];
            }
            $email = $email_prefix . '@balitech.org';
            
            // Validation - Check required fields
            $errors = [];
            if (empty($employee_code)) {
                $errors[] = 'Employee ID is required';
            }
            if (empty($full_name)) {
                $errors[] = 'Full name is required';
            }
            if (empty($email_prefix)) {
                $errors[] = 'Email prefix / username is required';
            }
            if (empty($portal_role)) {
                $errors[] = 'Portal role is required';
            } elseif (!is_valid_portal_role($portal_role)) {
                $errors[] = 'Invalid portal role selected';
            }
            if ($portal_role === 'super_admin' && !$can_assign_super) {
                $errors[] = 'You are not allowed to create a Super Admin account';
            }
            if (!is_valid_company_branch($company_branch)) {
                $errors[] = 'Please select a valid login branch';
            }
            if (empty($password)) {
                $errors[] = 'Password is required';
            }
            
            // Email format validation
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            }
            
            // Phone validation (if provided)
            if (!empty($phone) && !preg_match('/^[0-9]{11}$/', $phone)) {
                $errors[] = 'Phone number must be 11 digits';
            }
            
            if (empty($errors)) {
                // Check if employee_code already exists
                $stmt_emp = $conn->prepare("SELECT id FROM users WHERE employee_code = ?");
                $stmt_emp->bind_param("s", $employee_code);
                $stmt_emp->execute();
                $res_emp = $stmt_emp->get_result();
                
                if ($res_emp && $res_emp->num_rows > 0) {
                    $message = 'Employee ID already exists';
                    $messageType = 'error';
                } 
                else {
                    // Check if email already exists
                    $stmt_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt_email->bind_param("s", $email);
                    $stmt_email->execute();
                    $res_email = $stmt_email->get_result();
                    
                    if ($res_email && $res_email->num_rows > 0) {
                        $message = 'Email already exists';
                        $messageType = 'error';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (employee_code, full_name, email, phone, portal_role, department, designation, branch, company_branch, team, joined_date, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssssssssss", $employee_code, $full_name, $email, $phone, $portal_role, $department, $designation, $branch, $company_branch, $team, $joined_date, $hash);
                        
                        if ($stmt->execute()) {
                            $message = 'User created successfully';
                            $messageType = 'success';
                        } else {
                            $message = 'Error: ' . $conn->error;
                            $messageType = 'error';
                        }
                        $stmt->close();
                    }
                    $stmt_email->close();
                }
                $stmt_emp->close();
            } else {
                $message = implode(', ', $errors);
                $messageType = 'error';
            }
        }
        elseif ($_POST['action'] === 'delete') {
            $id = intval($_POST['id']);
            if ($id == $_SESSION['user_id']) {
                $message = 'Cannot delete your own account';
                $messageType = 'error';
            } else {
                // Check if target user is a super_admin — only super_admin can delete super_admin
                $stmt_target = $conn->prepare("SELECT portal_role FROM users WHERE id = ?");
                $stmt_target->bind_param("i", $id);
                $stmt_target->execute();
                $target = $stmt_target->get_result()->fetch_assoc();
                $stmt_target->close();
                
                if ($target && $target['portal_role'] === 'super_admin' && !$current_is_super) {
                    $message = 'Only a Super Admin can delete another Super Admin account';
                    $messageType = 'error';
                } else {
                    $stmt_del = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmt_del->bind_param("i", $id);
                    if ($stmt_del->execute()) {
                        $message = 'User deleted';
                        $messageType = 'success';
                    } else {
                        $message = 'Error: ' . $conn->error;
                        $messageType = 'error';
                    }
                    $stmt_del->close();
                }
            }
        }
        elseif ($_POST['action'] === 'reset_password') {
            $id = intval($_POST['id']);
            $new_password = $_POST['new_password'];
            if (!empty($new_password) && strlen($new_password) >= 4) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_pw = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt_pw->bind_param("si", $hash, $id);
                if ($stmt_pw->execute()) {
                    $message = 'Password updated';
                    $messageType = 'success';
                } else {
                    $message = 'Error: ' . $conn->error;
                    $messageType = 'error';
                }
                $stmt_pw->close();
            } else {
                $message = 'Password must be at least 4 characters';
                $messageType = 'error';
            }
        }
        elseif ($_POST['action'] === 'update_user') {
            $id = intval($_POST['id']);
            $employee_code = trim($_POST['employee_code']);
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $portal_role = $_POST['portal_role'];
            $department = trim($_POST['department']);
            $designation = trim($_POST['designation']);
            $branch = trim($_POST['branch']);
            $company_branch = normalize_company_branch($_POST['company_branch'] ?? 'main');
            $team = trim($_POST['team']);
            $joined_date = $_POST['joined_date'];
            $status = $_POST['status'];
            
            $errors = [];
            if (empty($employee_code)) $errors[] = 'Employee ID required';
            if (empty($full_name)) $errors[] = 'Full name required';
            if (empty($email)) $errors[] = 'Email required';
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
            
            if (!empty($_POST['edit_as_super_admin'])) {
                $portal_role = 'super_admin';
            }

            if (!$can_assign_super) {
                $stmt_check = $conn->prepare("SELECT portal_role FROM users WHERE id = ?");
                $stmt_check->bind_param("i", $id);
                $stmt_check->execute();
                $target_check = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();

                if ($target_check && $target_check['portal_role'] === 'super_admin') {
                    $errors[] = 'You cannot edit a Super Admin account';
                }
                if ($portal_role === 'super_admin') {
                    $errors[] = 'You cannot assign the Super Admin role';
                    $portal_role = $target_check['portal_role'] ?? 'user';
                }
            }
            if (!is_valid_company_branch($company_branch)) {
                $errors[] = 'Please select a valid login branch';
            }

            if (empty($errors)) {
                $stmt = $conn->prepare("UPDATE users SET employee_code=?, full_name=?, email=?, phone=?, portal_role=?, department=?, designation=?, branch=?, company_branch=?, team=?, joined_date=?, status=? WHERE id=?");
                $stmt->bind_param("ssssssssssssi", $employee_code, $full_name, $email, $phone, $portal_role, $department, $designation, $branch, $company_branch, $team, $joined_date, $status, $id);
                
                if ($stmt->execute()) {
                    $message = 'User updated successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Error: ' . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            } else {
                $message = implode(', ', $errors);
                $messageType = 'error';
            }
        }
    }
}

// Get filter parameters for user table
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';
$role_filter = isset($_GET['role']) ? $conn->real_escape_string($_GET['role']) : '';

// Build query
$where = [];
if ($search) {
    $where[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%' OR employee_code LIKE '%$search%')";
}
if ($department_filter) {
    $where[] = "department = '$department_filter'";
}
if ($role_filter) {
    $where[] = "portal_role = '$role_filter'";
}
$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Fetch all users with filters
$users = $conn->query("
    SELECT id, full_name, email, phone, portal_role, employee_code, department, designation, branch, company_branch, team, joined_date, status, created_at 
    FROM users 
    $where_clause 
    ORDER BY CAST(employee_code AS UNSIGNED) ASC
");

// Get unique departments and roles for filters
$departments = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$roles = $conn->query("SELECT DISTINCT portal_role FROM users ORDER BY portal_role");

// Get stats
$total_users = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'] ?? 0;
$active_users = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'active'")->fetch_assoc()['c'] ?? 0;
$admin_count = $conn->query("SELECT COUNT(*) as c FROM users WHERE portal_role = 'admin'")->fetch_assoc()['c'] ?? 0;
$super_admin_count = $conn->query("SELECT COUNT(*) as c FROM users WHERE portal_role = 'super_admin'")->fetch_assoc()['c'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balitech · User Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/dropdown-fix.css">
    <link rel="stylesheet" href="css/portal-ui-polish.css?v=1">
    <link rel="stylesheet" href="css/admin-users-pro.css?v=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body {
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
            animation: float linear infinite;
        }
        @keyframes float {
            0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; position: relative; z-index: 1; }
        
        /* Header */
        .header {
            background: rgba(10,12,21,0.6);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 20px 32px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .logo h1 {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .logo span { color: #f97316; }
        .user-info { display: flex; align-items: center; gap: 20px; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(249,115,22,0.4);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(249,115,22,0.5); }
        .btn-danger { background: rgba(239,68,68,0.2); color: #f87171; border-color: rgba(239,68,68,0.3); }
        .btn-danger:hover { background: #ef4444; color: white; }
        .btn:hover { transform: translateY(-2px); }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.05);
            transition: all 0.3s;
        }
        .stat-card:hover { transform: translateY(-5px); border-color: rgba(249,115,22,0.4); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        .stat-icon.total { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .stat-icon.active { background: linear-gradient(135deg, #10b981, #047857); }
        .stat-icon.admin { background: linear-gradient(135deg, #f97316, #ea580c); }
        .stat-value { font-size: 32px; font-weight: 800; color: white; margin-bottom: 4px; }
        .stat-label { color: rgba(255,255,255,0.6); font-size: 13px; }
        
        /* Create User Card */
        .create-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 28px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .create-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .create-header h2 {
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
        }
        .toggle-form-btn {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .create-form {
            display: none;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .create-form.show { display: block; animation: fadeIn 0.3s ease; }
        .bulk-import-zone {
            border: 2px dashed rgba(249,115,22,0.35);
            border-radius: 20px;
            padding: 32px 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            margin-top: 16px;
        }
        .bulk-import-zone:hover, .bulk-import-zone.drag-over {
            border-color: #f97316;
            background: rgba(249,115,22,0.06);
        }
        .bulk-import-zone i { font-size: 36px; color: #f97316; margin-bottom: 12px; }
        .bulk-import-preview { margin-top: 20px; display: none; }
        .bulk-import-preview.show { display: block; }
        .bulk-import-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 12px; }
        .bulk-import-table th, .bulk-import-table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            text-align: left;
        }
        .bulk-import-table th { color: rgba(255,255,255,0.55); font-weight: 600; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
        }
        .form-group label i { color: #f97316; margin-right: 6px; }
        .form-group .required { color: #ef4444; margin-left: 4px; }
        .field-hint {
            display: block;
            margin-top: 6px;
            font-size: 10px;
            line-height: 1.45;
            color: rgba(255, 255, 255, 0.45);
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.05);
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            font-size: 14px;
            color: white;
            transition: all 0.3s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #f97316;
            background: rgba(255,255,255,0.08);
            box-shadow: 0 0 0 3px rgba(249,115,22,0.2);
        }
        .form-group input::placeholder { color: rgba(255,255,255,0.4); }
        .form-group input.error, .form-group select.error { border-color: #ef4444; }
        .email-prefix-wrapper {
            display: flex;
            align-items: center;
            background: rgba(255,255,255,0.05);
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 0 16px;
            width: 100%;
            transition: all 0.3s;
        }
        .email-prefix-wrapper:focus-within {
            border-color: #f97316;
            background: rgba(255,255,255,0.08);
            box-shadow: 0 0 0 3px rgba(249,115,22,0.2);
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
        }
        
        /* Control Panel */
        .control-panel {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 24px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .filter-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.5);
        }
        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 48px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            font-size: 14px;
            color: white;
        }
        .search-box input:focus { outline: none; border-color: #f97316; }
        .filter-select {
            padding: 12px 24px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            font-size: 14px;
            color: white;
            cursor: pointer;
        }
        .filter-select option { background: #1a1c2c; }
        
        /* Table */
        .table-container {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 24px;
            overflow-x: auto;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .table-header h3 {
            color: white;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        th {
            text-align: left;
            padding: 16px;
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.7);
            font-weight: 600;
            font-size: 12px;
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.9);
            font-size: 13px;
        }
        tr:hover td { background: rgba(255,255,255,0.03); }
        
        /* Badges */
        .role-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .role-badge.super_admin { background: linear-gradient(135deg, rgba(250,204,21,0.25), rgba(234,179,8,0.15)); color: #facc15; border: 1px solid rgba(250,204,21,0.4); }
        .super-admin-toggle {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 18px;
            border-radius: 12px;
            border: 1px solid rgba(250,204,21,0.35);
            background: linear-gradient(135deg, rgba(250,204,21,0.12), rgba(234,179,8,0.06));
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .super-admin-toggle:hover { border-color: rgba(250,204,21,0.55); }
        .super-admin-toggle input { position: absolute; opacity: 0; pointer-events: none; }
        .super-admin-toggle-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, #facc15, #ca8a04);
            color: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .super-admin-toggle strong { display: block; color: #facc15; font-size: 14px; margin-bottom: 4px; }
        .super-admin-toggle small { display: block; color: rgba(255,255,255,0.55); font-size: 11px; line-height: 1.45; }
        .super-admin-toggle:has(input:checked) { border-color: rgba(250,204,21,0.7); background: linear-gradient(135deg, rgba(250,204,21,0.2), rgba(234,179,8,0.1)); }
        .role-badge.admin { background: rgba(249,115,22,0.2); color: #f97316; }
        .role-badge.user,
        .role-badge.agent { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .role-badge.receptionist { background: rgba(249,115,22,0.2); color: #f97316; }
        .role-badge.hr { background: rgba(16,185,129,0.2); color: #10b981; }
        .role-badge.recruiter { background: rgba(139,92,246,0.2); color: #8b5cf6; }
        .role-badge.management { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .role-badge.training { background: rgba(236,72,153,0.2); color: #ec4899; }
        .role-badge.analytics { background: rgba(59,130,246,0.2); color: #3b82f6; }
        .role-badge.attendance { background: rgba(168,85,247,0.2); color: #a855f7; }
        .role-badge.data_entry { background: rgba(34,211,238,0.2); color: #22d3ee; }
        .role-badge.dialer { background: rgba(251,191,36,0.2); color: #fbbf24; }
        .role-badge.developer { background: rgba(52,211,153,0.2); color: #34d399; }
        .role-badge.team_lead { background: rgba(96,165,250,0.2); color: #60a5fa; }
        .role-badge.floor_manager { background: rgba(167,139,250,0.2); color: #a78bfa; }
        .status-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-badge.active { background: rgba(16,185,129,0.2); color: #10b981; }
        .status-badge.inactive { background: rgba(239,68,68,0.2); color: #ef4444; }
        .status-badge.vacation { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .status-badge.terminated { background: rgba(239,68,68,0.25); color: #f87171; border: 1px solid rgba(239,68,68,0.4); }
        .status-badge.resigned { background: rgba(156,163,175,0.2); color: #d1d5db; border: 1px solid rgba(156,163,175,0.35); }
        
        /* Action Icons */
        .action-icons { display: flex; gap: 8px; }
        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: rgba(255,255,255,0.7);
        }
        .action-icon:hover { background: #f97316; color: white; }
        
        /* Message */
        .message {
            padding: 14px 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .message.success { background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
        .message.error { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        
        /* Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 11, 18, 0.85);
            backdrop-filter: blur(12px);
            z-index: 1000;
            justify-content: center;
            align-items: flex-start;
            overflow-y: auto;
            padding: 40px 16px;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: linear-gradient(135deg, #1a1c2c, #0f1119);
            border-radius: 28px;
            max-width: 700px;
            width: 90%;
            border: 1px solid rgba(255,255,255,0.1);
            margin: auto 0;
            position: relative;
        }
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 { color: white; display: flex; align-items: center; gap: 12px; }
        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: rgba(255,255,255,0.05);
            color: white;
        }
        .modal-close:hover { background: #ef4444; }
        .modal-body { padding: 24px; }
        
        .footer {
            text-align: center;
            margin-top: 28px;
            padding: 20px;
            color: rgba(255,255,255,0.5);
            font-size: 12px;
        }

        /* View/Edit Modal Tabs */
        .modal-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 0 24px;
        }
        .modal-tab {
            padding: 14px 20px;
            font-size: 13px;
            font-weight: 600;
            color: rgba(255,255,255,0.5);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: -1px;
        }
        .modal-tab:hover { color: rgba(255,255,255,0.85); }
        .modal-tab.active {
            color: #f97316;
            border-bottom-color: #f97316;
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* Quick Action Buttons inside View Modal */
        .view-action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .view-action-bar .vbtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.25s ease;
            flex: 1;
        }
        .vbtn-edit  { background: linear-gradient(135deg,#f97316,#ea580c); color:#fff; }
        .vbtn-edit:hover  { box-shadow: 0 4px 14px rgba(249,115,22,0.45); transform:translateY(-1px); }
        .vbtn-key   { background: rgba(139,92,246,0.15); color:#a78bfa; border:1px solid rgba(139,92,246,0.3); }
        .vbtn-key:hover   { background:rgba(139,92,246,0.25); transform:translateY(-1px); }
        .vbtn-portal{ background: rgba(59,130,246,0.15); color:#60a5fa; border:1px solid rgba(59,130,246,0.3); }
        .vbtn-portal:hover{ background:rgba(59,130,246,0.25); transform:translateY(-1px); }
        .vbtn-del   { background: rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.3); }
        .vbtn-del:hover   { background:#ef4444; color:#fff; transform:translateY(-1px); }

        /* Quick Status Panel */
        .quick-status-panel {
            margin-top: 14px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 14px 16px;
        }
        .quick-status-panel p {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-bottom: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .qs-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .qs-btn {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.7);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .qs-btn:hover { background: rgba(255,255,255,0.08); color: white; transform: translateY(-1px); }
        .qs-btn.qs-current {
            color: #fff;
            border-color: currentColor;
            box-shadow: 0 4px 12px var(--shadow-color);
        }
        .qs-btn.qs-active.qs-current     { background: rgba(16,185,129,0.2); color: #10b981; --shadow-color: rgba(16,185,129,0.2); }
        .qs-btn.qs-inactive.qs-current   { background: rgba(239,68,68,0.2); color: #f87171; --shadow-color: rgba(239,68,68,0.25); }
        .qs-btn.qs-vacation.qs-current   { background: rgba(245,158,11,0.2); color: #f59e0b; --shadow-color: rgba(245,158,11,0.2); }
        .qs-btn.qs-terminated.qs-current { background: rgba(239,68,68,0.25); color: #f87171; --shadow-color: rgba(239,68,68,0.25); }
        .qs-btn.qs-resigned.qs-current   { background: rgba(156,163,175,0.2); color: #d1d5db; --shadow-color: rgba(156,163,175,0.2); }

        /* Profile info rows */
        .pinfo-row { display:flex; align-items:baseline; gap:10px; padding:7px 0; border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px; }
        .pinfo-row:last-of-type { border-bottom:none; }
        .pinfo-label { color:rgba(255,255,255,0.5); min-width:110px; font-size:12px; }
        .pinfo-val   { color:rgba(255,255,255,0.9); font-weight:500; }
        
        @media (max-width: 768px) {
            .stats-grid, .form-grid { grid-template-columns: 1fr; }
            .filter-row { flex-direction: column; }
        }
        
        /* Portal Link Button */
        .portal-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .portal-link-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(249,115,22,0.4);
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>
    <div class="particles" id="particles"></div>

    <div class="container">
        <div class="header">
            <div class="logo">
                <h1>BALI<span>TECH</span> · User Management</h1>
                <p class="page-subtitle">Accounts, portal roles, and sheet sync</p>
            </div>
            <div class="user-info">
                <span style="color: white; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-user-cog"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    <?php if ($current_is_super): ?>
                    <span style="background: linear-gradient(135deg, #facc15, #ca8a04); color: #000; font-size: 10px; font-weight: 800; padding: 3px 10px; border-radius: 20px; letter-spacing: 0.5px;">⚡ SUPER ADMIN</span>
                    <?php else: ?>
                    <span style="background: rgba(249,115,22,0.2); color: #f97316; font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px;">ADMIN</span>
                    <?php endif; ?>
                </span>
                <a href="admin-dashboard.html" class="btn"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid" style="grid-template-columns: repeat(<?php echo $current_is_super ? 5 : 4; ?>, 1fr);">
            <div class="stat-card"><div class="stat-header"><div class="stat-icon total"><i class="fas fa-users"></i></div></div><div class="stat-value"><?php echo $total_users; ?></div><div class="stat-label">Total Users</div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-icon active"><i class="fas fa-user-check"></i></div></div><div class="stat-value"><?php echo $active_users; ?></div><div class="stat-label">Active Users</div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-icon admin"><i class="fas fa-crown"></i></div></div><div class="stat-value"><?php echo $admin_count; ?></div><div class="stat-label">Administrators</div></div>
            <?php if ($current_is_super): ?>
            <div class="stat-card" style="border-color: rgba(250,204,21,0.3);"><div class="stat-header"><div class="stat-icon" style="background: linear-gradient(135deg, #facc15, #ca8a04);"><i class="fas fa-shield-halved"></i></div></div><div class="stat-value" style="color:#facc15;"><?php echo $super_admin_count; ?></div><div class="stat-label" style="color:rgba(250,204,21,0.8);">Super Admins</div></div>
            <?php endif; ?>
            <div class="stat-card"><div class="stat-header"><div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fas fa-building"></i></div></div><div class="stat-value"><?php echo $users ? $users->num_rows : 0; ?></div><div class="stat-label">Showing</div></div>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>"><i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Create User Card -->
        <div class="create-card">
            <div class="create-header">
                <h2><i class="fas fa-user-plus"></i> Create New User</h2>
                <button class="toggle-form-btn" onclick="toggleForm()"><i class="fas fa-plus"></i> Show Form</button>
            </div>
            <div class="create-form" id="createForm">
                <form method="POST" id="createUserForm" onsubmit="return validateCreateForm()">
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> Employee ID <span class="required">*</span></label>
                            <div style="display:flex;gap:8px;align-items:stretch;">
                                <input type="text" name="employee_code" id="emp_code" required placeholder="e.g., 1001" style="flex:1;" onblur="fetchEmployeeFromSheet(false)">
                                <button type="button" class="btn-primary" style="white-space:nowrap;padding:10px 16px;" onclick="fetchEmployeeFromSheet(true)" title="Load from employee sheet">
                                    <i class="fas fa-file-import"></i> Fetch
                                </button>
                            </div>
                            <small id="sheetFetchHint" style="color:rgba(255,255,255,0.45);font-size:10px;display:block;margin-top:6px;">Enter ID and click Fetch to autofill from sheet</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" id="full_name" required placeholder="e.g., John Doe">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email Prefix / Username <span class="required">*</span></label>
                            <div class="email-prefix-wrapper">
                                <input type="text" name="email" id="email" required placeholder="e.g., john" style="flex:1; border:none; background:transparent; padding:12px 0; outline:none; color:white; font-size:14px;">
                                <span style="color: rgba(255,255,255,0.6); font-size: 14px; font-weight: 600; padding-left: 8px; user-select:none;">@balitech.org</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" name="phone" id="phone" placeholder="03001234567">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Department</label>
                            <input type="text" name="department" id="department" placeholder="e.g., IT, HR, ACA">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Designation</label>
                            <input type="text" name="designation" id="designation" placeholder="e.g., Software Engineer">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Login Branch <span class="required">*</span></label>
                            <select name="company_branch" id="company_branch" required>
                                <?php foreach (COMPANY_BRANCHES as $bk => $bm): ?>
                                <option value="<?php echo htmlspecialchars($bk); ?>"><?php echo htmlspecialchars($bm['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="field-hint">User must select this exact branch on the login page. Super Admin is the only role that can sign in from any branch.</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Office Location</label>
                            <input type="text" name="branch" id="branch" placeholder="e.g., Islamabad, Lahore">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Team</label>
                            <input type="text" name="team" id="team" placeholder="e.g., Team Alpha">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Joining Date</label>
                            <input type="date" name="joined_date" id="joined_date">
                        </div>
                        <?php if ($can_assign_super): ?>
                        <div class="form-group super-admin-option" style="grid-column: 1 / -1;">
                            <label class="super-admin-toggle">
                                <input type="checkbox" name="create_as_super_admin" id="create_as_super_admin" value="1" onchange="toggleSuperAdminCreate(this)">
                                <span class="super-admin-toggle-box"><i class="fas fa-shield-halved"></i></span>
                                <span>
                                    <strong>Create as Super Admin</strong>
                                    <small>Full access to every portal — HR, Recruiter, Reception, Management, Attendance, Analytics, Employee portal, and User Management.</small>
                                </span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Portal Role <span class="required">*</span></label>
                            <select name="portal_role" id="portal_role" required>
                                <?php foreach (portal_role_options() as $roleValue => $roleLabel): ?>
                                <option value="<?php echo htmlspecialchars($roleValue); ?>"<?php echo $roleValue === 'user' ? ' selected' : ''; ?>><?php echo htmlspecialchars($roleLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: rgba(255,255,255,0.45); font-size: 10px; display:block; margin-top:6px;">Role controls which portal opens at login. Use Super Admin above for full portal access, or pick a specific role.</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password <span class="required">*</span></label>
                            <input type="password" name="password" id="password" required placeholder="Min 4 characters">
                            <small style="color: rgba(255,255,255,0.5); font-size: 10px;">Minimum 4 characters</small>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="toggleForm()">Cancel</button>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Create User</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($current_is_super): ?>
        <div class="create-card" id="bulkImportCard">
            <div class="create-header">
                <h2><i class="fas fa-file-upload"></i> Bulk Import Users</h2>
                <button type="button" class="toggle-form-btn" style="background:linear-gradient(135deg,#10b981,#059669);" onclick="downloadUserImportTemplate()">
                    <i class="fas fa-download"></i> Download Template
                </button>
            </div>
            <p style="color:rgba(255,255,255,0.55);font-size:13px;margin:0 0 8px;">
                Upload Excel (.xlsx) or CSV to create many accounts at once. Required columns:
                <strong>employee_code</strong>, <strong>full_name</strong>, <strong>email</strong>, <strong>portal_role</strong>, <strong>company_branch</strong>.
                Optional: phone, department, designation, branch, team, joined_date, password.
            </p>
            <div class="form-group" style="max-width:320px;margin-bottom:16px;">
                <label><i class="fas fa-key"></i> Default password (if sheet row has no password)</label>
                <input type="text" id="bulkDefaultPassword" value="Balitech@123" placeholder="Min 4 characters">
            </div>
            <div class="bulk-import-zone" id="bulkDropZone" onclick="document.getElementById('bulkFileInput').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <h3 style="color:white;margin:0 0 8px;font-size:16px;">Click or drag file here</h3>
                <p style="color:rgba(255,255,255,0.45);font-size:12px;margin:0;">.xlsx, .xls, or .csv — up to 500 rows</p>
                <input type="file" id="bulkFileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="handleBulkUserFile(event)">
            </div>
            <div class="bulk-import-preview" id="bulkImportPreview"></div>
        </div>
        <?php endif; ?>

        <!-- Control Panel -->
        <div class="control-panel">
            <div class="filter-row">
                <div class="search-box"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search by name, email, or employee ID..." onkeyup="filterTable()"></div>
                <select id="departmentFilter" class="filter-select" onchange="filterTable()"><option value="">All Departments</option><?php while($dept = $departments->fetch_assoc()): ?><option value="<?php echo htmlspecialchars($dept['department']); ?>"><?php echo htmlspecialchars($dept['department']); ?></option><?php endwhile; ?></select>
                <select id="roleFilter" class="filter-select" onchange="filterTable()"><option value="">All Roles</option><?php while($role = $roles->fetch_assoc()): ?><option value="<?php echo htmlspecialchars($role['portal_role']); ?>"><?php echo htmlspecialchars(portal_role_label($role['portal_role'])); ?></option><?php endwhile; ?></select>
                <button class="btn" onclick="clearFilters()"><i class="fas fa-times"></i> Clear</button>
            </div>
        </div>

        <!-- User Table -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> User Directory</h3>
                <span class="badge" style="background: rgba(249,115,22,0.2); padding: 6px 14px; border-radius: 30px; color: #f97316;"><?php echo $users ? $users->num_rows : 0; ?> users</span>
            </div>
            <div style="overflow-x: auto;">
                <table id="usersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Login Branch</th>
                            <th>Office</th>
                            <th>Team</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if ($users && $users->num_rows > 0): ?>
                            <?php while($row = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $row['id']; ?></td>
                                    <td><strong><?php echo $row['employee_code'] ?: '—'; ?></strong></td>
                                    <td><div><div style="font-weight: 600;"><?php echo htmlspecialchars($row['full_name']); ?></div><div style="font-size: 11px; color: rgba(255,255,255,0.5);"><?php echo $row['phone'] ?: '—'; ?></div></div></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($row['designation'] ?: '—'); ?></td>
                                    <td><span class="role-badge" style="background:rgba(249,115,22,0.15);color:#f97316;"><?php echo htmlspecialchars(company_branch_label($row['company_branch'] ?? 'main')); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['branch'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($row['team'] ?: '—'); ?></td>
                                    <td><span class="role-badge <?php echo htmlspecialchars($row['portal_role']); ?>"><?php echo htmlspecialchars(portal_role_label($row['portal_role'])); ?></span></td>
                                    <td><span class="status-badge <?php echo $row['status'] ?: 'active'; ?>"><?php echo ucfirst($row['status'] ?: 'active'); ?></span></td>
                                    <td>
                                        <div class="action-icons">
                                            <div class="action-icon" onclick="viewUser(<?php echo $row['id']; ?>)" title="View Details"><i class="fas fa-eye"></i></div>
                                            <div class="action-icon" onclick="editUser(<?php echo $row['id']; ?>)" title="Edit User"><i class="fas fa-edit"></i></div>
                                            <div class="action-icon" onclick="resetPassword(<?php echo $row['id']; ?>)" title="Reset Password"><i class="fas fa-key"></i></div>
                                            <div class="action-icon" onclick="openPortal(<?php echo $row['id']; ?>)" title="Open Portal"><i class="fas fa-external-link-alt"></i></div>
                                            <?php if ($row['id'] != $_SESSION['user_id']): ?>
                                                <div class="action-icon" onclick="deleteUser(<?php echo $row['id']; ?>)" title="Delete User" style="color: #f87171;"><i class="fas fa-trash"></i></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="12" style="text-align: center; padding: 60px;">No users found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="footer"><p><i class="fas fa-shield-alt"></i> Enterprise User Management · Real-time Sync</p></div>
    </div>

    <!-- Modals -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-edit"></i> Edit User</h2>
                <div class="modal-close" onclick="closeModal('editModal')">&times;</div>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST" onsubmit="return validateEditForm()">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-form-grid">
                        <div class="form-group"><label>Employee ID *</label><input type="text" name="employee_code" id="edit_employee_code" required></div>
                        <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" id="edit_full_name" required></div>
                        <div class="form-group"><label>Email *</label><input type="email" name="email" id="edit_email" required></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone"></div>
                        <div class="form-group"><label>Department</label><input type="text" name="department" id="edit_department"></div>
                        <div class="form-group"><label>Designation</label><input type="text" name="designation" id="edit_designation"></div>
                        <div class="form-group"><label>Login Branch *</label><select name="company_branch" id="edit_company_branch" required>
                            <?php foreach (COMPANY_BRANCHES as $bk => $bm): ?>
                            <option value="<?php echo htmlspecialchars($bk); ?>"><?php echo htmlspecialchars($bm['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="field-hint">Changing this updates which branch the user must pick at login (except Super Admin).</small></div>
                        <div class="form-group"><label>Office Location</label><input type="text" name="branch" id="edit_branch"></div>
                        <div class="form-group"><label>Team</label><input type="text" name="team" id="edit_team"></div>
                        <div class="form-group"><label>Joining Date</label><input type="date" name="joined_date" id="edit_joined_date"></div>
                        <?php if ($can_assign_super): ?>
                        <div class="form-group super-admin-option" style="grid-column: 1 / -1;">
                            <label class="super-admin-toggle">
                                <input type="checkbox" name="edit_as_super_admin" id="edit_as_super_admin" value="1" onchange="toggleSuperAdminEdit(this, 'edit_portal_role')">
                                <span class="super-admin-toggle-box"><i class="fas fa-shield-halved"></i></span>
                                <span>
                                    <strong>Super Admin account</strong>
                                    <small>Grants full access to every portal in the system.</small>
                                </span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <div class="form-group"><label>Portal Role *</label><select name="portal_role" id="edit_portal_role" required>
                            <?php foreach (portal_role_options() as $roleValue => $roleLabel): ?>
                            <option value="<?php echo htmlspecialchars($roleValue); ?>"><?php echo htmlspecialchars($roleLabel); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="form-group"><label>Status</label><select name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="vacation">Vacation</option>
                            <option value="terminated">Terminated</option>
                            <option value="resigned">Resigned</option>
                        </select></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal" id="resetModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h2><i class="fas fa-key"></i> Reset Password</h2>
                <div class="modal-close" onclick="closeModal('resetModal')">&times;</div>
            </div>
            <div class="modal-body">
                <form method="POST" onsubmit="return validatePasswordForm()">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" id="reset_id">
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" id="new_password" required placeholder="Min 4 characters">
                        <small style="color: rgba(255,255,255,0.5); font-size: 10px;">Minimum 4 characters</small>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="closeModal('resetModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal" id="viewModal">
            <div class="modal-content" style="max-width: 920px; width: 95%;">
                <div class="modal-header">
                    <h2 id="viewModalTitle"><i class="fas fa-id-card"></i> User Details</h2>
                    <div class="modal-close" onclick="closeModal('viewModal')">&times;</div>
                </div>
                <!-- Tabs -->
                <div class="modal-tabs">
                    <div class="modal-tab active" id="tab-profile" onclick="switchViewTab('profile')">
                        <i class="fas fa-user-circle"></i> Profile &amp; Attendance
                    </div>
                    <div class="modal-tab" id="tab-edit" onclick="switchViewTab('edit')">
                        <i class="fas fa-user-edit"></i> Edit User
                    </div>
                </div>
                <!-- Profile Tab -->
                <div class="tab-pane active" id="pane-profile">
                    <div class="modal-body" id="viewContent">
                        <div class="loading-state" style="text-align: center; padding: 40px;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: #f97316;"></i>
                            <p style="margin-top: 16px;">Loading user details...</p>
                        </div>
                    </div>
                </div>
                <!-- Edit Tab -->
                <div class="tab-pane" id="pane-edit">
                    <div class="modal-body">
                        <form id="inlineEditForm" method="POST">
                            <input type="hidden" name="action" value="update_user">
                            <input type="hidden" name="id" id="ief_id">
                            <div class="modal-form-grid">
                                <div class="form-group"><label><i class="fas fa-id-card" style="color:#f97316;"></i> Employee ID *</label><input type="text" name="employee_code" id="ief_employee_code" required></div>
                                <div class="form-group"><label><i class="fas fa-user" style="color:#f97316;"></i> Full Name *</label><input type="text" name="full_name" id="ief_full_name" required></div>
                                <div class="form-group"><label><i class="fas fa-envelope" style="color:#f97316;"></i> Email *</label><input type="email" name="email" id="ief_email" required></div>
                                <div class="form-group"><label><i class="fas fa-phone" style="color:#f97316;"></i> Phone</label><input type="text" name="phone" id="ief_phone"></div>
                                <div class="form-group"><label><i class="fas fa-building" style="color:#f97316;"></i> Department</label><input type="text" name="department" id="ief_department"></div>
                                <div class="form-group"><label><i class="fas fa-user-tie" style="color:#f97316;"></i> Designation</label><input type="text" name="designation" id="ief_designation"></div>
                                <div class="form-group"><label><i class="fas fa-code-branch" style="color:#f97316;"></i> Login Branch *</label>
                                    <select name="company_branch" id="ief_company_branch" required>
                                        <?php foreach (COMPANY_BRANCHES as $bk => $bm): ?>
                                        <option value="<?php echo htmlspecialchars($bk); ?>"><?php echo htmlspecialchars($bm['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="field-hint">Must match the branch selected on index login.</small>
                                </div>
                                <div class="form-group"><label><i class="fas fa-map-marker-alt" style="color:#f97316;"></i> Office Location</label><input type="text" name="branch" id="ief_branch"></div>
                                <div class="form-group"><label><i class="fas fa-users" style="color:#f97316;"></i> Team</label><input type="text" name="team" id="ief_team"></div>
                                <div class="form-group"><label><i class="fas fa-calendar" style="color:#f97316;"></i> Joining Date</label><input type="date" name="joined_date" id="ief_joined_date"></div>
                                <?php if ($can_assign_super): ?>
                                <div class="form-group super-admin-option" style="grid-column: 1 / -1;">
                                    <label class="super-admin-toggle">
                                        <input type="checkbox" name="edit_as_super_admin" id="ief_as_super_admin" value="1" onchange="toggleSuperAdminEdit(this, 'ief_portal_role')">
                                        <span class="super-admin-toggle-box"><i class="fas fa-shield-halved"></i></span>
                                        <span>
                                            <strong>Super Admin account</strong>
                                            <small>Full portal access for this user.</small>
                                        </span>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <div class="form-group"><label><i class="fas fa-tag" style="color:#f97316;"></i> Portal Role *</label>
                                    <select name="portal_role" id="ief_portal_role" required>
                                        <?php foreach (portal_role_options() as $roleValue => $roleLabel): ?>
                                        <option value="<?php echo htmlspecialchars($roleValue); ?>"><?php echo htmlspecialchars($roleLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><label><i class="fas fa-circle" style="color:#f97316;"></i> Account Status</label>
                                    <select name="status" id="ief_status">
                                        <option value="active">✅ Active</option>
                                        <option value="inactive">❌ Inactive</option>
                                        <option value="vacation">🌴 On Vacation</option>
                                        <option value="terminated">🚫 Terminated</option>
                                        <option value="resigned">🚪 Resigned</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-actions" style="margin-top:12px;">
                                <button type="button" class="btn" onclick="switchViewTab('profile')"><i class="fas fa-arrow-left"></i> Back</button>
                                <button type="button" class="btn-primary" onclick="submitInlineEdit()"><i class="fas fa-save"></i> Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header" style="border-bottom: none; padding: 24px 24px 10px;">
                <h2 style="color: #f87171; font-size: 20px;"><i class="fas fa-exclamation-triangle"></i> Delete Account</h2>
                <div class="modal-close" onclick="closeModal('deleteModal')">&times;</div>
            </div>
            <div class="modal-body" style="padding: 10px 24px 24px;">
                <p style="color: rgba(255,255,255,0.8); margin-bottom: 24px; font-size: 14px; line-height: 1.5;">
                    Are you sure you want to permanently delete this user account? This action cannot be undone.
                </p>
                <form id="deleteForm" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="form-actions" style="margin-top: 0; justify-content: flex-end; gap: 12px;">
                        <button type="button" class="btn" onclick="closeModal('deleteModal')">Cancel</button>
                        <button type="submit" class="btn-danger btn" style="padding: 10px 24px; border-radius: 40px; font-weight: 600;"><i class="fas fa-trash"></i> Delete Permanent</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/portal-access.js"></script>
    <script>
        // Role to portal URL mapping
        const portalUrls = Object.assign({}, window.BALITECH_PORTAL_URLS || {}, {
            admin: 'admin-dashboard.html'
        });

        // Function to open portal based on user role
        function openPortal(userId) {
            fetch(`get_user_details.php?id=${userId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.user) {
                        const role = data.user.effective_portal_role || data.user.portal_role;
                        const portalUrl = portalUrls[role] || 'employee-portal.html';

                        localStorage.setItem('userRole', role);
                        localStorage.setItem('userId', data.user.id);
                        localStorage.setItem('userFullName', data.user.full_name);
                        localStorage.setItem('userEmail', data.user.email);
                        localStorage.setItem('userEmployeeCode', data.user.employee_code || '');

                        sessionStorage.setItem('adminPortalAccess', 'true');
                        fetch('api/admin_portal_access.php', {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ portal_url: portalUrl })
                        }).finally(() => {
                            window.open(portalUrl, '_blank');
                        });

                        showNotification(`Opening ${role.toUpperCase()} Portal for ${data.user.full_name}`, 'success');
                    } else {
                        showNotification('Failed to fetch user details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error opening portal', 'error');
                });
        }

        // Function to show notification (replaces alert for better UX)
        function showNotification(message, type = 'success') {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'rgba(16,185,129,0.95)' : 'rgba(239,68,68,0.95)'};
                color: white;
                padding: 12px 24px;
                border-radius: 12px;
                font-size: 14px;
                font-weight: 500;
                z-index: 10000;
                animation: slideIn 0.3s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                backdrop-filter: blur(8px);
            `;
            notification.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Add CSS animations for notification
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);

        function toggleSuperAdminEdit(checkbox, selectId) {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            const group = sel.closest('.form-group');
            if (checkbox.checked) {
                sel.value = 'super_admin';
                sel.disabled = true;
                if (group) group.style.opacity = '0.55';
            } else {
                sel.disabled = false;
                if (group) group.style.opacity = '1';
                if (sel.value === 'super_admin') sel.value = 'user';
            }
        }

        function toggleSuperAdminCreate(checkbox) {
            toggleSuperAdminEdit(checkbox, 'portal_role');
        }

        function syncSuperAdminCheckbox(role, checkboxId, selectId) {
            const cb = document.getElementById(checkboxId);
            if (!cb) return;
            cb.checked = (role === 'super_admin');
            toggleSuperAdminEdit(cb, selectId);
        }

        // Form validation
        function validateCreateForm() {
            const empCode = document.getElementById('emp_code').value.trim();
            const fullName = document.getElementById('full_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const phone = document.getElementById('phone').value.trim();
            
            if (!empCode) { showValidationError('Employee ID is required'); return false; }
            if (!fullName) { showValidationError('Full name is required'); return false; }
            if (!email) { showValidationError('Email prefix/username is required'); return false; }
            if (email.includes('@')) {
                if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) { showValidationError('Invalid email format'); return false; }
            } else {
                if (!email.match(/^[a-zA-Z0-9._%+-]+$/)) { showValidationError('Invalid characters in email prefix'); return false; }
            }
            if (!password) { showValidationError('Password is required'); return false; }
            if (password.length < 4) { showValidationError('Password must be at least 4 characters'); return false; }
            if (phone && !phone.match(/^\d{11}$/)) { showValidationError('Phone number must be 11 digits'); return false; }
            
            return true;
        }
        
        function validateEditForm() {
            const empCode = document.getElementById('edit_employee_code').value.trim();
            const fullName = document.getElementById('edit_full_name').value.trim();
            const email = document.getElementById('edit_email').value.trim();
            
            if (!empCode) { showValidationError('Employee ID is required'); return false; }
            if (!fullName) { showValidationError('Full name is required'); return false; }
            if (!email) { showValidationError('Email is required'); return false; }
            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) { showValidationError('Invalid email format'); return false; }
            
            return true;
        }
        
        function validatePasswordForm() {
            const password = document.getElementById('new_password').value;
            if (!password) { showValidationError('Password is required'); return false; }
            if (password.length < 4) { showValidationError('Password must be at least 4 characters'); return false; }
            return true;
        }
        
        function showValidationError(message) {
            showNotification(message, 'error');
        }
        
        function createParticles() {
            const container = document.getElementById('particles');
            if (!container) return;
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

        async function fetchEmployeeFromSheet(showToastOnSuccess) {
            const code = document.getElementById('emp_code').value.trim();
            const hint = document.getElementById('sheetFetchHint');
            if (!code) {
                if (showToastOnSuccess) showNotification('Enter Employee ID first', 'error');
                return;
            }
            if (hint) hint.textContent = 'Loading from sheet...';
            try {
                const res = await fetch(`api/fetch_employee_sheet.php?employee_code=${encodeURIComponent(code)}`);
                const data = await res.json();
                if (!data.success || !data.data) {
                    if (hint) hint.textContent = data.error || 'Not found in sheet';
                    if (showToastOnSuccess) showNotification(data.error || 'Employee not found in sheet', 'error');
                    return;
                }
                const e = data.data;
                document.getElementById('full_name').value = e.full_name || '';
                document.getElementById('department').value = e.department || '';
                document.getElementById('designation').value = e.designation || '';
                document.getElementById('branch').value = e.branch || '';
                document.getElementById('team').value = e.team || '';
                const emailEl = document.getElementById('email');
                if (emailEl && (!emailEl.value || showToastOnSuccess) && e.suggested_email) {
                    let val = e.suggested_email;
                    if (val.includes('@')) {
                        val = val.split('@')[0];
                    }
                    emailEl.value = val;
                }
                const roleEl = document.getElementById('portal_role');
                const hrmsRoles = ['user', 'team_lead', 'floor_manager', 'data_entry', 'dialer', 'developer'];
                if (roleEl) {
                    const suggested = e.suggested_portal_role || 'user';
                    if (hrmsRoles.includes(suggested)) {
                        roleEl.value = suggested;
                    } else if (suggested === 'receptionist' || suggested === 'agent') {
                        roleEl.value = 'user';
                    } else {
                        roleEl.value = suggested;
                    }
                }
                if (hint) {
                    let msg = `Loaded from ${e.source || 'sheet'}: ${e.full_name}`;
                    if (e.suggested_portal_role === 'receptionist' || e.suggested_portal_role === 'agent') {
                        msg += ' — set Reception only if this person works at the front desk';
                    }
                    hint.textContent = msg;
                }
                if (showToastOnSuccess) showNotification(`Autofilled: ${e.full_name}`, 'success');
            } catch (err) {
                console.error(err);
                if (hint) hint.textContent = 'Failed to load sheet data';
                if (showToastOnSuccess) showNotification('Could not fetch sheet data', 'error');
            }
        }

        function toggleForm() {
            const form = document.getElementById('createForm');
            const btn = document.querySelector('.toggle-form-btn');
            form.classList.toggle('show');
            if (form.classList.contains('show')) btn.innerHTML = '<i class="fas fa-minus"></i> Hide Form';
            else btn.innerHTML = '<i class="fas fa-plus"></i> Show Form';
        }

        function filterTable() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const department = document.getElementById('departmentFilter').value.toLowerCase();
            const role = document.getElementById('roleFilter').value.toLowerCase();
            const rows = document.querySelectorAll('#usersTable tbody tr');
            rows.forEach(row => {
                if (row.cells.length < 11) return;
                const name = row.cells[2]?.innerText.toLowerCase() || '';
                const email = row.cells[3]?.innerText.toLowerCase() || '';
                const empId = row.cells[1]?.innerText.toLowerCase() || '';
                const dept = row.cells[4]?.innerText.toLowerCase() || '';
                const userRole = row.cells[8]?.innerText.toLowerCase() || '';
                let show = true;
                if (search && !name.includes(search) && !email.includes(search) && !empId.includes(search)) show = false;
                if (department && !dept.includes(department)) show = false;
                if (role && !userRole.includes(role)) show = false;
                row.style.display = show ? '' : 'none';
            });
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('departmentFilter').value = '';
            document.getElementById('roleFilter').value = '';
            filterTable();
        }

        function getShiftDate(timestamp) {
            const date = new Date(timestamp);
            const hour = date.getHours();
            const dateStr = date.toISOString().split('T')[0];
            if (hour >= 18) return dateStr;
            if (hour < 4) {
                const prevDate = new Date(date);
                prevDate.setDate(prevDate.getDate() - 1);
                return prevDate.toISOString().split('T')[0];
            }
            return dateStr;
        }

        // Stores current viewed user id for tab switching
        let _viewedUserId = null;

        function switchViewTab(tab) {
            const vm = document.getElementById('viewModal');
            if (!vm) return;
            vm.querySelectorAll('.modal-tab').forEach(t => t.classList.remove('active'));
            vm.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            const tabEl  = document.getElementById('tab-'  + tab);
            const paneEl = document.getElementById('pane-' + tab);
            if (tabEl)  tabEl.classList.add('active');
            if (paneEl) paneEl.classList.add('active');
        }

        function editFromView(userId) {
            switchViewTab('edit');
            loadInlineEditForm(userId);
        }

        function loadInlineEditForm(userId) {
            fetch(`get_user_details.php?id=${userId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.user) {
                        const u = data.user;
                        document.getElementById('ief_id').value = u.id;
                        document.getElementById('ief_employee_code').value = u.employee_code || '';
                        document.getElementById('ief_full_name').value = u.full_name;
                        document.getElementById('ief_email').value = u.email;
                        document.getElementById('ief_phone').value = u.phone || '';
                        document.getElementById('ief_department').value = u.department || '';
                        document.getElementById('ief_designation').value = u.designation || '';
                        document.getElementById('ief_branch').value = u.branch || '';
                        const cb = document.getElementById('ief_company_branch');
                        if (cb) cb.value = u.company_branch || 'main';
                        document.getElementById('ief_team').value = u.team || '';
                        document.getElementById('ief_joined_date').value = u.joined_date || '';
                        document.getElementById('ief_portal_role').value = u.portal_role || 'user';
                        syncSuperAdminCheckbox(u.portal_role, 'ief_as_super_admin', 'ief_portal_role');
                        document.getElementById('ief_status').value = u.status || 'active';
                    }
                });
        }

        function validateInlineEdit() {
            const emp   = document.getElementById('ief_employee_code').value.trim();
            const name  = document.getElementById('ief_full_name').value.trim();
            const email = document.getElementById('ief_email').value.trim();
            if (!emp)   { showNotification('Employee ID is required', 'error'); return false; }
            if (!name)  { showNotification('Full name is required', 'error');   return false; }
            if (!email) { showNotification('Email is required', 'error');       return false; }
            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                showNotification('Invalid email format', 'error'); return false;
            }
            return true;
        }

        async function submitInlineEdit() {
            if (!validateInlineEdit()) return;

            const saveBtn = document.querySelector('#pane-edit .btn-primary');
            const origHTML = saveBtn ? saveBtn.innerHTML : '';
            if (saveBtn) { saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; saveBtn.disabled = true; }

            try {
                const fd = new FormData(document.getElementById('inlineEditForm'));
                const resp = await fetch('admin.php', { method: 'POST', body: fd });

                if (resp.ok) {
                    showNotification('User updated successfully!', 'success');
                    // Refresh the profile tab with new data
                    const uid = document.getElementById('ief_id').value;
                    if (uid) {
                        switchViewTab('profile');
                        await viewUser(parseInt(uid));
                        // Also refresh the table row status badge
                        const statusVal = document.getElementById('ief_status').value;
                        const statusLabels = { active:'Active', inactive:'Inactive', vacation:'Vacation', terminated:'Terminated', resigned:'Resigned' };
                        document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
                            const idCell = tr.cells[0];
                            if (idCell && idCell.textContent.trim() === uid) {
                                const statusCell = tr.cells[10];
                                if (statusCell) {
                                    statusCell.innerHTML = `<span class="status-badge ${statusVal}">${statusLabels[statusVal] || statusVal}</span>`;
                                }
                            }
                        });
                    }
                } else {
                    showNotification('Error saving changes. Please try again.', 'error');
                }
            } catch(e) {
                showNotification('Network error. Please check connection.', 'error');
            } finally {
                if (saveBtn) { saveBtn.innerHTML = origHTML; saveBtn.disabled = false; }
            }
        }

        async function quickStatus(userId, newStatus) {
            const labels = { active:'Active', inactive:'Inactive', vacation:'Vacation', terminated:'Terminated', resigned:'Resigned' };
            try {
                // Get current user data first so we don't wipe other fields
                const res  = await fetch(`get_user_details.php?id=${userId}`);
                const data = await res.json();
                if (!data.success) { showNotification('Failed to fetch user data', 'error'); return; }
                const u = data.user;
                const form = new FormData();
                form.append('action',          'update_user');
                form.append('id',              u.id);
                form.append('employee_code',   u.employee_code  || '');
                form.append('full_name',        u.full_name);
                form.append('email',            u.email);
                form.append('phone',            u.phone          || '');
                form.append('portal_role',      u.portal_role);
                form.append('department',       u.department     || '');
                form.append('designation',      u.designation    || '');
                form.append('branch',           u.branch         || '');
                form.append('company_branch',   u.company_branch || 'main');
                form.append('team',             u.team           || '');
                form.append('joined_date',      u.joined_date    || '');
                form.append('status',           newStatus);

                const resp = await fetch('admin.php', { method:'POST', body: form });
                if (resp.ok) {
                    showNotification(`Status updated to "${labels[newStatus]}"`, 'success');
                    // Refresh profile tab
                    await viewUser(userId);
                } else {
                    showNotification('Update failed', 'error');
                }
            } catch(e) {
                showNotification('Network error', 'error');
            }
        }

        async function viewUser(id) {
            _viewedUserId = id;
            const modal = document.getElementById('viewModal');
            const content = document.getElementById('viewContent');
            // Always start on profile tab
            switchViewTab('profile');
            modal.classList.add('active');
            content.innerHTML = '<div class="loading-state" style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 40px;"></i><p>Loading user details...</p></div>';
            
            try {
                const response = await fetch(`get_user_details.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const user = data.user;
                    const rawAttendance = data.attendance_raw || [];
                    
                    const shifts = {};
                    rawAttendance.forEach(record => {
                        const shiftDate = getShiftDate(record.timestamp);
                        if (!shifts[shiftDate]) shifts[shiftDate] = [];
                        shifts[shiftDate].push(record.timestamp);
                    });
                    
                    const attendance = [];
                    for (const [shiftDate, punches] of Object.entries(shifts)) {
                        punches.sort();
                        const firstPunch = punches[0];
                        const lastPunch = punches.length > 1 ? punches[punches.length - 1] : null;
                        
                        const inTime = new Date(firstPunch).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                        const outTime = lastPunch ? new Date(lastPunch).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '---';
                        
                        let hours = '0 hrs';
                        if (lastPunch) {
                            const inDate = new Date(firstPunch);
                            let outDate = new Date(lastPunch);
                            if (outDate < inDate) outDate.setDate(outDate.getDate() + 1);
                            const diffHours = (outDate - inDate) / 3600000;
                            hours = diffHours.toFixed(2) + ' hrs';
                        }
                        
                        const punchHour = new Date(firstPunch).getHours();
                        let status = 'present';
                        if (punchHour >= 18) {
                            const minutes = new Date(firstPunch).getMinutes();
                            if (punchHour === 18 && minutes > 10) status = 'late';
                            else if (punchHour > 18) status = 'late';
                        }
                        
                        attendance.push({ date: shiftDate, in_time: inTime, out_time: outTime, hours: hours, status: status });
                    }
                    
                    attendance.sort((a, b) => b.date.localeCompare(a.date));
                    
                    let attendanceHtml = '';
                    if (attendance.length > 0) {
                        attendanceHtml = `
                            <h4 style="margin: 0 0 12px; color: white; display: flex; align-items: center; gap: 8px; font-size: 15px;"><i class="fas fa-clock" style="color: #f97316;"></i> Recent Attendance (Last 30 Days)</h4>
                            <div class="modal-attendance-wrapper">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead style="position: sticky; top: 0; z-index: 10; background: #1a1c2c;"><tr style="background: rgba(255,255,255,0.05);"><th style="padding: 12px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.6);">Date</th><th style="padding: 12px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.6);">Check In</th><th style="padding: 12px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.6);">Check Out</th><th style="padding: 12px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.6);">Hours</th><th style="padding: 12px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.6);">Status</th></tr></thead>
                                    <tbody>
                                        ${attendance.map(a => `<tr style="border-bottom: 1px solid rgba(255,255,255,0.05);"><td style="padding: 10px; font-weight: 500;">${a.date}</td><td style="padding: 10px;">${a.in_time}</td><td style="padding: 10px;">${a.out_time}</td><td style="padding: 10px; font-variant-numeric: tabular-nums;">${a.hours}</td><td style="padding: 10px;"><span class="status-badge ${a.status === 'present' ? 'active' : 'vacation'}">${a.status === 'present' ? 'Present' : 'Late'}</span></td></tr>`).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                    } else {
                        attendanceHtml = '<p style="color: rgba(255,255,255,0.6); margin-top: 20px; text-align: center;">No attendance records found for this user.</p>';
                    }
                    
                    const statusColors = { active:'#10b981', inactive:'#ef4444', vacation:'#f59e0b', terminated:'#f87171', resigned:'#9ca3af' };
                    const statusIcons  = { active:'fa-check-circle', inactive:'fa-times-circle', vacation:'fa-umbrella-beach', terminated:'fa-ban', resigned:'fa-door-open' };
                    const currentStatus = user.status || 'active';

                    document.getElementById('viewModalTitle').innerHTML = `<i class="fas fa-id-card"></i> ${user.full_name}`;

                    content.innerHTML = `
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">

                            <!-- LEFT: Profile Card -->
                            <div style="flex: 0 0 270px; min-width:240px;">
                                <div style="background: rgba(255,255,255,0.05); border-radius: 20px; padding: 20px;">

                                    <!-- Avatar + Name -->
                                    <div style="text-align:center; margin-bottom:16px;">
                                        <div style="width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,#f97316,#ea580c);display:inline-flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:white;margin-bottom:10px;">
                                            ${user.full_name.charAt(0).toUpperCase()}
                                        </div>
                                        <div style="font-size:16px;font-weight:700;color:white;">${user.full_name}</div>
                                        <div style="font-size:12px;color:rgba(255,255,255,0.5);">${user.email}</div>
                                        <div style="margin-top:8px;">
                                            <span class="role-badge ${user.portal_role}" style="margin-right:6px;">${user.portal_role}</span>
                                            <span class="status-badge ${currentStatus}">${currentStatus.charAt(0).toUpperCase()+currentStatus.slice(1)}</span>
                                        </div>
                                    </div>

                                    <!-- Info Rows -->
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-id-card"></i> Emp ID</span><span class="pinfo-val">${user.employee_code || '—'}</span></div>
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-phone"></i> Phone</span><span class="pinfo-val">${user.phone || '—'}</span></div>
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-building"></i> Dept</span><span class="pinfo-val">${user.department || '—'}</span></div>
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-user-tie"></i> Role</span><span class="pinfo-val">${user.designation || '—'}</span></div>
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-map-marker-alt"></i> Office</span><span class="pinfo-val">${user.branch || '—'}</span></div>
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-users"></i> Team</span><span class="pinfo-val">${user.team || '—'}</span></div>
                                    <div class="pinfo-row"><span class="pinfo-label"><i class="fas fa-calendar"></i> Joined</span><span class="pinfo-val">${user.joined_date || '—'}</span></div>

                                    <!-- Quick Status Panel -->
                                    <div class="quick-status-panel">
                                        <p><i class="fas fa-sliders-h"></i> Quick Status</p>
                                        <div class="qs-grid">
                                            <button class="qs-btn qs-active ${currentStatus==='active'?'qs-current':''}"     onclick="quickStatus(${user.id},'active')">✅ Active</button>
                                            <button class="qs-btn qs-inactive ${currentStatus==='inactive'?'qs-current':''}" onclick="quickStatus(${user.id},'inactive')">❌ Inactive</button>
                                            <button class="qs-btn qs-vacation ${currentStatus==='vacation'?'qs-current':''}" onclick="quickStatus(${user.id},'vacation')">🌴 Vacation</button>
                                            <button class="qs-btn qs-terminated ${currentStatus==='terminated'?'qs-current':''}" onclick="quickStatus(${user.id},'terminated')">🚫 Terminated</button>
                                            <button class="qs-btn qs-resigned ${currentStatus==='resigned'?'qs-current':''}"   onclick="quickStatus(${user.id},'resigned')">🚪 Resigned</button>
                                        </div>
                                    </div>

                                    <!-- Action Bar -->
                                    <div class="view-action-bar" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08);">
                                        <button class="vbtn vbtn-edit" onclick="editFromView(${user.id})"><i class="fas fa-user-edit"></i> Edit Info</button>
                                        <button class="vbtn vbtn-portal" onclick="openPortal(${user.id}); closeModal('viewModal');"><i class="fas fa-external-link-alt"></i> Portal</button>
                                        <button class="vbtn vbtn-key" onclick="closeModal('viewModal'); resetPassword(${user.id});"><i class="fas fa-key"></i> Reset PW</button>
                                        ${user.id != <?php echo $_SESSION['user_id']; ?> ? `<button class="vbtn vbtn-del" onclick="closeModal('viewModal'); deleteUser(${user.id});"><i class="fas fa-trash"></i> Delete</button>` : ''}
                                    </div>
                                </div>
                            </div>

                            <!-- RIGHT: Attendance -->
                            <div style="flex: 1; min-width:280px;">
                                ${attendanceHtml}
                                ${user.employee_code ? `<div style="margin-top: 20px; text-align: center;">
                                    <a href="attendance/attendance-dashboard.html?employee=${user.employee_code}" class="btn-primary" style="padding: 10px 24px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-chart-line"></i> View Full Attendance Report
                                    </a>
                                </div>` : ''}
                            </div>

                        </div>
                    `;
                } else {
                    content.innerHTML = `<div style="text-align: center; padding: 40px; color: #f87171;"><i class="fas fa-exclamation-circle"></i> ${data.message}</div>`;
                }
            } catch (error) {
                content.innerHTML = `<div style="text-align: center; padding: 40px; color: #f87171;"><i class="fas fa-exclamation-circle"></i> Error loading user details</div>`;
            }
        }

        function editUser(id) {
            fetch(`get_user_details.php?id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.user) {
                        const u = data.user;
                        document.getElementById('edit_id').value = u.id;
                        document.getElementById('edit_employee_code').value = u.employee_code || '';
                        document.getElementById('edit_full_name').value = u.full_name;
                        document.getElementById('edit_email').value = u.email;
                        document.getElementById('edit_phone').value = u.phone || '';
                        document.getElementById('edit_department').value = u.department || '';
                        document.getElementById('edit_designation').value = u.designation || '';
                        document.getElementById('edit_branch').value = u.branch || '';
                        if (document.getElementById('edit_company_branch')) {
                            document.getElementById('edit_company_branch').value = u.company_branch || 'main';
                        }
                        document.getElementById('edit_team').value = u.team || '';
                        document.getElementById('edit_joined_date').value = u.joined_date || '';
                        document.getElementById('edit_portal_role').value = u.portal_role;
                        syncSuperAdminCheckbox(u.portal_role, 'edit_as_super_admin', 'edit_portal_role');
                        document.getElementById('edit_status').value = u.status || 'active';
                        document.getElementById('editModal').classList.add('active');
                    }
                });
        }

        function resetPassword(id) {
            document.getElementById('reset_id').value = id;
            document.getElementById('resetModal').classList.add('active');
        }

        function deleteUser(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) event.target.classList.remove('active');
        }

        <?php if ($current_is_super): ?>
        let parsedBulkUsers = [], bulkImportFileName = '';

        function downloadUserImportTemplate() {
            const header = 'employee_code,full_name,email,phone,portal_role,department,designation,company_branch,branch,team,joined_date,password';
            const sample = '5056,Mehmood Ali,mehmood.ali,03001234567,user,Sales,Agent,commercial,Commercial Office,Team A,2024-01-15,';
            const blob = new Blob([header + '\n' + sample + '\n'], { type: 'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'user_import_template.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        }

        function normalizeBulkHeader(key) {
            return String(key || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        }

        function pickBulkValue(row, aliases) {
            for (const [col, val] of Object.entries(row)) {
                const norm = normalizeBulkHeader(col);
                if (aliases.includes(norm) && String(val).trim() !== '') return String(val).trim();
            }
            return '';
        }

        function parseBulkUserRows(data) {
            const out = [];
            data.forEach(r => {
                const employee_code = pickBulkValue(r, ['employeecode', 'employeeid', 'empid', 'bid', 'id']);
                const full_name = pickBulkValue(r, ['fullname', 'name', 'employeename']);
                const email = pickBulkValue(r, ['email', 'emailprefix', 'username', 'login']);
                const phone = pickBulkValue(r, ['phone', 'mobile', 'contact']);
                const portal_role = pickBulkValue(r, ['portalrole', 'role', 'userrole']);
                const department = pickBulkValue(r, ['department', 'dept']);
                const designation = pickBulkValue(r, ['designation', 'title', 'jobtitle']);
                const company_branch = pickBulkValue(r, ['companybranch', 'loginbranch', 'branchlogin']);
                const branch = pickBulkValue(r, ['office', 'officelocation', 'location', 'branch']);
                const team = pickBulkValue(r, ['team']);
                const joined_date = pickBulkValue(r, ['joineddate', 'joiningdate', 'dateofjoining']);
                const password = pickBulkValue(r, ['password', 'pass']);
                if (employee_code && full_name && email) {
                    out.push({
                        employee_code, full_name, email, phone, portal_role: portal_role || 'user',
                        department, designation, company_branch: company_branch || 'main',
                        branch, team, joined_date, password
                    });
                }
            });
            return out;
        }

        function handleBulkUserFile(e) {
            const f = (e.target.files && e.target.files[0]) || null;
            if (!f) return;
            bulkImportFileName = f.name;
            if (typeof XLSX === 'undefined') {
                showNotification('Excel parser loading — try again in a moment', 'error');
                return;
            }
            const reader = new FileReader();
            reader.onload = (ev) => {
                const wb = XLSX.read(ev.target.result, { type: 'array' });
                const data = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]]);
                parsedBulkUsers = parseBulkUserRows(data);
                renderBulkImportPreview();
            };
            reader.readAsArrayBuffer(f);
        }

        function renderBulkImportPreview() {
            const preview = document.getElementById('bulkImportPreview');
            if (!preview) return;
            preview.classList.add('show');
            if (!parsedBulkUsers.length) {
                preview.innerHTML = '<div class="message error"><i class="fas fa-exclamation-circle"></i> No valid rows. Each row needs employee_code, full_name, and email.</div>';
                return;
            }
            const rows = parsedBulkUsers.slice(0, 8).map(u =>
                `<tr><td>${escapeHtml(u.employee_code)}</td><td>${escapeHtml(u.full_name)}</td><td>${escapeHtml(u.email)}</td><td>${escapeHtml(u.portal_role)}</td><td>${escapeHtml(u.company_branch)}</td></tr>`
            ).join('');
            preview.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <p style="color:#10b981;margin:0;"><i class="fas fa-check-circle"></i> <strong>${parsedBulkUsers.length}</strong> valid row(s) in ${escapeHtml(bulkImportFileName)}</p>
                    <button type="button" class="btn-primary" onclick="commitBulkUserImport()"><i class="fas fa-upload"></i> Import ${parsedBulkUsers.length} User(s)</button>
                </div>
                <table class="bulk-import-table"><thead><tr><th>Employee ID</th><th>Name</th><th>Email</th><th>Role</th><th>Login Branch</th></tr></thead><tbody>${rows}</tbody></table>
                ${parsedBulkUsers.length > 8 ? '<p style="color:rgba(255,255,255,0.45);font-size:11px;margin:8px 0 0;">Showing first 8 rows…</p>' : ''}`;
        }

        async function commitBulkUserImport() {
            if (!parsedBulkUsers.length) return;
            const defaultPassword = (document.getElementById('bulkDefaultPassword') || {}).value || 'Balitech@123';
            if (defaultPassword.length < 4) {
                showNotification('Default password must be at least 4 characters', 'error');
                return;
            }
            const preview = document.getElementById('bulkImportPreview');
            if (preview) preview.innerHTML = '<p style="color:rgba(255,255,255,0.7);"><i class="fas fa-spinner fa-spin"></i> Importing… please wait.</p>';
            try {
                const res = await fetch('api/bulk_import_users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        file_name: bulkImportFileName,
                        default_password: defaultPassword,
                        users: parsedBulkUsers
                    })
                });
                const data = await res.json();
                if (!data.success) {
                    showNotification(data.message || 'Import failed', 'error');
                    renderBulkImportPreview();
                    return;
                }
                const d = data.data || {};
                let msg = `Created ${d.inserted || 0}, skipped ${d.skipped || 0}`;
                if (d.errors && d.errors.length) msg += '. ' + d.errors.slice(0, 3).join('; ');
                showNotification(msg, d.inserted ? 'success' : 'error');
                parsedBulkUsers = [];
                document.getElementById('bulkFileInput').value = '';
                if (preview) {
                    preview.innerHTML = `<div class="message success"><i class="fas fa-check-circle"></i> ${escapeHtml(msg)}</div>`;
                }
                setTimeout(() => location.reload(), 1500);
            } catch (err) {
                console.error(err);
                showNotification('Import request failed', 'error');
                renderBulkImportPreview();
            }
        }

        function escapeHtml(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        (function initBulkDropZone() {
            const zone = document.getElementById('bulkDropZone');
            if (!zone) return;
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
            zone.addEventListener('dragleave', e => { e.preventDefault(); zone.classList.remove('drag-over'); });
            zone.addEventListener('drop', e => {
                e.preventDefault();
                zone.classList.remove('drag-over');
                const input = document.getElementById('bulkFileInput');
                if (e.dataTransfer.files[0] && input) {
                    input.files = e.dataTransfer.files;
                    handleBulkUserFile({ target: input });
                }
            });
        })();
        <?php endif; ?>
    </script>
    <?php if ($current_is_super): ?>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>
    <?php endif; ?>
</body>
</html>