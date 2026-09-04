CREATE TABLE IF NOT EXISTS `payroll_adjustment_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `adjustment_id` INT(11) DEFAULT NULL,
  `source_log_id` INT(11) DEFAULT NULL,
  `employee_code` VARCHAR(100) NOT NULL,
  `employee_name` VARCHAR(150) DEFAULT NULL,
  `month` CHAR(7) NOT NULL,
  `adj_type` VARCHAR(50) NOT NULL,
  `action_type` ENUM('ADD','DEDUCT','OVERRIDE','REVERT') NOT NULL DEFAULT 'ADD',
  `amount` DECIMAL(12,2) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `before_state_json` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `after_state_json` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `performed_by_id` INT(11) DEFAULT NULL,
  `performed_by_name` VARCHAR(100) NOT NULL,
  `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_month_branch` (`month`, `company_branch`),
  KEY `idx_emp` (`employee_code`),
  KEY `idx_type_month_branch` (`adj_type`, `month`, `company_branch`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_source_log` (`source_log_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
