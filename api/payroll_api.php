<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$portalRole = $_SESSION['portal_role'] ?? '';
if (!in_array($portalRole, ['finance', 'admin', 'super_admin'], true)) {
    respond(false, null, 'Finance access required');
}

// Production: schema migrations are run manually during deployment.

$branch = get_active_company_branch();
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

/** Adjustment types stored as multiple rows */
$LIST_ADJ_TYPES = ['tada', 'arrears', 'bonus', 'halfDay', 'ncns', 'sd', 'qaHr', 'misspunch'];
/** Single-value numeric per employee per month */
$SCALAR_ADJ_TYPES = ['manualLate', 'manualPunctuality', 'manualLeaves', 'tax'];
/** Single-value string/json per employee per month */
$STRING_SCALAR_ADJ_TYPES = ['remarks', 'comments', 'attendanceOverrides', 'extraDays', 'appointmentDate'];

function loadEmployeeDataFromCSV() {
    global $conn;
    $csv_file = dirname(__DIR__) . '/attendance/Present Employee Data - Sheet4.csv';
    $employees = [];
    if (!file_exists($csv_file)) {
        return $employees;
    }
    $file = fopen($csv_file, 'r');
    if (!$file) {
        return $employees;
    }
    fgetcsv($file);
    while (($row = fgetcsv($file)) !== FALSE) {
        if (empty(array_filter($row))) {
            continue;
        }
        $row = array_map('trim', $row);
        if (!empty($row[0])) {
            $employees[$row[0]] = [
                'id' => $row[0],
                'name' => $row[1] ?? '',
                'team' => $row[2] ?? '',
                'department' => $row[3] ?? '',
                'designation' => $row[4] ?? '',
                'branch' => $row[5] ?? '',
            ];
        }
    }
    fclose($file);
    $db = $conn->query("
        SELECT e.employee_code, e.full_name, e.department, COALESCE(NULLIF(u.team, ''), NULLIF(e.team, ''), '') as team
        FROM employees e
        LEFT JOIN users u ON (e.employee_code IS NOT NULL AND e.employee_code != '' AND e.employee_code COLLATE utf8mb4_unicode_ci = u.employee_code COLLATE utf8mb4_unicode_ci)
        WHERE e.is_active = 1
    ");
    if ($db) {
        while ($row = $db->fetch_assoc()) {
            $code = $row['employee_code'];
            if (!isset($employees[$code])) {
                $employees[$code] = [
                    'id' => $code,
                    'name' => $row['full_name'],
                    'department' => $row['department'],
                    'designation' => 'Employee',
                    'branch' => 'Main',
                    'team' => $row['team'] ?? '',
                ];
            }
        }
    }
    return $employees;
}

function emptyPayrollBundle(): array {
    return [
        'tada' => [], 'arrears' => [], 'bonus' => [], 'halfDay' => [], 'ncns' => [], 'sd' => [],
        'qaHr' => [], 'misspunch' => [], 'advance' => [], 'manualLate' => [], 'manualPunctuality' => [],
        'manualLeaves' => [], 'tax' => [], 'appointmentDate' => [], 'empMeta' => [],
        'remarks' => [], 'comments' => [], 'attendanceOverrides' => [], 'extraDays' => [],
    ];
}

function fetchMonthBundle(mysqli $conn, string $month, string $branch): array {
    global $LIST_ADJ_TYPES, $SCALAR_ADJ_TYPES, $STRING_SCALAR_ADJ_TYPES;
    $bundle = emptyPayrollBundle();

    $stmt = $conn->prepare("SELECT * FROM payroll_adjustments WHERE month = ? AND company_branch = ?");
    $stmt->bind_param('ss', $month, $branch);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = $row['adj_type'];
        $emp = $row['employee_code'];
        if (in_array($type, $LIST_ADJ_TYPES, true)) {
            if (!isset($bundle[$type][$emp])) {
                $bundle[$type][$emp] = [];
            }
            $bundle[$type][$emp][] = [
                'id' => (int)$row['id'],
                'amount' => (float)$row['amount'],
                'reason' => $row['reason'] ?? '',
                'team' => $row['team'] ?? '',
                'date' => $row['adj_date'] ?? '',
                'addedAt' => $row['created_at'],
            ];
        } elseif (in_array($type, $SCALAR_ADJ_TYPES, true)) {
            $bundle[$type][$emp] = (float)$row['amount'];
        } elseif (in_array($type, $STRING_SCALAR_ADJ_TYPES, true)) {
            if ($type === 'attendanceOverrides') {
                $bundle[$type][$emp] = json_decode($row['reason'] ?? '', true) ?: [];
            } else {
                $bundle[$type][$emp] = (string)($row['reason'] ?? '');
            }
        }
    }

    $adv = $conn->prepare("SELECT * FROM payroll_advances WHERE company_branch = ?");
    $adv->bind_param('s', $branch);
    $adv->execute();
    $ar = $adv->get_result();
    while ($row = $ar->fetch_assoc()) {
        $bundle['advance'][$row['employee_code']] = [
            'total' => (float)$row['total_amount'],
            'perMonth' => (float)$row['per_month'],
            'paid' => (float)$row['paid_amount'],
            'skipMonths' => json_decode($row['skip_months'] ?? '[]', true) ?: [],
            'addedAt' => $row['updated_at'],
        ];
    }

    $meta = $conn->prepare("SELECT * FROM employee_payroll_meta WHERE company_branch = ?");
    $meta->bind_param('s', $branch);
    $meta->execute();
    $mr = $meta->get_result();
    while ($row = $mr->fetch_assoc()) {
        $code = $row['employee_code'];
        $bundle['empMeta'][$code] = [
            'basicSalary' => (float)$row['basic_salary'],
            'punctualityEnabled' => (bool)$row['punctuality_enabled'],
            'punctualityAmount' => (float)($row['punctuality_amount'] ?? 5000.00),
            'sudoName' => $row['sudo_name'] ?? '',
            'designation' => $row['designation'] ?? '',
            'cnic' => $row['cnic'] ?? '',
            'bankName' => $row['bank_name'] ?? '',
            'accountNo' => $row['account_no'] ?? '',
            'accountTitle' => $row['account_title'] ?? '',
        ];
        if (!empty($row['appointment_date'])) {
            $bundle['appointmentDate'][$code] = $row['appointment_date'];
        }
    }

    return $bundle;
}

function fetchLeaves(mysqli $conn, string $branch): array {
    $leaves = [];
    $stmt = $conn->prepare("SELECT employee_code, leave_date, leave_type, reason FROM employee_leaves WHERE company_branch = ? ORDER BY leave_date");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $code = $row['employee_code'];
        if (!isset($leaves[$code])) {
            $leaves[$code] = [];
        }
        $leaves[$code][] = [
            'date' => $row['leave_date'],
            'type' => $row['leave_type'],
            'reason' => $row['reason'] ?? '',
        ];
    }
    return $leaves;
}

function saveMonthBundle(mysqli $conn, string $month, string $branch, array $bundle, array $leaves): bool {
    global $LIST_ADJ_TYPES, $SCALAR_ADJ_TYPES, $STRING_SCALAR_ADJ_TYPES;
    $userName = getCurrentUserName();

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM payroll_adjustments WHERE month = ? AND company_branch = ?");
        $del->bind_param('ss', $month, $branch);
        $del->execute();

        $ins = $conn->prepare("INSERT INTO payroll_adjustments (employee_code, month, adj_type, amount, reason, team, adj_date, company_branch, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($LIST_ADJ_TYPES as $type) {
            if (empty($bundle[$type]) || !is_array($bundle[$type])) {
                continue;
            }
            foreach ($bundle[$type] as $empCode => $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    $amount = (float)($item['amount'] ?? 0);
                    $reason = $item['reason'] ?? '';
                    $team = $item['team'] ?? '';
                    $adjDate = !empty($item['date']) ? $item['date'] : null;
                    $ins->bind_param('sssdsssss', $empCode, $month, $type, $amount, $reason, $team, $adjDate, $branch, $userName);
                    $ins->execute();
                }
            }
        }

        foreach ($SCALAR_ADJ_TYPES as $type) {
            if (empty($bundle[$type]) || !is_array($bundle[$type])) {
                continue;
            }
            foreach ($bundle[$type] as $empCode => $val) {
                if ($val === '' || $val === null) {
                    continue;
                }
                $amount = (float)$val;
                $reason = '';
                $team = '';
                $adjDate = null;
                $ins->bind_param('sssdsssss', $empCode, $month, $type, $amount, $reason, $team, $adjDate, $branch, $userName);
                $ins->execute();
            }
        }

        foreach ($STRING_SCALAR_ADJ_TYPES as $type) {
            if (empty($bundle[$type]) || !is_array($bundle[$type])) {
                continue;
            }
            foreach ($bundle[$type] as $empCode => $val) {
                if ($val === '' || $val === null) {
                    continue;
                }
                $amount = 0;
                $reason = is_array($val) ? json_encode($val) : (string)$val;
                $team = '';
                $adjDate = null;
                $ins->bind_param('sssdsssss', $empCode, $month, $type, $amount, $reason, $team, $adjDate, $branch, $userName);
                $ins->execute();
            }
        }

        $delAdv = $conn->prepare("DELETE FROM payroll_advances WHERE company_branch = ?");
        $delAdv->bind_param('s', $branch);
        $delAdv->execute();

        if (!empty($bundle['advance']) && is_array($bundle['advance'])) {
            $ains = $conn->prepare("INSERT INTO payroll_advances (employee_code, total_amount, per_month, paid_amount, skip_months, company_branch) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($bundle['advance'] as $empCode => $adv) {
                $total = (float)($adv['total'] ?? 0);
                $per = (float)($adv['perMonth'] ?? 0);
                $paid = (float)($adv['paid'] ?? 0);
                $skip = json_encode($adv['skipMonths'] ?? []);
                $ains->bind_param('sdddss', $empCode, $total, $per, $paid, $skip, $branch);
                $ains->execute();
            }
        }

        if (!empty($bundle['empMeta']) && is_array($bundle['empMeta'])) {
            $metaStmt = $conn->prepare("INSERT INTO employee_payroll_meta
                (employee_code, basic_salary, punctuality_enabled, punctuality_amount, sudo_name, designation, cnic, bank_name, account_no, account_title, appointment_date, company_branch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                basic_salary = VALUES(basic_salary),
                punctuality_enabled = VALUES(punctuality_enabled),
                punctuality_amount = VALUES(punctuality_amount),
                sudo_name = VALUES(sudo_name),
                designation = VALUES(designation),
                cnic = VALUES(cnic),
                bank_name = VALUES(bank_name),
                account_no = VALUES(account_no),
                account_title = VALUES(account_title),
                appointment_date = VALUES(appointment_date),
                company_branch = VALUES(company_branch)");
            foreach ($bundle['empMeta'] as $empCode => $m) {
                $basic = (float)($m['basicSalary'] ?? 50000);
                $punc = !empty($m['punctualityEnabled']) ? 1 : 0;
                $puncAmt = (float)($m['punctualityAmount'] ?? 5000.00);
                $sudo = $m['sudoName'] ?? '';
                $desig = $m['designation'] ?? '';
                $cnic = $m['cnic'] ?? '';
                $bank = $m['bankName'] ?? '';
                $acc = $m['accountNo'] ?? '';
                $title = $m['accountTitle'] ?? '';
                $appt = $bundle['appointmentDate'][$empCode] ?? null;
                $metaStmt->bind_param('sdidssssssss', $empCode, $basic, $punc, $puncAmt, $sudo, $desig, $cnic, $bank, $acc, $title, $appt, $branch);
                $metaStmt->execute();
            }
        }

        $delLeaves = $conn->prepare("DELETE FROM employee_leaves WHERE company_branch = ?");
        $delLeaves->bind_param('s', $branch);
        $delLeaves->execute();

        if (!empty($leaves)) {
            $lins = $conn->prepare("INSERT INTO employee_leaves (employee_code, leave_date, leave_type, reason, company_branch) VALUES (?, ?, ?, ?, ?)");
            foreach ($leaves as $empCode => $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $lv) {
                    $date = $lv['date'] ?? null;
                    if (!$date) {
                        continue;
                    }
                    $type = $lv['type'] ?? 'approved';
                    $reason = $lv['reason'] ?? '';
                    $lins->bind_param('sssss', $empCode, $date, $type, $reason, $branch);
                    $lins->execute();
                }
            }
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, null, 'Save failed: ' . $e->getMessage());
        return false;
    }
}

switch ($action) {
    case 'searchEmployees':
        $query = trim($_GET['query'] ?? '');
        $csv_employees = loadEmployeeDataFromCSV();
        $results = [];
        if ($query === '') {
            $results = array_values($csv_employees);
        } else {
            $q = strtolower($query);
            foreach ($csv_employees as $emp) {
                if (strpos(strtolower($emp['name']), $q) !== false ||
                    strpos(strtolower($emp['id']), $q) !== false ||
                    strpos(strtolower($emp['department'] ?? ''), $q) !== false) {
                    $results[] = $emp;
                }
            }
        }
        respond(true, array_slice($results, 0, 50));
        break;

    case 'switchBranch':
        $newBranch = normalize_company_branch($_GET['branch'] ?? 'main');
        if (is_valid_company_branch($newBranch)) {
            $_SESSION['company_branch'] = $newBranch;
            respond(true, ['branch' => $newBranch], 'Branch switched successfully');
        } else {
            respond(false, null, 'Invalid branch');
        }
        break;


    case 'getFinanceUsers':
        $stmt = $conn->prepare("
            SELECT u.id, u.employee_code, u.full_name, u.email, u.department, u.designation, u.team, u.phone AS contact_no,
                   m.basic_salary, m.punctuality_enabled, m.punctuality_amount, m.appointment_date,
                   m.bank_name, m.account_no, m.account_title, m.cnic
            FROM users u
            LEFT JOIN employee_payroll_meta m
              ON u.employee_code COLLATE utf8mb4_unicode_ci = m.employee_code COLLATE utf8mb4_unicode_ci
             AND COALESCE(NULLIF(m.company_branch, ''), 'main') = ?
            WHERE COALESCE(NULLIF(u.company_branch, ''), 'main') = ?
            ORDER BY CAST(u.employee_code AS UNSIGNED) ASC
        ");
        $stmt->bind_param('ss', $branch, $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        $results = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['basic_salary'] = (float)($row['basic_salary'] ?? 0.0);
                $row['punctuality_enabled'] = (bool)($row['punctuality_enabled'] ?? false);
                $row['punctuality_amount'] = (float)($row['punctuality_amount'] ?? 5000.00);
                $row['appointment_date'] = $row['appointment_date'] ?? '';
                $row['bank_name'] = $row['bank_name'] ?? '';
                $row['account_no'] = $row['account_no'] ?? '';
                $row['account_title'] = $row['account_title'] ?? '';
                $row['cnic'] = $row['cnic'] ?? '';
                $row['contact_no'] = $row['contact_no'] ?? '';
                $results[] = $row;
            }
        }
        respond(true, $results);
        break;

    case 'updateFinanceUser':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            respond(false, null, 'POST request required');
        }

        $data = $input;
        $employee_code = trim($data['employee_code'] ?? '');
        $basicSalaryInput = $data['basic_salary'] ?? null;
        $punctualityAmountInput = $data['punctuality_amount'] ?? null;

        if ($employee_code === '' || strlen($employee_code) > 32) {
            respond(false, null, 'Valid employee ID required');
        }
        if (!is_numeric($basicSalaryInput) || !is_numeric($punctualityAmountInput)) {
            respond(false, null, 'Salary and punctuality amounts must be numeric');
        }

        $basic_salary = (float)$basicSalaryInput;
        $punctuality_enabled = !empty($data['punctuality_enabled']) ? 1 : 0;
        $punctuality_amount = (float)$punctualityAmountInput;
        $appointment_date = !empty($data['appointment_date']) ? trim($data['appointment_date']) : null;

        $bank_name = trim($data['bank_name'] ?? '');
        $account_no = trim($data['account_no'] ?? '');
        $account_title = trim($data['account_title'] ?? '');
        $cnic = trim($data['cnic'] ?? '');
        $contact_no = trim($data['contact_no'] ?? '');

        if ($basic_salary < 0 || $punctuality_amount < 0) {
            respond(false, null, 'Amounts cannot be negative');
        }

        $userStmt = $conn->prepare("
            SELECT id FROM users
            WHERE employee_code = ?
              AND COALESCE(NULLIF(company_branch, ''), 'main') = ?
            LIMIT 1
        ");
        $userStmt->bind_param('ss', $employee_code, $branch);
        $userStmt->execute();
        if (!$userStmt->get_result()->fetch_assoc()) {
            respond(false, null, 'Employee not found in the active branch');
        }

        $metaStmt = $conn->prepare("INSERT INTO employee_payroll_meta
            (employee_code, basic_salary, punctuality_enabled, punctuality_amount, appointment_date, bank_name, account_no, account_title, cnic, company_branch)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            basic_salary = VALUES(basic_salary),
            punctuality_enabled = VALUES(punctuality_enabled),
            punctuality_amount = VALUES(punctuality_amount),
            appointment_date = VALUES(appointment_date),
            bank_name = VALUES(bank_name),
            account_no = VALUES(account_no),
            account_title = VALUES(account_title),
            cnic = VALUES(cnic),
            company_branch = VALUES(company_branch)");
        $metaStmt->bind_param('sdidssssss', $employee_code, $basic_salary, $punctuality_enabled, $punctuality_amount, $appointment_date, $bank_name, $account_no, $account_title, $cnic, $branch);
        if (!$metaStmt->execute()) {
            respond(false, null, 'Unable to save payroll settings');
        }

        if ($contact_no !== '') {
            $uStmt = $conn->prepare("UPDATE users SET phone = ? WHERE employee_code = ? AND COALESCE(NULLIF(company_branch, ''), 'main') = ?");
            $uStmt->bind_param('sss', $contact_no, $employee_code, $branch);
            $uStmt->execute();
        }

        respond(true, null, 'Payroll settings updated successfully');
        break;

    case 'importPayrollCSV':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            respond(false, null, 'POST request required');
        }
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            respond(false, null, 'No valid CSV file uploaded');
        }

        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmpFile, 'r');
        if (!$handle) {
            respond(false, null, 'Failed to open uploaded file');
        }

        // Parse header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            respond(false, null, 'Empty CSV file');
        }

        // Map column indices
        $colMap = [
            'code' => -1,
            'name' => -1,
            'salary' => -1,
            'punctuality_amount' => -1,
            'appointment_date' => -1,
            'bank_name' => -1,
            'account_no' => -1,
            'account_title' => -1,
            'cnic' => -1,
            'contact_no' => -1
        ];

        foreach ($headers as $idx => $header) {
            $header = strtolower(trim($header));
            if (in_array($header, ['biometric id', 'employee_code', 'biometric_id', 'code', 'id', 'biometricid', 'employee code', 'b-id'])) {
                $colMap['code'] = $idx;
            } elseif (in_array($header, ['name', 'full_name', 'employee name', 'employee_name', 'fullname', 'employees name'])) {
                $colMap['name'] = $idx;
            } elseif (in_array($header, ['basic salary', 'salary', 'basic_salary', 'amount', 'basicsalary'])) {
                $colMap['salary'] = $idx;
            } elseif (in_array($header, ['punctuality amount', 'punctuality_amount', 'punctuality', 'punctualityamount', 'punctuality reward', 'punctuality_reward'])) {
                $colMap['punctuality_amount'] = $idx;
            } elseif (in_array($header, ['appointment date', 'appointment_date', 'appointmentdate', 'joining date', 'joining_date'])) {
                $colMap['appointment_date'] = $idx;
            } elseif (in_array($header, ['bank name', 'bank_name', 'bankname', 'bank'])) {
                $colMap['bank_name'] = $idx;
            } elseif (in_array($header, ['account no', 'account_no', 'account number', 'accountnos', 'account_nos', 'accountnum', 'accountno', 'account #'])) {
                $colMap['account_no'] = $idx;
            } elseif (in_array($header, ['account title', 'account_title', 'accounttitle', 'title'])) {
                $colMap['account_title'] = $idx;
            } elseif (in_array($header, ['cnic', 'cnic#', 'cnic_no', 'cnic number'])) {
                $colMap['cnic'] = $idx;
            } elseif (in_array($header, ['contact no', 'contact_no', 'phone', 'contact', 'mobile', 'contact number'])) {
                $colMap['contact_no'] = $idx;
            }
        }

        // Fallback checks if exact headers not found
        if ($colMap['code'] === -1 && $colMap['name'] === -1) {
            fclose($handle);
            respond(false, null, 'CSV must contain at least a Biometric ID or Employee Name header');
        }

        $processed = 0;
        $updated = [];
        $failed = [];
        $skipped = [];

        // Prepare statements
        $findCodeStmt = $conn->prepare("SELECT employee_code, full_name FROM users WHERE employee_code = ? AND COALESCE(NULLIF(company_branch, ''), 'main') = ? LIMIT 1");
        $findNameStmt = $conn->prepare("SELECT employee_code, full_name FROM users WHERE LOWER(full_name) = LOWER(?) AND COALESCE(NULLIF(company_branch, ''), 'main') = ? LIMIT 1");

        $metaStmt = $conn->prepare("INSERT INTO employee_payroll_meta
            (employee_code, basic_salary, punctuality_enabled, punctuality_amount, appointment_date, bank_name, account_no, account_title, cnic, company_branch)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            basic_salary = VALUES(basic_salary),
            punctuality_enabled = 1,
            punctuality_amount = VALUES(punctuality_amount),
            appointment_date = VALUES(appointment_date),
            bank_name = VALUES(bank_name),
            account_no = VALUES(account_no),
            account_title = VALUES(account_title),
            cnic = VALUES(cnic),
            company_branch = VALUES(company_branch)");

        $uStmt = $conn->prepare("UPDATE users SET phone = ? WHERE employee_code = ? AND COALESCE(NULLIF(company_branch, ''), 'main') = ?");

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }
            $processed++;

            $codeVal = $colMap['code'] !== -1 ? trim($row[$colMap['code']] ?? '') : '';
            $nameVal = $colMap['name'] !== -1 ? trim($row[$colMap['name']] ?? '') : '';
            $salaryVal = $colMap['salary'] !== -1 ? trim($row[$colMap['salary']] ?? '0') : '0';
            $puncAmtVal = $colMap['punctuality_amount'] !== -1 ? trim($row[$colMap['punctuality_amount']] ?? '5000') : '5000';
            $apptDateVal = $colMap['appointment_date'] !== -1 ? trim($row[$colMap['appointment_date']] ?? '') : null;
            $bankVal = $colMap['bank_name'] !== -1 ? trim($row[$colMap['bank_name']] ?? '') : '';
            $accNoVal = $colMap['account_no'] !== -1 ? trim($row[$colMap['account_no']] ?? '') : '';
            $accTitleVal = $colMap['account_title'] !== -1 ? trim($row[$colMap['account_title']] ?? '') : '';
            $cnicVal = $colMap['cnic'] !== -1 ? trim($row[$colMap['cnic']] ?? '') : '';
            $contactVal = $colMap['contact_no'] !== -1 ? trim($row[$colMap['contact_no']] ?? '') : '';

            $empCode = '';
            $empName = '';

            // Match logic
            if ($codeVal !== '') {
                $findCodeStmt->bind_param('ss', $codeVal, $branch);
                $findCodeStmt->execute();
                $findRes = $findCodeStmt->get_result()->fetch_assoc();
                if ($findRes) {
                    $empCode = $findRes['employee_code'];
                    $empName = $findRes['full_name'];
                }
            }

            if ($empCode === '' && $nameVal !== '') {
                $findNameStmt->bind_param('ss', $nameVal, $branch);
                $findNameStmt->execute();
                $findRes = $findNameStmt->get_result()->fetch_assoc();
                if ($findRes) {
                    $empCode = $findRes['employee_code'];
                    $empName = $findRes['full_name'];
                }
            }

            if ($empCode === '') {
                $skipped[] = [
                    'row' => $processed,
                    'code' => $codeVal,
                    'name' => $nameVal,
                    'reason' => 'No active matching employee found'
                ];
                continue;
            }

            // Sanitization
            $salary = floatval(preg_replace('/[^\d.]/', '', $salaryVal));
            $puncAmt = floatval(preg_replace('/[^\d.]/', '', $puncAmtVal));
            if ($puncAmt < 0) $puncAmt = 5000.00;

            // Appointment Date format check (YYYY-MM-DD or parse)
            $apptDate = null;
            if ($apptDateVal !== '' && $apptDateVal !== null) {
                $ts = strtotime($apptDateVal);
                if ($ts !== false) {
                    $apptDate = date('Y-m-d', $ts);
                }
            }

            $metaStmt->bind_param('sddssssss', $empCode, $salary, $puncAmt, $apptDate, $bankVal, $accNoVal, $accTitleVal, $cnicVal, $branch);
            if ($metaStmt->execute()) {
                if ($contactVal !== '') {
                    $uStmt->bind_param('sss', $contactVal, $empCode, $branch);
                    $uStmt->execute();
                }
                $updated[] = [
                    'code' => $empCode,
                    'name' => $empName,
                    'salary' => $salary,
                    'punctuality_amount' => $puncAmt,
                    'appointment_date' => $apptDate ? $apptDate : '—'
                ];
            } else {
                $failed[] = [
                    'row' => $processed,
                    'code' => $empCode,
                    'name' => $empName,
                    'reason' => $conn->error
                ];
            }
        }

        fclose($handle);

        respond(true, [
            'total_rows' => $processed,
            'updated_count' => count($updated),
            'failed_count' => count($failed),
            'skipped_count' => count($skipped),
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped
        ], 'CSV processed successfully');
        break;

    case 'getMonthBundle':
        $month = $_GET['month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            respond(false, null, 'Invalid month');
        }
        $bundle = fetchMonthBundle($conn, $month, $branch);
        $leaves = fetchLeaves($conn, $branch);
        respond(true, ['bundle' => $bundle, 'leaves' => $leaves, 'month' => $month, 'branch' => $branch]);
        break;

    case 'saveMonthBundle':
        $month = $input['month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            respond(false, null, 'Invalid month');
        }
        $bundle = $input['bundle'] ?? [];
        $leaves = $input['leaves'] ?? [];
        if (saveMonthBundle($conn, $month, $branch, $bundle, $leaves)) {
            respond(true, null, 'Payroll data saved to database');
        }
        break;

    case 'getLeaves':
        respond(true, fetchLeaves($conn, $branch));
        break;

    case 'saveLeaves':
        $leaves = $input['leaves'] ?? [];
        $conn->begin_transaction();
        try {
            $delLeaves = $conn->prepare("DELETE FROM employee_leaves WHERE company_branch = ?");
            $delLeaves->bind_param('s', $branch);
            $delLeaves->execute();
            $lins = $conn->prepare("INSERT INTO employee_leaves (employee_code, leave_date, leave_type, reason, company_branch) VALUES (?, ?, ?, ?, ?)");
            foreach ($leaves as $empCode => $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $lv) {
                    $date = $lv['date'] ?? null;
                    if (!$date) {
                        continue;
                    }
                    $type = $lv['type'] ?? 'approved';
                    $reason = $lv['reason'] ?? '';
                    $lins->bind_param('sssss', $empCode, $date, $type, $reason, $branch);
                    $lins->execute();
                }
            }
            $conn->commit();
            respond(true, null, 'Leaves saved');
        } catch (Throwable $e) {
            $conn->rollback();
            respond(false, null, $e->getMessage());
        }
        break;

    case 'getEmployeePayrollData':
        $empCode = $_GET['employee_code'] ?? '';
        $month = $_GET['month'] ?? date('Y-m');
        if (!$empCode) {
            respond(false, null, 'Employee code required');
        }
        $bundle = fetchMonthBundle($conn, $month, $branch);
        $meta = $bundle['empMeta'][$empCode] ?? null;
        $adjustments = [];
        foreach (array_merge($LIST_ADJ_TYPES, $SCALAR_ADJ_TYPES) as $t) {
            if (isset($bundle[$t][$empCode])) {
                $adjustments[$t] = $bundle[$t][$empCode];
            }
        }
        respond(true, ['meta' => $meta, 'adjustments' => $adjustments, 'advance' => $bundle['advance'][$empCode] ?? null]);
        break;

    case 'savePayrollMeta':
        $data = $input;
        $empCode = $data['employee_code'] ?? '';
        if (!$empCode) {
            respond(false, null, 'Employee code required');
        }
        $stmt = $conn->prepare("INSERT INTO employee_payroll_meta
            (employee_code, basic_salary, punctuality_enabled, cnic, bank_name, account_no, account_title, appointment_date, company_branch)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            basic_salary = VALUES(basic_salary),
            punctuality_enabled = VALUES(punctuality_enabled),
            cnic = VALUES(cnic),
            bank_name = VALUES(bank_name),
            account_no = VALUES(account_no),
            account_title = VALUES(account_title),
            appointment_date = VALUES(appointment_date)");
        $basic = (float)($data['basic_salary'] ?? 50000);
        $punc = !empty($data['punctuality_enabled']) ? 1 : 0;
        $cnic = $data['cnic'] ?? '';
        $bank = $data['bank_name'] ?? '';
        $acc = $data['account_no'] ?? '';
        $title = $data['account_title'] ?? '';
        $appt = $data['appointment_date'] ?? null;
        $stmt->bind_param('sdissssss', $empCode, $basic, $punc, $cnic, $bank, $acc, $title, $appt, $branch);
        if ($stmt->execute()) {
            respond(true, null, 'Metadata saved');
        }
        respond(false, null, $conn->error);
        break;

    case 'getAdjustmentLogs':
        $month = $_GET['month'] ?? date('Y-m');
        $adjType = trim($_GET['type'] ?? '');
        $limit = min(max(intval($_GET['limit'] ?? 50), 1), 200);

        if ($adjType !== '') {
            $logStmt = $conn->prepare("
                SELECT id, adjustment_id, employee_code, employee_name, month, adj_type, action_type, amount, reason, performed_by_id, performed_by_name, company_branch, created_at
                FROM payroll_adjustment_logs
                WHERE month = ? AND company_branch = ? AND adj_type = ?
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ");
            $logStmt->bind_param('sssi', $month, $branch, $adjType, $limit);
        } else {
            $logStmt = $conn->prepare("
                SELECT id, adjustment_id, employee_code, employee_name, month, adj_type, action_type, amount, reason, performed_by_id, performed_by_name, company_branch, created_at
                FROM payroll_adjustment_logs
                WHERE month = ? AND company_branch = ?
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ");
            $logStmt->bind_param('ssi', $month, $branch, $limit);
        }
        $logStmt->execute();
        $logRes = $logStmt->get_result();
        $logs = [];
        if ($logRes) {
            while ($lRow = $logRes->fetch_assoc()) {
                $logs[] = [
                    'id' => (int)$lRow['id'],
                    'adjustment_id' => $lRow['adjustment_id'] ? (int)$lRow['adjustment_id'] : null,
                    'employee_code' => $lRow['employee_code'],
                    'employee_name' => $lRow['employee_name'] ?? '',
                    'month' => $lRow['month'],
                    'adj_type' => $lRow['adj_type'],
                    'action_type' => $lRow['action_type'],
                    'amount' => (float)$lRow['amount'],
                    'reason' => $lRow['reason'] ?? '',
                    'performed_by_id' => $lRow['performed_by_id'] ? (int)$lRow['performed_by_id'] : null,
                    'performed_by_name' => $lRow['performed_by_name'],
                    'company_branch' => $lRow['company_branch'],
                    'created_at' => $lRow['created_at'],
                ];
            }
        }
        respond(true, ['logs' => $logs]);
        break;

    case 'saveSingleOverrideAdjustment':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            respond(false, null, 'POST request required');
        }
        $data = $input;
        $empCode = trim($data['employee_code'] ?? '');
        $empName = trim($data['employee_name'] ?? '');
        $month = trim($data['month'] ?? date('Y-m'));
        $adjType = trim($data['adj_type'] ?? '');
        $actionType = strtoupper(trim($data['action_type'] ?? 'ADD')); // ADD, DEDUCT, OVERRIDE
        $amount = (float)($data['amount'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        $adjDate = !empty($data['adj_date']) ? $data['adj_date'] : null;
        $team = trim($data['team'] ?? '');

        if (!$empCode || !$adjType || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            respond(false, null, 'Missing or invalid parameters');
        }

        // Fetch user name if not provided
        if (!$empName) {
            $uFind = $conn->prepare("SELECT full_name FROM users WHERE employee_code = ? LIMIT 1");
            $uFind->bind_param('s', $empCode);
            $uFind->execute();
            if ($uRow = $uFind->get_result()->fetch_assoc()) {
                $empName = $uRow['full_name'];
            }
        }

        $userName = getCurrentUserName();
        $userId = getCurrentUserId();
        $adjId = null;

        $conn->begin_transaction();
        try {
            if (in_array($adjType, $LIST_ADJ_TYPES, true)) {
                // If actionType is DEDUCT for types like tada, bonus, arrears -> make amount negative
                if ($actionType === 'DEDUCT' && in_array($adjType, ['tada', 'bonus', 'arrears'], true) && $amount > 0) {
                    $amount = -$amount;
                }
                $ins = $conn->prepare("INSERT INTO payroll_adjustments (employee_code, month, adj_type, amount, reason, team, adj_date, company_branch, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param('sssdsssss', $empCode, $month, $adjType, $amount, $reason, $team, $adjDate, $branch, $userName);
                $ins->execute();
                $adjId = $conn->insert_id;
            } elseif (in_array($adjType, $SCALAR_ADJ_TYPES, true)) {
                // Scalar: replace single record for that month/employee/type
                $del = $conn->prepare("DELETE FROM payroll_adjustments WHERE employee_code = ? AND month = ? AND adj_type = ? AND company_branch = ?");
                $del->bind_param('ssss', $empCode, $month, $adjType, $branch);
                $del->execute();

                if ($amount != 0 || $actionType === 'OVERRIDE') {
                    $ins = $conn->prepare("INSERT INTO payroll_adjustments (employee_code, month, adj_type, amount, reason, team, adj_date, company_branch, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->bind_param('sssdsssss', $empCode, $month, $adjType, $amount, $reason, $team, $adjDate, $branch, $userName);
                    $ins->execute();
                    $adjId = $conn->insert_id;
                }
            } elseif ($adjType === 'advance') {
                // Advance setting
                $totalAdv = (float)($data['total_amount'] ?? $amount);
                $perMonthAdv = (float)($data['per_month'] ?? $amount);
                $paidAdv = (float)($data['paid_amount'] ?? 0);

                $delA = $conn->prepare("DELETE FROM payroll_advances WHERE employee_code = ? AND company_branch = ?");
                $delA->bind_param('ss', $empCode, $branch);
                $delA->execute();

                if ($totalAdv > 0) {
                    $insA = $conn->prepare("INSERT INTO payroll_advances (employee_code, total_amount, per_month, paid_amount, skip_months, company_branch) VALUES (?, ?, ?, ?, '[]', ?)");
                    $insA->bind_param('sddds', $empCode, $totalAdv, $perMonthAdv, $paidAdv, $branch);
                    $insA->execute();
                    $adjId = $conn->insert_id;
                }
            }

            // Write Audit Log
            $logStmt = $conn->prepare("
                INSERT INTO payroll_adjustment_logs (adjustment_id, employee_code, employee_name, month, adj_type, action_type, amount, reason, performed_by_id, performed_by_name, company_branch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $logStmt->bind_param('isssssdssss', $adjId, $empCode, $empName, $month, $adjType, $actionType, $amount, $reason, $userId, $userName, $branch);
            $logStmt->execute();

            $conn->commit();
            respond(true, ['adjustment_id' => $adjId], 'Adjustment recorded successfully');
        } catch (Throwable $e) {
            $conn->rollback();
            respond(false, null, 'Adjustment failed: ' . $e->getMessage());
        }
        break;

    case 'revertAdjustment':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            respond(false, null, 'POST request required');
        }
        $logId = intval($input['log_id'] ?? 0);
        $adjId = intval($input['adjustment_id'] ?? 0);
        $empCode = trim($input['employee_code'] ?? '');
        $adjType = trim($input['adj_type'] ?? '');
        $month = trim($input['month'] ?? date('Y-m'));

        $userName = getCurrentUserName();
        $userId = getCurrentUserId();

        $conn->begin_transaction();
        try {
            // If log_id provided, fetch original info
            $origEmp = $empCode;
            $origName = '';
            $origType = $adjType;
            $origAmount = 0.0;
            $origMonth = $month;

            if ($logId > 0) {
                $fLog = $conn->prepare("SELECT * FROM payroll_adjustment_logs WHERE id = ? AND company_branch = ? LIMIT 1");
                $fLog->bind_param('is', $logId, $branch);
                $fLog->execute();
                if ($lRow = $fLog->get_result()->fetch_assoc()) {
                    $origEmp = $lRow['employee_code'];
                    $origName = $lRow['employee_name'];
                    $origType = $lRow['adj_type'];
                    $origAmount = (float)$lRow['amount'];
                    $origMonth = $lRow['month'];
                    if (!$adjId && $lRow['adjustment_id']) {
                        $adjId = (int)$lRow['adjustment_id'];
                    }
                }
            }

            // Remove from payroll_adjustments if adjustment_id exists or by type/code/month
            if ($adjId > 0) {
                $del = $conn->prepare("DELETE FROM payroll_adjustments WHERE id = ? AND company_branch = ?");
                $del->bind_param('is', $adjId, $branch);
                $del->execute();
            } elseif (in_array($origType, $SCALAR_ADJ_TYPES, true)) {
                $del = $conn->prepare("DELETE FROM payroll_adjustments WHERE employee_code = ? AND month = ? AND adj_type = ? AND company_branch = ?");
                $del->bind_param('ssss', $origEmp, $origMonth, $origType, $branch);
                $del->execute();
            } elseif ($origType === 'advance') {
                $delA = $conn->prepare("DELETE FROM payroll_advances WHERE employee_code = ? AND company_branch = ?");
                $delA->bind_param('ss', $origEmp, $branch);
                $delA->execute();
            }

            // Record Revert Log
            $revertReason = "Reverted adjustment ($origType - ₨" . number_format($origAmount, 2) . ")";
            $logStmt = $conn->prepare("
                INSERT INTO payroll_adjustment_logs (adjustment_id, employee_code, employee_name, month, adj_type, action_type, amount, reason, performed_by_id, performed_by_name, company_branch)
                VALUES (?, ?, ?, ?, ?, 'REVERT', ?, ?, ?, ?, ?)
            ");
            $nullAdjId = null;
            $logStmt->bind_param('issssdssss', $nullAdjId, $origEmp, $origName, $origMonth, $origType, $origAmount, $revertReason, $userId, $userName, $branch);
            $logStmt->execute();

            $conn->commit();
            respond(true, null, 'Adjustment successfully reverted');
        } catch (Throwable $e) {
            $conn->rollback();
            respond(false, null, 'Revert failed: ' . $e->getMessage());
        }
        break;

    case 'addAdjustment':
        $data = $input;
        $stmt = $conn->prepare("INSERT INTO payroll_adjustments (employee_code, month, adj_type, amount, reason, team, adj_date, company_branch, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $userName = getCurrentUserName();
        $stmt->bind_param('sssdsssss',
            $data['employee_code'],
            $data['month'],
            $data['adj_type'],
            $data['amount'],
            $data['reason'] ?? '',
            $data['team'] ?? '',
            $data['adj_date'] ?? null,
            $branch,
            $userName
        );
        if ($stmt->execute()) {
            respond(true, ['id' => $conn->insert_id], 'Adjustment added');
        }
        respond(false, null, $conn->error);
        break;

    case 'deleteAdjustment':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM payroll_adjustments WHERE id = ? AND company_branch = ?");
        $stmt->bind_param('is', $id, $branch);
        if ($stmt->execute()) {
            respond(true, null, 'Adjustment deleted');
        }
        respond(false, null, $conn->error);
        break;

    case 'getBankCodeMappings':
        ensure_bank_format_schema($conn);
        $banks = [];
        $resB = $conn->query("SELECT `id`, `bank_name`, `normalized_name` FROM `banks` WHERE `is_active` = 1 ORDER BY `bank_name` ASC");
        if ($resB) {
            while ($row = $resB->fetch_assoc()) {
                $banks[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['bank_name'],
                    'norm' => strtolower($row['normalized_name'])
                ];
            }
        }

        $mappings = ['ASKARI' => [], 'ALFALAH' => []];
        $resM = $conn->query("SELECT `source_bank`, `destination_bank_id`, `bank_code` FROM `bank_code_mappings`");
        if ($resM) {
            while ($row = $resM->fetch_assoc()) {
                $src = strtoupper($row['source_bank']);
                $destId = (int)$row['destination_bank_id'];
                $mappings[$src][$destId] = (string)$row['bank_code'];
            }
        }

        $companyAccounts = ['ASKARI' => '01801006543210', 'ALFALAH' => '00100987654321'];
        $resC = $conn->query("SELECT `source_bank`, `debit_account_number` FROM `company_bank_accounts`");
        if ($resC) {
            while ($row = $resC->fetch_assoc()) {
                $src = strtoupper($row['source_bank']);
                $companyAccounts[$src] = (string)$row['debit_account_number'];
            }
        }

        respond(true, [
            'banks' => $banks,
            'mappings' => $mappings,
            'companyAccounts' => $companyAccounts
        ]);
        break;

    case 'exportBankXlsx':
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $input['filename'] ?? 'bank_export.xlsx');
        if (!preg_match('/\.xlsx$/i', $filename)) {
            $filename .= '.xlsx';
        }
        $headers = is_array($input['headers'] ?? null) ? $input['headers'] : [];
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];

        if (empty($headers) || empty($rows)) {
            respond(false, null, 'No valid records to export');
        }

        createNativeXlsx($filename, $headers, $rows);
        exit;

    default:
        respond(false, null, 'Invalid action');
}

function createNativeXlsx(string $filename, array $headers, array $rows): void {
    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/plain');
        die("Error: PHP ZipArchive extension required for XLSX generation.");
    }
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Content-Type: text/plain');
        die("Error: Cannot create temporary spreadsheet archive.");
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
    '<Default Extension="xml" ContentType="application/xml"/>' .
    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
    '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
    '</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
    '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
    '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
    '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>' .
    '</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>' .
    '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
    '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>' .
    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
    '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>' .
    '</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    $colLetter = function(int $colIndex): string {
        $str = '';
        while ($colIndex >= 0) {
            $str = chr(65 + ($colIndex % 26)) . $str;
            $colIndex = (int)floor($colIndex / 26) - 1;
        }
        return $str;
    };

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<sheetData>';

    $rowIndex = 1;
    $sheetXml .= '<row r="' . $rowIndex . '">';
    foreach ($headers as $cIdx => $val) {
        $cellRef = $colLetter($cIdx) . $rowIndex;
        $escVal = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
        $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escVal . '</t></is></c>';
    }
    $sheetXml .= '</row>';

    foreach ($rows as $row) {
        $rowIndex++;
        $sheetXml .= '<row r="' . $rowIndex . '">';
        foreach ($row as $cIdx => $val) {
            $cellRef = $colLetter($cIdx) . $rowIndex;
            $escVal = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
            $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escVal . '</t></is></c>';
        }
        $sheetXml .= '</row>';
    }

    $sheetXml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    ob_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: max-age=0');
    readfile($tempFile);
    @unlink($tempFile);
    exit;
}
