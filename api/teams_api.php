<?php
/**
 * Team Master API
 * Manage teams for User Management dropdowns and Team Master portal.
 * Add / Edit / Deactivate actions are strictly restricted to Super Admin.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['portal_role'] ?? '';
$is_admin = in_array($role, ['admin', 'super_admin', 'finance'], true);
$is_super = ($role === 'super_admin');

if (!isAuthenticated() || !$is_admin) {
    respond(false, null, 'Unauthorized access.');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        $include_all = isset($_GET['all']) && $_GET['all'] == '1' && $is_super;
        if ($include_all) {
            $sql = "SELECT * FROM `teams` ORDER BY id DESC";
        } else {
            $sql = "SELECT * FROM `teams` WHERE `status` = 'active' ORDER BY `team_name` ASC";
        }
        
        $res = $conn->query($sql);
        $teams = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $teams[] = $row;
            }
        }
        respond(true, ['teams' => $teams]);
    }
    
    respond(false, null, 'Invalid GET action.');
}

if ($method === 'POST') {
    // Super Admin restriction for all mutating operations
    if (!$is_super) {
        respond(false, null, 'Only Super Admin accounts have permission to modify Team Master.');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    if ($action === 'add') {
        $team_name = trim((string)($input['team_name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $shift_start_time = trim((string)($input['shift_start_time'] ?? '18:00:00'));
        if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $shift_start_time)) {
            $shift_start_time .= ':00';
        } elseif (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $shift_start_time)) {
            $shift_start_time = '18:00:00';
        }
        
        if ($team_name === '') {
            respond(false, null, 'Team name is required.');
        }
        
        // Check uniqueness
        $chk = $conn->prepare("SELECT id FROM `teams` WHERE LOWER(`team_name`) = LOWER(?) LIMIT 1");
        $chk->bind_param("s", $team_name);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            respond(false, null, 'A team with this name already exists.');
        }
        $chk->close();
        
        $stmt = $conn->prepare("INSERT INTO `teams` (`team_name`, `description`, `shift_start_time`, `status`) VALUES (?, ?, ?, 'active')");
        $stmt->bind_param("sss", $team_name, $description, $shift_start_time);
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();
            respond(true, ['id' => $new_id, 'team_name' => $team_name, 'shift_start_time' => $shift_start_time], 'Team created successfully.');
        } else {
            $error = $conn->error;
            $stmt->close();
            respond(false, null, 'Database error: ' . $error);
        }
    }
    
    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $team_name = trim((string)($input['team_name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $shift_start_time = trim((string)($input['shift_start_time'] ?? '18:00:00'));
        if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $shift_start_time)) {
            $shift_start_time .= ':00';
        } elseif (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $shift_start_time)) {
            $shift_start_time = '18:00:00';
        }

        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        
        if ($id <= 0 || $team_name === '') {
            respond(false, null, 'Valid Team ID and Team name are required.');
        }
        
        // Fetch current team
        $cur = $conn->prepare("SELECT `team_name` FROM `teams` WHERE id = ? LIMIT 1");
        $cur->bind_param("i", $id);
        $cur->execute();
        $cur_res = $cur->get_result()->fetch_assoc();
        $cur->close();
        
        if (!$cur_res) {
            respond(false, null, 'Team not found.');
        }
        $old_team_name = $cur_res['team_name'];
        
        // Check duplicate name on another team
        $chk = $conn->prepare("SELECT id FROM `teams` WHERE LOWER(`team_name`) = LOWER(?) AND id != ? LIMIT 1");
        $chk->bind_param("si", $team_name, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            respond(false, null, 'Another team with this name already exists.');
        }
        $chk->close();
        
        $stmt = $conn->prepare("UPDATE `teams` SET `team_name` = ?, `description` = ?, `shift_start_time` = ?, `status` = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $team_name, $description, $shift_start_time, $status, $id);
        if ($stmt->execute()) {
            $stmt->close();
            
            // If team_name changed, update linked user & employee records
            if ($old_team_name !== '' && strcasecmp($old_team_name, $team_name) !== 0) {
                $u_up = $conn->prepare("UPDATE `users` SET `team` = ? WHERE `team` = ?");
                $u_up->bind_param("ss", $team_name, $old_team_name);
                $u_up->execute();
                $u_up->close();
                
                $e_up = $conn->prepare("UPDATE `employees` SET `team` = ? WHERE `team` = ?");
                $e_up->bind_param("ss", $team_name, $old_team_name);
                $e_up->execute();
                $e_up->close();
            }
            
            respond(true, null, 'Team updated successfully.');
        } else {
            $error = $conn->error;
            $stmt->close();
            respond(false, null, 'Database error: ' . $error);
        }
    }
    
    if ($action === 'toggle_status') {
        $id = (int)($input['id'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            respond(false, null, 'Invalid status.');
        }
        if ($id <= 0) {
            respond(false, null, 'Valid Team ID required.');
        }
        
        $stmt = $conn->prepare("UPDATE `teams` SET `status` = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            $stmt->close();
            respond(true, ['status' => $status], 'Team status updated to ' . $status . '.');
        } else {
            $error = $conn->error;
            $stmt->close();
            respond(false, null, 'Database error: ' . $error);
        }
    }
    
    respond(false, null, 'Invalid POST action.');
}

respond(false, null, 'Method not allowed.');
