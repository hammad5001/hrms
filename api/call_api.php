<?php
// api/call_api.php - WebRTC Calling Business Logic API Controller

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/call.php';
require_once __DIR__ . '/../includes/call_auth.php';
require_once __DIR__ . '/../includes/call_repository.php';
require_once __DIR__ . '/../includes/call_events.php';
require_once __DIR__ . '/../includes/call_token_client.php';

$me = call_authenticate($conn);
$me_id = (int)$me['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CORS/CSRF / Input Parsing
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : [];
    }
} else {
    $input = $_POST;
}

function call_json(bool $success, $data = null, ?string $message = null, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'config':
            call_json(true, [
                'enabled' => CALL_FEATURE_ENABLED,
                'livekit_ws_url' => LIVEKIT_WS_URL,
                'timeout' => CALL_TIMEOUT_SECONDS
            ]);
            break;

        case 'activeCall':
            $active = call_repo_get_active_call_by_user($conn, $me_id);
            if ($active) {
                $active['participants'] = call_repo_get_participants($conn, $active['id']);
                call_json(true, $active);
            }
            call_json(true, null);
            break;

        case 'history':
            $cid = (int)($_GET['conversation_id'] ?? $input['conversation_id'] ?? 0);
            call_validate_conversation_membership($conn, $cid, $me_id);
            $history = call_repo_get_call_history($conn, $cid);
            call_json(true, $history);
            break;

        case 'create':
            $callerRole = strtolower(trim((string)($me['portal_role'] ?? '')));
            $allowedCallerRoles = ['super_admin', 'admin', 'team_lead'];

            if (!in_array($callerRole, $allowedCallerRoles, true)) {
                call_json(
                    false,
                    null,
                    'Only Super Admins, Admins and Team Leads can initiate calls',
                    403
                );
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                call_json(false, null, 'Method not allowed', 450);
            }
            $cid = (int)($input['conversation_id'] ?? 0);
            call_validate_conversation_membership($conn, $cid, $me_id);

            // Get conversation participants
            $participants = call_repo_get_conversation_participants($conn, $cid);
            if (count($participants) < 2) {
                call_json(false, null, 'Conversation must have at least 2 participants');
            }

            // Check if creator is already busy
            $busyCreator = call_repo_check_busy_users($conn, [$me_id]);
            if (!empty($busyCreator)) {
                call_json(false, null, 'You are already in an active call');
            }

            // Clean participants - filter inactive or blocked
            $valid_invitees = [];
            foreach ($participants as $uid) {
                if ($uid === $me_id) continue;
                // Verify user exists and is active
                $uStmt = $conn->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
                $uStmt->bind_param('i', $uid);
                $uStmt->execute();
                $uInfo = $uStmt->get_result()->fetch_assoc();
                if (!$uInfo || $uInfo['status'] !== 'active') {
                    continue; // Skip inactive
                }
                // Check block status
                if (chat_is_blocked($conn, $me_id, $uid)) {
                    continue; // Skip blocked
                }
                $valid_invitees[] = $uid;
            }

            if (empty($valid_invitees)) {
                call_json(false, null, 'No eligible active participants to call');
            }

            $call_type = (count($participants) > 2) ? 'group' : 'direct';
            $call = call_repo_create_call($conn, $cid, $me_id, $valid_invitees, $call_type);

            if (!$call) {
                call_json(false, null, 'Failed to initiate call');
            }

            // Generate host token
            $token = call_token_client_generate($call['room_name'], (string)$me_id, $me['full_name']);
            if (!$token) {
                call_repo_end_call($conn, $call['id'], $me_id, 'token_service_failed');
                call_json(false, null, 'Calling service currently unavailable');
            }

            // Check which invitees are currently busy
            $busy_uids = call_repo_check_busy_users($conn, $valid_invitees);
            $ringing_uids = array_diff($valid_invitees, $busy_uids);

            // Ring eligible invitees
            foreach ($ringing_uids as $ruid) {
                call_repo_update_participant_ringing($conn, $call['id'], $ruid);
            }

            // Notify invitees via WebSocket
            $invitePayload = [
                'call_id' => $call['id'],
                'uuid' => $call['uuid'],
                'room_name' => $call['room_name'],
                'conversation_id' => $cid,
                'caller_id' => $me_id,
                'caller_name' => $me['full_name'],
                'call_type' => $call_type,
            ];

            foreach ($ringing_uids as $ruid) {
                call_events_send_to_user($ruid, 'call.invite', $invitePayload);
            }

            // Notify busy invitees with busy response
            foreach ($busy_uids as $buid) {
                $stmtBusy = $conn->prepare("
                    UPDATE rtc_call_participants 
                    SET invitation_status = 'busy', left_at = NOW() 
                    WHERE call_id = ? AND user_id = ?
                ");
                $stmtBusy->bind_param('ii', $call['id'], $buid);
                $stmtBusy->execute();

                call_events_send_to_user($buid, 'call.busy', [
                    'call_id' => $call['id'],
                    'user_id' => $buid,
                ]);
            }

            call_json(true, [
                'call' => $call,
                'token' => $token,
                'busy_users' => $busy_uids
            ]);
            break;

        case 'accept':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                call_json(false, null, 'Method not allowed', 450);
            }
            $uuid = $input['uuid'] ?? '';
            $call = call_repo_get_call_by_uuid($conn, $uuid);
            if (!$call) {
                call_json(false, null, 'Call not found');
            }

            $success = call_repo_accept_call($conn, $call['id'], $me_id);
            if (!$success) {
                call_json(false, null, 'already_answered');
            }

            // Generate token for acceptor
            $token = call_token_client_generate($call['room_name'], (string)$me_id, $me['full_name']);
            if (!$token) {
                call_repo_leave_call($conn, $call['id'], $me_id);
                call_json(false, null, 'Failed to sign media token');
            }

            // Broadcast accepted
            call_events_broadcast($conn, $call['id'], 'call.accepted', [
                'call_id' => $call['id'],
                'uuid' => $call['uuid'],
                'user_id' => $me_id,
                'user_name' => $me['full_name']
            ]);

            call_json(true, [
                'call' => $call,
                'token' => $token
            ]);
            break;

        case 'decline':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                call_json(false, null, 'Method not allowed', 450);
            }
            $uuid = $input['uuid'] ?? '';
            $call = call_repo_get_call_by_uuid($conn, $uuid);
            if (!$call) {
                call_json(false, null, 'Call not found');
            }

            call_repo_decline_call($conn, $call['id'], $me_id);
            
            // Broadcast decline event
            call_events_broadcast($conn, $call['id'], 'call.declined', [
                'call_id' => $call['id'],
                'uuid' => $call['uuid'],
                'user_id' => $me_id,
                'user_name' => $me['full_name']
            ]);

            call_json(true, ['ok' => true]);
            break;

        case 'cancel':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                call_json(false, null, 'Method not allowed', 450);
            }
            $uuid = $input['uuid'] ?? '';
            $call = call_repo_get_call_by_uuid($conn, $uuid);
            if (!$call) {
                call_json(false, null, 'Call not found');
            }

            if ((int)$call['creator_id'] !== $me_id) {
                call_json(false, null, 'Only the host can cancel this call');
            }

            call_repo_end_call($conn, $call['id'], $me_id, 'cancelled');

            // Broadcast cancelled event
            call_events_broadcast($conn, $call['id'], 'call.cancelled', [
                'call_id' => $call['id'],
                'uuid' => $call['uuid'],
            ]);

            call_json(true, ['ok' => true]);
            break;

        case 'leave':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                call_json(false, null, 'Method not allowed', 450);
            }
            $uuid = $input['uuid'] ?? '';
            $call = call_repo_get_call_by_uuid($conn, $uuid);
            if (!$call) {
                call_json(false, null, 'Call not found');
            }

            call_repo_leave_call($conn, $call['id'], $me_id);

            // Broadcast left event
            call_events_broadcast($conn, $call['id'], 'call.participant_left', [
                'call_id' => $call['id'],
                'uuid' => $call['uuid'],
                'user_id' => $me_id,
                'user_name' => $me['full_name']
            ]);

            call_json(true, ['ok' => true]);
            break;

        case 'end':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                call_json(false, null, 'Method not allowed', 450);
            }
            $uuid = $input['uuid'] ?? '';
            $call = call_repo_get_call_by_uuid($conn, $uuid);
            if (!$call) {
                call_json(false, null, 'Call not found');
            }

            if ((int)$call['creator_id'] !== $me_id) {
                call_json(false, null, 'Only the host can end this call for everyone');
            }

            call_repo_end_call($conn, $call['id'], $me_id, 'host_ended');

            // Broadcast ended event
            call_events_broadcast($conn, $call['id'], 'call.ended', [
                'call_id' => $call['id'],
                'uuid' => $call['uuid'],
            ]);

            call_json(true, ['ok' => true]);
            break;

        case 'heartbeat':
            $active = call_repo_get_active_call_by_user($conn, $me_id);
            if ($active) {
                // Heartbeat touches the session and confirms status
                call_json(true, ['call_status' => $active['status']]);
            }
            call_json(true, ['call_status' => 'none']);
            break;

        default:
            call_json(false, null, 'Invalid action requested');
            break;
    }
} catch (Exception $ex) {
    error_log("Error in RTC Call Controller: " . $ex->getMessage());
    call_json(false, null, 'Internal server error occurred');
}
?>
