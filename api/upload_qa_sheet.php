<?php
session_start();
header('Content-Type: application/json');

$role = $_SESSION['portal_role'] ?? 'user';
if (!isset($_SESSION['user_id']) || ($role !== 'super_admin' && $role !== 'qa')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized Access']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';
// Production: schema migrations are run manually during deployment.

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['data'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or empty data provided']);
    exit;
}

$filename = $conn->real_escape_string($input['filename'] ?? 'unknown.csv');
$data = $input['data'];
$uploaded_by = $_SESSION['user_id'];
$company_branch = $_SESSION['company_branch'] ?? 'main';

// 1. Record the Upload
$stmt = $conn->prepare("INSERT INTO qa_bulk_uploads (uploaded_by, filename, total_rows, company_branch) VALUES (?, ?, ?, ?)");
$total = count($data);
$stmt->bind_param("isis", $uploaded_by, $filename, $total, $company_branch);
$stmt->execute();
$upload_id = $stmt->insert_id;
$stmt->close();

// 2. Process Rows
$processed = 0;
$insertStmt = $conn->prepare("
    INSERT INTO qa_performance_stats (biometric_id, report_date, sales, rejected, transfers, qa_upload_id, company_branch) 
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        sales = VALUES(sales), 
        rejected = VALUES(rejected), 
        transfers = VALUES(transfers), 
        qa_upload_id = VALUES(qa_upload_id)
");

foreach ($data as $row) {
    // Find keys flexibly
    $bioKey = $dateKey = $salesKey = $rejKey = $transKey = null;
    
    foreach (array_keys($row) as $k) {
        $lk = strtolower(trim($k));
        if (strpos($lk, 'biometric') !== false || strpos($lk, 'did') !== false || $lk === 'id') $bioKey = $k;
        if (strpos($lk, 'date') !== false) $dateKey = $k;
        if (strpos($lk, 'sales') !== false) $salesKey = $k;
        if (strpos($lk, 'reject') !== false) $rejKey = $k;
        if (strpos($lk, 'transfer') !== false) $transKey = $k;
    }

    if (!$bioKey || !$dateKey) {
        continue; // Skip if missing required keys
    }

    $bioId = trim((string)$row[$bioKey]);
    $dateStr = trim((string)$row[$dateKey]);
    
    // Attempt to format date to YYYY-MM-DD
    $time = strtotime($dateStr);
    if (!$time) {
        // Handle Excel numeric date (days since 1900)
        if (is_numeric($dateStr)) {
            $time = ($dateStr - 25569) * 86400; 
        } else {
            continue; // Cannot parse date
        }
    }
    $reportDate = date('Y-m-d', $time);

    $sales = isset($row[$salesKey]) ? (int)$row[$salesKey] : 0;
    $rejected = isset($row[$rejKey]) ? (int)$row[$rejKey] : 0;
    $transfers = isset($row[$transKey]) ? (int)$row[$transKey] : 0;

    if (!empty($bioId)) {
        $insertStmt->bind_param("ssiiiis", $bioId, $reportDate, $sales, $rejected, $transfers, $upload_id, $company_branch);
        if ($insertStmt->execute()) {
            $processed++;
        }
    }
}
$insertStmt->close();

// Update processed rows count
$conn->query("UPDATE qa_bulk_uploads SET processed_rows = $processed WHERE id = $upload_id");

echo json_encode(['success' => true, 'processed_rows' => $processed]);
?>
