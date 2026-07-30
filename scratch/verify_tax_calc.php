<?php
function calcTax($subNetSalary) {
    $annualIncome = $subNetSalary * 12;
    $annualTax = 0;
    if ($annualIncome <= 600000) {
        $annualTax = 0;
    } else if ($annualIncome <= 1200000) {
        $annualTax = ($annualIncome - 600000) * 0.01;
    } else if ($annualIncome <= 2200000) {
        $annualTax = 6000 + ($annualIncome - 1200000) * 0.11;
    } else if ($annualIncome <= 3200000) {
        $annualTax = 116000 + ($annualIncome - 2200000) * 0.20;
    } else if ($annualIncome <= 4100000) {
        $annualTax = 316000 + ($annualIncome - 3200000) * 0.25;
    } else if ($annualIncome <= 5600000) {
        $annualTax = 541000 + ($annualIncome - 4100000) * 0.29;
    } else if ($annualIncome <= 7000000) {
        $annualTax = 976000 + ($annualIncome - 5600000) * 0.32;
    } else {
        $annualTax = 1424000 + ($annualIncome - 7000000) * 0.35;
    }
    $monthlyTax = round($annualTax / 12);
    $finalNetSalary = max(0, $subNetSalary - $monthlyTax);
    return [
        'subNetSalary' => $subNetSalary,
        'annualIncome' => $annualIncome,
        'annualTax' => $annualTax,
        'monthlyTax' => $monthlyTax,
        'finalNetSalary' => $finalNetSalary
    ];
}

$testCases = [
    50000,   // Annual 600k -> 0 tax
    75000,   // Annual 900k -> 3,000 annual -> 250/mo tax
    109309,  // Emp 5052 SUB Net -> 1,311,708 annual -> 6000 + 11% of 111,708 = 18,287.88 -> 1,524/mo tax
    150000,  // Annual 1.8M -> 6000 + 11% of 600k = 72,000 -> 6,000/mo tax
    250000,  // Annual 3.0M -> 116,000 + 20% of 800k = 276,000 -> 23,000/mo tax
];

echo "=== PAKISTAN FY 2026-2027 TAX CALCULATION TEST RESULTS ===\n\n";
foreach ($testCases as $sub) {
    $res = calcTax($sub);
    echo sprintf("SUB Net: Rs %-8s | Annual: Rs %-10s | Monthly Tax: Rs %-6s | Final Net: Rs %-8s\n",
        number_format($res['subNetSalary']),
        number_format($res['annualIncome']),
        number_format($res['monthlyTax']),
        number_format($res['finalNetSalary'])
    );
}
