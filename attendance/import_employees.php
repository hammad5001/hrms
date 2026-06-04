<?php
require_once 'config.php';

// Path to your Excel file
$excel_file = __DIR__ . '/python-script/all_users.xlsx';

// Check if file exists
if (!file_exists($excel_file)) {
    die("Excel file not found at: $excel_file");
}

// Load the Excel file (you need PHPExcel or similar library)
// For simplicity, let's use a CSV approach if you can export from Excel

echo "<h1>Import Employees</h1>";

// If you have a CSV version, use this:
$csv_file = __DIR__ . '/python-script/all_users.csv';

if (file_exists($csv_file)) {
    $file = fopen($csv_file, 'r');
    $headers = fgetcsv($file);
    
    $imported = 0;
    $skipped = 0;
    
    while (($row = fgetcsv($file)) !== FALSE) {
        $user_id = $row[0] ?? '';
        $name = $row[1] ?? '';
        $privilege = $row[2] ?? 'User';
        
        if (empty($user_id) || empty($name)) {
            $skipped++;
            continue;
        }
        
        // Check if employee already exists
        $check = $conn->query("SELECT id FROM employees WHERE employee_code = '$user_id'");
        
        if ($check->num_rows == 0) {
            $conn->query("
                INSERT INTO employees (employee_code, full_name, department, is_active)
                VALUES ('$user_id', '$name', 'General', 1)
            ");
            $imported++;
        } else {
            $skipped++;
        }
    }
    
    fclose($file);
    echo "<p>✅ Imported: $imported employees</p>";
    echo "<p>⏭️ Skipped: $skipped duplicates</p>";
} else {
    echo "<p>❌ CSV file not found. Please export all_users.xlsx to CSV first.</p>";
}

// Show current employees
$result = $conn->query("SELECT COUNT(*) as total FROM employees");
$row = $result->fetch_assoc();
echo "<p>Total employees in database: " . $row['total'] . "</p>";
?>