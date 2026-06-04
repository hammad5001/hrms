<?php
// test_csv.php - Test CSV loading

require_once 'attendance-api.php';

echo "<h1>CSV Test</h1>";

$csv_data = loadEmployeeDataFromCSV();
echo "<h2>Total Employees Loaded: " . count($csv_data) . "</h2>";

echo "<h3>First 10 Employees:</h3>";
echo "<pre>";
$count = 0;
foreach ($csv_data as $id => $emp) {
    if ($count++ >= 10) break;
    print_r($emp);
}
echo "</pre>";

echo "<h3>Departments:</h3>";
echo "<pre>";
print_r(getDepartmentsFromCSV());
echo "</pre>";

echo "<h3>Branches:</h3>";
echo "<pre>";
print_r(getBranchesFromCSV());
echo "</pre>";

echo "<h3>Designations:</h3>";
echo "<pre>";
print_r(getDesignationsFromCSV());
echo "</pre>";

echo "<h3>Teams:</h3>";
echo "<pre>";
print_r(getTeamsFromCSV());
echo "</pre>";
?>