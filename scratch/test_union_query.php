<?php
require_once __DIR__ . '/../api/config.php';

// Fix table collations for employees_commercial as well
$conn->query("ALTER TABLE employees_commercial CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->query("ALTER TABLE employees CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->query("ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$sql = "
    SELECT 
        e.employee_code COLLATE utf8mb4_unicode_ci as employee_code, 
        e.id, 
        e.full_name COLLATE utf8mb4_unicode_ci as full_name, 
        e.department COLLATE utf8mb4_unicode_ci as department, 
        COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') COLLATE utf8mb4_unicode_ci as team,
        COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), NULLIF(e.branch, ''), 'Main') COLLATE utf8mb4_unicode_ci as branch,
        COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), NULLIF(e.designation, ''), 'Employee') COLLATE utf8mb4_unicode_ci as designation
    FROM employees e
    LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
    LEFT JOIN employee_payroll_meta m ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci)
    WHERE e.is_active = 1

    UNION

    SELECT 
        ec.employee_code COLLATE utf8mb4_unicode_ci as employee_code, 
        ec.id, 
        ec.full_name COLLATE utf8mb4_unicode_ci as full_name, 
        ec.department COLLATE utf8mb4_unicode_ci as department, 
        COALESCE(NULLIF(u.team, ''), NULLIF(ec.team, ''), '') COLLATE utf8mb4_unicode_ci as team,
        COALESCE(NULLIF(m.company_branch, ''), NULLIF(u.company_branch, ''), NULLIF(u.branch, ''), NULLIF(ec.branch, ''), 'Commercial') COLLATE utf8mb4_unicode_ci as branch,
        COALESCE(NULLIF(m.designation, ''), NULLIF(u.designation, ''), NULLIF(ec.designation, ''), 'Employee') COLLATE utf8mb4_unicode_ci as designation
    FROM employees_commercial ec
    LEFT JOIN users u ON (ec.employee_code IS NOT NULL AND ec.employee_code != '' AND ec.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
    LEFT JOIN employee_payroll_meta m ON (ec.employee_code IS NOT NULL AND ec.employee_code != '' AND ec.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci)
    WHERE ec.is_active = 1
";

$res = $conn->query($sql);
if (!$res) {
    echo "DB Error: " . $conn->error . "\n";
} else {
    echo "Total Combined Employees: " . $res->num_rows . "\n";
    $commercialCount = 0;
    $mainCount = 0;
    while ($row = $res->fetch_assoc()) {
        $b = strtolower($row['branch']);
        if (strpos($b, 'commercial') !== false) {
            $commercialCount++;
        } else {
            $mainCount++;
        }
    }
    echo "Commercial Branch Employees: $commercialCount\n";
    echo "Main / Other Branch Employees: $mainCount\n";
}
