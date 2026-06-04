<?php
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = [];
}

$branch = normalize_company_branch($_SESSION['company_branch'] ?? 'main');

function portal_role_can_voice(): bool {
    $role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
    if (in_array($role, ['hr', 'management', 'admin', 'recruiter', 'receptionist', 'agent', 'training'], true)) {
        return true;
    }
    if (!empty($_SESSION['admin_portal_view'])) {
        return true;
    }
    return isAuthenticated();
}

switch ($action) {
    case 'list':
        if (!portal_role_can_voice()) {
            respond(false, null, 'Not logged in');
        }
        $unplayed = isset($_GET['unplayed']) ? (int)$_GET['unplayed'] : 0;
        $typeFilter = trim((string)($_GET['type'] ?? ''));
        $target = trim((string)($_GET['target'] ?? 'reception'));
        $allowedTargets = ['reception', 'agent', 'reception_kiosk'];
        if (!in_array($target, $allowedTargets, true)) {
            $target = 'reception';
        }
        $portal_role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
        $receptionDesk = in_array($portal_role, ['receptionist', 'agent', 'admin'], true);

        $sql = "SELECT id, notification_type, target_portal, payload, is_played, created_at 
                FROM portal_notifications WHERE target_portal = ?";
        $params = [$target];
        $types = 's';

        if (!$receptionDesk) {
            $sql .= " AND company_branch = ?";
            $params[] = $branch;
            $types .= 's';
        }
        if ($unplayed) {
            $sql .= " AND is_played = 0";
        }
        if ($typeFilter !== '') {
            $sql .= " AND notification_type = ?";
            $params[] = $typeFilter;
            $types .= 's';
        }
        $sql .= " ORDER BY created_at ASC LIMIT 100";
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['payload'] = json_decode($r['payload'], true);
        }
        respond(true, $rows);
        break;

    case 'create':
        if (!portal_role_can_voice()) {
            respond(false, null, 'Not logged in. Please sign in again from the login page.');
        }
        $type = $input['type'] ?? 'voice_call';
        $target = trim((string)($input['target'] ?? 'reception'));
        $allowedTargets = ['reception', 'agent', 'reception_kiosk'];
        if (!in_array($target, $allowedTargets, true)) {
            respond(false, null, 'Invalid target portal');
        }
        $payload = $input['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }
        if ($type === 'voice_call') {
            $payload['name'] = trim((string)($payload['name'] ?? 'Candidate'));
            $payload['room'] = trim((string)($payload['room'] ?? 'HR'));
            $payload['repeat_count'] = max(1, min(3, (int)($payload['repeat_count'] ?? 3)));
            $payload['voice_pref'] = trim((string)($payload['voice_pref'] ?? 'female_en'));
            $payload['requested_by'] = getCurrentUserName();
            $payload['count'] = 1;
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            respond(false, null, 'Invalid notification payload');
        }
        $branch = get_active_company_branch();
        $stmt = $conn->prepare("INSERT INTO portal_notifications (notification_type, target_portal, payload, company_branch) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            respond(false, null, 'Database prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('ssss', $type, $target, $payloadJson, $branch);
        if ($stmt->execute()) {
            respond(true, ['id' => (int)$conn->insert_id], 'Notification created');
        }
        respond(false, null, 'Database insert failed: ' . $stmt->error);
        break;

    case 'markPlayed':
        if (!portal_role_can_voice()) {
            respond(false, null, 'Not logged in');
        }
        $ids = $input['ids'] ?? [];
        $consumerPortal = trim((string)($input['consumer_portal'] ?? 'reception'));
        $allowedTargets = ['reception', 'agent', 'reception_kiosk'];
        if (!in_array($consumerPortal, $allowedTargets, true)) {
            $consumerPortal = 'reception';
        }
        if (!is_array($ids) || empty($ids)) {
            respond(false, null, 'No ids');
        }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $portal_role = $_SESSION['portal_role'] ?? $_SESSION['role'] ?? '';
        $receptionDesk = in_array($portal_role, ['receptionist', 'agent', 'admin'], true);

        $sql = "UPDATE portal_notifications
                SET is_played = 1, played_at = NOW(), played_by_portal = ?
                WHERE target_portal = ? AND id IN ($placeholders) AND is_played = 0";
        $types = 'ss' . str_repeat('i', count($ids));
        $params = array_merge([$consumerPortal, $consumerPortal], $ids);

        if (!$receptionDesk) {
            $sql = "UPDATE portal_notifications
                    SET is_played = 1, played_at = NOW(), played_by_portal = ?
                    WHERE company_branch = ? AND target_portal = ? AND id IN ($placeholders) AND is_played = 0";
            $types = 'sss' . str_repeat('i', count($ids));
            $params = array_merge([$consumerPortal, $branch, $consumerPortal], $ids);
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        respond(true, null, 'Marked played');
        break;

    default:
        respond(false, null, 'Invalid action');
}
