<?php
require_once 'config.php';
$sql = "ALTER TABLE users ADD COLUMN IF NOT EXISTS plain_password VARCHAR(255) DEFAULT NULL";
if ($conn->query($sql)) {
    echo "SUCCESS\n";
} else {
    echo "ERROR: " . $conn->error . "\n";
}
?>
