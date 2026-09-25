<?php
/**
 * Super Admin IP Security API
 * Strictly restricted to Super Admin users.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/ip_security.php';

// Super Admin Authorization check (resolve from session or database fallback)
$currentUser = resolve_logged_in_user($conn);
$portal_role = $currentUser['portal_role'] ?? $_SESSION['portal_role'] ?? '';
$is_super = ($portal_role === 'super_admin');

if (!$is_super) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Only Super Administrators have access to IP Security management.'
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'GET') {
    if ($action === 'get_security_status') {
        $enabled = is_ip_restriction_enabled($conn);
        $client_ip = get_client_ip();

        $res = $conn->query("SELECT * FROM `allowed_ips` ORDER BY id DESC");
        $ips = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ips[] = $row;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'restriction_enabled' => $enabled,
                'client_ip' => $client_ip,
                'is_current_ip_whitelisted' => is_ip_allowed($conn, $client_ip),
                'total_ips' => count($ips),
                'allowed_ips' => $ips
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid GET action']);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $post_action = $input['action'] ?? $action;
    $admin_name = $currentUser['full_name'] ?? $_SESSION['full_name'] ?? 'Super Admin';

    // 1. Toggle Master Restriction
    if ($post_action === 'toggle_restriction') {
        $enable = !empty($input['enable']);

        // Safety check: Don't enable if no active allowed IPs exist and current IP is not localhost
        if ($enable) {
            $cnt_res = $conn->query("SELECT COUNT(*) as cnt FROM allowed_ips WHERE is_active = 1");
            $active_cnt = $cnt_res ? (int)$cnt_res->fetch_assoc()['cnt'] : 0;
            if ($active_cnt === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot enable IP restriction when no active allowed IPs exist. Please whitelist at least one IP first.'
                ]);
                exit;
            }
        }

        $val = $enable ? '1' : '0';
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description, updated_by) VALUES ('ip_restriction_enabled', ?, 'Global toggle for IP whitelisting restriction', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
        $stmt->bind_param('ss', $val, $admin_name);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode([
                'success' => true,
                'message' => $enable ? 'IP Restriction has been enabled.' : 'IP Restriction has been disabled.',
                'restriction_enabled' => $enable
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update restriction setting: ' . $conn->error]);
        }
        exit;
    }

    // 2. Add Allowed IP
    if ($post_action === 'add_ip') {
        $ip_address = trim($input['ip_address'] ?? '');
        $label = trim($input['label'] ?? '');
        $is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (empty($ip_address)) {
            echo json_encode(['success' => false, 'message' => 'IP address is required']);
            exit;
        }

        // Validate IP or CIDR
        $is_valid = false;
        if (strpos($ip_address, '/') !== false) {
            [$subnet, $mask] = explode('/', $ip_address, 2);
            $mask = (int)$mask;
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) {
                $is_valid = true;
            } elseif (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) {
                $is_valid = true;
            }
        } else {
            if (filter_var($ip_address, FILTER_VALIDATE_IP) || is_local_ip($ip_address)) {
                $is_valid = true;
            }
        }

        if (!$is_valid) {
            echo json_encode(['success' => false, 'message' => 'Invalid IPv4, IPv6 or CIDR format. Example: 192.168.1.50 or 202.142.1.0/24']);
            exit;
        }

        if (empty($label)) {
            $label = 'Allowed IP (' . $ip_address . ')';
        }

        // allowed_ips columns: id, ip_address, label, is_active, created_by, created_at, updated_at
        $stmt = $conn->prepare("INSERT INTO allowed_ips (ip_address, label, is_active, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = VALUES(is_active)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param('ssis', $ip_address, $label, $is_active, $admin_name);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => "IP {$ip_address} added to whitelist successfully."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . ($err ?: $conn->error)]);
        }
        exit;
    }

    // 3. Quick Allow Current IP
    if ($post_action === 'allow_current_ip') {
        $client_ip = get_client_ip();
        $label = trim($input['label'] ?? "Super Admin Current IP ({$admin_name})");
        $is_active = 1;

        $stmt = $conn->prepare("INSERT INTO allowed_ips (ip_address, label, is_active, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_active = 1, label = VALUES(label)");
        $stmt->bind_param('ssis', $client_ip, $label, $is_active, $admin_name);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Your IP ({$client_ip}) has been whitelisted.", 'client_ip' => $client_ip]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }

    // 4. Update IP
    if ($post_action === 'update_ip') {
        $id = (int)($input['id'] ?? 0);
        $ip_address = trim($input['ip_address'] ?? '');
        $label = trim($input['label'] ?? '');
        $is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if ($id <= 0 || empty($ip_address)) {
            echo json_encode(['success' => false, 'message' => 'ID and IP address are required.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE allowed_ips SET ip_address = ?, label = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param('ssii', $ip_address, $label, $is_active, $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'IP updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $conn->error]);
        }
        exit;
    }

    // 5. Toggle Status of Single IP
    if ($post_action === 'toggle_ip_status') {
        $id = (int)($input['id'] ?? 0);
        $status = !empty($input['is_active']) ? 1 : 0;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid IP ID.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE allowed_ips SET is_active = ? WHERE id = ?");
        $stmt->bind_param('ii', $status, $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'IP status changed successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $conn->error]);
        }
        exit;
    }

    // 6. Delete Allowed IP
    if ($post_action === 'delete_ip') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid IP ID.']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM allowed_ips WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'IP removed from whitelist.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete: ' . $conn->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid POST action.']);
    exit;
}
