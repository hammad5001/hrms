<?php
/**
 * Dedicated chat file upload — does not read php://input (keeps $_FILES intact).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

ensure_chat_schema($conn);

$me = resolve_logged_in_user($conn);
if (!$me || ($me['status'] ?? 'active') !== 'active') {
    chat_json(false, null, 'Not authenticated');
}

$me_id = (int)$me['id'];
chat_touch_user_active($conn, $me_id);
chat_process_upload($conn, $me_id);
