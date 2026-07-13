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
            `msg_type` ENUM('text','image','file','audio') NOT NULL DEFAULT 'text',
            `file_name` VARCHAR(255) DEFAULT NULL,
            `file_path` VARCHAR(500) DEFAULT NULL,
            `file_size` INT DEFAULT NULL,
            `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
            `pinned_by` INT DEFAULT NULL,
            `pinned_at` DATETIME DEFAULT NULL,
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

    @$conn->query("ALTER TABLE `chat_participants` ADD COLUMN IF NOT EXISTS `last_active_at` DATETIME DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_participants` ADD COLUMN IF NOT EXISTS `typing_until` DATETIME DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_participants` ADD COLUMN IF NOT EXISTS `is_admin` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN IF NOT EXISTS `is_edited` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN IF NOT EXISTS `edited_at` DATETIME DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_messages` MODIFY COLUMN `msg_type` ENUM('text','image','file','audio') NOT NULL DEFAULT 'text'");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN IF NOT EXISTS `is_pinned` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN IF NOT EXISTS `pinned_by` INT DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_messages` ADD COLUMN IF NOT EXISTS `pinned_at` DATETIME DEFAULT NULL");

    @$conn->query("ALTER TABLE `chat_messages` ADD INDEX IF NOT EXISTS `idx_conv_id` (`conversation_id`, `id`)");
    @$conn->query("ALTER TABLE `chat_messages` ADD INDEX IF NOT EXISTS `idx_conv_deleted` (`conversation_id`, `is_deleted`, `id`)");
    @$conn->query("ALTER TABLE `chat_participants` ADD INDEX IF NOT EXISTS `idx_user_conv` (`user_id`, `conversation_id`)");
    @$conn->query("ALTER TABLE `chat_message_receipts` ADD INDEX IF NOT EXISTS `idx_msg_read` (`message_id`, `read_at`)");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `chat_avatar` VARCHAR(255) DEFAULT NULL");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `team` VARCHAR(80) DEFAULT NULL");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `branch` VARCHAR(80) DEFAULT NULL");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `joined_date` DATE DEFAULT NULL");
    @$conn->query("ALTER TABLE `chat_participants` ADD COLUMN IF NOT EXISTS `participant_status` ENUM('active','pending','declined') NOT NULL DEFAULT 'active'");

    @$conn->query("CREATE TABLE IF NOT EXISTS `chat_blocks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `blocker_id` INT NOT NULL,
        `blocked_id` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_chat_block` (`blocker_id`, `blocked_id`),
        INDEX `idx_blocked` (`blocked_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS `chat_message_hides` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `message_id` INT NOT NULL,
        `hidden_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_hide` (`user_id`, `message_id`),
        INDEX `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS `chat_message_reactions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `reaction` VARCHAR(32) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_msg_user_reaction` (`message_id`, `user_id`, `reaction`),
        INDEX `idx_msg` (`message_id`),
        INDEX `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

/** Extensions that must never be uploaded through chat. */
function chat_blocked_upload_extensions(): array {
    return [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'vbs', 'js', 'jse',
        'sh', 'bash', 'ps1', 'html', 'htm', 'xhtml', 'svgz',
    ];
}

/** @return string[] */
function chat_allowed_image_extensions(): array {
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff', 'heic', 'heif', 'ico', 'avif'];
}

/** @return string[] */
function chat_allowed_document_extensions(): array {
    return [
        'pdf', 'doc', 'docx', 'dot', 'dotx', 'rtf', 'txt', 'md', 'log',
        'xls', 'xlsx', 'xlsm', 'xlsb', 'csv', 'tsv', 'ods',
        'ppt', 'pptx', 'pps', 'ppsx', 'odp',
        'odt', 'odg', 'odf',
        'zip', 'rar', '7z', 'tar', 'gz',
        'json', 'xml', 'yml', 'yaml',
    ];
}

function chat_normalize_upload_extension(string $ext): string {
    $ext = strtolower(trim($ext));
    return $ext === 'jpeg' ? 'jpg' : $ext;
}

function chat_mime_for_extension(string $ext): string {
    $ext = chat_normalize_upload_extension($ext);
    static $map = [
        'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dot' => 'application/msword', 'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'rtf' => 'application/rtf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'log' => 'text/plain',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
        'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'pps' => 'application/vnd.ms-powerpoint', 'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
        'odt' => 'application/vnd.oasis.opendocument.text', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'odg' => 'application/vnd.oasis.opendocument.graphics', 'odf' => 'application/vnd.oasis.opendocument.formula',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar', 'gz' => 'application/gzip',
        'json' => 'application/json', 'xml' => 'application/xml',
        'yml' => 'text/yaml', 'yaml' => 'text/yaml',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'webm' => 'audio/webm',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function chat_extension_from_image_mime(string $mime): string {
    return match (true) {
        str_contains($mime, 'png') => 'png',
        str_contains($mime, 'gif') => 'gif',
        str_contains($mime, 'webp') => 'webp',
        str_contains($mime, 'bmp') => 'bmp',
        str_contains($mime, 'svg') => 'svg',
        str_contains($mime, 'tiff') => 'tiff',
        str_contains($mime, 'heic') => 'heic',
        str_contains($mime, 'heif') => 'heif',
        str_contains($mime, 'avif') => 'avif',
        str_contains($mime, 'icon') => 'ico',
        default => 'jpg',
    };
}

function chat_is_allowed_upload_extension(string $ext): bool {
    $ext = chat_normalize_upload_extension($ext);
    if ($ext === '' || in_array($ext, chat_blocked_upload_extensions(), true)) {
        return false;
    }
    return in_array($ext, chat_allowed_image_extensions(), true)
        || in_array($ext, chat_allowed_document_extensions(), true)
        || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'webm'], true);
}

function chat_resolve_upload_type_from_mime(string $mime, string $fallbackExt = ''): ?array {
    $mime = strtolower(trim($mime));
    if ($mime === '' || $mime === 'application/octet-stream') {
        return null;
    }
    if (str_starts_with($mime, 'image/')) {
        $ext = chat_extension_from_image_mime($mime);
        if ($fallbackExt !== '' && chat_is_allowed_upload_extension($fallbackExt)) {
            $ext = chat_normalize_upload_extension($fallbackExt);
        }
        return ['mime' => $mime, 'ext' => $ext];
    }
    if (str_starts_with($mime, 'audio/')) {
        $ext = match (true) {
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'm4a') || str_contains($mime, 'mp4') => 'm4a',
            str_contains($mime, 'webm') => 'webm',
            default => 'mp3',
        };
        if ($fallbackExt !== '' && chat_is_allowed_upload_extension($fallbackExt)) {
            $ext = chat_normalize_upload_extension($fallbackExt);
        }
        return ['mime' => $mime, 'ext' => $ext];
    }
    if (str_starts_with($mime, 'text/') || str_starts_with($mime, 'application/')) {
        $ext = $fallbackExt !== '' ? chat_normalize_upload_extension($fallbackExt) : '';
        if ($ext === '' || !chat_is_allowed_upload_extension($ext)) {
            $ext = match (true) {
                str_contains($mime, 'pdf') => 'pdf',
                str_contains($mime, 'word') => 'docx',
                str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet') => 'xlsx',
                str_contains($mime, 'csv') => 'csv',
                str_contains($mime, 'powerpoint') || str_contains($mime, 'presentation') => 'pptx',
                str_contains($mime, 'json') => 'json',
                str_contains($mime, 'xml') => 'xml',
                str_contains($mime, 'zip') || str_contains($mime, 'x-zip') => 'zip',
                str_contains($mime, 'rar') => 'rar',
                str_contains($mime, '7z') => '7z',
                str_contains($mime, 'gzip') || $mime === 'application/x-gzip' => 'gz',
                str_contains($mime, 'x-tar') || $mime === 'application/x-tar' => 'tar',
                str_starts_with($mime, 'text/') => 'txt',
                default => '',
            };
        }
        if ($ext === '' || !chat_is_allowed_upload_extension($ext)) {
            return null;
        }
        return ['mime' => $mime, 'ext' => $ext];
    }
    return null;
}

/** @return array{ext: string, mime: string}|null */
function chat_resolve_upload_type(string $tmpPath, string $clientMime, string $originalName): ?array {
    $ext = chat_normalize_upload_extension(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, chat_blocked_upload_extensions(), true)) {
        return null;
    }

    $trustedExts = ['xlsx', 'xlsm', 'xlsb', 'docx', 'dotx', 'pptx', 'ppsx', 'odt', 'ods', 'odp', 'zip', 'rar', '7z', 'tar', 'gz'];
    if ($ext !== '' && in_array($ext, $trustedExts, true)) {
        return ['mime' => chat_mime_for_extension($ext), 'ext' => $ext];
    }

    $detected = '';
    if (is_readable($tmpPath) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) finfo_file($finfo, $tmpPath));
        finfo_close($finfo);
        $fromDetected = chat_resolve_upload_type_from_mime($detected, $ext);
        if ($fromDetected) {
            return $fromDetected;
        }
    }

    $fromClient = chat_resolve_upload_type_from_mime(strtolower(trim($clientMime)), $ext);
    if ($fromClient) {
        return $fromClient;
    }

    if ($ext !== '' && chat_is_allowed_upload_extension($ext)) {
        return ['mime' => chat_mime_for_extension($ext), 'ext' => $ext];
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
