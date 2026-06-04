<?php
require_once 'config.php';

echo "<h1>Syncing Missing Users to Database</h1>";

// Get all users from all_users.csv
$csv_file = __DIR__ . '/all_users.csv';
$all_users = [];

if (file_exists($csv_file)) {
    $handle = fopen($csv_file, 'r');
    if ($handle) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (!empty($row[0])) {
                $user_id = trim($row[0]);
                $all_users[$user_id] = [
                    'user_id' => $user_id,
                    'name' => isset($row[1]) ? trim($row[1]) : "User_{$user_id}"
                ];
            }
        }
        fclose($handle);
        echo "<p>Found " . count($all_users) . " users in all_users.csv</p>";
    }
}

// If no CSV, create range 1-1215
if (empty($all_users)) {
    echo "<p>all_users.csv not found. Creating users 1 to 1215...</p>";
    for ($i = 1; $i <= 1215; $i++) {
        $all_users[$i] = [
            'user_id' => $i,
            'name' => "User_{$i}"
        ];
    }
}

// Get existing users from database
$db_users = [];
$result = $conn->query("SELECT employee_code FROM employees");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $db_users[$row['employee_code']] = true;
    }
}
echo "<p>Existing users in database: " . count($db_users) . "</p>";

// Load Sheet4 details
$sheet4_details = [];
$sheet4_file = __DIR__ . '/Present Employee Data - Sheet4.csv';

if (file_exists($sheet4_file)) {
    $handle = fopen($sheet4_file, 'r');
    if ($handle) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (!empty($row[0])) {
                $b_id = trim($row[0]);
                $sheet4_details[$b_id] = [
                    'name' => isset($row[1]) ? trim($row[1]) : '',
                    'team' => isset($row[2]) ? trim($row[2]) : '',
                    'department' => isset($row[3]) ? trim($row[3]) : 'General',
                    'designation' => isset($row[4]) ? trim($row[4]) : 'Employee',
                    'branch' => isset($row[5]) ? trim($row[5]) : 'Head Office'
                ];
            }
        }
        fclose($handle);
        echo "<p>Loaded " . count($sheet4_details) . " details from Sheet4</p>";
    }
}

// Insert missing users
$inserted = 0;
$skipped = 0;

foreach ($all_users as $user_id => $user) {
    if (!isset($db_users[$user_id])) {
        $user_id_escaped = $conn->real_escape_string($user_id);
        $name_escaped = $conn->real_escape_string($user['name']);
        
        // Get details from Sheet4 if available
        $detail = $sheet4_details[$user_id] ?? null;
        $department = isset($detail['department']) ? $conn->real_escape_string($detail['department']) : 'General';
        $designation = isset($detail['designation']) ? $conn->real_escape_string($detail['designation']) : 'Employee';
        $branch = isset($detail['branch']) ? $conn->real_escape_string($detail['branch']) : 'Head Office';
        $team = isset($detail['team']) ? $conn->real_escape_string($detail['team']) : '';
        $detail_name = isset($detail['name']) ? $conn->real_escape_string($detail['name']) : '';
        
        $final_name = !empty($detail_name) ? $detail_name : $name_escaped;
        
        $conn->query("
            INSERT INTO employees (employee_code, full_name, department, designation, branch, team, is_active) 
            VALUES ('$user_id_escaped', '$final_name', '$department', '$designation', '$branch', '$team', 1)
        ");
        
        if ($conn->affected_rows > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }
}

echo "<h3>Results:</h3>";
echo "<p>✅ Inserted: <strong>$inserted</strong> new employees</p>";
echo "<p>⏭️ Skipped: <strong>$skipped</strong> (already existed)</p>";

// Show final count
$result = $conn->query("SELECT COUNT(*) as total FROM employees");
$total = $result->fetch_assoc()['total'];
echo "<p><strong>Total employees in database now: $total</strong></p>";

// Show sample of new users
echo "<h3>Sample of users in database now (first 20):</h3>";
$result = $conn->query("
    SELECT employee_code, full_name, department 
    FROM employees 
    ORDER BY CAST(employee_code AS UNSIGNED) ASC 
    LIMIT 20
");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Employee Code</th><th>Name</th><th>Department</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['employee_code'] . "</td>";
    echo "<td>" . $row['full_name'] . "</td>";
    echo "<td>" . $row['department'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p><a href='attendance-dashboard.html' style='background:orange; color:white; padding:10px; text-decoration:none;'>Go to Dashboard</a></p>";
?>