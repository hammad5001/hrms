<?php
// includes/call_repository.php - Database repository for RTC calling using prepared statements and transactions

require_once __DIR__ . '/../config.php';

function call_repo_get_conversation_participants(mysqli $conn, int $conversation_id): array {
    $stmt = $conn->prepare("
        SELECT user_id, participant_status 
        FROM chat_participants 
        WHERE conversation_id = ? AND participant_status != 'declined'
    ");
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $users = [];
    while ($row = $res->fetch_assoc()) {
        $users[] = (int)$row['user_id'];
    }
    return $users;
}

function call_repo_get_active_call_by_user(mysqli $conn, int $user_id): ?array {
    $stmt = $conn->prepare("
        SELECT c.* 
        FROM rtc_active_users a
        INNER JOIN rtc_calls c ON c.id = a.active_call_id
        WHERE a.user_id = ? AND c.status IN ('initiated', 'active')
        LIMIT 1
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function call_repo_get_call_by_uuid(mysqli $conn, string $uuid): ?array {
    $stmt = $conn->prepare("SELECT * FROM rtc_calls WHERE uuid = ? LIMIT 1");
    $stmt->bind_param('s', $uuid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function call_repo_get_call_by_id(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM rtc_calls WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function call_repo_check_busy_users(mysqli $conn, array $user_ids): array {
    if (empty($user_ids)) return [];
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $types = str_repeat('i', count($user_ids));
    $stmt = $conn->prepare("SELECT user_id FROM rtc_active_users WHERE user_id IN ($placeholders)");
    $stmt->bind_param($types, ...$user_ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $busy = [];
    while ($row = $res->fetch_assoc()) {
        $busy[] = (int)$row['user_id'];
    }
    return $busy;
}

function call_repo_create_call(mysqli $conn, int $conversation_id, int $creator_id, array $participants, string $call_type): ?array {
    $conn->begin_transaction();
    try {
        // Double check host status
        $busy = call_repo_check_busy_users($conn, [$creator_id]);
        if (!empty($busy)) {
            throw new Exception("Creator is currently busy on another call");
        }

        $uuid = bin2hex(random_bytes(16));
        $room_name = 'room_' . $uuid;

        $stmt = $conn->prepare("
            INSERT INTO rtc_calls (uuid, conversation_id, room_name, creator_id, call_type, status)
            VALUES (?, ?, ?, ?, ?, 'initiated')
        ");
        $stmt->bind_param('sisis', $uuid, $conversation_id, $room_name, $creator_id, $call_type);
        $stmt->execute();
        $call_id = $conn->insert_id;

        // Insert creator as accepted participant
        $stmtCreator = $conn->prepare("
            INSERT INTO rtc_call_participants (call_id, user_id, is_host, invitation_status, joined_at)
            VALUES (?, ?, 1, 'accepted', NOW())
        ");
        $stmtCreator->bind_param('ii', $call_id, $creator_id);
        $stmtCreator->execute();

        // Lock host
        $stmtLock = $conn->prepare("INSERT INTO rtc_active_users (user_id, active_call_id) VALUES (?, ?)");
        $stmtLock->bind_param('ii', $creator_id, $call_id);
        $stmtLock->execute();

        // Insert invitees
        $stmtInvitee = $conn->prepare("
            INSERT INTO rtc_call_participants (call_id, user_id, is_host, invitation_status)
            VALUES (?, ?, 0, 'invited')
        ");
        foreach ($participants as $uid) {
            if ($uid === $creator_id) continue;
            $stmtInvitee->bind_param('ii', $call_id, $uid);
            $stmtInvitee->execute();
        }

        // Log event
        $event_type = 'create';
        $meta = json_encode(['creator' => $creator_id]);
        $stmtEvent = $conn->prepare("INSERT INTO rtc_call_events (call_id, user_id, event_type, metadata) VALUES (?, ?, ?, ?)");
        $stmtEvent->bind_param('iiss', $call_id, $creator_id, $event_type, $meta);
        $stmtEvent->execute();

        $conn->commit();
        return call_repo_get_call_by_id($conn, $call_id);
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error creating call: " . $e->getMessage());
        return null;
    }
}

function call_repo_accept_call(mysqli $conn, int $call_id, int $user_id): bool {
    $conn->begin_transaction();
    try {
        // Check if user is already locked
        $busy = call_repo_check_busy_users($conn, [$user_id]);
        if (!empty($busy)) {
            // Check if they are locked in THIS call
            $stmtCheck = $conn->prepare("SELECT active_call_id FROM rtc_active_users WHERE user_id = ?");
            $stmtCheck->bind_param('i', $user_id);
            $stmtCheck->execute();
            $actCall = $stmtCheck->get_result()->fetch_assoc();
            if ($actCall && (int)$actCall['active_call_id'] === $call_id) {
                $conn->commit();
                return true; // Already accepted
            }
            throw new Exception("already_answered");
        }

        // Check call status
        $call = call_repo_get_call_by_id($conn, $call_id);
        if (!$call || in_array($call['status'], ['ended', 'cancelled'])) {
            throw new Exception("Call has already ended");
        }

        // Update participant status
        $stmtPart = $conn->prepare("
            UPDATE rtc_call_participants 
            SET invitation_status = 'accepted', joined_at = NOW() 
            WHERE call_id = ? AND user_id = ?
        ");
        $stmtPart->bind_param('ii', $call_id, $user_id);
        $stmtPart->execute();

        // Lock user
        $stmtLock = $conn->prepare("INSERT INTO rtc_active_users (user_id, active_call_id) VALUES (?, ?)");
        $stmtLock->bind_param('ii', $user_id, $call_id);
        $stmtLock->execute();

        // If call is initiated, make it active
        if ($call['status'] === 'initiated') {
            $stmtCall = $conn->prepare("UPDATE rtc_calls SET status = 'active', answered_at = NOW() WHERE id = ?");
            $stmtCall->bind_param('i', $call_id);
            $stmtCall->execute();
        }

        // Log event
        $event_type = 'accept';
        $stmtEvent = $conn->prepare("INSERT INTO rtc_call_events (call_id, user_id, event_type) VALUES (?, ?, ?)");
        $stmtEvent->bind_param('iis', $call_id, $user_id, $event_type);
        $stmtEvent->execute();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error accepting call: " . $e->getMessage());
        return false;
    }
}

function call_repo_decline_call(mysqli $conn, int $call_id, int $user_id): bool {
    $conn->begin_transaction();
    try {
        $stmtPart = $conn->prepare("
            UPDATE rtc_call_participants 
            SET invitation_status = 'declined', left_at = NOW() 
            WHERE call_id = ? AND user_id = ?
        ");
        $stmtPart->bind_param('ii', $call_id, $user_id);
        $stmtPart->execute();

        // Remove active lock if any
        $stmtUnlock = $conn->prepare("DELETE FROM rtc_active_users WHERE user_id = ? AND active_call_id = ?");
        $stmtUnlock->bind_param('ii', $user_id, $call_id);
        $stmtUnlock->execute();

        // Log event
        $event_type = 'decline';
        $stmtEvent = $conn->prepare("INSERT INTO rtc_call_events (call_id, user_id, event_type) VALUES (?, ?, ?)");
        $stmtEvent->bind_param('iis', $call_id, $user_id, $event_type);
        $stmtEvent->execute();

        // Check if all invitees have declined or left
        call_repo_auto_evaluate_end($conn, $call_id);

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error declining call: " . $e->getMessage());
        return false;
    }
}

function call_repo_leave_call(mysqli $conn, int $call_id, int $user_id): bool {
    $conn->begin_transaction();
    try {
        $stmtPart = $conn->prepare("
            UPDATE rtc_call_participants 
            SET invitation_status = 'left', left_at = NOW() 
            WHERE call_id = ? AND user_id = ?
        ");
        $stmtPart->bind_param('ii', $call_id, $user_id);
        $stmtPart->execute();

        // Remove active lock
        $stmtUnlock = $conn->prepare("DELETE FROM rtc_active_users WHERE user_id = ? AND active_call_id = ?");
        $stmtUnlock->bind_param('ii', $user_id, $call_id);
        $stmtUnlock->execute();

        // Log event
        $event_type = 'leave';
        $stmtEvent = $conn->prepare("INSERT INTO rtc_call_events (call_id, user_id, event_type) VALUES (?, ?, ?)");
        $stmtEvent->bind_param('iis', $call_id, $user_id, $event_type);
        $stmtEvent->execute();

        // Check if all participants left
        call_repo_auto_evaluate_end($conn, $call_id);

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error leaving call: " . $e->getMessage());
        return false;
    }
}

function call_repo_end_call(mysqli $conn, int $call_id, int $ended_by_id, string $reason = 'host_ended'): bool {
    $conn->begin_transaction();
    try {
        $stmtCall = $conn->prepare("
            UPDATE rtc_calls 
            SET status = 'ended', ended_at = NOW(), ended_by_id = ?, end_reason = ? 
            WHERE id = ? AND status IN ('initiated', 'active')
        ");
        $stmtCall->bind_param('isi', $ended_by_id, $reason, $call_id);
        $stmtCall->execute();

        // Set remaining participants to left
        $stmtPart = $conn->prepare("
            UPDATE rtc_call_participants 
            SET invitation_status = 'left', left_at = NOW() 
            WHERE call_id = ? AND invitation_status IN ('invited', 'ringing', 'accepted')
        ");
        $stmtPart->bind_param('i', $call_id);
        $stmtPart->execute();

        // Remove active locks
        $stmtUnlock = $conn->prepare("DELETE FROM rtc_active_users WHERE active_call_id = ?");
        $stmtUnlock->bind_param('i', $call_id);
        $stmtUnlock->execute();

        // Log event
        $event_type = 'end';
        $meta = json_encode(['reason' => $reason]);
        $stmtEvent = $conn->prepare("INSERT INTO rtc_call_events (call_id, user_id, event_type, metadata) VALUES (?, ?, ?, ?)");
        $stmtEvent->bind_param('iiss', $call_id, $ended_by_id, $event_type, $meta);
        $stmtEvent->execute();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error ending call: " . $e->getMessage());
        return false;
    }
}

function call_repo_auto_evaluate_end(mysqli $conn, int $call_id): void {
    // Check if anyone is still accepted/active
    $stmt = $conn->prepare("
        SELECT COUNT(*) as active_count 
        FROM rtc_call_participants 
        WHERE call_id = ? AND invitation_status IN ('accepted', 'invited', 'ringing')
    ");
    $stmt->bind_param('i', $call_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && (int)$row['active_count'] === 0) {
        // End the call automatically
        $stmtCall = $conn->prepare("
            UPDATE rtc_calls 
            SET status = 'ended', ended_at = NOW(), end_reason = 'all_left' 
            WHERE id = ? AND status IN ('initiated', 'active')
        ");
        $stmtCall->bind_param('i', $call_id);
        $stmtCall->execute();

        // Remove active locks just in case
        $stmtUnlock = $conn->prepare("DELETE FROM rtc_active_users WHERE active_call_id = ?");
        $stmtUnlock->bind_param('i', $call_id);
        $stmtUnlock->execute();
    }
}

function call_repo_get_participants(mysqli $conn, int $call_id): array {
    $stmt = $conn->prepare("
        SELECT p.user_id, p.is_host, p.invitation_status, u.full_name, u.email 
        FROM rtc_call_participants p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.call_id = ?
    ");
    $stmt->bind_param('i', $call_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $row['user_id'] = (int)$row['user_id'];
        $row['is_host'] = (bool)$row['is_host'];
        $list[] = $row;
    }
    return $list;
}

function call_repo_get_call_history(mysqli $conn, int $conversation_id, int $limit = 20): array {
    $stmt = $conn->prepare("
        SELECT c.*, u.full_name as creator_name
        FROM rtc_calls c
        INNER JOIN users u ON u.id = c.creator_id
        WHERE c.conversation_id = ?
        ORDER BY c.id DESC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $conversation_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $history = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['conversation_id'] = (int)$row['conversation_id'];
        $row['creator_id'] = (int)$row['creator_id'];
        $row['participants'] = call_repo_get_participants($conn, $row['id']);
        $history[] = $row;
    }
    return $history;
}

function call_repo_update_participant_ringing(mysqli $conn, int $call_id, int $user_id): void {
    $stmt = $conn->prepare("
        UPDATE rtc_call_participants 
        SET invitation_status = 'ringing' 
        WHERE call_id = ? AND user_id = ? AND invitation_status = 'invited'
    ");
    $stmt->bind_param('ii', $call_id, $user_id);
    $stmt->execute();
}
?>
