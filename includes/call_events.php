<?php
// includes/call_events.php - Publishes signaling call events to specific user IDs via WebSocket server

require_once __DIR__ . '/chat_ws.php';
require_once __DIR__ . '/call_repository.php';

function call_events_broadcast(mysqli $conn, int $call_id, string $event_type, array $data, ?array $exclude_uids = null): void {
    $participants = call_repo_get_participants($conn, $call_id);
    $uids = [];
    foreach ($participants as $p) {
        $uid = (int)$p['user_id'];
        if ($exclude_uids && in_array($uid, $exclude_uids)) {
            continue;
        }
        $uids[] = $uid;
    }
    if (!empty($uids)) {
        chat_ws_publish($uids, $event_type, $data);
    }
}

function call_events_send_to_user(int $user_id, string $event_type, array $data): void {
    chat_ws_publish([$user_id], $event_type, $data);
}
?>
