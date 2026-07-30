<?php
require_once __DIR__ . '/../config.php';
$_SESSION['portal_role'] = 'finance';
$_SESSION['user_id'] = 1;

ensure_app_schema($conn);

echo "=== TESTING PAYROLL API BUNDLE DATA ===\n";
$month = date('Y-m');
$branch = 'main';
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM payroll_adjustments WHERE month = ? AND company_branch = ?");
if (!$stmt) {
    echo "Prepare failed: " . $conn->error . "\n";
} else {
    $stmt->bind_param('ss', $month, $branch);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    echo "Adjustments for current month ($month): " . $row['cnt'] . "\n";
    $stmt->close();
}

echo "Testing fetchMonthBundle function from api/payroll_api.php:\n";
require_once __DIR__ . '/../api/payroll_api.php';
// test fetchMonthBundle
$bundle = fetchMonthBundle($conn, $month, $branch);
echo "Bundle fetched successfully! Keys: " . implode(', ', array_keys($bundle)) . "\n";
