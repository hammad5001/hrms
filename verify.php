<?php
require_once __DIR__ . '/config.php';

$employee_code = isset($_GET['code']) ? trim($_GET['code']) : '';
$user = null;

if (!empty($employee_code)) {
    $stmt = $conn->prepare("SELECT full_name, designation, department, status, email, chat_avatar FROM users WHERE employee_code = ? LIMIT 1");
    $stmt->bind_param("s", $employee_code);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user = $res->fetch_assoc();
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Verification - Balitech</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --active-color: #10b981;
            --inactive-color: #f59e0b;
            --terminated-color: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-image: radial-gradient(circle at top left, rgba(99, 102, 241, 0.15), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.08), transparent 40%);
        }

        .container {
            width: 100%;
            max-width: 440px;
            perspective: 1000px;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 35px 30px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .logo {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            color: var(--text-main);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .logo span {
            color: #6366f1;
        }

        .status-badge-container {
            margin: 20px 0 30px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin-bottom: 5px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }

        .profile-photo-container {
            position: relative;
            width: 90px;
            height: 90px;
            margin-bottom: 5px;
            display: inline-block;
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }

        .status-badge-mini {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            border: 2px solid #1e293b;
        }

        .status-badge-mini.active {
            background-color: var(--active-color);
            color: white;
        }

        .status-badge-mini.inactive {
            background-color: var(--inactive-color);
            color: white;
        }

        .status-badge-mini.terminated {
            background-color: var(--terminated-color);
            color: white;
        }

        /* Active styling */
        .status-icon.active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--active-color);
            border: 2px solid rgba(16, 185, 129, 0.4);
            box-shadow: 0 0 25px rgba(16, 185, 129, 0.2);
        }

        .status-label.active {
            color: var(--active-color);
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        /* Inactive styling */
        .status-icon.inactive {
            background: rgba(245, 158, 11, 0.15);
            color: var(--inactive-color);
            border: 2px solid rgba(245, 158, 11, 0.4);
            box-shadow: 0 0 25px rgba(245, 158, 11, 0.2);
        }

        .status-label.inactive {
            color: var(--inactive-color);
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Terminated styling */
        .status-icon.terminated {
            background: rgba(239, 68, 68, 0.15);
            color: var(--terminated-color);
            border: 2px solid rgba(239, 68, 68, 0.4);
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.2);
        }

        .status-label.terminated {
            color: var(--terminated-color);
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .status-label {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .details-list {
            text-align: left;
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            padding-bottom: 10px;
        }

        .detail-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-item span {
            color: var(--text-muted);
            font-weight: 500;
        }

        .detail-item strong {
            color: var(--text-main);
            font-weight: 600;
        }

        .footer-note {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .error-card {
            border-top: 4px solid var(--terminated-color);
        }

        .error-card i.error-icon {
            font-size: 50px;
            color: var(--terminated-color);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($user): 
            $status = strtolower($user['status']);
            $status_class = 'active';
            $status_text = 'Active HRMS Member';
            $status_icon = 'fa-check-circle';
            
            if ($status === 'inactive') {
                $status_class = 'inactive';
                $status_text = 'Inactive Member';
                $status_icon = 'fa-exclamation-triangle';
            } elseif ($status === 'terminated') {
                $status_class = 'terminated';
                $status_text = 'Terminated / Inactive';
                $status_icon = 'fa-ban';
            }

            $avatar_url = '';
            if (!empty($user['chat_avatar'])) {
                $avatar_name = basename($user['chat_avatar']);
                $avatar_url = 'uploads/chat/avatars/' . $avatar_name;
                if (!file_exists(__DIR__ . '/' . $avatar_url)) {
                    $avatar_url = '';
                }
            }
        ?>
            <div class="card">
                <div class="logo">
                    <img src="assets/images/balitech-logo.png" alt="Balitech Logo" style="height: 48px; width: auto; object-fit: contain;">
                </div>
                
                <div class="status-badge-container">
                    <?php if (!empty($avatar_url)): ?>
                        <div class="profile-photo-container">
                            <img src="<?php echo $avatar_url; ?>" alt="Profile Photo" class="profile-photo">
                            <div class="status-badge-mini <?php echo $status_class; ?>">
                                <i class="fas <?php echo $status_icon; ?>"></i>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="status-icon <?php echo $status_class; ?>">
                            <i class="fas <?php echo $status_icon; ?>"></i>
                        </div>
                    <?php endif; ?>
                    <div class="status-label <?php echo $status_class; ?>">
                        <?php echo $status_text; ?>
                    </div>
                </div>

                <div class="details-list">
                    <div class="detail-item">
                        <span>Full Name</span>
                        <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                    </div>
                    <div class="detail-item">
                        <span>Biometric ID</span>
                        <strong><?php echo htmlspecialchars($employee_code); ?></strong>
                    </div>
                    <div class="detail-item">
                        <span>Designation</span>
                        <strong><?php echo htmlspecialchars($user['designation'] ?: '-'); ?></strong>
                    </div>
                    <div class="detail-item">
                        <span>Department</span>
                        <strong><?php echo htmlspecialchars($user['department'] ?: '-'); ?></strong>
                    </div>
                </div>

                <div class="footer-note">
                    <i class="fas fa-clock"></i> Verified: <?php echo date('d M Y, h:i A'); ?><br>
                    Securely generated from Balitech HRMS network.
                </div>
            </div>
        <?php else: ?>
            <div class="card error-card">
                <div class="logo">
                    <img src="assets/images/balitech-logo.png" alt="Balitech Logo" style="height: 48px; width: auto; object-fit: contain;">
                </div>
                <i class="fas fa-circle-xmark error-icon"></i>
                <h3 style="margin-bottom: 10px; font-weight: 700;">Invalid QR Code</h3>
                <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 25px; line-height: 1.6;">
                    The employee code is invalid or the member record could not be found in the database.
                </p>
                <div class="footer-note">
                    If you believe this is an error, please contact the Balitech HR department.
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
