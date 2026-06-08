<?php

function ensure_chat_schema(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $queries = [
        "CREATE TABLE IF NOT EXISTS `chat_conversations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `type` ENUM('direct','group') NOT NULL DEFAULT 'direct',
            `title` VARCHAR(150) DEFAULT NULL,
            `avatar_color` VARCHAR(12) DEFAULT '#6264a7',
            `created_by` INT NOT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_type` (`type`),
            INDEX `idx_branch` (`company_branch`),
            INDEX `idx_updated` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `chat_participants` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `conversation_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `last_read_at` DATETIME DEFAULT NULL,
            `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_conv_user` (`conversation_id`, `user_id`),
            INDEX `idx_user` (`user_id`),
            INDEX `idx_conv` (`conversation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `chat_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `conversation_id` INT NOT NULL,
            `sender_id` INT NOT NULL,
            `body` TEXT,
            `msg_type` ENUM('text','image','file') NOT NULL DEFAULT 'text',
            `file_name` VARCHAR(255) DEFAULT NULL,
            `file_path` VARCHAR(500) DEFAULT NULL,
            `file_size` INT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_conv_created` (`conversation_id`, `created_at`),
            INDEX `idx_sender` (`sender_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `chat_message_receipts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `message_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `delivered_at` DATETIME DEFAULT NULL,
            `read_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_msg_user` (`message_id`, `user_id`),
            INDEX `idx_msg` (`message_id`),
            INDEX `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $sql) {
        @$conn->query($sql);
    }

    @$conn->query("ALTER TABLE `chat_participants` ADD COLUMN `last_active_at` DATETIME DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_participants` ADD COLUMN `typing_until` DATETIME DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN `is_edited` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN `edited_at` DATETIME DEFAULT NULL");

    @$conn->query("ALTER TABLE `chat_messages` ADD INDEX `idx_conv_id` (`conversation_id`, `id`)");
    @$conn->query("ALTER TABLE `chat_messages` ADD INDEX `idx_conv_deleted` (`conversation_id`, `is_deleted`, `id`)");
    @$conn->query("ALTER TABLE `chat_participants` ADD INDEX `idx_user_conv` (`user_id`, `conversation_id`)");
    @$conn->query("ALTER TABLE `chat_message_receipts` ADD INDEX `idx_msg_read` (`message_id`, `read_at`)");
    @$conn->query("ALTER TABLE `users` ADD COLUMN `chat_avatar` VARCHAR(255) DEFAULT NULL");

    $uploadDir = dirname(__DIR__) . '/uploads/chat';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $avatarDir = chat_avatar_dir();
    if (!is_dir($avatarDir)) {
        @mkdir($avatarDir, 0755, true);
    }
}

function chat_upload_dir(): string {
    return dirname(__DIR__) . '/uploads/chat';
}

function chat_avatar_dir(): string {
    return dirname(__DIR__) . '/uploads/chat/avatars';
}

function chat_public_avatar_url(?string $storedName): string {
    $storedName = $storedName ? basename($storedName) : '';
    if ($storedName === '') {
        return '';
    }
    return 'uploads/chat/avatars/' . $storedName;
}

function chat_upload_url_prefix(): string {
    return 'uploads/chat/';
}

/** Web-accessible URL for a stored filename (relative to interview-forms root). */
function chat_public_file_url(string $storedName): string {
    $storedName = basename($storedName);
    if ($storedName === '') {
        return '';
    }
    return chat_upload_url_prefix() . $storedName;
}

/** @return array{ext: string, mime: string}|null */
function chat_resolve_upload_type(string $tmpPath, string $clientMime, string $originalName): ?array {
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/octet-stream' => null,
    ];

    $mime = strtolower(trim($clientMime));
    if (isset($map[$mime]) && $map[$mime] !== null) {
        return ['mime' => $mime, 'ext' => $map[$mime]];
    }

    if (is_readable($tmpPath) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = strtolower((string)finfo_file($finfo, $tmpPath));
        finfo_close($finfo);
        if (isset($map[$detected]) && $map[$detected] !== null) {
            return ['mime' => $detected, 'ext' => $map[$detected]];
        }
        if (str_starts_with($detected, 'image/')) {
            $ext = match ($detected) {
                'image/jpeg', 'image/jpg', 'image/pjpeg' => 'jpg',
                'image/png', 'image/x-png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            return ['mime' => $detected, 'ext' => $ext];
        }
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extToMime = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (isset($extToMime[$ext])) {
        return ['mime' => $extToMime[$ext], 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
    }

    return null;
}

function chat_upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (server limit)',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted, try again',
        UPLOAD_ERR_NO_FILE => 'No file selected',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder missing',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot save file',
        default => 'Upload failed (code ' . $code . ')',
    };
}
