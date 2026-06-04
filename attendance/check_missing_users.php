<?php
require_once 'config.php';

echo "<h1>Check Missing Users</h1>";

// Get all users from database
$db_users = [];
$result = $conn->query("SELECT employee_code FROM employees");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $db_users[$row['employee_code']] = true;
    }
}
echo "<p>Users in database: " . count($db_users) . "</p>";

// Try to get users from all_users.csv
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
        echo "<p>Users in all_users.csv: " . count($all_users) . "</p>";
    }
}

// If no CSV, create a range based on max code
if (empty($all_users)) {
    echo "<p>all_users.csv not found. Creating range from 1 to 1215...</p>";
    for ($i = 1; $i <= 1215; $i++) {
        $all_users[$i] = [
            'user_id' => $i,
            'name' => "User_{$i}"
        ];
    }
}

// Find missing users
$missing_users = [];
foreach ($all_users as $user_id => $user) {
    if (!isset($db_users[$user_id])) {
        $missing_users[] = $user;
    }
}

echo "<h3>Missing Users: " . count($missing_users) . "</h3>";

if (count($missing_users) > 0) {
    echo "<p>First 50 missing users:</p>";
    echo "<ul>";
    foreach (array_slice($missing_users, 0, 50) as $user) {
        echo "<li>User ID: " . $user['user_id'] . " - " . $user['name'] . "</li>";
    }
    if (count($missing_users) > 50) {
        echo "<li>... and " . (count($missing_users) - 50) . " more</li>";
    }
    echo "</ul>";
    
    echo "<p><a href='sync_missing_users.php' style='background:green; color:white; padding:10px; text-decoration:none;'>Click here to sync missing users</a></p>";
} else {
    echo "<p style='color:green'>✅ All users are in the database!</p>";
}
?>