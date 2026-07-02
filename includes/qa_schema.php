<?php
/**
 * QA & Reporting Schema
 */
declare(strict_types=1);

function ensure_qa_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Ensure 'qa' role exists in portal_role ENUM safely by replacing it if necessary
    $res = $conn->query("SHOW COLUMNS FROM `users` LIKE 'portal_role'");
    if ($res && $row = $res->fetch_assoc()) {
        $type = $row['Type'];
        if (strpos($type, "'qa'") === false) {
            // Need to alter the ENUM
            $enum = "'super_admin','admin','hr','recruiter','management','training','agent','receptionist','user','team_lead','floor_manager','data_entry','dialer','developer','analytics','attendance','qa'";
            $conn->query("ALTER TABLE `users` MODIFY COLUMN `portal_role` ENUM($enum) NOT NULL DEFAULT 'user'");
        }
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS `agent_daily_transfers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `biometric_id` VARCHAR(32) NOT NULL,
            `customer_number` VARCHAR(50) NOT NULL,
            `customer_zip` VARCHAR(20),
            `customer_name` VARCHAR(150),
            `customer_age` VARCHAR(10),
            `transfer_on` ENUM('D1','D2') NOT NULL DEFAULT 'D1',
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_adt_bio` (`biometric_id`),
            INDEX `idx_adt_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `qa_bulk_uploads` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `uploaded_by` INT NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `total_rows` INT DEFAULT 0,
            `processed_rows` INT DEFAULT 0,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `qa_performance_stats` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `biometric_id` VARCHAR(32) NOT NULL,
            `report_date` DATE NOT NULL,
            `sales` INT NOT NULL DEFAULT 0,
            `rejected` INT NOT NULL DEFAULT 0,
            `transfers` INT NOT NULL DEFAULT 0,
            `qa_upload_id` INT NOT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_qa_bio_date` (`biometric_id`, `report_date`),
            INDEX `idx_qa_bio` (`biometric_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($queries as $sql) {
        @$conn->query($sql);
    }
}
