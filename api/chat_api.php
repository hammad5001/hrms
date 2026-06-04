<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

ensure_chat_schema($conn);

$me = resolve_logged_in_user($conn);
if (!$me || ($me['status'] ?? 'active') !== 'active') {
    chat_json(false, null, 'Not authenticated');
}

$me_id = (int)$me['id'];
$branch = $me['company_branch'] ?? 'main';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

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

switch ($action) {

    case 'me':
        chat_json(true, chat_format_user($me));
        break;

    case 'heartbeat':
        chat_touch_user_active($conn, $me_id);
        chat_json(true, ['ok' => true]);
        break;

    case 'setTyping':
        $cid = (int)($input['conversation_id'] ?? 0);
        $typing = !empty($input['typing']);
        if ($cid && chat_user_is_participant($conn, $cid, $me_id)) {
            chat_set_typing($conn, $cid, $me_id, $typing);
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
            SELECT id, full_name, email, employee_code, department, designation, portal_role
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
            $rows[] = chat_format_user($row);
        }
        chat_json(true, $rows);
        break;

    case 'listConversations':
        $stmt = $conn->prepare("
            SELECT c.id, c.type, c.title, c.avatar_color, c.updated_at
            FROM chat_conversations c
            INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = ?
            ORDER BY c.updated_at DESC
            LIMIT 80
        ");
        $stmt->bind_param('i', $me_id);
        $stmt->execute();
        $list = [];
        $res = $stmt->get_result();
        while ($conv = $res->fetch_assoc()) {
            $cid = (int)$conv['id'];
            $conv['id'] = $cid;
            $conv['display_title'] = chat_conversation_title($conn, $conv, $me_id);
            $conv['unread'] = chat_unread_count($conn, $cid, $me_id);

            $last = $conn->prepare("
                SELECT m.body, m.msg_type, m.created_at, u.full_name AS sender_name
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ?
                ORDER BY m.id DESC LIMIT 1
            ");
            $last->bind_param('i', $cid);
            $last->execute();
            $conv['last_message'] = $last->get_result()->fetch_assoc() ?: null;

            $list[] = $conv;
        }
        chat_json(true, $list);
        break;

    case 'getMessages':
        $cid = (int)($_GET['conversation_id'] ?? $input['conversation_id'] ?? 0);
        $after_id = (int)($_GET['after_id'] ?? $input['after_id'] ?? 0);
        if (!$cid || !chat_user_is_participant($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
        }
        chat_backfill_receipts($conn, $cid);

        if ($after_id > 0) {
            $stmt = $conn->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.body, m.msg_type,
                       m.file_name, m.file_path, m.file_size, m.created_at,
                       u.full_name AS sender_name
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ? AND m.id > ?
                ORDER BY m.id ASC
                LIMIT 100
            ");
            $stmt->bind_param('ii', $cid, $after_id);
        } else {
            $stmt = $conn->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.body, m.msg_type,
                       m.file_name, m.file_path, m.file_size, m.created_at,
                       u.full_name AS sender_name
                FROM chat_messages m
                INNER JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ?
                ORDER BY m.id DESC
                LIMIT 80
            ");
            $stmt->bind_param('i', $cid);
        }
        $stmt->execute();
        $messages = [];
        $incoming_ids = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['file_path'])) {
                $row['file_url'] = chat_public_file_url($row['file_path']);
            }
            $row['is_mine'] = ((int)$row['sender_id'] === $me_id);
            if (!$row['is_mine']) {
                $incoming_ids[] = (int)$row['id'];
            }
            $messages[] = $row;
        }
        if ($after_id === 0) {
            $messages = array_reverse($messages);
        }

        if (!empty($incoming_ids)) {
            chat_mark_delivered_for_viewer($conn, $cid, $me_id, $incoming_ids);
        }
        chat_attach_message_meta($conn, $messages, $me_id);

        $parts = $conn->prepare("
            SELECT u.id, u.full_name, u.email, u.employee_code
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

        chat_json(true, [
            'conversation' => $conv,
            'messages' => $messages,
            'participants' => $participants,
            'presence' => $presence,
            'last_read' => $last_read,
        ]);
        break;

    case 'sendMessage':
        $cid = (int)($input['conversation_id'] ?? 0);
        $body = trim($input['body'] ?? '');
        if (!$cid || !chat_user_is_participant($conn, $cid, $me_id)) {
            chat_json(false, null, 'Conversation not found');
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

        chat_json(true, ['message_id' => $mid, 'status' => 'sent']);
        break;

    case 'markRead':
        $cid = (int)($input['conversation_id'] ?? 0);
        if ($cid && chat_user_is_participant($conn, $cid, $me_id)) {
            chat_mark_conversation_read($conn, $cid, $me_id);
        }
        chat_json(true, ['ok' => true]);
        break;

    case 'createDirect':
        $other_id = (int)($input['user_id'] ?? 0);
        if ($other_id <= 0 || $other_id === $me_id) {
            chat_json(false, null, 'Invalid user');
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
            chat_json(true, ['conversation_id' => $existing]);
        }

        $ins = $conn->prepare("INSERT INTO chat_conversations (type, created_by, company_branch) VALUES ('direct', ?, ?)");
        $ins->bind_param('is', $me_id, $branch);
        $ins->execute();
        $cid = (int)$conn->insert_id;

        $part = $conn->prepare('INSERT INTO chat_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW())');
        foreach ([$me_id, $other_id] as $uid) {
            $part->bind_param('ii', $cid, $uid);
            $part->execute();
        }
        chat_json(true, ['conversation_id' => $cid]);
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

        chat_json(true, ['conversation_id' => $cid]);
        break;

    case 'upload':
        chat_process_upload($conn, $me_id);
        break;

    default:
        chat_json(false, null, 'Unknown action');
}
