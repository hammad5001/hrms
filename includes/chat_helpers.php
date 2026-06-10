<?php

require_once __DIR__ . '/chat_schema.php';

function chat_json(bool $ok, $data = null, ?string $error = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'data' => $data, 'error' => $error]);
    exit;
}

/** @return int[] */
function chat_participant_user_ids(mysqli $conn, int $conversation_id): array {
    $stmt = $conn->prepare('SELECT user_id FROM chat_participants WHERE conversation_id = ?');
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
    $ids = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['user_id'];
    }
    return $ids;
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
    $sendErr = chat_assert_can_send($conn, $cid, $me_id);
    if ($sendErr) {
        chat_json(false, null, $sendErr);
    }

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

    chat_ws_push_new_message($conn, $mid);

    $broadcast = chat_fetch_message_broadcast($conn, $mid);
    chat_json(true, [
        'message_id' => $mid,
        'file_url' => chat_public_file_url($stored),
        'msg_type' => $msg_type,
        'file_name' => $safeName,
        'status' => 'sent',
        'message' => $broadcast,
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

function chat_user_avatar_color(int $user_id): string {
    $colors = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777'];
    return $colors[abs($user_id) % count($colors)];
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
        'avatar_url' => chat_public_avatar_url($row['chat_avatar'] ?? ''),
        'avatar_color' => chat_user_avatar_color((int)$row['id']),
    ];
}

/** Direct-chat peer row (excludes current user). */
function chat_direct_peer_row(mysqli $conn, int $conversation_id, int $me_id): ?array {
    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.employee_code, u.department, u.designation,
               u.portal_role, u.chat_avatar
        FROM chat_participants p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.conversation_id = ? AND p.user_id != ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $conversation_id, $me_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function chat_delete_stored_avatar(?string $stored): void {
    if (!$stored) {
        return;
    }
    $path = chat_avatar_dir() . DIRECTORY_SEPARATOR . basename($stored);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** Resize and save profile photo (JPEG). Returns false on failure. */
function chat_save_avatar_image(string $tmpPath, string $destPath): bool {
    if (!function_exists('imagecreatefromstring')) {
        return is_uploaded_file($tmpPath) && move_uploaded_file($tmpPath, $destPath);
    }
    $raw = @file_get_contents($tmpPath);
    if ($raw === false) {
        return false;
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return is_uploaded_file($tmpPath) && move_uploaded_file($tmpPath, $destPath);
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $max = 400;
    $scale = min(1.0, $max / max($w, $h, 1));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $ok = imagejpeg($dst, $destPath, 88);
    imagedestroy($dst);
    return $ok;
}

/** Upload or replace the signed-in user's chat profile photo. */
function chat_process_avatar_upload(mysqli $conn, int $me_id, array $me_row): void {
    if (empty($_FILES['file'])) {
        chat_json(false, null, 'No image received.');
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        chat_json(false, null, chat_upload_error_message((int)$file['error']));
    }
    $maxSize = 3 * 1024 * 1024;
    if ((int)$file['size'] > $maxSize) {
        chat_json(false, null, 'Image too large (max 3MB)');
    }
    $resolved = chat_resolve_upload_type($file['tmp_name'], $file['type'] ?? '', $file['name'] ?? 'photo.jpg');
    if (!$resolved || !str_starts_with($resolved['mime'], 'image/')) {
        chat_json(false, null, 'Use a JPG, PNG, GIF, or WEBP image.');
    }

    $dir = chat_avatar_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        chat_json(false, null, 'Avatar folder could not be created');
    }
    if (!is_writable($dir)) {
        chat_json(false, null, 'Avatar folder is not writable');
    }

    $stored = 'avatar_' . $me_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $dest = $dir . DIRECTORY_SEPARATOR . $stored;
    if (!chat_save_avatar_image($file['tmp_name'], $dest)) {
        chat_json(false, null, 'Could not process image');
    }

    $old = $me_row['chat_avatar'] ?? '';
    $upd = $conn->prepare('UPDATE users SET chat_avatar = ? WHERE id = ?');
    $upd->bind_param('si', $stored, $me_id);
    if (!$upd->execute()) {
        @unlink($dest);
        chat_json(false, null, 'Could not save profile photo');
    }
    chat_delete_stored_avatar($old);

    chat_json(true, [
        'avatar_url' => chat_public_avatar_url($stored),
        'avatar_color' => chat_user_avatar_color($me_id),
    ]);
}

function chat_remove_avatar(mysqli $conn, int $me_id, array $me_row): void {
    $old = $me_row['chat_avatar'] ?? '';
    if (!$old) {
        chat_json(true, ['avatar_url' => '', 'avatar_color' => chat_user_avatar_color($me_id)]);
    }
    $upd = $conn->prepare('UPDATE users SET chat_avatar = NULL WHERE id = ?');
    $upd->bind_param('i', $me_id);
    $upd->execute();
    chat_delete_stored_avatar($old);
    chat_json(true, ['avatar_url' => '', 'avatar_color' => chat_user_avatar_color($me_id)]);
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
    require_once __DIR__ . '/chat_redis.php';
    $cached = chat_redis_get_unread($user_id, $conversation_id);
    if ($cached >= 0) {
        return $cached;
    }
    $count = chat_unread_count_db($conn, $conversation_id, $user_id);
    chat_redis_set_unread($user_id, $conversation_id, $count);
    return $count;
}

function chat_unread_count_db(mysqli $conn, int $conversation_id, int $user_id): int {
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
    require_once __DIR__ . '/chat_redis.php';
    chat_redis_invalidate_unread($user_id, $conversation_id);
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
    require_once __DIR__ . '/chat_redis.php';
    chat_redis_online_touch($user_id);
    $stmt = $conn->prepare('UPDATE chat_participants SET last_active_at = NOW() WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

function chat_set_typing(mysqli $conn, int $conversation_id, int $user_id, bool $typing): void {
    require_once __DIR__ . '/chat_redis.php';
    chat_redis_set_typing($conversation_id, $user_id, $typing);
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
    require_once __DIR__ . '/chat_redis.php';
    if ($conv_type !== 'direct') {
        $isTyping = chat_redis_available()
            ? chat_redis_group_any_typing($conversation_id, $me_id)
            : false;
        if (!$isTyping) {
            $typing = $conn->prepare("
                SELECT COUNT(*) AS c FROM chat_participants
                WHERE conversation_id = ? AND user_id != ? AND typing_until > NOW()
            ");
            $typing->bind_param('ii', $conversation_id, $me_id);
            $typing->execute();
            $isTyping = (int)($typing->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        }
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

    $peer_id_stmt = $conn->prepare('SELECT user_id FROM chat_participants WHERE conversation_id = ? AND user_id != ? LIMIT 1');
    $peer_id_stmt->bind_param('ii', $conversation_id, $me_id);
    $peer_id_stmt->execute();
    $peer_id = (int)($peer_id_stmt->get_result()->fetch_assoc()['user_id'] ?? 0);

    $isTyping = $peer_id && chat_redis_is_typing($conversation_id, $peer_id);
    if (!$isTyping && !empty($peer['typing_until']) && strtotime($peer['typing_until']) > time()) {
        $isTyping = true;
    }
    if ($isTyping) {
        return ['status' => 'typing', 'label' => 'typing…', 'typing' => true, 'last_seen' => null];
    }

    if ($peer_id && chat_redis_is_online($peer_id)) {
        return ['status' => 'online', 'label' => 'online', 'typing' => false, 'last_seen' => $peer['last_active_at'] ?? null];
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

/** Message payload for WebSocket clients (is_mine set per viewer in JS). */
function chat_fetch_message_broadcast(mysqli $conn, int $message_id): ?array {
    $stmt = $conn->prepare("
        SELECT m.id, m.conversation_id, m.sender_id, m.body, m.msg_type,
               m.file_name, m.file_path, m.file_size, m.created_at,
               m.is_edited, m.is_deleted, m.edited_at,
               u.full_name AS sender_name, u.chat_avatar AS sender_avatar
        FROM chat_messages m
        INNER JOIN users u ON u.id = m.sender_id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $message_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    if (!empty($row['file_path'])) {
        $row['file_url'] = chat_public_file_url($row['file_path']);
    } else {
        $row['file_url'] = null;
    }
    unset($row['file_path']);
    $row['sender_avatar_url'] = chat_public_avatar_url($row['sender_avatar'] ?? '');
    unset($row['sender_avatar']);
    $row['sender_avatar_color'] = chat_user_avatar_color((int)$row['sender_id']);
    $row['id'] = (int)$row['id'];
    $row['conversation_id'] = (int)$row['conversation_id'];
    $row['sender_id'] = (int)$row['sender_id'];
    $row['is_edited'] = (int)($row['is_edited'] ?? 0);
    $row['is_deleted'] = (int)($row['is_deleted'] ?? 0);
    return $row;
}

function chat_ws_push_new_message(mysqli $conn, int $message_id): void {
    require_once __DIR__ . '/chat_ws.php';
    require_once __DIR__ . '/chat_redis.php';
    $msg = chat_fetch_message_broadcast($conn, $message_id);
    if (!$msg) {
        return;
    }
    $cid = (int)$msg['conversation_id'];
    chat_redis_sync_conv_members($conn, $cid);
    foreach (chat_participant_user_ids($conn, $cid) as $uid) {
        if ($uid !== (int)$msg['sender_id']) {
            $cur = chat_redis_get_unread($uid, $cid);
            $next = ($cur >= 0 ? $cur : chat_unread_count_db($conn, $cid, $uid)) + 1;
            chat_redis_set_unread($uid, $cid, $next);
        }
    }
    chat_ws_notify_conversation($conn, $cid, 'message.new', ['message' => $msg]);
    chat_ws_notify_inbox($conn, $cid);
}

/** True if either user blocked the other. */
function chat_is_blocked(mysqli $conn, int $user_a, int $user_b): bool {
    if ($user_a <= 0 || $user_b <= 0 || $user_a === $user_b) {
        return false;
    }
    $stmt = $conn->prepare('SELECT 1 FROM chat_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1');
    $stmt->bind_param('iiii', $user_a, $user_b, $user_b, $user_a);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function chat_get_participant_status(mysqli $conn, int $conversation_id, int $user_id): string {
    $stmt = $conn->prepare('SELECT participant_status FROM chat_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $conversation_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['participant_status'] ?? 'active';
}

function chat_direct_peer_id(mysqli $conn, int $conversation_id, int $me_id): ?int {
    $stmt = $conn->prepare('SELECT user_id FROM chat_participants WHERE conversation_id = ? AND user_id != ? LIMIT 1');
    $stmt->bind_param('ii', $conversation_id, $me_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['user_id'] : null;
}

/** @return string|null Error message if sending is not allowed. */
function chat_assert_can_send(mysqli $conn, int $conversation_id, int $sender_id): ?string {
    $convStmt = $conn->prepare('SELECT type FROM chat_conversations WHERE id = ? LIMIT 1');
    $convStmt->bind_param('i', $conversation_id);
    $convStmt->execute();
    $conv = $convStmt->get_result()->fetch_assoc();
    if (!$conv) {
        return 'Conversation not found';
    }
    if (($conv['type'] ?? '') !== 'direct') {
        return null;
    }
    $peer_id = chat_direct_peer_id($conn, $conversation_id, $sender_id);
    if (!$peer_id) {
        return null;
    }
    if (chat_is_blocked($conn, $sender_id, $peer_id)) {
        return 'You cannot message this user';
    }
    $my_status = chat_get_participant_status($conn, $conversation_id, $sender_id);
    $peer_status = chat_get_participant_status($conn, $conversation_id, $peer_id);
    if ($my_status === 'declined') {
        return 'This conversation was declined';
    }
    if ($my_status === 'pending') {
        return 'Accept the message request to reply';
    }
    if ($peer_status === 'declined') {
        return 'This user declined your message request';
    }
    return null;
}

function chat_conversation_meta_for_user(mysqli $conn, int $conversation_id, int $me_id, array $conv): array {
    $my_status = chat_get_participant_status($conn, $conversation_id, $me_id);
    $meta = [
        'my_status' => $my_status,
        'is_request' => false,
        'can_reply' => true,
        'peer_id' => null,
    ];
    if (($conv['type'] ?? '') === 'direct') {
        $peer_id = chat_direct_peer_id($conn, $conversation_id, $me_id);
        $meta['peer_id'] = $peer_id;
        $peer_status = $peer_id ? chat_get_participant_status($conn, $conversation_id, $peer_id) : 'active';
        $meta['peer_status'] = $peer_status;
        $meta['is_request'] = ($my_status === 'pending');
        $meta['can_reply'] = ($my_status !== 'pending' && $my_status !== 'declined');
        if ($peer_id && chat_is_blocked($conn, $me_id, $peer_id)) {
            $meta['is_blocked'] = true;
            $meta['can_reply'] = false;
        }
    }
    return $meta;
}

function chat_add_participant(mysqli $conn, int $conversation_id, int $user_id, string $status = 'active'): void {
    $stmt = $conn->prepare('INSERT INTO chat_participants (conversation_id, user_id, participant_status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE participant_status = VALUES(participant_status)');
    $stmt->bind_param('iis', $conversation_id, $user_id, $status);
    $stmt->execute();
}

/** Remove messages hidden by this viewer. */
function chat_filter_hidden_messages(mysqli $conn, array $messages, int $viewer_id): array {
    if (empty($messages)) {
        return $messages;
    }
    $ids = array_map(fn($m) => (int)$m['id'], $messages);
    $ids = array_filter($ids, fn($id) => $id > 0);
    if (empty($ids)) {
        return $messages;
    }
    $ph = implode(',', $ids);
    $hidden = [];
    $res = $conn->query("SELECT message_id FROM chat_message_hides WHERE user_id = " . (int)$viewer_id . " AND message_id IN ($ph)");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $hidden[(int)$row['message_id']] = true;
        }
    }
    return array_values(array_filter($messages, fn($m) => !isset($hidden[(int)$m['id']])));
}
