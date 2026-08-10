# Database Schema Changes Summary (Today's Updates)

This document summarizes all database tables created, modified, or aligned in the workspace codebase ([includes/db_schema.php](file:///c:/xampp/htdocs/interview-forms/includes/db_schema.php)).

---

## 1. `teams` Table (New Table & Schema Migration)

**Description**: Stores master team definitions along with shift timing for each team. Managed exclusively by Super Admin.

```sql
CREATE TABLE IF NOT EXISTS `teams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `team_name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT DEFAULT NULL,
    `shift_start_time` TIME NOT NULL DEFAULT '18:00:00',
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration for shift_start_time if table already existed:
ALTER TABLE `teams` ADD COLUMN `shift_start_time` TIME NOT NULL DEFAULT '18:00:00' AFTER `description`;
```

---

## 2. `users` Table (Modified & Collation Aligned)

**Description**: Main system user accounts table. Stores employee portal user profile, credentials, role, assigned team, and branch.

```sql
-- Collation Alignment for employee_code:
ALTER TABLE `users` MODIFY COLUMN `employee_code` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

-- Key Columns Used:
-- `team` (VARCHAR 80): Synced with teams.team_name
-- `company_branch` (VARCHAR 32): Main / Commercial / WorkFromHome
```

---

## 3. `employees` Table (Modified & Collation Aligned)

**Description**: Employee profiles for Main branch attendance and HR records.

```sql
-- Collation Alignment for employee_code:
ALTER TABLE `employees` MODIFY COLUMN `employee_code` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

-- Key Columns Used:
-- `team` (VARCHAR 80): Synced with users.team
-- `branch` (VARCHAR 32): Branch designation
```

---

## 4. `employees_commercial` Table (Modified & Collation Aligned)

**Description**: Commercial Branch employee records table.

```sql
-- Collation Conversion for Commercial Branch UNION Queries:
ALTER TABLE `employees_commercial` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Key Columns Used:
-- `employee_code` (VARCHAR 100): Unique employee identifier
-- `team` (VARCHAR 80): Synced team name
-- `branch` (VARCHAR 32): Commercial branch tag
```

---

## 5. `employee_payroll_meta` Table (Collation Aligned)

**Description**: Per-employee payroll metadata (basic salary, punctuality settings, bank details, appointment date).

```sql
-- Collation Alignment:
ALTER TABLE `employee_payroll_meta` MODIFY COLUMN `employee_code` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
```

---

## 6. Summary Matrix

| Table Name | Action Type | Purpose / Highlight |
| :--- | :--- | :--- |
| **`teams`** | **CREATE / ALTER** | Added master teams table & `shift_start_time` field. |
| **`users`** | **ALTER** | Aligned `employee_code` collation to `utf8mb4_unicode_ci` and updated team dropdown mapping. |
| **`employees`** | **ALTER** | Aligned `employee_code` collation to `utf8mb4_unicode_ci` and synced assigned team. |
| **`employees_commercial`** | **ALTER** | Converted collation to `utf8mb4_unicode_ci` for Commercial branch `UNION` queries. |
| **`employee_payroll_meta`** | **ALTER** | Aligned `employee_code` collation to `utf8mb4_unicode_ci` for cross-table JOINs. |
