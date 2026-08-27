-- Balitech portal chat tables (also auto-created on first load)
USE `balitech`;

CREATE TABLE IF NOT EXISTS `chat_conversations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('direct','group') NOT NULL DEFAULT 'direct',
    `title` VARCHAR(150) DEFAULT NULL,
    `avatar_color` VARCHAR(12) DEFAULT '#6264a7',
    `created_by` INT NOT NULL,
    `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_participants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `last_read_at` DATETIME DEFAULT NULL,
    `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_conv_user` (`conversation_id`, `user_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` INT NOT NULL,
    `sender_id` INT NOT NULL,
    `body` TEXT,
    `msg_type` ENUM('text','image','file') NOT NULL DEFAULT 'text',
    `file_name` VARCHAR(255) DEFAULT NULL,
    `file_path` VARCHAR(500) DEFAULT NULL,
    `file_size` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_conv_created` (`conversation_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HRMS CHAT PERFORMANCE INDEXES 20260827
-- Required by unread-count and pinned-message hot paths.
ALTER TABLE `chat_messages`
    ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `chat_messages`
    ADD COLUMN IF NOT EXISTS `is_pinned` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `chat_messages`
    ADD COLUMN IF NOT EXISTS `pinned_by` INT DEFAULT NULL;

ALTER TABLE `chat_messages`
    ADD COLUMN IF NOT EXISTS `pinned_at` DATETIME DEFAULT NULL;

ALTER TABLE `chat_messages`
    ADD INDEX IF NOT EXISTS `idx_unread_lookup`
        (`conversation_id`, `is_deleted`, `created_at`, `sender_id`);

ALTER TABLE `chat_messages`
    ADD INDEX IF NOT EXISTS `idx_pinned_lookup`
        (`conversation_id`, `is_pinned`, `is_deleted`, `pinned_at`);

ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_employee_code` (`employee_code`);

CREATE TABLE IF NOT EXISTS `chat_message_receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `delivered_at` DATETIME DEFAULT NULL,
    `read_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `uq_msg_user` (`message_id`, `user_id`),
    INDEX `idx_msg` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `chat_participants` ADD COLUMN `last_active_at` DATETIME DEFAULT NULL;
ALTER TABLE `chat_participants` ADD COLUMN `typing_until` DATETIME DEFAULT NULL;
