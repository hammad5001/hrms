-- ============================================================
-- BALITECH HRMS - RTC CALL TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS `rtc_calls` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(36) NOT NULL UNIQUE,
    `conversation_id` INT NOT NULL,
    `room_name` VARCHAR(100) NOT NULL UNIQUE,
    `creator_id` INT NOT NULL,
    `call_type` ENUM('direct', 'group') NOT NULL DEFAULT 'direct',
    `status` ENUM('initiated', 'active', 'ended', 'cancelled') NOT NULL DEFAULT 'initiated',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `answered_at` DATETIME DEFAULT NULL,
    `ended_at` DATETIME DEFAULT NULL,
    `ended_by_id` INT DEFAULT NULL,
    `end_reason` VARCHAR(255) DEFAULT NULL,
    INDEX `idx_rtc_conv` (`conversation_id`),
    INDEX `idx_rtc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rtc_call_participants` (
    `call_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `is_host` TINYINT(1) DEFAULT 0,
    `invitation_status` ENUM('invited', 'ringing', 'accepted', 'declined', 'missed', 'busy', 'left') NOT NULL DEFAULT 'invited',
    `joined_at` DATETIME DEFAULT NULL,
    `left_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`call_id`, `user_id`),
    INDEX `idx_participant_status` (`invitation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rtc_call_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `call_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_event_call` (`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rtc_active_users` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `active_call_id` INT NOT NULL,
    INDEX `idx_active_call` (`active_call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
