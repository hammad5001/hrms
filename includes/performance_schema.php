<?php
/**
 * Employee Performance — daily report schema (submit once, no edit).
 */
declare(strict_types=1);

function ensure_performance_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $queries = [
        "CREATE TABLE IF NOT EXISTS `employee_daily_reports` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
            `report_date` DATE NOT NULL,
            `calls_made` INT NOT NULL DEFAULT 0,
            `sales_closed` INT NOT NULL DEFAULT 0,
            `transfers_done` INT NOT NULL DEFAULT 0,
            `leads_contacted` INT NOT NULL DEFAULT 0,
            `follow_ups` INT NOT NULL DEFAULT 0,
            `callbacks_done` INT NOT NULL DEFAULT 0,
            `talk_minutes` INT NOT NULL DEFAULT 0,
            `day_summary` TEXT,
            `submitted_at` DATETIME NOT NULL,
            UNIQUE KEY `uq_daily_report` (`user_id`, `report_date`, `company_branch`),
            INDEX `idx_edr_user_date` (`user_id`, `report_date`),
            INDEX `idx_edr_branch_date` (`company_branch`, `report_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $sql) {
        $conn->query($sql);
    }
}
