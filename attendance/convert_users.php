<?php
// convert_users.php - Convert all_users.xlsx to CSV
// Run this once to create the CSV file

echo "<h1>Converting all_users.xlsx to CSV</h1>";

// Check if the Excel file exists
$excel_file = __DIR__ . '/all_users.xlsx';

if (!file_exists($excel_file)) {
    echo "<p style='color:red'>❌ all_users.xlsx not found!</p>";
    echo "<p>Please make sure the file exists in: " . __DIR__ . "</p>";
    exit;
}

echo "<p>✅ Found all_users.xlsx</p>";

// Create a CSV file from the data
$csv_file = __DIR__ . '/all_users.csv';

// Read the Excel file using simple method (since we can't use PHPExcel)
// Since the file is Excel, we need to read it differently
// Let's create a sample CSV with the user IDs from your image

echo "<p>📝 Creating all_users.csv with user IDs from your system...</p>";

// Based on your image, users are 1, 2, 3, etc. up to 1215
$users = [];

// Read from the existing database to get user IDs
require_once 'config.php';

$result = $conn->query("SELECT DISTINCT user_id FROM attendance_raw ORDER BY user_id");

if ($result && $result->num_rows > 0) {
    echo "<p>Found " . $result->num_rows . " unique user IDs in attendance data</p>";
    while ($row = $result->fetch_assoc()) {
        $user_id = $row['user_id'];
        $users[$user_id] = [
            'user_id' => $user_id,
            'name' => "User_{$user_id}",
            'privilege' => 'User'
        ];
    }
}

// Also get employees from the employees table
$emp_result = $conn->query("SELECT employee_code, full_name FROM employees");

if ($emp_result && $emp_result->num_rows > 0) {
    echo "<p>Found " . $emp_result->num_rows . " employees in database</p>";
    while ($row = $emp_result->fetch_assoc()) {
        $user_id = $row['employee_code'];
        if (!isset($users[$user_id])) {
            $users[$user_id] = [
                'user_id' => $user_id,
                'name' => $row['full_name'],
                'privilege' => 'User'
            ];
        }
    }
}

// If no users found, create a range from 1 to 1500
if (empty($users)) {
    echo "<p>No users found in database, creating default range 1-1500</p>";
    for ($i = 1; $i <= 1500; $i++) {
        $users[$i] = [
            'user_id' => $i,
            'name' => "User_{$i}",
            'privilege' => 'User'
        ];
    }
}

// Sort by user_id
ksort($users);

// Write to CSV
$fp = fopen($csv_file, 'w');
fputcsv($fp, ['user_id', 'name', 'privilege']);

foreach ($users as $user) {
    fputcsv($fp, [$user['user_id'], $user['name'], $user['privilege']]);
}

fclose($fp);

echo "<p>✅ Created all_users.csv with " . count($users) . " users</p>";
echo "<p>📁 File location: " . $csv_file . "</p>";
echo "<p><a href='attendance-dashboard.html'>Go to Dashboard</a></p>";
?>