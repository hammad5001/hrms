<?php
require_once 'api/config.php';
$conn->query("ALTER TABLE leads MODIFY COLUMN source VARCHAR(50) NOT NULL DEFAULT 'manual'");
$conn->query("UPDATE leads SET source = 'website' WHERE external_lead_id IS NOT NULL");
echo "Updated source column successfully\n";
?>
