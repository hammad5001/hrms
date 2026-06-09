<?php

function employee_profile_defaults(): array
{
    return [
        'date_of_birth' => null,
        'expertise' => '',
        'marital_status' => '',
        'about_me' => '',
        'emergency_contact' => '',
        'personal_mobile' => '',
        'extension' => '',
        'personal_email' => '',
        'present_address' => '',
        'permanent_address' => '',
        'added_by_name' => null,
        'modified_by_name' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function fetch_employee_profile_details(mysqli $conn, int $user_id): array
{
    $defaults = employee_profile_defaults();

    $stmt = $conn->prepare("
        SELECT epd.date_of_birth, epd.expertise, epd.marital_status, epd.about_me,
               epd.emergency_contact, epd.personal_mobile, epd.extension, epd.personal_email,
               epd.present_address, epd.permanent_address,
               epd.created_at, epd.updated_at,
               ab.full_name AS added_by_name,
               mb.full_name AS modified_by_name
        FROM employee_profile_details epd
        LEFT JOIN users ab ON ab.id = epd.added_by_user_id
        LEFT JOIN users mb ON mb.id = epd.modified_by_user_id
        WHERE epd.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        return array_merge($defaults, $row);
    }

    $phoneStmt = $conn->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
    $phoneStmt->bind_param('i', $user_id);
    $phoneStmt->execute();
    $phoneRow = $phoneStmt->get_result()->fetch_assoc();
    if (!empty($phoneRow['phone'])) {
        $defaults['personal_mobile'] = $phoneRow['phone'];
    }

    return $defaults;
}

function save_employee_profile_details(mysqli $conn, int $user_id, array $input): array
{
    $dob = trim((string) ($input['date_of_birth'] ?? ''));
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        return ['ok' => false, 'error' => 'Invalid date of birth'];
    }
    if ($dob === '') {
        $dob = null;
    }

    $personalEmail = trim((string) ($input['personal_email'] ?? ''));
    if ($personalEmail !== '' && !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid personal email address'];
    }

    $personalMobile = preg_replace('/\s+/', '', trim((string) ($input['personal_mobile'] ?? '')));
    if ($personalMobile !== '' && !preg_match('/^[0-9+\-]{10,15}$/', $personalMobile)) {
        return ['ok' => false, 'error' => 'Enter a valid personal mobile number'];
    }

    $fields = [
        'expertise' => trim((string) ($input['expertise'] ?? '')),
        'marital_status' => trim((string) ($input['marital_status'] ?? '')),
        'about_me' => trim((string) ($input['about_me'] ?? '')),
        'emergency_contact' => trim((string) ($input['emergency_contact'] ?? '')),
        'extension' => trim((string) ($input['extension'] ?? '')),
        'present_address' => trim((string) ($input['present_address'] ?? '')),
        'permanent_address' => trim((string) ($input['permanent_address'] ?? '')),
    ];

    $existsStmt = $conn->prepare('SELECT user_id FROM employee_profile_details WHERE user_id = ? LIMIT 1');
    $existsStmt->bind_param('i', $user_id);
    $existsStmt->execute();
    $exists = (bool) $existsStmt->get_result()->fetch_assoc();

    if (!$exists) {
        $stmt = $conn->prepare("
            INSERT INTO employee_profile_details (
                user_id, date_of_birth, expertise, marital_status, about_me,
                emergency_contact, personal_mobile, extension, personal_email,
                present_address, permanent_address, added_by_user_id, modified_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'issssssssssii',
            $user_id,
            $dob,
            $fields['expertise'],
            $fields['marital_status'],
            $fields['about_me'],
            $fields['emergency_contact'],
            $personalMobile,
            $fields['extension'],
            $personalEmail,
            $fields['present_address'],
            $fields['permanent_address'],
            $user_id,
            $user_id
        );
    } else {
        $stmt = $conn->prepare("
            UPDATE employee_profile_details SET
                date_of_birth = ?,
                expertise = ?,
                marital_status = ?,
                about_me = ?,
                emergency_contact = ?,
                personal_mobile = ?,
                extension = ?,
                personal_email = ?,
                present_address = ?,
                permanent_address = ?,
                modified_by_user_id = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        $stmt->bind_param(
            'ssssssssssii',
            $dob,
            $fields['expertise'],
            $fields['marital_status'],
            $fields['about_me'],
            $fields['emergency_contact'],
            $personalMobile,
            $fields['extension'],
            $personalEmail,
            $fields['present_address'],
            $fields['permanent_address'],
            $user_id,
            $user_id
        );
    }

    if (!$stmt->execute()) {
        return ['ok' => false, 'error' => 'Could not save profile'];
    }

    $phoneStmt = $conn->prepare('UPDATE users SET phone = ? WHERE id = ?');
    $phoneStmt->bind_param('si', $personalMobile, $user_id);
    $phoneStmt->execute();

    return ['ok' => true, 'profile' => fetch_employee_profile_details($conn, $user_id)];
}
