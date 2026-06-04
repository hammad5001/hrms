-- ============================================================
-- BALITECH RECRUITER SYSTEM - COMPLETE DATABASE SETUP
-- Run this in phpMyAdmin or MySQL CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS `balitech` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `balitech`;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `username` VARCHAR(80) UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `portal_role` ENUM('admin','hr','recruiter','management','training','agent','receptionist','user','analytics','attendance') NOT NULL DEFAULT 'user',
    `employee_code` VARCHAR(20),
    `phone` VARCHAR(20),
    `department` VARCHAR(80),
    `designation` VARCHAR(80),
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_role` (`portal_role`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: recruiters
-- ============================================================
CREATE TABLE IF NOT EXISTS `recruiters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `recruiter_type` ENUM('super','regular') NOT NULL DEFAULT 'regular',
    `total_leads` INT DEFAULT 0,
    `total_calls` INT DEFAULT 0,
    `total_hired` INT DEFAULT 0,
    `total_rejected` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_type` (`recruiter_type`)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: leads
-- ============================================================
CREATE TABLE IF NOT EXISTS `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `father_name` VARCHAR(150),
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(150),
    `cnic` VARCHAR(20),
    `city` VARCHAR(80),
    `dob` DATE,
    `education` VARCHAR(100),
    `position_applied` VARCHAR(150),
    `referred_by` VARCHAR(100) DEFAULT 'Walk-in',
    `source` VARCHAR(80) DEFAULT 'manual',
    `bulk_import_id` INT,
    `current_stage` ENUM(
        'new','assigned','contacted','interested','callback',
        'outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg',
        'interview_scheduled','not_appeared','receptionist','interview_conducted',
        'hr_passed','hr_rejected','gm_passed','gm_rejected',
        'selected','pending','rejected','training','deployed','hired','mock_rejected','left'
    ) NOT NULL DEFAULT 'new',
    `assigned_recruiter_id` INT,
    `interview_date` DATE,
    `last_call_date` DATETIME,
    `call_count` INT DEFAULT 0,
    `assigned_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`assigned_recruiter_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_phone` (`phone`),
    INDEX `idx_stage` (`current_stage`),
    INDEX `idx_recruiter` (`assigned_recruiter_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: lead_remarks
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_remarks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NOT NULL,
    `added_by` INT,
    `added_by_name` VARCHAR(150),
    `added_by_role` VARCHAR(50) DEFAULT 'recruiter',
    `remark` TEXT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
    INDEX `idx_lead` (`lead_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: lead_audit
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_audit` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NOT NULL,
    `user_id` INT,
    `user_name` VARCHAR(150),
    `action` VARCHAR(50) NOT NULL,
    `old_value` VARCHAR(100),
    `new_value` VARCHAR(100),
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
    INDEX `idx_lead` (`lead_id`)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: bulk_imports (tracks import batches)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bulk_imports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `imported_by` INT NOT NULL,
    `file_name` VARCHAR(255),
    `total_rows` INT DEFAULT 0,
    `inserted_rows` INT DEFAULT 0,
    `skipped_rows` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`imported_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA: Default Admin User
-- Password: Admin@123
-- ============================================================
INSERT IGNORE INTO `users` (`full_name`, `email`, `username`, `password_hash`, `portal_role`, `employee_code`, `status`)
VALUES ('Super Admin', 'admin@balitech.com', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'ADM001', 'active');

-- Register super admin as recruiter type 'super'
INSERT IGNORE INTO `recruiters` (`user_id`, `recruiter_type`)
SELECT id, 'super' FROM `users` WHERE email = 'admin@balitech.com';

-- ============================================================
-- SEED DATA: Sample Recruiters
-- Password: Recruiter@123
-- ============================================================
INSERT IGNORE INTO `users` (`full_name`, `email`, `username`, `password_hash`, `portal_role`, `employee_code`, `phone`, `status`)
VALUES
('Naina Fareed',  'naina@balitech.com',  'naina',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recruiter', 'REC001', '03001111111', 'active'),
('Zoya Ahmed',    'zoya@balitech.com',   'zoya',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recruiter', 'REC002', '03002222222', 'active'),
('Bushra Malik',  'bushra@balitech.com', 'bushra', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recruiter', 'REC003', '03003333333', 'active'),
('Hamza Raza',    'hamza@balitech.com',  'hamza',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recruiter', 'REC004', '03004444444', 'active');

-- Register sample recruiters in recruiters table
INSERT IGNORE INTO `recruiters` (`user_id`, `recruiter_type`)
SELECT id, 'regular' FROM `users` WHERE email IN ('naina@balitech.com','zoya@balitech.com','bushra@balitech.com','hamza@balitech.com');

-- ============================================================
-- PAYROLL, LEAVES, NOTIFICATIONS (replaces browser localStorage)
-- ============================================================
CREATE TABLE IF NOT EXISTS `employee_payroll_meta` (
    `employee_code` VARCHAR(32) NOT NULL PRIMARY KEY,
    `basic_salary` DECIMAL(12,2) DEFAULT 50000,
    `punctuality_enabled` TINYINT(1) DEFAULT 1,
    `sudo_name` VARCHAR(150) DEFAULT NULL,
    `designation` VARCHAR(120) DEFAULT NULL,
    `cnic` VARCHAR(20) DEFAULT NULL,
    `bank_name` VARCHAR(120) DEFAULT NULL,
    `account_no` VARCHAR(50) DEFAULT NULL,
    `account_title` VARCHAR(150) DEFAULT NULL,
    `appointment_date` DATE DEFAULT NULL,
    `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_code` VARCHAR(32) NOT NULL,
    `month` CHAR(7) NOT NULL,
    `adj_type` VARCHAR(40) NOT NULL,
    `amount` DECIMAL(12,2) DEFAULT 0,
    `reason` TEXT,
    `team` VARCHAR(80) DEFAULT NULL,
    `adj_date` DATE DEFAULT NULL,
    `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
    `created_by` VARCHAR(150) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_emp_month` (`employee_code`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payroll_advances` (
    `employee_code` VARCHAR(32) NOT NULL PRIMARY KEY,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `per_month` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `skip_months` JSON DEFAULT NULL,
    `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employee_leaves` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_code` VARCHAR(32) NOT NULL,
    `leave_date` DATE NOT NULL,
    `leave_type` VARCHAR(40) DEFAULT 'approved',
    `reason` TEXT,
    `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_emp_date` (`employee_code`, `leave_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `portal_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `notification_type` VARCHAR(50) NOT NULL,
    `target_portal` VARCHAR(50) NOT NULL DEFAULT 'agent',
    `payload` JSON NOT NULL,
    `is_played` TINYINT(1) DEFAULT 0,
    `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_preferences` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `theme` VARCHAR(20) DEFAULT 'dark',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- NOTE: Default password for ALL seeded accounts is: password
-- (The hash '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' = 'password')
-- CHANGE PASSWORDS IMMEDIATELY IN PRODUCTION!
-- ============================================================
