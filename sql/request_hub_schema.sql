-- Request Hub Schema for Employee Self Service Portal
CREATE TABLE IF NOT EXISTS `portal_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_code` VARCHAR(30) NOT NULL UNIQUE,
  `employee_id` INT NOT NULL,
  `employee_name` VARCHAR(150) NOT NULL,
  `department` VARCHAR(50) NOT NULL,
  `request_type` VARCHAR(100) NOT NULL,
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `tagged_users` TEXT DEFAULT NULL,
  `status` ENUM('pending', 'in_progress', 'resolved', 'rejected', 'closed') NOT NULL DEFAULT 'pending',
  `resolution_notes` TEXT DEFAULT NULL,
  `resolved_by` VARCHAR(150) DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_emp (`employee_id`),
  INDEX idx_dept (`department`),
  INDEX idx_status (`status`),
  INDEX idx_created (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_request_remarks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `author_id` INT NOT NULL,
  `author_name` VARCHAR(150) NOT NULL,
  `author_role` VARCHAR(50) DEFAULT 'Employee',
  `remark` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `status_change` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_req (`request_id`),
  INDEX idx_created (`created_at`),
  CONSTRAINT `fk_portal_req_remarks` FOREIGN KEY (`request_id`) REFERENCES `portal_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
