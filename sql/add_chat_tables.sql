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
