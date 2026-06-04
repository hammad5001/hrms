<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/employee_resolve.php';

$requiredTables = [
    'users',
    'recruiters',
    'attendance_raw',
    'employee_payroll_meta',
    'payroll_adjustments',
    'payroll_advances',
    'employee_leaves',
    'chat_conversations',
    'chat_participants',
    'chat_messages',
];

echo "DB SANITY CHECK\n";
echo str_repeat('=', 60) . "\n";

$missing = 0;
foreach ($requiredTables as $table) {
    $q = $conn->query("SHOW TABLES LIKE '{$table}'");
    $ok = $q && $q->num_rows > 0;
    echo ($ok ? '[OK]  ' : '[MISS]') . " table: {$table}\n";
    if (!$ok) {
        $missing++;
    }
}

$rec = $conn->query("SELECT id, full_name, employee_code, status FROM users WHERE portal_role='recruiter' ORDER BY id DESC LIMIT 10");
echo str_repeat('-', 60) . "\n";
echo "Recruiter BID resolution sample:\n";
if ($rec) {
    $rows = 0;
    while ($u = $rec->fetch_assoc()) {
        $rows++;
        $res = resolve_employee_code_for_user($conn, $u, false);
        $att = fetch_attendance_bundle($conn, $res['code'], date('Y-m-d'));
        echo "- {$u['full_name']} | profile={$u['employee_code']} | resolved={$res['code']} ({$res['source']}) | punches_30d=" . count($att['attendance_raw']) . "\n";
    }
    if ($rows === 0) {
        echo "- No recruiter accounts found\n";
    }
}

$payRows = $conn->query("SELECT employee_code, basic_salary FROM employee_payroll_meta ORDER BY updated_at DESC LIMIT 5");
echo str_repeat('-', 60) . "\n";
echo "Recent payroll rows:\n";
if ($payRows) {
    $rows = 0;
    while ($p = $payRows->fetch_assoc()) {
        $rows++;
        echo "- {$p['employee_code']} | salary={$p['basic_salary']}\n";
    }
    if ($rows === 0) {
        echo "- No payroll rows found\n";
    }
}

echo str_repeat('=', 60) . "\n";
echo $missing === 0 ? "Tables: all present\n" : "Tables missing: {$missing}\n";
exit($missing === 0 ? 0 : 1);
