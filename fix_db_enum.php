<?php
require 'config.php';
$enum = "'super_admin','admin','hr','recruiter','management','training','agent','receptionist','user','team_lead','floor_manager','data_entry','dialer','developer','analytics','attendance','qa','finance'";
if ($conn->query("ALTER TABLE users MODIFY COLUMN portal_role ENUM($enum) NOT NULL DEFAULT 'user'")) {
    echo "Successfully updated ENUM";
} else {
    echo "Error: " . $conn->error;
}
?>
