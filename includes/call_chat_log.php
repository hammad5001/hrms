<?php

require_once __DIR__ . '/chat_helpers.php';

function call_chat_log_finalize(
    mysqli $conn,
    int $callId,
    string $forcedType = ''
): ?int {
    $messageId = null;
    $conversationId = 0;
    $senderId = 0;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT
                c.*,
                creator.full_name AS creator_name
            FROM rtc_calls c
            INNER JOIN users creator ON creator.id = c.creator_id
            WHERE c.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $callId);
        $stmt->execute();

        $call = $stmt->get_result()->fetch_assoc();

        if (!$call) {
            $conn->rollback();
            return null;
        }

        if (!empty($call['chat_message_id'])) {
            $conn->commit();
            return (int)$call['chat_message_id'];
        }

        $conversationId = (int)$call['conversation_id'];
        $senderId = (int)$call['creator_id'];
        $creatorName = trim((string)$call['creator_name']);

        $recipientName = 'Recipient';

        $participantStmt = $conn->prepare("
            SELECT u.full_name
            FROM rtc_call_participants p
            INNER JOIN users u ON u.id = p.user_id
            WHERE p.call_id = ?
              AND p.user_id != ?
            ORDER BY p.is_host ASC, p.user_id ASC
            LIMIT 1
        ");
        $participantStmt->bind_param('ii', $callId, $senderId);
        $participantStmt->execute();

        $participant = $participantStmt->get_result()->fetch_assoc();

        if ($participant && !empty($participant['full_name'])) {
            $recipientName = trim((string)$participant['full_name']);
        }

        $type = $forcedType;

        if ($type === '') {
            $type = !empty($call['answered_at']) ? 'completed' : 'missed';
        }

        if ($type === 'completed' && !empty($call['answered_at'])) {
            $start = new DateTime($call['answered_at']);
            $end = !empty($call['ended_at'])
                ? new DateTime($call['ended_at'])
                : new DateTime();

            $seconds = max(
                0,
                $end->getTimestamp() - $start->getTimestamp()
            );

            $duration = sprintf(
                '%02d:%02d',
                intdiv($seconds, 60),
                $seconds % 60
            );

            $body = "📞 Audio call • {$duration}";
        } elseif ($type === 'declined') {
            $body = "📞 {$recipientName} declined the call";
        } else {
            $body = "📞 Missed call from {$creatorName}";
        }

        $insert = $conn->prepare("
            INSERT INTO chat_messages
                (conversation_id, sender_id, body, msg_type)
            VALUES (?, ?, ?, 'text')
        ");
        $insert->bind_param(
            'iis',
            $conversationId,
            $senderId,
            $body
        );
        $insert->execute();

        $messageId = (int)$conn->insert_id;

        $update = $conn->prepare("
            UPDATE rtc_calls
            SET chat_message_id = ?
            WHERE id = ?
              AND chat_message_id IS NULL
        ");
        $update->bind_param('ii', $messageId, $callId);
        $update->execute();

        if ($update->affected_rows !== 1) {
            $delete = $conn->prepare("
                DELETE FROM chat_messages
                WHERE id = ?
            ");
            $delete->bind_param('i', $messageId);
            $delete->execute();

            $existing = $conn->prepare("
                SELECT chat_message_id
                FROM rtc_calls
                WHERE id = ?
                LIMIT 1
            ");
            $existing->bind_param('i', $callId);
            $existing->execute();

            $row = $existing->get_result()->fetch_assoc();
            $messageId = (int)($row['chat_message_id'] ?? 0);
        }

        $conn->commit();

    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Call chat log error: ' . $e->getMessage());
        return null;
    }

    if ($messageId > 0) {
        try {
            chat_create_message_receipts(
                $conn,
                $messageId,
                $conversationId,
                $senderId
            );

            chat_touch_conversation($conn, $conversationId);
            chat_ws_push_new_message($conn, $messageId);
        } catch (Throwable $e) {
            error_log(
                'Call chat realtime push error: ' . $e->getMessage()
            );
        }
    }

    return $messageId > 0 ? $messageId : null;
}
