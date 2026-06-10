<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/chat_helpers.php';
require_once __DIR__ . '/../includes/chat_redis.php';
require_once __DIR__ . '/../includes/chat_security.php';
require_once __DIR__ . '/../includes/chat_ws.php';

ensure_chat_schema($conn);

$me = resolve_logged_in_user($conn);
if (!$me || ($me['status'] ?? 'active') !== 'active') {
    chat_json(false, null, 'Not authenticated');
}

$me_id = (int)$me['id'];
$branch = $me['company_branch'] ?? 'main';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '' && !chat_rate_limit_check($me_id, $action)) {
    chat_json(false, null, 'Too many requests. Please wait a moment.');
}

// Do NOT read php://input on multipart uploads — it empties $_POST / $_FILES on some PHP setups
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : [];
    }
}

chat_touch_user_active($conn, $me_id);

try {

switch ($action) {

    case 'me':
        chat_json(true, chat_format_user($me));
        break;

    case 'heartbeat':
        chat_touch_user_active($conn, $me_id);
        chat_json(true, ['ok' => true, 'redis' => chat_redis_available()]);
        break;

    case 'wsConfig':
        if (!chat_ws_enabled()) {
            chat_json(true, ['enabled' => false, 'url' => '', 'token' => '', 'redis' => false]);
        }
        chat_json(true, [
            'enabled' => true,
            'url' => chat_ws_client_url(),
            'token' => chat_ws_issue_token($me_id),
            'redis' => chat_redis_available(),
        ]);
        break;

    case 'setTyping':
        $cid = (int)($input['conversation_id'] ?? 0);
        $typing = !empty($input['typing']);
        if ($cid && chat_user_is_participant($conn, $cid, $me_id)) {
            chat_set_typing($conn, $cid, $me_id, $typing);
            chat_ws_notify_conversation($conn, $cid, 'typing', [
                'user_id' => $me_id,
                'user_name' => $me['full_name'] ?? 'User',
                'typing' => $typing,
            ]);
        }
        chat_json(true, ['ok' => true]);
        break;

    case 'getPresence':
        $cid = (int)($_GET['conversation_id'] ?? $input['conversation_id'] ?? 0);
        if (!$cid || !chat_user_is_participant($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        $convStmt = $conn->prepare('SELECT type FROM chat_conversations WHERE id = ?');
        $convStmt->bind_param('i', $cid);
        $convStmt->execute();
        $conv = $convStmt->get_result()->fetch_assoc();
        $presence = chat_peer_presence($conn, $cid, $me_id, $conv['type'] ?? 'direct');
        $seen = chat_last_seen_read_info($conn, $cid, $me_id, $conv['type'] ?? 'direct');
        chat_json(true, ['presence' => $presence, 'last_read' => $seen]);
        break;

    case 'messageStatuses':
        $cid = (int)($_GET['conversation_id'] ?? 0);
        $ids = $_GET['ids'] ?? '';
        if (!$cid || !chat_user_is_participant($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        $idList = array_filter(array_map('intval', explode(',', (string)$ids)));
        $statuses = [];
        foreach ($idList as $mid) {
            $chk = $conn->prepare('SELECT sender_id FROM chat_messages WHERE id = ? AND conversation_id = ?');
            $chk->bind_param('ii', $mid, $cid);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            if ($row && (int)$row['sender_id'] === $me_id) {
                $statuses[$mid] = chat_receipt_status($conn, $mid);
            }
        }
        chat_json(true, $statuses);
        break;

    case 'searchUsers':
        $q = trim($_GET['q'] ?? $input['q'] ?? '');
        if (strlen($q) < 1) {
            chat_json(true, []);
        }
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT id, full_name, email, employee_code, department, designation, portal_role, chat_avatar
            FROM users
            WHERE status = 'active' AND id != ?
            AND (
                email LIKE ? OR employee_code LIKE ? OR full_name LIKE ?
                OR phone LIKE ?
            )
            ORDER BY full_name ASC
            LIMIT 25
        ");
        $stmt->bind_param('issss', $me_id, $like, $like, $like, $like);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (chat_is_blocked($conn, $me_id, (int)$row['id'])) {
                continue;
            }
            $rows[] = chat_format_user($row);
        }
        chat_json(true, $rows);
        break;

    case 'unreadSummary':
        $stmt = $conn->prepare("
            SELECT c.id, c.type
            FROM chat_conversations c
            INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = ?
            WHERE p.participant_status != 'declined'
            ORDER BY c.updated_at DESC
            LIMIT 80
        ");
        $stmt->bind_param('i', $me_id);
        $stmt->execute();
        $total_unread = 0;
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $cid = (int)$row['id'];
            if (($row['type'] ?? '') === 'direct') {
                $peer = chat_direct_peer_row($conn, $cid, $me_id);
                if ($peer && chat_is_blocked($conn, $me_id, (int)$peer['id'])) {
                    continue;
                }
            }
            $total_unread += chat_unread_count($conn, $cid, $me_id);
        }
        chat_json(true, ['total_unread' => $total_unread]);
        break;

    case 'listConversations':
        $stmt = $conn->prepare("
            SELECT c.id, c.type, c.title, c.avatar_color, c.updated_at, p.participant_status AS my_status
            FROM chat_conversations c
            INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = ?
            WHERE p.participant_status != 'declined'
            ORDER BY c.updated_at DESC
            LIMIT 80
        ");
        $stmt->bind_param('i', $me_id);
        $stmt->execute();
        $list = [];
        $request_count = 0;
        $res = $stmt->get_result();
        while ($conv = $res->fetch_assoc()) {
            $cid = (int)$conv['id'];
            $conv['id'] = $cid;
            $my_status = $conv['my_status'] ?? 'active';
            $conv['my_status'] = $my_status;
            $conv['is_request'] = ($conv['type'] === 'direct' && $my_status === 'pending');
            if ($conv['is_request']) {
                $request_count++;
            }
            $conv['display_title'] = chat_conversation_title($conn, $conv, $me_id);
            $conv['unread'] = chat_unread_count($conn, $cid, $me_id);

            $last = $conn->prepare("
                SELECT m.body, m.msg_type, m.created_at, u.full_name AS sender_name, m.is_deleted
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ? AND m.is_deleted = 0
                ORDER BY m.id DESC LIMIT 1
            ");
            $last->bind_param('i', $cid);
            $last->execute();
            $conv['last_message'] = $last->get_result()->fetch_assoc() ?: null;

            if ($conv['type'] === 'direct') {
                $peer = chat_direct_peer_row($conn, $cid, $me_id);
                if ($peer) {
                    if (chat_is_blocked($conn, $me_id, (int)$peer['id'])) {
                        continue;
                    }
                    $conv['avatar_url'] = chat_public_avatar_url($peer['chat_avatar'] ?? '');
                    $conv['avatar_color'] = chat_user_avatar_color((int)$peer['id']);
                    $conv['peer_id'] = (int)$peer['id'];
                }
            }

            $list[] = $conv;
        }
        chat_json(true, ['items' => $list, 'request_count' => $request_count]);
        break;

    case 'getMessages':
        $cid = (int)($_GET['conversation_id'] ?? $input['conversation_id'] ?? 0);
        $after_id = (int)($_GET['after_id'] ?? $input['after_id'] ?? 0);
        $before_id = (int)($_GET['before_id'] ?? $input['before_id'] ?? 0);
        $limit = min(100, max(10, (int)($_GET['limit'] ?? $input['limit'] ?? 50)));
        if (!$cid || !chat_validate_conversation_access($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        chat_backfill_receipts($conn, $cid);
        chat_redis_sync_conv_members($conn, $cid);

        $has_more = false;
        $limitFetch = $limit + 1;
        if ($after_id > 0) {
            $stmt = $conn->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.body, m.msg_type,
                       m.file_name, m.file_path, m.file_size, m.created_at,
                       m.is_edited, m.is_deleted,
                       u.full_name AS sender_name, u.chat_avatar AS sender_avatar
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ? AND m.id > ? AND m.is_deleted = 0
                ORDER BY m.id ASC
                LIMIT ?
            ");
            $stmt->bind_param('iii', $cid, $after_id, $limit);
        } elseif ($before_id > 0) {
            $stmt = $conn->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.body, m.msg_type,
                       m.file_name, m.file_path, m.file_size, m.created_at,
                       m.is_edited, m.is_deleted,
                       u.full_name AS sender_name, u.chat_avatar AS sender_avatar
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ? AND m.id < ? AND m.is_deleted = 0
                ORDER BY m.id DESC
                LIMIT ?
            ");
            $stmt->bind_param('iii', $cid, $before_id, $limitFetch);
        } else {
            $stmt = $conn->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.body, m.msg_type,
                       m.file_name, m.file_path, m.file_size, m.created_at,
                       m.is_edited, m.is_deleted,
                       u.full_name AS sender_name, u.chat_avatar AS sender_avatar
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ? AND m.is_deleted = 0
                ORDER BY m.id DESC
                LIMIT ?
            ");
            $stmt->bind_param('ii', $cid, $limitFetch);
        }
        if (!$stmt) {
            chat_json(false, null, 'Could not load messages');
        }
        $stmt->execute();
        $messages = [];
        $incoming_ids = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['file_path'])) {
                $row['file_url'] = chat_public_file_url($row['file_path']);
            }
            $row['sender_avatar_url'] = chat_public_avatar_url($row['sender_avatar'] ?? '');
            unset($row['sender_avatar']);
            $row['sender_avatar_color'] = chat_user_avatar_color((int)$row['sender_id']);
            $row['is_mine'] = ((int)$row['sender_id'] === $me_id);
            if (!$row['is_mine']) {
                $incoming_ids[] = (int)$row['id'];
            }
            $messages[] = $row;
        }
        if ($before_id > 0 || ($after_id === 0 && $before_id === 0)) {
            if (count($messages) > $limit) {
                $has_more = true;
                array_pop($messages);
            }
            $messages = array_reverse($messages);
        }

        if (!empty($incoming_ids)) {
            chat_mark_delivered_for_viewer($conn, $cid, $me_id, $incoming_ids);
        }
        chat_attach_message_meta($conn, $messages, $me_id);
        $messages = chat_filter_hidden_messages($conn, $messages, $me_id);

        $parts = $conn->prepare("
            SELECT u.id, u.full_name, u.email, u.employee_code, u.department, u.designation,
                   u.portal_role, u.chat_avatar
            FROM chat_participants p
            INNER JOIN users u ON u.id = p.user_id
            WHERE p.conversation_id = ?
        ");
        $parts->bind_param('i', $cid);
        $parts->execute();
        $participants = [];
        $pr = $parts->get_result();
        while ($p = $pr->fetch_assoc()) {
            $participants[] = chat_format_user($p);
        }

        $convStmt = $conn->prepare('SELECT id, type, title, avatar_color FROM chat_conversations WHERE id = ?');
        $convStmt->bind_param('i', $cid);
        $convStmt->execute();
        $conv = $convStmt->get_result()->fetch_assoc();
        $conv['display_title'] = chat_conversation_title($conn, $conv, $me_id);
        $presence = chat_peer_presence($conn, $cid, $me_id, $conv['type'] ?? 'direct');
        $last_read = chat_last_seen_read_info($conn, $cid, $me_id, $conv['type'] ?? 'direct');

        $lrStmt = $conn->prepare('SELECT last_read_at FROM chat_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1');
        $lrStmt->bind_param('ii', $cid, $me_id);
        $lrStmt->execute();
        $last_read_at = $lrStmt->get_result()->fetch_assoc()['last_read_at'] ?? null;

        $conv_meta = chat_conversation_meta_for_user($conn, $cid, $me_id, $conv);

        chat_json(true, [
            'conversation' => $conv,
            'messages' => $messages,
            'participants' => $participants,
            'presence' => $presence,
            'last_read' => $last_read,
            'last_read_at' => $last_read_at,
            'unread_count' => chat_unread_count($conn, $cid, $me_id),
            'has_more' => $has_more,
            'meta' => $conv_meta,
        ]);
        break;

    case 'sendMessage':
        $cid = (int)($input['conversation_id'] ?? 0);
        $body = chat_sanitize_message_body($input['body'] ?? '');
        if (!$cid || !chat_validate_conversation_access($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        $sendErr = chat_assert_can_send($conn, $cid, $me_id);
        if ($sendErr) {
            chat_json(false, null, $sendErr);
        }
        if ($body === '') {
            chat_json(false, null, 'Message cannot be empty');
        }
        if (mb_strlen($body) > 8000) {
            chat_json(false, null, 'Message too long');
        }

        $stmt = $conn->prepare("
            INSERT INTO chat_messages (conversation_id, sender_id, body, msg_type)
            VALUES (?, ?, ?, 'text')
        ");
        $stmt->bind_param('iis', $cid, $me_id, $body);
        $stmt->execute();
        $mid = (int)$conn->insert_id;
        chat_create_message_receipts($conn, $mid, $cid, $me_id);
        chat_touch_conversation($conn, $cid);
        chat_mark_conversation_read($conn, $cid, $me_id);

        $broadcast = chat_fetch_message_broadcast($conn, $mid);
        chat_ws_push_new_message($conn, $mid);

        chat_json(true, [
            'message_id' => $mid,
            'status' => 'sent',
            'message' => $broadcast,
        ]);
        break;

    case 'markRead':
        $cid = (int)($input['conversation_id'] ?? 0);
        if ($cid && chat_user_is_participant($conn, $cid, $me_id)) {
            chat_mark_conversation_read($conn, $cid, $me_id);
            chat_ws_notify_conversation($conn, $cid, 'read', ['reader_id' => $me_id]);
        }
        chat_json(true, ['ok' => true]);
        break;

    case 'createDirect':
        $other_id = (int)($input['user_id'] ?? 0);
        if ($other_id <= 0 || $other_id === $me_id) {
            chat_json(false, null, 'Invalid user');
        }
        if (chat_is_blocked($conn, $me_id, $other_id)) {
            chat_json(false, null, 'You cannot message this user');
        }
        $check = $conn->prepare('SELECT id FROM users WHERE id = ? AND status = ? LIMIT 1');
        $active = 'active';
        $check->bind_param('is', $other_id, $active);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            chat_json(false, null, 'User not found');
        }

        $existing = chat_find_direct($conn, $me_id, $other_id);
        if ($existing) {
            $peer_status = chat_get_participant_status($conn, $existing, $other_id);
            if ($peer_status === 'declined') {
                $reopen = $conn->prepare("UPDATE chat_participants SET participant_status = 'pending' WHERE conversation_id = ? AND user_id = ?");
                $reopen->bind_param('ii', $existing, $other_id);
                $reopen->execute();
                chat_ws_notify_inbox($conn, $existing);
            }
            chat_json(true, [
                'conversation_id' => $existing,
                'my_status' => chat_get_participant_status($conn, $existing, $me_id),
            ]);
        }

        $ins = $conn->prepare("INSERT INTO chat_conversations (type, created_by, company_branch) VALUES ('direct', ?, ?)");
        $ins->bind_param('is', $me_id, $branch);
        $ins->execute();
        $cid = (int)$conn->insert_id;

        $status_active = 'active';
        $status_pending = 'pending';
        $part = $conn->prepare('INSERT INTO chat_participants (conversation_id, user_id, participant_status, last_read_at) VALUES (?, ?, ?, NOW())');
        $part->bind_param('iis', $cid, $me_id, $status_active);
        $part->execute();
        $part->bind_param('iis', $cid, $other_id, $status_pending);
        $part->execute();

        chat_ws_notify_inbox($conn, $cid);
        chat_json(true, ['conversation_id' => $cid, 'my_status' => 'active', 'is_new_request' => true]);
        break;

    case 'createGroup':
        $title = trim($input['title'] ?? '');
        $member_ids = $input['member_ids'] ?? [];
        if (!is_array($member_ids)) {
            $member_ids = [];
        }
        $member_ids = array_unique(array_map('intval', $member_ids));
        $member_ids = array_filter($member_ids, fn($id) => $id > 0 && $id !== $me_id);
        if ($title === '' || count($member_ids) < 1) {
            chat_json(false, null, 'Group name and at least one member required');
        }
        if (mb_strlen($title) > 150) {
            chat_json(false, null, 'Group name too long');
        }

        $colors = ['#6264a7', '#0078d4', '#107c10', '#d83b01', '#8764b8', '#00b7c3'];
        $color = $colors[array_rand($colors)];

        $ins = $conn->prepare("INSERT INTO chat_conversations (type, title, avatar_color, created_by, company_branch) VALUES ('group', ?, ?, ?, ?)");
        $ins->bind_param('ssis', $title, $color, $me_id, $branch);
        $ins->execute();
        $cid = (int)$conn->insert_id;

        $all = array_merge([$me_id], $member_ids);
        $part = $conn->prepare('INSERT INTO chat_participants (conversation_id, user_id) VALUES (?, ?)');
        foreach ($all as $uid) {
            $part->bind_param('ii', $cid, $uid);
            $part->execute();
        }

        $welcome = $me['full_name'] . ' created the group.';
        $msg = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, body, msg_type) VALUES (?, ?, ?, 'text')");
        $msg->bind_param('iis', $cid, $me_id, $welcome);
        $msg->execute();
        $welcome_id = (int)$conn->insert_id;
        chat_create_message_receipts($conn, $welcome_id, $cid, $me_id);
        chat_touch_conversation($conn, $cid);
        chat_ws_push_new_message($conn, $welcome_id);
        chat_ws_notify_inbox($conn, $cid);

        chat_json(true, ['conversation_id' => $cid]);
        break;

    case 'upload':
        chat_process_upload($conn, $me_id);
        break;

    case 'uploadProfilePhoto':
        chat_process_avatar_upload($conn, $me_id, $me);
        break;

    case 'removeProfilePhoto':
        chat_remove_avatar($conn, $me_id, $me);
        break;

    case 'editMessage':
        $mid  = (int)($input['message_id'] ?? 0);
        $body = chat_sanitize_message_body($input['body'] ?? '');
        if (!$mid || $body === '') {
            chat_json(false, null, 'Invalid request');
        }
        // Verify sender owns the message and it is not deleted
        $chk = $conn->prepare('SELECT conversation_id, sender_id, is_deleted FROM chat_messages WHERE id = ? LIMIT 1');
        $chk->bind_param('i', $mid);
        $chk->execute();
        $mrow = $chk->get_result()->fetch_assoc();
        if (!$mrow || (int)$mrow['sender_id'] !== $me_id) {
            chat_json(false, null, 'Not allowed');
        }
        if ((int)$mrow['is_deleted']) {
            chat_json(false, null, 'Cannot edit a deleted message');
        }
        $upd = $conn->prepare('UPDATE chat_messages SET body = ?, is_edited = 1, edited_at = NOW() WHERE id = ?');
        $upd->bind_param('si', $body, $mid);
        $upd->execute();
        $cid = (int)$mrow['conversation_id'];
        chat_touch_conversation($conn, $cid);
        chat_ws_notify_conversation($conn, $cid, 'message.edit', [
            'message_id' => $mid,
            'body' => $body,
        ]);
        chat_ws_notify_inbox($conn, $cid);
        chat_json(true, ['message_id' => $mid, 'body' => $body]);
        break;

    case 'deleteMessage':
        $mid  = (int)($input['message_id'] ?? 0);
        $forAll = !empty($input['for_all']); // true = delete for everyone
        if (!$mid) {
            chat_json(false, null, 'Invalid request');
        }
        $chk = $conn->prepare('SELECT conversation_id, sender_id FROM chat_messages WHERE id = ? LIMIT 1');
        $chk->bind_param('i', $mid);
        $chk->execute();
        $mrow = $chk->get_result()->fetch_assoc();
        if (!$mrow) {
            chat_json(false, null, 'Message not found');
        }
        // Only sender can delete for everyone; anyone can delete for themselves (future: per-user hide)
        if ($forAll && (int)$mrow['sender_id'] !== $me_id) {
            chat_json(false, null, 'Only the sender can delete for everyone');
        }
        if ($forAll) {
            $upd = $conn->prepare("UPDATE chat_messages SET is_deleted = 1, body = '', file_path = NULL, file_name = NULL WHERE id = ?");
            $upd->bind_param('i', $mid);
            $upd->execute();
        } else {
            $hide = $conn->prepare('INSERT IGNORE INTO chat_message_hides (user_id, message_id) VALUES (?, ?)');
            $hide->bind_param('ii', $me_id, $mid);
            $hide->execute();
        }
        $cid = (int)$mrow['conversation_id'];
        chat_touch_conversation($conn, $cid);
        if ($forAll) {
            chat_ws_notify_conversation($conn, $cid, 'message.delete', ['message_id' => $mid]);
            chat_ws_notify_inbox($conn, $cid);
        }
        chat_json(true, ['message_id' => $mid, 'for_all' => $forAll]);
        break;

    case 'listBlockedUsers':
        $stmt = $conn->prepare("
            SELECT u.id, u.full_name, u.email, u.employee_code, u.department, u.designation,
                   u.portal_role, u.chat_avatar, b.created_at AS blocked_at
            FROM chat_blocks b
            INNER JOIN users u ON u.id = b.blocked_id
            WHERE b.blocker_id = ?
            ORDER BY b.created_at DESC
            LIMIT 100
        ");
        $stmt->bind_param('i', $me_id);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $user = chat_format_user($row);
            $user['blocked_at'] = $row['blocked_at'];
            $rows[] = $user;
        }
        chat_json(true, ['items' => $rows, 'count' => count($rows)]);
        break;

    case 'blockUser':
        $other_id = (int)($input['user_id'] ?? 0);
        if ($other_id <= 0 || $other_id === $me_id) {
            chat_json(false, null, 'Invalid user');
        }
        $ins = $conn->prepare('INSERT IGNORE INTO chat_blocks (blocker_id, blocked_id) VALUES (?, ?)');
        $ins->bind_param('ii', $me_id, $other_id);
        $ins->execute();
        chat_json(true, ['ok' => true]);
        break;

    case 'unblockUser':
        $other_id = (int)($input['user_id'] ?? 0);
        if ($other_id <= 0) {
            chat_json(false, null, 'Invalid user');
        }
        $del = $conn->prepare('DELETE FROM chat_blocks WHERE blocker_id = ? AND blocked_id = ?');
        $del->bind_param('ii', $me_id, $other_id);
        $del->execute();
        chat_json(true, ['ok' => true]);
        break;

    case 'acceptRequest':
        $cid = (int)($input['conversation_id'] ?? 0);
        if (!$cid || chat_get_participant_status($conn, $cid, $me_id) !== 'pending') {
            chat_json(false, null, 'No pending message request');
        }
        $active = 'active';
        $upd = $conn->prepare("UPDATE chat_participants SET participant_status = ? WHERE conversation_id = ? AND user_id = ?");
        $upd->bind_param('sii', $active, $cid, $me_id);
        $upd->execute();
        chat_mark_conversation_read($conn, $cid, $me_id);
        chat_ws_notify_conversation($conn, $cid, 'request.accepted', ['conversation_id' => $cid, 'user_id' => $me_id]);
        chat_ws_notify_inbox($conn, $cid);
        chat_json(true, ['ok' => true]);
        break;

    case 'declineRequest':
        $cid = (int)($input['conversation_id'] ?? 0);
        if (!$cid || chat_get_participant_status($conn, $cid, $me_id) !== 'pending') {
            chat_json(false, null, 'No pending message request');
        }
        $declined = 'declined';
        $upd = $conn->prepare("UPDATE chat_participants SET participant_status = ? WHERE conversation_id = ? AND user_id = ?");
        $upd->bind_param('sii', $declined, $cid, $me_id);
        $upd->execute();
        chat_ws_notify_conversation($conn, $cid, 'request.declined', ['conversation_id' => $cid, 'user_id' => $me_id]);
        chat_json(true, ['ok' => true]);
        break;

    case 'clearChat':
        $cid = (int)($input['conversation_id'] ?? 0);
        if (!$cid || !chat_user_is_participant($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        // Mark all messages in this conversation as deleted (soft delete)
        $del = $conn->prepare("UPDATE chat_messages SET is_deleted = 1, body = '' WHERE conversation_id = ?");
        $del->bind_param('i', $cid);
        $del->execute();
        // Remove all receipts so counts reset
        $dr = $conn->prepare("DELETE r FROM chat_message_receipts r INNER JOIN chat_messages m ON m.id = r.message_id WHERE m.conversation_id = ?");
        $dr->bind_param('i', $cid);
        $dr->execute();
        chat_touch_conversation($conn, $cid);
        chat_ws_notify_conversation($conn, $cid, 'chat.cleared', []);
        chat_ws_notify_inbox($conn, $cid);
        chat_json(true, ['ok' => true]);
        break;

    case 'deleteChat':
        $cid = (int)($input['conversation_id'] ?? 0);
        if (!$cid || !chat_user_is_participant($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        // Remove the current user from the conversation participants
        $dp = $conn->prepare('DELETE FROM chat_participants WHERE conversation_id = ? AND user_id = ?');
        $dp->bind_param('ii', $cid, $me_id);
        $dp->execute();
        // If no participants remain, hard-delete the conversation and messages
        $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM chat_participants WHERE conversation_id = ?');
        $countStmt->bind_param('i', $cid);
        $countStmt->execute();
        $remaining = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($remaining === 0) {
            $conn->prepare("DELETE FROM chat_message_receipts WHERE message_id IN (SELECT id FROM chat_messages WHERE conversation_id = ?)")->bind_param('i', $cid);
            // Use subquery-safe approach
            $conn->query("DELETE r FROM chat_message_receipts r INNER JOIN chat_messages m ON m.id = r.message_id WHERE m.conversation_id = $cid");
            $conn->query("DELETE FROM chat_messages WHERE conversation_id = $cid");
            $conn->query("DELETE FROM chat_conversations WHERE id = $cid");
        }
        chat_json(true, ['ok' => true]);
        break;

    default:
        chat_json(false, null, 'Unknown action');
}

} catch (Throwable $e) {
    error_log('chat_api: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    chat_json(false, null, 'Server error. Please try again.');
}
