<?php
/**
 * Internal Mail API Module
 * Balitech HRMS Employee Portal Internal Mail Service
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/session_user.php';

// Helper response function
function mail_respond(bool $success, $data = null, string $message = '', int $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Ensure schema auto-check
function ensure_mail_tables($conn) {
    static $checked = false;
    if ($checked) return;
    $conn->query("CREATE TABLE IF NOT EXISTS `internal_mails` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `sender_id` INT NOT NULL,
      `parent_id` INT DEFAULT NULL,
      `subject` VARCHAR(255) NOT NULL,
      `body` LONGTEXT NOT NULL,
      `status` ENUM('draft', 'sent') NOT NULL DEFAULT 'sent',
      `importance` ENUM('normal', 'high', 'low') NOT NULL DEFAULT 'normal',
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_sender` (`sender_id`),
      INDEX `idx_parent` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `mail_recipients` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `mail_id` INT NOT NULL,
      `recipient_id` INT NOT NULL,
      `recipient_type` ENUM('to', 'cc', 'bcc') NOT NULL DEFAULT 'to',
      `is_read` TINYINT(1) NOT NULL DEFAULT 0,
      `read_at` DATETIME DEFAULT NULL,
      `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
      `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
      INDEX `idx_mail` (`mail_id`),
      INDEX `idx_recipient` (`recipient_id`, `is_read`),
      FOREIGN KEY (`mail_id`) REFERENCES `internal_mails`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `mail_attachments` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `mail_id` INT NOT NULL,
      `file_name` VARCHAR(255) NOT NULL,
      `file_path` VARCHAR(255) NOT NULL,
      `file_size` INT NOT NULL DEFAULT 0,
      `file_type` VARCHAR(100) NOT NULL DEFAULT '',
      `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_mail_attach` (`mail_id`),
      FOREIGN KEY (`mail_id`) REFERENCES `internal_mails`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `user_mail_signatures` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL UNIQUE,
      `signature_text` TEXT NOT NULL,
      `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
      `default_importance` ENUM('normal', 'high', 'low') NOT NULL DEFAULT 'normal',
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_user_sig` (`user_id`),
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Seamless schema migration for Gmail-style features (is_starred, is_important)
    $chkStar = $conn->query("SHOW COLUMNS FROM `mail_recipients` LIKE 'is_starred'");
    if ($chkStar && $chkStar->num_rows === 0) {
        @$conn->query("ALTER TABLE `mail_recipients` ADD COLUMN `is_starred` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_deleted`");
    }
    $chkImp = $conn->query("SHOW COLUMNS FROM `mail_recipients` LIKE 'is_important'");
    if ($chkImp && $chkImp->num_rows === 0) {
        @$conn->query("ALTER TABLE `mail_recipients` ADD COLUMN `is_important` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_starred`");
    }

    $checked = true;
}

ensure_mail_tables($conn);

// Authenticate user
$user = resolve_logged_in_user($conn);
if (!$user || ($user['status'] ?? 'active') !== 'active') {
    mail_respond(false, null, 'Not authenticated', 401);
}

$user_id = (int)$user['id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (!$action) {
    $rawBody = file_get_contents('php://input');
    $inputData = json_decode($rawBody, true);
    if (is_array($inputData) && !empty($inputData['action'])) {
        $action = $inputData['action'];
    }
} else {
    $inputData = [];
}

if (empty($inputData)) {
    $rawBody = file_get_contents('php://input');
    $parsed = json_decode($rawBody, true);
    if (is_array($parsed)) {
        $inputData = $parsed;
    }
}

switch ($action) {

    // 1. Search employees for autocomplete composer
    case 'search_employees':
        $query = trim($_GET['q'] ?? ($_POST['q'] ?? ($inputData['q'] ?? '')));
        $sql = "SELECT id, full_name, email, employee_code, department, designation, portal_role 
                FROM users 
                WHERE status = 'active'";
        $params = [];
        $types = '';
        if ($query !== '') {
            $sql .= " AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ? OR department LIKE ? OR designation LIKE ?)";
            $searchTerm = '%' . $query . '%';
            $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
            $types = 'sssss';
        }
        $sql .= " ORDER BY full_name ASC LIMIT 25";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $employees = [];
        while ($row = $res->fetch_assoc()) {
            $employees[] = [
                'id'            => (int)$row['id'],
                'full_name'     => $row['full_name'],
                'email'         => $row['email'] ?: ($row['employee_code'] . '@balitech.internal'),
                'employee_code' => $row['employee_code'],
                'department'    => $row['department'] ?? '',
                'designation'   => $row['designation'] ?? '',
                'portal_role'   => $row['portal_role'] ?? ''
            ];
        }
        mail_respond(true, $employees);
        break;

    // 2. Unread mail counter & folder metric counts
    case 'unread_count':
    case 'folder_counts':
        $stUnread = $conn->prepare("SELECT COUNT(*) AS cnt FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id WHERE mr.recipient_id = ? AND mr.is_read = 0 AND mr.is_deleted = 0 AND mr.is_archived = 0 AND m.status = 'sent'");
        $stUnread->bind_param('i', $user_id);
        $stUnread->execute();
        $cUnread = (int)($stUnread->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stInbox = $conn->prepare("SELECT COUNT(*) AS cnt FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id WHERE mr.recipient_id = ? AND mr.is_deleted = 0 AND mr.is_archived = 0 AND m.status = 'sent'");
        $stInbox->bind_param('i', $user_id);
        $stInbox->execute();
        $cInbox = (int)($stInbox->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stStarred = $conn->prepare("SELECT COUNT(*) AS cnt FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id WHERE mr.recipient_id = ? AND mr.is_starred = 1 AND mr.is_deleted = 0 AND m.status = 'sent'");
        $stStarred->bind_param('i', $user_id);
        $stStarred->execute();
        $cStarred = (int)($stStarred->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stImp = $conn->prepare("SELECT COUNT(*) AS cnt FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id WHERE mr.recipient_id = ? AND (mr.is_important = 1 OR m.importance = 'high') AND mr.is_deleted = 0 AND m.status = 'sent'");
        $stImp->bind_param('i', $user_id);
        $stImp->execute();
        $cImp = (int)($stImp->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stSent = $conn->prepare("SELECT COUNT(*) AS cnt FROM internal_mails m WHERE m.sender_id = ? AND m.status = 'sent'");
        $stSent->bind_param('i', $user_id);
        $stSent->execute();
        $cSent = (int)($stSent->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stDrafts = $conn->prepare("SELECT COUNT(*) AS cnt FROM internal_mails m WHERE m.sender_id = ? AND m.status = 'draft'");
        $stDrafts->bind_param('i', $user_id);
        $stDrafts->execute();
        $cDrafts = (int)($stDrafts->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stArch = $conn->prepare("SELECT COUNT(*) AS cnt FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id WHERE mr.recipient_id = ? AND mr.is_archived = 1 AND mr.is_deleted = 0 AND m.status = 'sent'");
        $stArch->bind_param('i', $user_id);
        $stArch->execute();
        $cArch = (int)($stArch->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stTrash = $conn->prepare("SELECT COUNT(*) AS cnt FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id WHERE mr.recipient_id = ? AND mr.is_deleted = 1 AND m.status = 'sent'");
        $stTrash->bind_param('i', $user_id);
        $stTrash->execute();
        $cTrash = (int)($stTrash->get_result()->fetch_assoc()['cnt'] ?? 0);

        mail_respond(true, [
            'unread_count' => $cUnread,
            'inbox'        => $cInbox,
            'starred'      => $cStarred,
            'important'    => $cImp,
            'sent'         => $cSent,
            'drafts'       => $cDrafts,
            'archive'      => $cArch,
            'trash'        => $cTrash
        ]);
        break;

    // 3. Toggle Star
    case 'toggle_star':
        $mail_id = (int)($_POST['mail_id'] ?? ($_GET['mail_id'] ?? ($inputData['mail_id'] ?? 0)));
        if ($mail_id <= 0) mail_respond(false, null, 'Invalid mail ID');
        $st = $conn->prepare("SELECT id, is_starred FROM mail_recipients WHERE mail_id = ? AND recipient_id = ?");
        $st->bind_param('ii', $mail_id, $user_id);
        $st->execute();
        $rec = $st->get_result()->fetch_assoc();
        if (!$rec) {
            // Ensure entry if sender is toggling star
            $chkMail = $conn->prepare("SELECT id FROM internal_mails WHERE id = ? AND sender_id = ?");
            $chkMail->bind_param('ii', $mail_id, $user_id);
            $chkMail->execute();
            if ($chkMail->get_result()->num_rows === 0) mail_respond(false, null, 'Mail not found');
            $ins = $conn->prepare("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type, is_read, is_starred) VALUES (?, ?, 'to', 1, 1)");
            $ins->bind_param('ii', $mail_id, $user_id);
            $ins->execute();
            mail_respond(true, ['is_starred' => true]);
        }
        $newVal = $rec['is_starred'] ? 0 : 1;
        $up = $conn->prepare("UPDATE mail_recipients SET is_starred = ? WHERE id = ?");
        $up->bind_param('ii', $newVal, $rec['id']);
        $up->execute();
        mail_respond(true, ['is_starred' => (bool)$newVal]);
        break;

    // 4. Toggle Important
    case 'toggle_important':
        $mail_id = (int)($_POST['mail_id'] ?? ($_GET['mail_id'] ?? ($inputData['mail_id'] ?? 0)));
        if ($mail_id <= 0) mail_respond(false, null, 'Invalid mail ID');
        $st = $conn->prepare("SELECT id, is_important FROM mail_recipients WHERE mail_id = ? AND recipient_id = ?");
        $st->bind_param('ii', $mail_id, $user_id);
        $st->execute();
        $rec = $st->get_result()->fetch_assoc();
        if (!$rec) {
            $ins = $conn->prepare("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type, is_read, is_important) VALUES (?, ?, 'to', 1, 1)");
            $ins->bind_param('ii', $mail_id, $user_id);
            $ins->execute();
            mail_respond(true, ['is_important' => true]);
        }
        $newVal = $rec['is_important'] ? 0 : 1;
        $up = $conn->prepare("UPDATE mail_recipients SET is_important = ? WHERE id = ?");
        $up->bind_param('ii', $newVal, $rec['id']);
        $up->execute();
        mail_respond(true, ['is_important' => (bool)$newVal]);
        break;

    // 5. Archive Mail
    case 'archive_mail':
        $mail_ids = $inputData['mail_ids'] ?? ($_POST['mail_id'] ?? ($_GET['mail_id'] ?? []));
        if (!is_array($mail_ids)) $mail_ids = [$mail_ids];
        $ids = array_filter(array_map('intval', $mail_ids));
        if (empty($ids)) mail_respond(false, null, 'No valid mail IDs');
        foreach ($ids as $mId) {
            $chk = $conn->query("SELECT id FROM mail_recipients WHERE mail_id = $mId AND recipient_id = $user_id");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type, is_read) VALUES ($mId, $user_id, 'to', 1)");
            }
        }
        $inClause = implode(',', $ids);
        $conn->query("UPDATE mail_recipients SET is_archived = 1, is_deleted = 0 WHERE recipient_id = $user_id AND mail_id IN ($inClause)");
        mail_respond(true, null, 'Mail archived successfully');
        break;

    // 6. Trash Mail
    case 'trash_mail':
    case 'delete_mail':
        $mail_ids = $inputData['mail_ids'] ?? ($_POST['mail_id'] ?? ($_GET['mail_id'] ?? []));
        if (!is_array($mail_ids)) $mail_ids = [$mail_ids];
        $ids = array_filter(array_map('intval', $mail_ids));
        if (empty($ids)) mail_respond(false, null, 'No valid mail IDs');
        foreach ($ids as $mId) {
            $chk = $conn->query("SELECT id FROM mail_recipients WHERE mail_id = $mId AND recipient_id = $user_id");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type, is_read) VALUES ($mId, $user_id, 'to', 1)");
            }
        }
        $inClause = implode(',', $ids);
        $conn->query("UPDATE mail_recipients SET is_deleted = 1 WHERE recipient_id = $user_id AND mail_id IN ($inClause)");
        mail_respond(true, null, 'Mail moved to trash');
        break;

    // 7. Restore Mail
    case 'restore_mail':
        $mail_ids = $inputData['mail_ids'] ?? ($_POST['mail_id'] ?? ($_GET['mail_id'] ?? []));
        if (!is_array($mail_ids)) $mail_ids = [$mail_ids];
        $ids = array_filter(array_map('intval', $mail_ids));
        if (empty($ids)) mail_respond(false, null, 'No valid mail IDs');
        foreach ($ids as $mId) {
            $chk = $conn->query("SELECT id FROM mail_recipients WHERE mail_id = $mId AND recipient_id = $user_id");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type, is_read) VALUES ($mId, $user_id, 'to', 1)");
            }
        }
        $inClause = implode(',', $ids);
        $conn->query("UPDATE mail_recipients SET is_deleted = 0, is_archived = 0 WHERE recipient_id = $user_id AND mail_id IN ($inClause)");
        mail_respond(true, null, 'Mail restored successfully');
        break;

    // 8. Dynamic Folder Listings (inbox, starred, important, archive, trash)
    case 'inbox':
    case 'starred':
    case 'important':
    case 'archive':
    case 'trash':
        $page = max(1, (int)($_GET['page'] ?? ($inputData['page'] ?? 1)));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? ($inputData['limit'] ?? 25))));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? ($inputData['search'] ?? ''));
        $filter = trim($_GET['filter'] ?? ($inputData['filter'] ?? ''));
        $sort = strtolower(trim($_GET['sort'] ?? ($inputData['sort'] ?? 'newest')));
        $dateFrom = trim($_GET['date_from'] ?? ($inputData['date_from'] ?? ''));
        $dateTo = trim($_GET['date_to'] ?? ($inputData['date_to'] ?? ''));

        $whereClause = "WHERE mr.recipient_id = ? AND m.status = 'sent'";
        $params = [$user_id];
        $types = 'i';

        if ($action === 'inbox') {
            $whereClause .= " AND mr.is_deleted = 0 AND mr.is_archived = 0";
        } elseif ($action === 'starred') {
            $whereClause .= " AND mr.is_starred = 1 AND mr.is_deleted = 0";
        } elseif ($action === 'important') {
            $whereClause .= " AND (mr.is_important = 1 OR m.importance = 'high') AND mr.is_deleted = 0";
        } elseif ($action === 'archive') {
            $whereClause .= " AND mr.is_archived = 1 AND mr.is_deleted = 0";
        } elseif ($action === 'trash') {
            $whereClause .= " AND mr.is_deleted = 1";
        }

        // Apply quick filter pills
        if ($filter === 'unread') {
            $whereClause .= " AND mr.is_read = 0";
        } elseif ($filter === 'starred') {
            $whereClause .= " AND mr.is_starred = 1";
        } elseif ($filter === 'important') {
            $whereClause .= " AND (mr.is_important = 1 OR m.importance = 'high')";
        } elseif ($filter === 'has_attachment') {
            $whereClause .= " AND (SELECT COUNT(*) FROM mail_attachments WHERE mail_id = m.id) > 0";
        }

        // Search
        if ($search !== '') {
            $whereClause .= " AND (m.subject LIKE ? OR m.body LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $st = '%' . $search . '%';
            $params = array_merge($params, [$st, $st, $st, $st]);
            $types .= 'ssss';
        }

        // Date range
        if ($dateFrom !== '') {
            $whereClause .= " AND m.created_at >= ?";
            $params[] = $dateFrom . ' 00:00:00';
            $types .= 's';
        }
        if ($dateTo !== '') {
            $whereClause .= " AND m.created_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }

        // Count query for pagination
        $countSql = "SELECT COUNT(*) as total FROM mail_recipients mr JOIN internal_mails m ON mr.mail_id = m.id JOIN users u ON m.sender_id = u.id $whereClause";
        $cStmt = $conn->prepare($countSql);
        $cStmt->bind_param($types, ...$params);
        $cStmt->execute();
        $totalItems = (int)($cStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($totalItems / $limit));

        // Data query
        $orderDir = ($sort === 'oldest') ? 'ASC' : 'DESC';
        $sql = "
            SELECT 
                m.id AS mail_id,
                m.sender_id,
                m.parent_id,
                m.subject,
                m.body,
                m.importance,
                m.created_at,
                mr.id AS recipient_record_id,
                mr.recipient_type,
                mr.is_read,
                mr.read_at,
                mr.is_starred,
                mr.is_important,
                mr.is_archived,
                mr.is_deleted,
                u.full_name AS sender_name,
                u.email AS sender_email,
                u.employee_code AS sender_code,
                u.designation AS sender_designation,
                (SELECT COUNT(*) FROM mail_attachments WHERE mail_id = m.id) AS attachment_count
            FROM mail_recipients mr
            JOIN internal_mails m ON mr.mail_id = m.id
            JOIN users u ON m.sender_id = u.id
            $whereClause
            ORDER BY m.created_at $orderDir LIMIT ? OFFSET ?
        ";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $mails = [];
        while ($row = $res->fetch_assoc()) {
            $snippet = strip_tags($row['body']);
            if (mb_strlen($snippet) > 120) {
                $snippet = mb_substr($snippet, 0, 120) . '...';
            }
            $mails[] = [
                'mail_id'             => (int)$row['mail_id'],
                'recipient_record_id' => (int)$row['recipient_record_id'],
                'sender_id'           => (int)$row['sender_id'],
                'sender_name'         => $row['sender_name'],
                'sender_email'        => $row['sender_email'] ?: ($row['sender_code'] . '@balitech.internal'),
                'sender_designation'  => $row['sender_designation'] ?? '',
                'subject'             => $row['subject'],
                'snippet'             => $snippet,
                'importance'          => $row['importance'],
                'is_read'             => (bool)$row['is_read'],
                'is_starred'          => (bool)$row['is_starred'],
                'is_important'        => (bool)$row['is_important'],
                'is_archived'         => (bool)$row['is_archived'],
                'is_deleted'          => (bool)$row['is_deleted'],
                'read_at'             => $row['read_at'],
                'recipient_type'      => $row['recipient_type'],
                'parent_id'           => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'attachment_count'    => (int)$row['attachment_count'],
                'created_at'          => $row['created_at']
            ];
        }

        mail_respond(true, [
            'mails'       => $mails,
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $totalItems,
            'total_pages' => $totalPages
        ]);
        break;

    // 9. Sent listing
    case 'sent':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');

        // Auto-ensure recipient row for sender
        $conn->query("
            INSERT IGNORE INTO mail_recipients (mail_id, recipient_id, recipient_type, is_read)
            SELECT id, sender_id, 'to', 1 FROM internal_mails
            WHERE sender_id = $user_id AND id NOT IN (SELECT mail_id FROM mail_recipients WHERE recipient_id = $user_id)
        ");

        $sql = "
            SELECT 
                m.id AS mail_id,
                m.parent_id,
                m.subject,
                m.body,
                m.importance,
                m.created_at,
                (SELECT COUNT(*) FROM mail_attachments WHERE mail_id = m.id) AS attachment_count
            FROM internal_mails m
            LEFT JOIN mail_recipients mr ON m.id = mr.mail_id AND mr.recipient_id = ?
            WHERE m.sender_id = ? AND m.status = 'sent'
              AND (mr.is_deleted IS NULL OR mr.is_deleted = 0)
              AND (mr.is_archived IS NULL OR mr.is_archived = 0)
        ";
        $params = [$user_id, $user_id];
        $types = 'ii';

        if ($search !== '') {
            $sql .= " AND (m.subject LIKE ? OR m.body LIKE ?)";
            $st = '%' . $search . '%';
            $params = array_merge($params, [$st, $st]);
            $types .= 'ss';
        }

        $sql .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $mails = [];
        while ($row = $res->fetch_assoc()) {
            $mailId = (int)$row['mail_id'];
            $rStmt = $conn->prepare("
                SELECT u.full_name, u.email, mr.recipient_type
                FROM mail_recipients mr
                JOIN users u ON mr.recipient_id = u.id
                WHERE mr.mail_id = ?
            ");
            $rStmt->bind_param('i', $mailId);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            $recipients = [];
            while ($rRow = $rRes->fetch_assoc()) {
                $recipients[] = [
                    'name' => $rRow['full_name'],
                    'email' => $rRow['email'],
                    'type' => $rRow['recipient_type']
                ];
            }

            $snippet = strip_tags($row['body']);
            if (mb_strlen($snippet) > 120) {
                $snippet = mb_substr($snippet, 0, 120) . '...';
            }

            $mails[] = [
                'mail_id'          => $mailId,
                'subject'          => $row['subject'],
                'snippet'          => $snippet,
                'importance'       => $row['importance'],
                'recipients'       => $recipients,
                'parent_id'        => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'attachment_count' => (int)$row['attachment_count'],
                'created_at'       => $row['created_at']
            ];
        }
        mail_respond(true, ['mails' => $mails, 'page' => $page]);
        break;

    // 10. Drafts listing
    case 'drafts':
        $stmt = $conn->prepare("
            SELECT m.id AS mail_id, m.subject, m.body, m.importance, m.updated_at
            FROM internal_mails m
            WHERE m.sender_id = ? AND m.status = 'draft'
            ORDER BY m.updated_at DESC
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $drafts = [];
        while ($row = $res->fetch_assoc()) {
            $mailId = (int)$row['mail_id'];
            $rStmt = $conn->prepare("
                SELECT recipient_id, recipient_type, u.full_name, u.email
                FROM mail_recipients mr
                LEFT JOIN users u ON mr.recipient_id = u.id
                WHERE mr.mail_id = ?
            ");
            $rStmt->bind_param('i', $mailId);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            $recList = [];
            while ($rRow = $rRes->fetch_assoc()) {
                $recList[] = [
                    'id'   => (int)$rRow['recipient_id'],
                    'type' => $rRow['recipient_type'],
                    'name' => $rRow['full_name'],
                    'email'=> $rRow['email']
                ];
            }

            $drafts[] = [
                'mail_id'    => $mailId,
                'subject'    => $row['subject'],
                'body'       => $row['body'],
                'importance' => $row['importance'],
                'recipients' => $recList,
                'updated_at' => $row['updated_at']
            ];
        }
        mail_respond(true, ['drafts' => $drafts]);
        break;

    // 6. View Single Mail Details & Conversation Thread History
    case 'read':
        $mail_id = (int)($_GET['mail_id'] ?? ($inputData['mail_id'] ?? 0));
        if ($mail_id <= 0) {
            mail_respond(false, null, 'Invalid mail ID');
        }

        // Fetch mail record
        $stmt = $conn->prepare("
            SELECT m.*, u.full_name AS sender_name, u.email AS sender_email, u.employee_code AS sender_code, u.designation AS sender_designation
            FROM internal_mails m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $stmt->bind_param('i', $mail_id);
        $stmt->execute();
        $mail = $stmt->get_result()->fetch_assoc();
        if (!$mail) {
            mail_respond(false, null, 'Mail not found');
        }

        // Verify access permission (user is sender OR recipient)
        $isSender = ((int)$mail['sender_id'] === $user_id);
        $checkRecip = $conn->prepare("SELECT id, is_read FROM mail_recipients WHERE mail_id = ? AND recipient_id = ?");
        $checkRecip->bind_param('ii', $mail_id, $user_id);
        $checkRecip->execute();
        $recipRow = $checkRecip->get_result()->fetch_assoc();

        if (!$isSender && !$recipRow) {
            mail_respond(false, null, 'Access denied');
        }

        // Mark read if logged in user is recipient
        if ($recipRow && !$recipRow['is_read']) {
            $up = $conn->prepare("UPDATE mail_recipients SET is_read = 1, read_at = NOW() WHERE id = ?");
            $up->bind_param('i', $recipRow['id']);
            $up->execute();
        }

        // Fetch recipients for this mail
        $recStmt = $conn->prepare("
            SELECT mr.recipient_type, u.id AS user_id, u.full_name, u.email, u.employee_code, u.designation
            FROM mail_recipients mr
            JOIN users u ON mr.recipient_id = u.id
            WHERE mr.mail_id = ?
        ");
        $recStmt->bind_param('i', $mail_id);
        $recStmt->execute();
        $recRes = $recStmt->get_result();
        $recipients = [];
        while ($r = $recRes->fetch_assoc()) {
            $recipients[] = [
                'user_id'     => (int)$r['user_id'],
                'full_name'   => $r['full_name'],
                'email'       => $r['email'] ?: ($r['employee_code'] . '@balitech.internal'),
                'designation' => $r['designation'] ?? '',
                'type'        => $r['recipient_type']
            ];
        }

        // Fetch attachments for this mail
        $attStmt = $conn->prepare("SELECT id, file_name, file_path, file_size, file_type FROM mail_attachments WHERE mail_id = ?");
        $attStmt->bind_param('i', $mail_id);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        $attachments = [];
        while ($a = $attRes->fetch_assoc()) {
            $attachments[] = [
                'id'        => (int)$a['id'],
                'file_name' => $a['file_name'],
                'file_path' => $a['file_path'],
                'file_size' => (int)$a['file_size'],
                'file_type' => $a['file_type']
            ];
        }

        // Thread Conversation History: Root ID is either parent_id or mail_id
        $rootId = $mail['parent_id'] ? (int)$mail['parent_id'] : (int)$mail['id'];
        $threadStmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.subject, m.body, m.created_at, u.full_name AS sender_name, u.email AS sender_email
            FROM internal_mails m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.id = ? OR m.parent_id = ?) AND m.status = 'sent'
            ORDER BY m.created_at ASC
        ");
        $threadStmt->bind_param('ii', $rootId, $rootId);
        $threadStmt->execute();
        $threadRes = $threadStmt->get_result();
        $thread = [];
        while ($t = $threadRes->fetch_assoc()) {
            // Attachments for thread item
            $tAttStmt = $conn->prepare("SELECT id, file_name, file_path, file_size, file_type FROM mail_attachments WHERE mail_id = ?");
            $tAttId = (int)$t['id'];
            $tAttStmt->bind_param('i', $tAttId);
            $tAttStmt->execute();
            $tAtts = $tAttStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $thread[] = [
                'mail_id'     => (int)$t['id'],
                'sender_id'   => (int)$t['sender_id'],
                'sender_name' => $t['sender_name'],
                'sender_email'=> $t['sender_email'],
                'subject'     => $t['subject'],
                'body'        => $t['body'],
                'created_at'  => $t['created_at'],
                'attachments' => $tAtts
            ];
        }

        mail_respond(true, [
            'mail' => [
                'mail_id'            => (int)$mail['id'],
                'parent_id'          => $mail['parent_id'] ? (int)$mail['parent_id'] : null,
                'sender_id'          => (int)$mail['sender_id'],
                'sender_name'        => $mail['sender_name'],
                'sender_email'       => $mail['sender_email'] ?: ($mail['sender_code'] . '@balitech.internal'),
                'sender_designation' => $mail['sender_designation'] ?? '',
                'subject'            => $mail['subject'],
                'body'               => $mail['body'],
                'importance'         => $mail['importance'],
                'created_at'         => $mail['created_at'],
                'recipients'         => $recipients,
                'attachments'        => $attachments
            ],
            'thread' => $thread
        ]);
        break;

    // 7. Send Email (or reply)
    case 'send':
        $subject = trim($inputData['subject'] ?? '');
        $body = trim($inputData['body'] ?? '');
        $parent_id = !empty($inputData['parent_id']) ? (int)$inputData['parent_id'] : null;
        $importance = in_array($inputData['importance'] ?? '', ['normal', 'high', 'low'], true) ? $inputData['importance'] : 'normal';
        $recipients = $inputData['recipients'] ?? [];
        $attachments = $inputData['attachments'] ?? [];
        $draft_id = !empty($inputData['draft_id']) ? (int)$inputData['draft_id'] : 0;

        if ($subject === '') {
            $subject = '(No Subject)';
        }
        if ($body === '') {
            mail_respond(false, null, 'Email body cannot be empty');
        }

        if (!is_array($recipients) || empty($recipients)) {
            mail_respond(false, null, 'Please specify at least one recipient');
        }

        $conn->begin_transaction();
        try {
            if ($draft_id > 0) {
                // Update draft to sent
                $stmt = $conn->prepare("UPDATE internal_mails SET subject = ?, body = ?, status = 'sent', importance = ?, parent_id = ? WHERE id = ? AND sender_id = ?");
                $stmt->bind_param('ssssii', $subject, $body, $importance, $parent_id, $draft_id, $user_id);
                $stmt->execute();
                $mail_id = $draft_id;

                // Clear previous recipients for this draft
                $delRec = $conn->prepare("DELETE FROM mail_recipients WHERE mail_id = ?");
                $delRec->bind_param('i', $mail_id);
                $delRec->execute();
            } else {
                // Create new mail record
                $stmt = $conn->prepare("INSERT INTO internal_mails (sender_id, parent_id, subject, body, status, importance) VALUES (?, ?, ?, ?, 'sent', ?)");
                $stmt->bind_param('iisss', $user_id, $parent_id, $subject, $body, $importance);
                $stmt->execute();
                $mail_id = (int)$conn->insert_id;
            }

            // Insert recipients
            $insertedRecipients = [];
            $recStmt = $conn->prepare("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type) VALUES (?, ?, ?)");
            foreach ($recipients as $r) {
                $rId = (int)(is_array($r) ? ($r['id'] ?? 0) : $r);
                $rType = is_array($r) ? ($r['type'] ?? 'to') : 'to';

                if ($rId <= 0) {
                    $ident = trim(is_array($r) ? ($r['email'] ?? ($r['full_name'] ?? ($r['name'] ?? ''))) : (string)$r);
                    if ($ident !== '') {
                        $uStmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR employee_code = ? OR full_name = ? LIMIT 1");
                        $uStmt->bind_param('sss', $ident, $ident, $ident);
                        $uStmt->execute();
                        $uRes = $uStmt->get_result()->fetch_assoc();
                        if ($uRes) {
                            $rId = (int)$uRes['id'];
                        }
                    }
                }

                if ($rId > 0 && !in_array($rId, $insertedRecipients, true)) {
                    $recStmt->bind_param('iis', $mail_id, $rId, $rType);
                    $recStmt->execute();
                    $insertedRecipients[] = $rId;
                }
            }

            // Insert attachments if provided
            if (is_array($attachments) && !empty($attachments)) {
                $attStmt = $conn->prepare("INSERT INTO mail_attachments (mail_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)");
                foreach ($attachments as $att) {
                    if (!empty($att['file_path'])) {
                        $fName = $att['file_name'] ?? basename($att['file_path']);
                        $fPath = $att['file_path'];
                        $fSize = (int)($att['file_size'] ?? 0);
                        $fType = $att['file_type'] ?? '';
                        $attStmt->bind_param('issis', $mail_id, $fName, $fPath, $fSize, $fType);
                        $attStmt->execute();
                    }
                }
            }

            // Insert HRMS Portal Notification for recipients
            $senderName = $user['full_name'] ?? 'HRMS User';
            $notifPayload = json_encode([
                'title'       => 'New Internal Email',
                'sender_name' => $senderName,
                'subject'     => $subject,
                'mail_id'     => $mail_id
            ], JSON_UNESCAPED_UNICODE);
            $branch = normalize_company_branch($_SESSION['company_branch'] ?? 'main');
            
            $notifStmt = $conn->prepare("INSERT INTO portal_notifications (notification_type, target_portal, payload, company_branch) VALUES ('internal_mail', 'agent', ?, ?)");
            $notifStmt->bind_param('ss', $notifPayload, $branch);
            $notifStmt->execute();

            $conn->commit();
            mail_respond(true, ['mail_id' => $mail_id], 'Email sent successfully');

        } catch (Exception $e) {
            $conn->rollback();
            mail_respond(false, null, 'Error sending email: ' . $e->getMessage(), 500);
        }
        break;

    // 8. Save Draft
    case 'save_draft':
        $subject = trim($inputData['subject'] ?? '');
        $body = trim($inputData['body'] ?? '');
        $importance = in_array($inputData['importance'] ?? '', ['normal', 'high', 'low'], true) ? $inputData['importance'] : 'normal';
        $recipients = $inputData['recipients'] ?? [];
        $draft_id = !empty($inputData['draft_id']) ? (int)$inputData['draft_id'] : 0;

        if ($draft_id > 0) {
            $stmt = $conn->prepare("UPDATE internal_mails SET subject = ?, body = ?, importance = ?, updated_at = NOW() WHERE id = ? AND sender_id = ? AND status = 'draft'");
            $stmt->bind_param('sssii', $subject, $body, $importance, $draft_id, $user_id);
            $stmt->execute();
            $mail_id = $draft_id;

            $delRec = $conn->prepare("DELETE FROM mail_recipients WHERE mail_id = ?");
            $delRec->bind_param('i', $mail_id);
            $delRec->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO internal_mails (sender_id, subject, body, status, importance) VALUES (?, ?, ?, 'draft', ?)");
            $stmt->bind_param('isss', $user_id, $subject, $body, $importance);
            $stmt->execute();
            $mail_id = (int)$conn->insert_id;
        }

        if (is_array($recipients) && !empty($recipients)) {
            $recStmt = $conn->prepare("INSERT INTO mail_recipients (mail_id, recipient_id, recipient_type) VALUES (?, ?, ?)");
            foreach ($recipients as $r) {
                $rId = (int)(is_array($r) ? ($r['id'] ?? 0) : $r);
                $rType = is_array($r) ? ($r['type'] ?? 'to') : 'to';
                if ($rId > 0) {
                    $recStmt->bind_param('iis', $mail_id, $rId, $rType);
                    $recStmt->execute();
                }
            }
        }

        mail_respond(true, ['draft_id' => $mail_id], 'Draft saved');
        break;

    // 9. Delete Draft
    case 'delete_draft':
        $draft_id = (int)($inputData['draft_id'] ?? ($_GET['draft_id'] ?? 0));
        if ($draft_id <= 0) {
            mail_respond(false, null, 'Invalid draft ID');
        }
        $stmt = $conn->prepare("DELETE FROM internal_mails WHERE id = ? AND sender_id = ? AND status = 'draft'");
        $stmt->bind_param('ii', $draft_id, $user_id);
        $stmt->execute();
        mail_respond(true, null, 'Draft deleted');
        break;

    // 10. Mark Read/Unread or Delete Mail Item
    case 'mark_read':
    case 'mark_unread':
    case 'delete_mail':
        $mail_ids = $inputData['mail_ids'] ?? [];
        if (!is_array($mail_ids) || empty($mail_ids)) {
            $singleId = (int)($inputData['mail_id'] ?? 0);
            if ($singleId > 0) $mail_ids = [$singleId];
        }
        if (empty($mail_ids)) {
            mail_respond(false, null, 'No mail IDs provided');
        }
        $mail_ids = array_map('intval', $mail_ids);
        $placeholders = implode(',', array_fill(0, count($mail_ids), '?'));

        if ($action === 'mark_read') {
            $sql = "UPDATE mail_recipients SET is_read = 1, read_at = NOW() WHERE recipient_id = ? AND mail_id IN ($placeholders)";
        } else if ($action === 'mark_unread') {
            $sql = "UPDATE mail_recipients SET is_read = 0, read_at = NULL WHERE recipient_id = ? AND mail_id IN ($placeholders)";
        } else {
            $sql = "UPDATE mail_recipients SET is_deleted = 1 WHERE recipient_id = ? AND mail_id IN ($placeholders)";
        }

        $types = 'i' . str_repeat('i', count($mail_ids));
        $params = array_merge([$user_id], $mail_ids);

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        mail_respond(true, null, 'Mail status updated');
        break;

    // 11. Upload File Attachment (with extension & size validation)
    case 'upload_attachment':
        if (empty($_FILES['file'])) {
            mail_respond(false, null, 'No file uploaded');
        }

        $file = $_FILES['file'];
        if ($file['size'] > 26214400) { // 25MB limit
            mail_respond(false, null, 'File exceeds maximum 25MB size limit');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $blockedExts = ['php', 'phtml', 'exe', 'bat', 'cmd', 'sh', 'js', 'vbs', 'ps1', 'py', 'cgi', 'pl', 'dll', 'msi', 'scr'];
        if (in_array($ext, $blockedExts, true)) {
            mail_respond(false, null, 'Executable and script files are restricted for security');
        }

        $uploadDir = __DIR__ . '/../uploads/mail/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $newFilename = time() . '_' . rand(1000, 9999) . '_' . $cleanName . '.' . $ext;
        $targetPath = $uploadDir . $newFilename;
        $relativePath = 'uploads/mail/' . $newFilename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            mail_respond(true, [
                'file_name' => $file['name'],
                'file_path' => $relativePath,
                'file_size' => (int)$file['size'],
                'file_type' => $file['type']
            ], 'File uploaded');
        } else {
            mail_respond(false, null, 'Failed to save uploaded file');
        }
        break;

    // 12. Get User Mail Settings & Signature
    case 'get_settings':
        $stmt = $conn->prepare("SELECT signature_text, is_enabled, default_importance FROM user_mail_signatures WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if (!$res) {
            $res = [
                'signature_text'     => '',
                'is_enabled'         => 1,
                'default_importance' => 'normal'
            ];
        } else {
            $res['is_enabled'] = (int)$res['is_enabled'];
        }
        mail_respond(true, $res);
        break;

    // 13. Save User Mail Settings & Signature
    case 'save_settings':
        $signature_text = trim($inputData['signature_text'] ?? '');
        $is_enabled = !empty($inputData['is_enabled']) ? 1 : 0;
        $default_importance = in_array($inputData['default_importance'] ?? '', ['normal', 'high', 'low'], true) ? $inputData['default_importance'] : 'normal';

        $stmt = $conn->prepare("
            INSERT INTO user_mail_signatures (user_id, signature_text, is_enabled, default_importance) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                signature_text = VALUES(signature_text),
                is_enabled = VALUES(is_enabled),
                default_importance = VALUES(default_importance)
        ");
        $stmt->bind_param('isis', $user_id, $signature_text, $is_enabled, $default_importance);
        $stmt->execute();
        mail_respond(true, null, 'Mail settings updated');
        break;

    // 14. Secure Attachment Download Stream
    case 'download_attachment':
        $attach_id = (int)($_GET['id'] ?? ($inputData['id'] ?? 0));
        if ($attach_id <= 0) {
            mail_respond(false, null, 'Invalid attachment ID');
        }

        $stmt = $conn->prepare("
            SELECT a.*, m.sender_id 
            FROM mail_attachments a
            JOIN internal_mails m ON a.mail_id = m.id
            WHERE a.id = ?
        ");
        $stmt->bind_param('i', $attach_id);
        $stmt->execute();
        $att = $stmt->get_result()->fetch_assoc();
        if (!$att) {
            mail_respond(false, null, 'Attachment not found');
        }

        // Verify access: user is sender OR a recipient
        $isSender = ((int)$att['sender_id'] === $user_id);
        $checkRec = $conn->prepare("SELECT id FROM mail_recipients WHERE mail_id = ? AND recipient_id = ?");
        $mailId = (int)$att['mail_id'];
        $checkRec->bind_param('ii', $mailId, $user_id);
        $checkRec->execute();
        $recRow = $checkRec->get_result()->fetch_assoc();

        if (!$isSender && !$recRow) {
            mail_respond(false, null, 'Access denied to attachment', 403);
        }

        $fullPath = __DIR__ . '/../' . ltrim($att['file_path'], '/');
        if (!file_exists($fullPath)) {
            mail_respond(false, null, 'File on server not found', 444);
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($att['file_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($att['file_name']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit();

    default:
        mail_respond(false, null, 'Invalid action');
}
?>
