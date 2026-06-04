<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);

$full_name = $conn->real_escape_string($data['full_name'] ?? '');
$father_name = $conn->real_escape_string($data['father_name'] ?? '');
$phone = $conn->real_escape_string($data['phone'] ?? '');
$email = $conn->real_escape_string($data['email'] ?? '');
$cnic = $conn->real_escape_string($data['cnic'] ?? '');
$city = $conn->real_escape_string($data['city'] ?? '');
$dob = $conn->real_escape_string($data['dob'] ?? '');
$education = $conn->real_escape_string($data['education'] ?? '');
$position_applied = $conn->real_escape_string($data['position_applied'] ?? '');
$referred_by = $conn->real_escape_string($data['referred_by'] ?? 'Walk-in');
$source = $conn->real_escape_string($data['source'] ?? 'manual');

if (!$full_name || !$phone || !$position_applied) {
    respond(false, null, 'Full name, phone, and position are required');
}

// Check for duplicate
$check = $conn->prepare("SELECT id FROM leads WHERE phone = ?");
$check->bind_param("s", $phone);
$check->execute();
$check_result = $check->get_result();

if ($check_result->num_rows > 0) {
    respond(false, null, 'Lead with this phone number already exists');
}

$user_id = $_SESSION['user_id'];
$recruiter_type = $_SESSION['recruiter_type'] ?? 'regular';

// For regular recruiters, auto-assign to themselves
$assigned_recruiter_id = $user_id;
$current_stage = 'new';

// For super admin, leave unassigned for distribution
if ($recruiter_type === 'super') {
    $assigned_recruiter_id = null;
    $current_stage = 'new';
}

$stmt = $conn->prepare("
    INSERT INTO leads (
        full_name, father_name, phone, email, cnic, city, dob, education, 
        position_applied, referred_by, source, assigned_recruiter_id, current_stage, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$stmt->bind_param(
    "sssssssssssis",
    $full_name, $father_name, $phone, $email, $cnic, $city, $dob, $education,
    $position_applied, $referred_by, $source, $assigned_recruiter_id, $current_stage
);

if ($stmt->execute()) {
    $lead_id = $conn->insert_id;
    
    // Update recruiter stats
    if ($recruiter_type !== 'super') {
        $update_stats = $conn->prepare("UPDATE recruiters SET total_leads = total_leads + 1 WHERE user_id = ?");
        $update_stats->bind_param("i", $user_id);
        $update_stats->execute();
    }
    
    respond(true, ['id' => $lead_id, 'message' => 'Lead added successfully']);
} else {
    respond(false, null, 'Database error: ' . $conn->error);
}
?>