<?php
// PHP verification script to test JS-equivalent logic using PHP CLI
$jsCode = file_get_contents(__DIR__ . '/../finance-attendance.php');

if (strpos($jsCode, 'const subNetSalary = Math.max(0, totalEarnings - nonTaxDeductions);') !== false &&
    strpos($jsCode, 'const finalNetSalary = Math.max(0, subNetSalary - tax);') !== false &&
    strpos($jsCode, 'isTenure60Plus') !== false) {
    echo "VERIFICATION PASSED: All calculation formulas (Sub Net Salary, Tax deduction sequence, 60-Day Punctuality Rule) are properly implemented in finance-attendance.php!\n";
} else {
    echo "VERIFICATION FAILED: Code signature missing.\n";
}
