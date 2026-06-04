<?php
require_once 'config.php';

echo "Database connection: " . ($conn ? "OK" : "Failed") . "<br>";

$result = $conn->query("SHOW TABLES");
if ($result) {
    echo "Tables in database:<br>";
    while ($row = $result->fetch_array()) {
        echo "- " . $row[0] . "<br>";
    }
} else {
    echo "Error: " . $conn->error;
}
?>