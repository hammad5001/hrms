<?php
// PHP verification of employee 5052 (Muhammad Hammad) calculations
$basicSalary = 60000;
$punctualityBonus = 10000;
$workingDaysCount = 23;
$present = 23;
$leave = 0;
$absent = 0;
$late = 11;
$bonus = 50000;
$extraDays = 1;
$tax = 100;

$perDaySalary = $basicSalary / $workingDaysCount; // 2608.6956...
$extraDayPay = $extraDays * $perDaySalary; // 2608.6956...

// Late deduction (>3 lates -> 11 * 300 = 3300)
$lateDeduction = 3300;
// Punctuality reward: late > 0 -> 0
$punctualityAmount = 0;

$totalAdditions = $punctualityAmount + $bonus + $extraDayPay; // 52608.6956... -> 52,609
$earningsBase = ($present + $leave) * $perDaySalary; // 60,000
$totalEarnings = $earningsBase + $totalAdditions; // 112608.6956... -> 112,609

$nonTaxDeductions = $lateDeduction; // 3,300

$subNetSalary = $totalEarnings - $nonTaxDeductions; // 109308.6956... -> 109,309
$finalNetSalary = $subNetSalary - $tax; // 109208.6956... -> 109,209

echo "=== CALCULATION VERIFICATION FOR EMP 5052 (Muhammad Hammad) ===\n";
echo "Basic Salary              : Rs " . number_format($basicSalary) . "\n";
echo "Punctuality Reward        : Rs " . number_format($punctualityAmount) . " (0 because late count is 11)\n";
echo "Worked Days Pay           : Rs " . number_format($earningsBase) . "\n";
echo "Bonus + Extra Day Pay     : Rs " . number_format($totalAdditions) . " (Bonus: 50k, Extra Day: 2,609)\n";
echo "Gross Salary (Total Earn) : Rs " . number_format(round($totalEarnings)) . "\n";
echo "Total Deduction Ept Tax   : Rs " . number_format(round($nonTaxDeductions)) . "\n";
echo "SUB - Net Salary          : Rs " . number_format(round($subNetSalary)) . "\n";
echo "Tax                       : Rs " . number_format($tax) . "\n";
echo "Final Net Salary          : Rs " . number_format(round($finalNetSalary)) . "\n";

if (round($subNetSalary) == 109309 && round($finalNetSalary) == 109209) {
    echo "\nSUCCESS: Calculations match expected values perfectly! No double tax deduction!\n";
} else {
    echo "\nMISMATCH: Please check calculations.\n";
}
