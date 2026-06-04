<?php
require_once 'api/config.php';
$res = $conn->query('DESCRIBE interviews');
while($row = $res->fetch_assoc()) {
    echo "Field: " . $row['Field'] . " | Type: " . $row['Type'] . "\n";
}
?>
