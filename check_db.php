<?php
require_once 'api/config.php';

$tables = [
    'interviews' => "CREATE TABLE IF NOT EXISTS `interviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `lead_id` INT NOT NULL,
        `recruiter_id` INT,
        `scheduled_at` DATETIME NOT NULL,
        `status` ENUM('scheduled', 'conducted', 'cancelled', 'no_show') DEFAULT 'scheduled',
        `notes` TEXT,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`recruiter_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB;"
];

foreach ($tables as $name => $sql) {
    $res = $conn->query("SHOW TABLES LIKE '$name'");
    if ($res->num_rows === 0) {
        echo "Creating table $name...\n";
        if ($conn->query($sql)) {
            echo "Table $name created successfully.\n";
        } else {
            echo "Error creating table $name: " . $conn->error . "\n";
        }
    } else {
        echo "Table $name already exists.\n";
    }
}
?>
