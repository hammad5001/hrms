<?php
// test_api.php - Run this to test your API
require_once 'config.php';

echo "<h1>API Test</h1>";

// Test database connection
echo "<h2>Database Connection</h2>";
if ($conn) {
    echo "✅ Database connected successfully<br>";
} else {
    echo "❌ Database connection failed<br>";
}

// Test if tables exist
echo "<h2>Tables Check</h2>";
$tables = ['branches', 'departments', 'designations', 'employees', 'attendance_raw'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✅ Table '$table' exists<br>";
    } else {
        echo "❌ Table '$table' does NOT exist<br>";
    }
}

// Test branches
echo "<h2>Branches</h2>";
$result = $conn->query("SELECT * FROM branches");
if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " branches:<br>";
    while ($row = $result->fetch_assoc()) {
        echo " - ID: " . $row['id'] . ", Name: " . $row['branch_name'] . "<br>";
    }
} else {
    echo "❌ No branches found<br>";
}

// Test departments
echo "<h2>Departments</h2>";
$result = $conn->query("SELECT * FROM departments");
if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " departments:<br>";
    while ($row = $result->fetch_assoc()) {
        echo " - ID: " . $row['id'] . ", Name: " . $row['department_name'] . "<br>";
    }
} else {
    echo "❌ No departments found<br>";
}

// Test designations
echo "<h2>Designations</h2>";
$result = $conn->query("SELECT * FROM designations");
if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " designations:<br>";
    while ($row = $result->fetch_assoc()) {
        echo " - ID: " . $row['id'] . ", Title: " . $row['title'] . "<br>";
    }
} else {
    echo "❌ No designations found<br>";
}

// Test employees
echo "<h2>Employees</h2>";
$result = $conn->query("SELECT COUNT(*) as total FROM employees");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Total employees: " . $row['total'] . "<br>";
    
    // Show sample
    $result = $conn->query("SELECT employee_code, full_name FROM employees LIMIT 5");
    if ($result->num_rows > 0) {
        echo "Sample employees:<br>";
        while ($row = $result->fetch_assoc()) {
            echo " - Code: " . $row['employee_code'] . ", Name: " . $row['full_name'] . "<br>";
        }
    }
} else {
    echo "❌ No employees found<br>";
}

// Test attendance_raw
echo "<h2>Attendance Data</h2>";
$result = $conn->query("SELECT COUNT(*) as total FROM attendance_raw");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Total attendance records: " . $row['total'] . "<br>";
    
    // Show sample
    $result = $conn->query("SELECT user_id, name, timestamp FROM attendance_raw ORDER BY timestamp DESC LIMIT 5");
    if ($result->num_rows > 0) {
        echo "Recent attendance records:<br>";
        while ($row = $result->fetch_assoc()) {
            echo " - User ID: " . $row['user_id'] . ", Name: " . $row['name'] . ", Time: " . $row['timestamp'] . "<br>";
        }
    } else {
        echo "❌ No attendance records found<br>";
    }
}

// Test the API endpoint directly
echo "<h2>API Test - getAttendanceWithDetails</h2>";
$date = date('Y-m-d');
$api_url = "attendance-api.php?action=getAttendanceWithDetails&date=$date";
echo "Calling: $api_url<br>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response) {
    echo "HTTP Code: $http_code<br>";
    $data = json_decode($response, true);
    if ($data) {
        echo "API Response:<br>";
        echo "<pre>";
        print_r($data);
        echo "</pre>";
    } else {
        echo "❌ Invalid JSON response<br>";
        echo "Raw response: " . htmlspecialchars($response) . "<br>";
    }
} else {
    echo "❌ Failed to call API<br>";
}
?>