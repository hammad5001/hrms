<?php

/**
 * Mailer utility for sending broadcast emails.
 * Uses native PHP mail() as fallback if PHPMailer is not installed.
 * For a production environment with 450+ emails, it is HIGHLY recommended to configure PHPMailer with SMTP.
 */

function send_salary_slip_email($to_email, $employee_name, $month, $year, $salaryData) {
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $template_path = __DIR__ . '/email_templates/salary_slip.html';
    if (!file_exists($template_path)) {
        error_log("Email template not found: " . $template_path);
        return false;
    }

    $html = file_get_contents($template_path);

    // Prepare table rows
    $table_rows = '';
    $net_payable = '0.00';
    
    // Identify net payable to highlight
    foreach (array_keys($salaryData) as $col) {
        if (stripos($col, 'net payable') !== false || stripos($col, 'net salary') !== false) {
            $net_payable = $salaryData[$col];
        }
    }

    // Generate table dynamically from all valid columns (skip empty values)
    foreach ($salaryData as $key => $val) {
        if (!empty($val) && stripos($key, 'Employee Name') === false && stripos($key, 'Biometric ID') === false && stripos($key, 'Sr. No') === false) {
            $table_rows .= "<tr><th>" . htmlspecialchars($key) . "</th><td>" . htmlspecialchars($val) . "</td></tr>";
        }
    }

    // Portal URL (Replace with actual domain if live)
    $portal_url = 'http://' . $_SERVER['HTTP_HOST'] . '/employee-portal.html';

    // Replace placeholders
    $html = str_replace('{{EMPLOYEE_NAME}}', htmlspecialchars($employee_name), $html);
    $html = str_replace('{{MONTH}}', htmlspecialchars($month), $html);
    $html = str_replace('{{YEAR}}', htmlspecialchars($year), $html);
    $html = str_replace('{{NET_PAYABLE}}', htmlspecialchars($net_payable), $html);
    $html = str_replace('{{SALARY_TABLE_ROWS}}', $table_rows, $html);
    $html = str_replace('{{PORTAL_URL}}', $portal_url, $html);

    $subject = "Your Salary Slip for $month $year";
    
    // Require PHPMailer classes manually since we aren't using Composer
    require_once __DIR__ . '/PHPMailer/PHPMailer-6.9.1/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer-6.9.1/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer-6.9.1/src/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // --- SMTP CONFIGURATION ---
        $mail->isSMTP();
        
        // Replace these with your actual physical server or email provider's SMTP details
        $mail->Host       = 'smtp.yourdomain.com';      // Set the SMTP server to send through
        $mail->SMTPAuth   = true;                       // Enable SMTP authentication
        $mail->Username   = 'hr@yourdomain.com';        // SMTP username
        $mail->Password   = 'your_email_password';      // SMTP password
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; // Enable TLS encryption (or ENCRYPTION_SMTPS for SSL)
        $mail->Port       = 587;                        // TCP port to connect to (465 for SSL)

        // --- SENDER & RECIPIENT ---
        $mail->setFrom('hr@yourdomain.com', 'Balitech HR');
        $mail->addAddress($to_email, $employee_name);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>
