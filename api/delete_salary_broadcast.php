<?php
session_start();
header('Content-Type: application/json');

// Ensure Super Admin or Finance access
if (!isset($_SESSION['user_id']) || ($_SESSION['portal_role'] !== 'super_admin' && $_SESSION['portal_role'] !== 'finance')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Elevated privileges required']);
    exit;
}

require_once '../config.php';

// Get JSON input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!$input || !isset($input['month']) || !isset($input['year'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data payload: Month and Year are required']);
    exit;
}

$month = $conn->real_escape_string($input['month']);
$year = (int)$input['year'];

// Run delete statement
$stmt = $conn->prepare("DELETE FROM employee_salary_slips WHERE month = ? AND year = ?");
if ($stmt) {
    $stmt->bind_param('si', $month, $year);
    if ($stmt->execute()) {
        $deleted_rows = $stmt->affected_rows;
        $stmt->close();
        
        // Clean up corresponding history record
        $histDel = $conn->prepare("DELETE FROM salary_broadcast_history WHERE month = ? AND year = ?");
        if ($histDel) {
            $histDel->bind_param('si', $month, $year);
            $histDel->execute();
            $histDel->close();
        }

        echo json_encode([
            'success' => true,
            'message' => "Successfully reverted $deleted_rows salary slips.",
            'deleted_count' => $deleted_rows
        ]);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to delete records from the database']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Database query preparation failed']);
}
?>
