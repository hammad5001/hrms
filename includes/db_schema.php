<?php
/**
 * Ensures all application tables exist (payroll, leaves, notifications).
 */
function ensure_app_schema(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (function_exists('ensure_company_branch_schema')) {
        ensure_company_branch_schema($conn);
    }

    if (function_exists('fix_misassigned_portal_roles')) {
        fix_misassigned_portal_roles($conn);
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS `employee_payroll_meta` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
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
            INDEX `idx_emp_month` (`employee_code`, `month`),
            INDEX `idx_month_type` (`month`, `adj_type`),
            INDEX `idx_branch` (`company_branch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `payroll_advances` (
            `employee_code` VARCHAR(32) NOT NULL PRIMARY KEY,
            `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `per_month` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `skip_months` JSON DEFAULT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_leaves` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_code` VARCHAR(32) NOT NULL,
            `leave_date` DATE NOT NULL,
            `leave_type` VARCHAR(40) DEFAULT 'approved',
            `reason` TEXT,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_emp_date` (`employee_code`, `leave_date`),
            INDEX `idx_emp` (`employee_code`),
            INDEX `idx_branch` (`company_branch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `portal_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `notification_type` VARCHAR(50) NOT NULL,
            `target_portal` VARCHAR(50) NOT NULL DEFAULT 'reception',
            `payload` JSON NOT NULL,
            `is_played` TINYINT(1) DEFAULT 0,
            `played_at` DATETIME DEFAULT NULL,
            `played_by_portal` VARCHAR(50) DEFAULT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_target_played` (`target_portal`, `is_played`),
            INDEX `idx_branch` (`company_branch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `interviews` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `lead_id` INT NOT NULL,
            `scheduled_by` INT NOT NULL,
            `scheduled_date` DATE NOT NULL,
            `scheduled_time` VARCHAR(10) DEFAULT NULL,
            `location` VARCHAR(255) DEFAULT NULL,
            `interviewer_name` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT,
            `status` ENUM('scheduled','reception_checked_in','hr_in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_int_lead` (`lead_id`),
            INDEX `idx_int_status` (`status`),
            INDEX `idx_int_date` (`scheduled_date`),
            INDEX `idx_int_branch` (`company_branch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `user_preferences` (
            `user_id` INT NOT NULL PRIMARY KEY,
            `theme` VARCHAR(20) DEFAULT 'dark',
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `leave_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `employee_code` VARCHAR(32) NOT NULL,
            `employee_name` VARCHAR(150) NOT NULL,
            `team` VARCHAR(80) DEFAULT NULL,
            `department` VARCHAR(120) DEFAULT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `leave_type` VARCHAR(40) NOT NULL DEFAULT 'annual',
            `duration_type` ENUM('full_day','half_day') NOT NULL DEFAULT 'full_day',
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `half_day_slot` VARCHAR(20) DEFAULT NULL,
            `reason` TEXT NOT NULL,
            `apply_through` ENUM('team_lead','floor_manager','hr') NOT NULL,
            `status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `tl_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
            `fm_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
            `hr_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
            `tl_user_id` INT DEFAULT NULL,
            `fm_user_id` INT DEFAULT NULL,
            `hr_user_id` INT DEFAULT NULL,
            `tl_note` TEXT,
            `fm_note` TEXT,
            `hr_note` TEXT,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_lr_emp` (`employee_code`),
            INDEX `idx_lr_status` (`status`),
            INDEX `idx_lr_team` (`team`),
            INDEX `idx_lr_branch` (`company_branch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `leave_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `recipient_user_id` INT NOT NULL,
            `leave_request_id` INT NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `message` TEXT,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ln_user` (`recipient_user_id`, `is_read`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_reporting` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_user_id` INT NOT NULL,
            `employee_code` VARCHAR(32) DEFAULT NULL,
            `employee_name` VARCHAR(150) DEFAULT NULL,
            `manager_user_id` INT NOT NULL,
            `manager_code` VARCHAR(32) DEFAULT NULL,
            `manager_name` VARCHAR(150) DEFAULT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_employee_user` (`employee_user_id`),
            INDEX `idx_manager` (`manager_user_id`),
            INDEX `idx_branch` (`company_branch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_profile_details` (
            `user_id` INT NOT NULL PRIMARY KEY,
            `date_of_birth` DATE DEFAULT NULL,
            `expertise` VARCHAR(255) DEFAULT NULL,
            `marital_status` VARCHAR(40) DEFAULT NULL,
            `about_me` TEXT DEFAULT NULL,
            `emergency_contact` VARCHAR(120) DEFAULT NULL,
            `personal_mobile` VARCHAR(20) DEFAULT NULL,
            `extension` VARCHAR(20) DEFAULT NULL,
            `personal_email` VARCHAR(150) DEFAULT NULL,
            `present_address` TEXT DEFAULT NULL,
            `permanent_address` TEXT DEFAULT NULL,
            `added_by_user_id` INT DEFAULT NULL,
            `modified_by_user_id` INT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_profile_modified` (`modified_by_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $sql) {
        @$conn->query($sql);
    }

    ensure_payroll_meta_columns($conn);
    ensure_interview_columns($conn);
    ensure_notification_columns($conn);
    ensure_leave_request_columns($conn);
    ensure_leads_pipeline_indexes($conn);
}

function ensure_leave_request_columns(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cols = [
        'approver_user_id' => 'INT DEFAULT NULL',
        'approver_name' => 'VARCHAR(150) DEFAULT NULL',
        'is_policy_allotment' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'allotted_by_user_id' => 'INT DEFAULT NULL',
        'allotted_by_name' => 'VARCHAR(150) DEFAULT NULL',
    ];
    foreach ($cols as $col => $def) {
        $res = $conn->query("SHOW COLUMNS FROM `leave_requests` LIKE '$col'");
        if ($res && $res->num_rows === 0) {
            @$conn->query("ALTER TABLE `leave_requests` ADD COLUMN `$col` $def");
        }
    }
}

/** Speed up portal list queries by stage + branch. */
function ensure_leads_pipeline_indexes(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $indexes = [
        'leads' => [
            'idx_leads_stage_branch_updated' => 'CREATE INDEX idx_leads_stage_branch_updated ON leads (current_stage, company_branch, updated_at)',
        ],
        'lead_remarks' => [
            'idx_lead_remarks_lead_created' => 'CREATE INDEX idx_lead_remarks_lead_created ON lead_remarks (lead_id, created_at)',
        ],
    ];

    foreach ($indexes as $table => $defs) {
        foreach ($defs as $name => $sql) {
            $chk = $conn->prepare("
                SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                LIMIT 1
            ");
            if (!$chk) {
                continue;
            }
            $chk->bind_param('ss', $table, $name);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                @$conn->query($sql);
            }
            $chk->close();
        }
    }
}

/** Align legacy payroll tables with current app schema. */
function ensure_payroll_meta_columns(mysqli $conn): void {
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;

    $table = 'employee_payroll_meta';
    $needed = [
        'designation' => "ALTER TABLE `$table` ADD COLUMN `designation` VARCHAR(120) DEFAULT NULL",
        'sudo_name' => "ALTER TABLE `$table` ADD COLUMN `sudo_name` VARCHAR(150) DEFAULT NULL",
        'company_branch' => "ALTER TABLE `$table` ADD COLUMN `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main'",
    ];

    foreach ($needed as $col => $sql) {
        $chk = $conn->prepare("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$chk) {
            continue;
        }
        $chk->bind_param('ss', $table, $col);
        $chk->execute();
        if (!$chk->get_result()->fetch_row()) {
            @$conn->query($sql);
        }
    }
}

function ensure_interview_columns(mysqli $conn): void {
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    $table = 'interviews';
    $needed = [
        'scheduled_by' => "ALTER TABLE `$table` ADD COLUMN `scheduled_by` INT NOT NULL DEFAULT 0",
        'scheduled_date' => "ALTER TABLE `$table` ADD COLUMN `scheduled_date` DATE NOT NULL DEFAULT (CURDATE())",
        'scheduled_time' => "ALTER TABLE `$table` ADD COLUMN `scheduled_time` VARCHAR(10) DEFAULT NULL",
        'location' => "ALTER TABLE `$table` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL",
        'interviewer_name' => "ALTER TABLE `$table` ADD COLUMN `interviewer_name` VARCHAR(255) DEFAULT NULL",
        'notes' => "ALTER TABLE `$table` ADD COLUMN `notes` TEXT",
        'company_branch' => "ALTER TABLE `$table` ADD COLUMN `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main'",
        'updated_at' => "ALTER TABLE `$table` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($needed as $col => $sql) {
        $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        if (!$chk) {
            continue;
        }
        $chk->bind_param('ss', $table, $col);
        $chk->execute();
        if (!$chk->get_result()->fetch_row()) {
            @$conn->query($sql);
        }
    }
}

function ensure_notification_columns(mysqli $conn): void {
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    $table = 'portal_notifications';
    $needed = [
        'played_at' => "ALTER TABLE `$table` ADD COLUMN `played_at` DATETIME DEFAULT NULL",
        'played_by_portal' => "ALTER TABLE `$table` ADD COLUMN `played_by_portal` VARCHAR(50) DEFAULT NULL",
    ];
    foreach ($needed as $col => $sql) {
        $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        if (!$chk) {
            continue;
        }
        $chk->bind_param('ss', $table, $col);
        $chk->execute();
        if (!$chk->get_result()->fetch_row()) {
            @$conn->query($sql);
        }
    }
}
