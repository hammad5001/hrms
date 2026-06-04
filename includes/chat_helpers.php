<?php

require_once __DIR__ . '/chat_schema.php';

function chat_json(bool $ok, $data = null, ?string $error = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'data' => $data, 'error' => $error]);
    exit;
}

function chat_user_is_participant(mysqli $conn, int $conversation_id, int $user_id): bool {
    if ($conversation_id <= 0 || $user_id <= 0) {
        return false;
    }
    $stmt = $conn->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $conversation_id, $user_id);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

/** Conversation id from URL / form / header (multipart-safe). */
function chat_parse_conversation_id(): int {
    $raw = [
        $_GET['conversation_id'] ?? null,
        $_POST['conversation_id'] ?? null,
        $_SERVER['HTTP_X_CHAT_CONVERSATION_ID'] ?? null,
    ];
    foreach ($raw as $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $id = (int)$v;
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

/** Repair missing participant row for legitimate users (creator or prior sender). */
function chat_ensure_participant_row(mysqli $conn, int $conversation_id, int $user_id): void {
    if ($conversation_id <= 0 || $user_id <= 0 || chat_user_is_participant($conn, $conversation_id, $user_id)) {
        return;
    }

    $conv = $conn->prepare('SELECT created_by FROM chat_conversations WHERE id = ? LIMIT 1');
    $conv->bind_param('i', $conversation_id);
    $conv->execute();
    $row = $conv->get_result()->fetch_assoc();
    if (!$row) {
        return;
    }
    if ((int)$row['created_by'] === $user_id) {
        $ins = $conn->prepare('INSERT IGNORE INTO chat_participants (conversation_id, user_id) VALUES (?, ?)');
        $ins->bind_param('ii', $conversation_id, $user_id);
        $ins->execute();
        return;
    }

    $hasMsg = $conn->prepare('SELECT 1 FROM chat_messages WHERE conversation_id = ? AND sender_id = ? LIMIT 1');
    $hasMsg->bind_param('ii', $conversation_id, $user_id);
    $hasMsg->execute();
    if ($hasMsg->get_result()->fetch_row()) {
        $ins = $conn->prepare('INSERT IGNORE INTO chat_participants (conversation_id, user_id) VALUES (?, ?)');
        $ins->bind_param('ii', $conversation_id, $user_id);
        $ins->execute();
    }
}

function chat_require_conversation_access(mysqli $conn, int $conversation_id, int $user_id): void {
    if ($conversation_id <= 0) {
        chat_json(false, null, 'Open a chat first, then attach a file.');
    }
    $chk = $conn->prepare('SELECT id FROM chat_conversations WHERE id = ? LIMIT 1');
    $chk->bind_param('i', $conversation_id);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        chat_json(false, null, 'Conversation not found');
    }
    if (!chat_user_is_participant($conn, $conversation_id, $user_id)) {
        chat_ensure_participant_row($conn, $conversation_id, $user_id);
    }
    if (!chat_user_is_participant($conn, $conversation_id, $user_id)) {
        chat_json(false, null, 'Conversation not found');
    }
}

/** Handle photo/file upload (never reads php://input). */
function chat_process_upload(mysqli $conn, int $me_id): void {
    $cid = chat_parse_conversation_id();
    chat_require_conversation_access($conn, $cid, $me_id);

    if (empty($_FILES['file'])) {
        $hint = empty($_POST) && empty($_FILES)
            ? ' Upload may exceed server limit — try a smaller file.'
            : '';
        chat_json(false, null, 'No file received.' . $hint);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        chat_json(false, null, chat_upload_error_message((int)$file['error']));
    }

    $maxSize = 10 * 1024 * 1024;
    if ((int)$file['size'] > $maxSize) {
        chat_json(false, null, 'File too large (max 10MB)');
    }

    $resolved = chat_resolve_upload_type(
        $file['tmp_name'],
        $file['type'] ?? '',
        $file['name'] ?? 'file'
    );
    if (!$resolved) {
        chat_json(false, null, 'File type not allowed. Use JPG, PNG, GIF, WEBP, PDF, or DOC.');
    }

    $uploadDir = chat_upload_dir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        chat_json(false, null, 'Upload folder could not be created');
    }
    if (!is_writable($uploadDir)) {
        chat_json(false, null, 'Upload folder is not writable on server');
    }

    $ext = $resolved['ext'];
    $mime = $resolved['mime'];
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    if ($safeName === '' || $safeName === '_') {
        $safeName = 'file.' . $ext;
    }
    if (strlen($safeName) > 120) {
        $safeName = substr($safeName, -120);
    }

    $stored = $cid . '_' . $me_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $stored;

    if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dest)) {
        chat_json(false, null, 'Could not save file on server');
    }

    $msg_type = str_starts_with($mime, 'image/') ? 'image' : 'file';
    $body = $msg_type === 'image' ? '' : ('📎 ' . $safeName);
    $fileSize = (int)$file['size'];

    $stmt = $conn->prepare("
        INSERT INTO chat_messages (conversation_id, sender_id, body, msg_type, file_name, file_path, file_size)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iissssi', $cid, $me_id, $body, $msg_type, $safeName, $stored, $fileSize);
    if (!$stmt->execute()) {
        @unlink($dest);
        chat_json(false, null, 'Could not save message');
    }

    $mid = (int)$conn->insert_id;
    chat_create_message_receipts($conn, $mid, $cid, $me_id);
    chat_touch_conversation($conn, $cid);
    chat_mark_conversation_read($conn, $cid, $me_id);

    chat_json(true, [
        'message_id' => $mid,
        'file_url' => chat_public_file_url($stored),
        'msg_type' => $msg_type,
        'file_name' => $safeName,
        'status' => 'sent',
    ]);
}

function chat_touch_conversation(mysqli $conn, int $conversation_id): void {
    $stmt = $conn->prepare('UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
}

function chat_find_direct(mysqli $conn, int $user_a, int $user_b): ?int {
    $stmt = $conn->prepare("
        SELECT c.id FROM chat_conversations c
        INNER JOIN chat_participants p1 ON p1.conversation_id = c.id AND p1.user_id = ?
        INNER JOIN chat_participants p2 ON p2.conversation_id = c.id AND p2.user_id = ?
        WHERE c.type = 'direct'
        LIMIT 1
    ");
    $stmt->bind_param('ii', $user_a, $user_b);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['id'] : null;
}

function chat_format_user(array $row): array {
    return [
        'id' => (int)$row['id'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'employee_code' => $row['employee_code'] ?? '',
        'department' => $row['department'] ?? '',
        'designation' => $row['designation'] ?? '',
        'portal_role' => $row['portal_role'] ?? '',
    ];
}

function chat_conversation_title(mysqli $conn, array $conv, int $me_id): string {
    if ($conv['type'] === 'group' && !empty($conv['title'])) {
        return $conv['title'];
    }
    if ($conv['type'] === 'direct') {
        $stmt = $conn->prepare("
            SELECT u.full_name FROM chat_participants p
            INNER JOIN users u ON u.id = p.user_id
            WHERE p.conversation_id = ? AND p.user_id != ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $conv['id'], $me_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row['full_name'] ?? 'Chat';
    }
    return $conv['title'] ?? 'Group';
}

function chat_unread_count(mysqli $conn, int $conversation_id, int $user_id): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM chat_messages m
        INNER JOIN chat_participants p ON p.conversation_id = m.conversation_id AND p.user_id = ?
        WHERE m.conversation_id = ?
        AND m.sender_id != ?
        AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)
    ");
    $stmt->bind_param('iii', $user_id, $conversation_id, $user_id);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
}

/** @return int[] */
function chat_recipient_ids(mysqli $conn, int $conversation_id, int $exclude_user_id): array {
    $stmt = $conn->prepare('SELECT user_id FROM chat_participants WHERE conversation_id = ? AND user_id != ?');
    $stmt->bind_param('ii', $conversation_id, $exclude_user_id);
    $stmt->execute();
    $ids = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['user_id'];
    }
    return $ids;
}

function chat_create_message_receipts(mysqli $conn, int $message_id, int $conversation_id, int $sender_id): void {
    foreach (chat_recipient_ids($conn, $conversation_id, $sender_id) as $uid) {
        $ins = $conn->prepare('INSERT IGNORE INTO chat_message_receipts (message_id, user_id) VALUES (?, ?)');
        $ins->bind_param('ii', $message_id, $uid);
        $ins->execute();
    }
}

function chat_backfill_receipts(mysqli $conn, int $conversation_id): void {
    $stmt = $conn->prepare("
        SELECT m.id, m.sender_id FROM chat_messages m
        WHERE m.conversation_id = ?
        AND NOT EXISTS (SELECT 1 FROM chat_message_receipts r WHERE r.message_id = m.id LIMIT 1)
        LIMIT 200
    ");
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        chat_create_message_receipts($conn, (int)$row['id'], $conversation_id, (int)$row['sender_id']);
    }
}

/** Mark incoming messages as delivered when recipient fetches them. */
function chat_mark_delivered_for_viewer(mysqli $conn, int $conversation_id, int $viewer_id, array $message_ids): void {
    if (empty($message_ids)) {
        return;
    }
    chat_backfill_receipts($conn, $conversation_id);
    $ids = array_map('intval', $message_ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "UPDATE chat_message_receipts r
            INNER JOIN chat_messages m ON m.id = r.message_id
            SET r.delivered_at = COALESCE(r.delivered_at, NOW())
            WHERE r.user_id = ? AND r.message_id IN ($placeholders)
            AND m.sender_id != ? AND r.delivered_at IS NULL";
    $stmt = $conn->prepare($sql);
    $bindTypes = 'i' . $types . 'i';
    $params = array_merge([$viewer_id], $ids, [$viewer_id]);
    $stmt->bind_param($bindTypes, ...$params);
    $stmt->execute();
}

function chat_mark_conversation_read(mysqli $conn, int $conversation_id, int $user_id): void {
    chat_backfill_receipts($conn, $conversation_id);

    $stmt = $conn->prepare("
        UPDATE chat_message_receipts r
        INNER JOIN chat_messages m ON m.id = r.message_id
        SET r.read_at = NOW(),
            r.delivered_at = COALESCE(r.delivered_at, NOW())
        WHERE m.conversation_id = ? AND r.user_id = ? AND m.sender_id != ?
        AND r.read_at IS NULL
    ");
    $stmt->bind_param('iii', $conversation_id, $user_id, $user_id);
    $stmt->execute();

    $p = $conn->prepare('UPDATE chat_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?');
    $p->bind_param('ii', $conversation_id, $user_id);
    $p->execute();
}

/** sent | delivered | read */
function chat_receipt_status(mysqli $conn, int $message_id): string {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
               SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count
        FROM chat_message_receipts WHERE message_id = ?
    ");
    $stmt->bind_param('i', $message_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $total = (int)($row['total'] ?? 0);
    if ($total === 0) {
        return 'sent';
    }
    $read = (int)($row['read_count'] ?? 0);
    $delivered = (int)($row['delivered'] ?? 0);
    if ($read >= $total) {
        return 'read';
    }
    if ($delivered >= $total) {
        return 'delivered';
    }
    return 'sent';
}

function chat_attach_message_meta(mysqli $conn, array &$messages, int $me_id): void {
    foreach ($messages as &$m) {
        if ((int)$m['sender_id'] === $me_id) {
            $m['status'] = chat_receipt_status($conn, (int)$m['id']);
        }
    }
    unset($m);
}

function chat_touch_user_active(mysqli $conn, int $user_id): void {
    $stmt = $conn->prepare('UPDATE chat_participants SET last_active_at = NOW() WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

function chat_set_typing(mysqli $conn, int $conversation_id, int $user_id, bool $typing): void {
    if ($typing) {
        $stmt = $conn->prepare('UPDATE chat_participants SET typing_until = DATE_ADD(NOW(), INTERVAL 8 SECOND) WHERE conversation_id = ? AND user_id = ?');
    } else {
        $stmt = $conn->prepare('UPDATE chat_participants SET typing_until = NULL WHERE conversation_id = ? AND user_id = ?');
    }
    $stmt->bind_param('ii', $conversation_id, $user_id);
    $stmt->execute();
    chat_touch_user_active($conn, $user_id);
}

/** @return array{status:string,label:string,typing:bool,last_seen:?string} */
function chat_peer_presence(mysqli $conn, int $conversation_id, int $me_id, string $conv_type): array {
    if ($conv_type !== 'direct') {
        $typing = $conn->prepare("
            SELECT COUNT(*) AS c FROM chat_participants
            WHERE conversation_id = ? AND user_id != ? AND typing_until > NOW()
        ");
        $typing->bind_param('ii', $conversation_id, $me_id);
        $typing->execute();
        $isTyping = (int)($typing->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        return [
            'status' => $isTyping ? 'typing' : 'group',
            'label' => $isTyping ? 'Someone is typing…' : 'Group chat',
            'typing' => $isTyping,
            'last_seen' => null,
        ];
    }

    $stmt = $conn->prepare("
        SELECT p.last_active_at, p.typing_until, u.full_name
        FROM chat_participants p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.conversation_id = ? AND p.user_id != ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $conversation_id, $me_id);
    $stmt->execute();
    $peer = $stmt->get_result()->fetch_assoc();
    if (!$peer) {
        return ['status' => 'offline', 'label' => '', 'typing' => false, 'last_seen' => null];
    }

    if (!empty($peer['typing_until']) && strtotime($peer['typing_until']) > time()) {
        return ['status' => 'typing', 'label' => 'typing…', 'typing' => true, 'last_seen' => null];
    }

    $last = $peer['last_active_at'] ?? null;
    if ($last && (time() - strtotime($last)) < 120) {
        return ['status' => 'online', 'label' => 'online', 'typing' => false, 'last_seen' => $last];
    }
    if ($last) {
        $ts = strtotime($last);
        $label = 'last seen ' . date('M j, g:i A', $ts);
        return ['status' => 'offline', 'label' => $label, 'typing' => false, 'last_seen' => $last];
    }
    return ['status' => 'offline', 'label' => 'offline', 'typing' => false, 'last_seen' => null];
}

function chat_last_seen_read_info(mysqli $conn, int $conversation_id, int $me_id, string $conv_type): ?array {
    if ($conv_type !== 'direct') {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT r.read_at, u.full_name
        FROM chat_message_receipts r
        INNER JOIN chat_messages m ON m.id = r.message_id
        INNER JOIN users u ON u.id = r.user_id
        WHERE m.conversation_id = ? AND m.sender_id = ?
        AND r.read_at IS NOT NULL
        ORDER BY r.read_at DESC
        LIMIT 1
    ");
    $stmt->bind_param('ii', $conversation_id, $me_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    return [
        'read_at' => $row['read_at'],
        'label' => 'Seen ' . date('M j, g:i A', strtotime($row['read_at'])),
    ];
}
