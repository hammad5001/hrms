-- Run once in phpMyAdmin (balitech database)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main' AFTER `branch`;

ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main' AFTER `source`;

ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_company_branch` (`company_branch`);
ALTER TABLE `leads` ADD INDEX IF NOT EXISTS `idx_company_branch` (`company_branch`);

UPDATE `users` SET `company_branch` = 'main' WHERE `company_branch` IS NULL OR `company_branch` = '';
