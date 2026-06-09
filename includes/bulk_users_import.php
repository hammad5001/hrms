<?php

require_once __DIR__ . '/portal_roles.php';
require_once __DIR__ . '/company_branches.php';

function bulk_import_normalize_row(array $raw, string $defaultPassword): array
{
    $get = static function (array $row, array $keys) {
        foreach ($keys as $key) {
            foreach ($row as $col => $val) {
                $norm = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $col));
                if ($norm === $key && trim((string) $val) !== '') {
                    return trim((string) $val);
                }
            }
        }
        return '';
    };

    $employeeCode = $get($raw, ['employeecode', 'employeeid', 'empid', 'bid', 'id']);
    $fullName = $get($raw, ['fullname', 'name', 'employeename']);
    $emailRaw = $get($raw, ['email', 'emailprefix', 'username', 'login']);
    $phone = preg_replace('/\D+/', '', $get($raw, ['phone', 'mobile', 'contact']));
    $portalRole = strtolower(str_replace([' ', '-'], '_', $get($raw, ['portalrole', 'role', 'userrole'])));
    $department = $get($raw, ['department', 'dept']);
    $designation = $get($raw, ['designation', 'title', 'jobtitle']);
    $companyBranch = normalize_company_branch($get($raw, ['companybranch', 'loginbranch', 'branchlogin']) ?: 'main');
    $branch = $get($raw, ['office', 'officelocation', 'location']) ?: $get($raw, ['branch']);
    $team = $get($raw, ['team']);
    $joined = $get($raw, ['joineddate', 'joiningdate', 'dateofjoining']);
    $password = $get($raw, ['password', 'pass']) ?: $defaultPassword;

    if ($emailRaw !== '' && strpos($emailRaw, '@') === false) {
        $email = $emailRaw . '@balitech.org';
    } elseif ($emailRaw !== '' && substr(strtolower($emailRaw), -13) !== '@balitech.org') {
        $parts = explode('@', $emailRaw);
        $email = $parts[0] . '@balitech.org';
    } else {
        $email = $emailRaw;
    }

    if ($joined !== '') {
        $ts = strtotime($joined);
        $joined = $ts ? date('Y-m-d', $ts) : '';
    }

    return [
        'employee_code' => $employeeCode,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'portal_role' => $portalRole !== '' ? $portalRole : 'user',
        'department' => $department,
        'designation' => $designation,
        'company_branch' => $companyBranch,
        'branch' => $branch,
        'team' => $team,
        'joined_date' => $joined !== '' ? $joined : null,
        'password' => $password,
    ];
}

function bulk_import_users(mysqli $conn, array $rows, string $actorRole, string $defaultPassword = 'Balitech@123'): array
{
    $canAssignSuper = can_assign_super_admin_role($actorRole);
    $inserted = 0;
    $skipped = 0;
    $errors = [];

    $checkEmp = $conn->prepare('SELECT id FROM users WHERE employee_code = ? LIMIT 1');
    $checkEmail = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $insert = $conn->prepare(
        'INSERT INTO users (employee_code, full_name, email, phone, portal_role, department, designation, branch, company_branch, team, joined_date, password_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($rows as $idx => $raw) {
        $line = $idx + 2;
        $row = bulk_import_normalize_row(is_array($raw) ? $raw : [], $defaultPassword);

        if ($row['employee_code'] === '' || $row['full_name'] === '' || $row['email'] === '') {
            $skipped++;
            $errors[] = "Row {$line}: missing employee ID, name, or email";
            continue;
        }
        if (!is_valid_portal_role($row['portal_role'])) {
            $skipped++;
            $errors[] = "Row {$line}: invalid role “{$row['portal_role']}”";
            continue;
        }
        if ($row['portal_role'] === 'super_admin' && !$canAssignSuper) {
            $skipped++;
            $errors[] = "Row {$line}: cannot create super admin";
            continue;
        }
        if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            $errors[] = "Row {$line}: invalid email";
            continue;
        }
        if ($row['phone'] !== '' && !preg_match('/^[0-9]{11}$/', $row['phone'])) {
            $skipped++;
            $errors[] = "Row {$line}: phone must be 11 digits";
            continue;
        }
        if (strlen($row['password']) < 4) {
            $skipped++;
            $errors[] = "Row {$line}: password too short";
            continue;
        }
        if (!is_valid_company_branch($row['company_branch'])) {
            $skipped++;
            $errors[] = "Row {$line}: invalid login branch";
            continue;
        }

        $checkEmp->bind_param('s', $row['employee_code']);
        $checkEmp->execute();
        if ($checkEmp->get_result()->fetch_assoc()) {
            $skipped++;
            $errors[] = "Row {$line}: employee ID {$row['employee_code']} already exists";
            continue;
        }

        $checkEmail->bind_param('s', $row['email']);
        $checkEmail->execute();
        if ($checkEmail->get_result()->fetch_assoc()) {
            $skipped++;
            $errors[] = "Row {$line}: email {$row['email']} already exists";
            continue;
        }

        $hash = password_hash($row['password'], PASSWORD_DEFAULT);
        $insert->bind_param(
            'ssssssssssss',
            $row['employee_code'],
            $row['full_name'],
            $row['email'],
            $row['phone'],
            $row['portal_role'],
            $row['department'],
            $row['designation'],
            $row['branch'],
            $row['company_branch'],
            $row['team'],
            $row['joined_date'],
            $hash
        );

        if ($insert->execute()) {
            $inserted++;
        } else {
            $skipped++;
            $errors[] = "Row {$line}: database error";
        }
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'errors' => array_slice($errors, 0, 50),
    ];
}
