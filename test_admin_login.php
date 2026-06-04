<?php
require_once 'config.php';

$email = 'admin@balitech.com';
$password = 'admin123';

$stmt = $conn->prepare("SELECT id, full_name, email, portal_role, password_hash FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    echo "User found: " . $user['full_name'] . "<br>";
    echo "Hash in DB: " . $user['password_hash'] . "<br>";
    echo "Hash length: " . strlen($user['password_hash']) . "<br>";
    
    if (password_verify($password, $user['password_hash'])) {
        echo "<span style='color:green;font-weight:bold'>✅ PASSWORD VERIFIED! Login would work.</span><br>";
    } else {
        echo "<span style='color:red;font-weight:bold'>❌ Password verification FAILED!</span><br>";
        
        // Create correct hash for comparison
        $correct_hash = password_hash($password, PASSWORD_DEFAULT);
        echo "New correct hash for '$password': " . $correct_hash . "<br>";
        echo "<br>Run this SQL to fix:<br>";
        echo "<code>UPDATE users SET password_hash = '$correct_hash' WHERE email = '$email';</code>";
    }
} else {
    echo "❌ User not found!";
}
?>