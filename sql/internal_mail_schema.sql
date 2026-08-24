-- Internal Mail Module Database Schema

CREATE TABLE IF NOT EXISTS `internal_mails` (
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
  INDEX `idx_parent` (`parent_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_recipients` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_attachments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `mail_id` INT NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` INT NOT NULL DEFAULT 0,
  `file_type` VARCHAR(100) NOT NULL DEFAULT '',
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_mail_attach` (`mail_id`),
  FOREIGN KEY (`mail_id`) REFERENCES `internal_mails`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
