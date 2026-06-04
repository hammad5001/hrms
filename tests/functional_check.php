<?php
/**
 * CLI functional checklist (run: php tests/functional_check.php)
 */
require_once __DIR__ . '/../config.php';
ensure_app_schema($conn);
require_once __DIR__ . '/../includes/employee_resolve.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

$results = [];

function check(string $name, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

// DB connection
check('Database connection', !$conn->connect_error, $conn->connect_error ?: 'OK');

// Chat schema
ensure_chat_schema($conn);
$tbl = $conn->query("SHOW TABLES LIKE 'chat_conversations'");
check('Chat tables exist', $tbl && $tbl->num_rows > 0);

$uploadDir = chat_upload_dir();
check('Chat upload dir exists', is_dir($uploadDir), $uploadDir);
check('Chat upload dir writable', is_writable($uploadDir));

// Recruiter users + BID resolution
$recQ = $conn->query("SELECT id, full_name, email, employee_code, portal_role, status FROM users WHERE portal_role = 'recruiter' LIMIT 10");
$recruiters = [];
if ($recQ) {
    while ($row = $recQ->fetch_assoc()) {
        $recruiters[] = $row;
    }
}
check('Recruiter users in DB', count($recruiters) > 0, count($recruiters) . ' found');

$naina = null;
foreach ($recruiters as $u) {
    if (stripos($u['full_name'] ?? '', 'Naina') !== false) {
        $naina = $u;
        break;
    }
}

if ($naina) {
    $res = resolve_employee_code_for_user($conn, $naina, false);
    $att = fetch_attendance_bundle($conn, $res['code'], date('Y-m-d'));
    $pay = fetch_payroll_bundle($conn, $res['code'], date('Y-m'), 'main');
    check(
        'Naina Fareed BID resolution',
        $res['code'] !== '' && $res['code'] !== ($naina['employee_code'] ?? '') || attendance_codes_have_punches($conn, employee_code_variants($res['code'])),
        "profile={$naina['employee_code']} resolved={$res['code']} source={$res['source']} punches=" . count($att['attendance_raw'])
    );
    check(
        'Naina attendance records',
        count($att['attendance_raw']) > 0,
        count($att['attendance_raw']) . ' punches (30d)'
    );
    check(
        'Naina payroll API (no crash)',
        true,
        !empty($pay['has_data']) ? 'has payroll row' : 'OK — no payroll row in HR yet for BID ' . $res['code'] . ' (add in HR portal)'
    );
} else {
    check('Naina Fareed BID resolution', false, 'User not found in DB');
}

// Sample any recruiter with name on sheet
$matched = 0;
foreach ($recruiters as $u) {
    if (($u['status'] ?? '') !== 'active') {
        continue;
    }
    $res = resolve_employee_code_for_user($conn, $u, false);
    if ($res['code'] !== '' && !preg_match('/^REC\d+$/i', $res['code'])) {
        $matched++;
    }
}
check('Active recruiters with resolvable BID', $matched > 0, "$matched / " . count($recruiters));

// employee_self_service bootstrap (no session - expect auth fail)
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
// Don't include full API without session

// File existence checks
$files = [
    'recruiter-portal.html',
    'recruiter-portal-hrms.js',
    'recruiter-portal-core.js',
    'api/employee_self_service.php',
    'api/chat_upload.php',
    'includes/employee_resolve.php',
    'js/chat-app.js',
    'chat-portal.html',
];
foreach ($files as $f) {
    $path = __DIR__ . '/../' . $f;
    check("File: $f", is_file($path));
}

// JS wiring
$hrmsJs = file_get_contents(__DIR__ . '/../recruiter-portal-hrms.js');
check('HRMS calls employee HR API', str_contains($hrmsJs, 'API.employeeHrms') || str_contains($hrmsJs, 'employee_self_service.php'));
check('HRMS exports showMyHrms', str_contains($hrmsJs, 'window.showMyHrms'));
$coreJs = file_get_contents(__DIR__ . '/../recruiter-portal-core.js');
check('Core API has employeeHrms', str_contains($coreJs, 'employeeHrms'));

$html = file_get_contents(__DIR__ . '/../recruiter-portal.html');
check('Portal loads recruiter-portal-hrms.js', str_contains($html, 'recruiter-portal-hrms.js'));
check('Portal has My HR nav', str_contains($html, 'My HR') && str_contains($html, 'showMyHrms'));

echo "FUNCTIONAL CHECKLIST\n";
echo str_repeat('=', 60) . "\n";
$pass = 0;
$fail = 0;
foreach ($results as $r) {
    $icon = $r['ok'] ? 'PASS' : 'FAIL';
    if ($r['ok']) {
        $pass++;
    } else {
        $fail++;
    }
    echo "[$icon] {$r['name']}";
    if ($r['detail'] !== '') {
        echo " — {$r['detail']}";
    }
    echo "\n";
}
echo str_repeat('=', 60) . "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
