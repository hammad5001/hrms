<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';

// Ensure tables exist
ensure_app_schema($conn);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$customer_number = trim($input['customer_number'] ?? '');
$customer_zip = trim($input['customer_zip'] ?? '');
$customer_name = trim($input['customer_name'] ?? '');
$customer_age = trim($input['customer_age'] ?? '');
$transfer_on = trim($input['transfer_on'] ?? '');

if (empty($customer_number) || empty($transfer_on)) {
    echo json_encode(['success' => false, 'message' => 'Customer Number and Transfer On are required fields.']);
    exit;
}

if (!in_array($transfer_on, ['D1', 'D2'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid transfer option. Must be D1 or D2.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$biometric_id = $_SESSION['employee_code'] ?? ''; // DID
$company_branch = $_SESSION['company_branch'] ?? 'main';

if (empty($biometric_id)) {
    echo json_encode(['success' => false, 'message' => 'Error: Your account does not have a Biometric ID (DID) assigned. Please contact HR.']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO agent_daily_transfers (user_id, biometric_id, customer_number, customer_zip, customer_name, customer_age, transfer_on, company_branch) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssssss", $user_id, $biometric_id, $customer_number, $customer_zip, $customer_name, $customer_age, $transfer_on, $company_branch);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Transfer reported successfully!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}

$stmt->close();
?>
