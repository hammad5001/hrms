<?php
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

ensure_app_schema($conn);

$branch = get_active_company_branch();
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

/** Adjustment types stored as multiple rows */
$LIST_ADJ_TYPES = ['tada', 'arrears', 'bonus', 'halfDay', 'ncns', 'sd', 'qaHr', 'misspunch'];
/** Single-value per employee per month */
$SCALAR_ADJ_TYPES = ['manualLate', 'manualPunctuality', 'manualLeaves', 'tax'];

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
    $db = $conn->query("SELECT employee_code, full_name, department FROM employees WHERE is_active = 1");
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
                    'team' => '',
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
    ];
}

function fetchMonthBundle(mysqli $conn, string $month, string $branch): array {
    global $LIST_ADJ_TYPES, $SCALAR_ADJ_TYPES;
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
    global $LIST_ADJ_TYPES, $SCALAR_ADJ_TYPES;
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
                (employee_code, basic_salary, punctuality_enabled, sudo_name, designation, cnic, bank_name, account_no, account_title, appointment_date, company_branch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                basic_salary = VALUES(basic_salary),
                punctuality_enabled = VALUES(punctuality_enabled),
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
                $sudo = $m['sudoName'] ?? '';
                $desig = $m['designation'] ?? '';
                $cnic = $m['cnic'] ?? '';
                $bank = $m['bankName'] ?? '';
                $acc = $m['accountNo'] ?? '';
                $title = $m['accountTitle'] ?? '';
                $appt = $bundle['appointmentDate'][$empCode] ?? null;
                $metaStmt->bind_param('sdissssssss', $empCode, $basic, $punc, $sudo, $desig, $cnic, $bank, $acc, $title, $appt, $branch);
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
        $stmt = $conn->prepare("DELETE FROM payroll_adjustments WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            respond(true, null, 'Adjustment deleted');
        }
        respond(false, null, $conn->error);
        break;

    default:
        respond(false, null, 'Invalid action');
}
