<?php
/**
 * Employee master data from attendance sheet (CSV) + employees table.
 */

function employee_sheet_csv_path(): string {
    return dirname(__DIR__) . '/attendance/Present Employee Data - Sheet4.csv';
}

function load_employee_sheet_data(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $employees = [];
    $csv_file = employee_sheet_csv_path();

    if (file_exists($csv_file)) {
        $file = fopen($csv_file, 'r');
        if ($file) {
            fgetcsv($file);
            while (($row = fgetcsv($file)) !== false) {
                if (empty(array_filter($row))) {
                    continue;
                }
                $row = array_map('trim', $row);
                if (empty($row[0])) {
                    continue;
                }
                $code = $row[0];
                $employees[$code] = [
                    'employee_code' => $code,
                    'full_name' => $row[1] ?? '',
                    'team' => $row[2] ?? '',
                    'department' => trim($row[3] ?? ''),
                    'designation' => $row[4] ?? '',
                    'branch' => $row[5] ?? '',
                    'source' => 'sheet',
                ];
            }
            fclose($file);
        }
    }

    $cache = $employees;
    return $cache;
}

function merge_employee_db_row(mysqli $conn, array $employees): array {
    $res = $conn->query("SELECT employee_code, full_name, department, designation, branch FROM employees WHERE is_active = 1");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $code = $row['employee_code'];
            if (!isset($employees[$code])) {
                $employees[$code] = [
                    'employee_code' => $code,
                    'full_name' => $row['full_name'],
                    'team' => '',
                    'department' => $row['department'] ?? '',
                    'designation' => $row['designation'] ?? 'Employee',
                    'branch' => $row['branch'] ?? 'Main',
                    'source' => 'database',
                ];
            } else {
                if (empty($employees[$code]['full_name'])) {
                    $employees[$code]['full_name'] = $row['full_name'];
                }
            }
        }
    }
    return $employees;
}

function get_employee_from_sheet(mysqli $conn, string $employee_code): ?array {
    $employee_code = trim($employee_code);
    if ($employee_code === '') {
        return null;
    }

    $all = merge_employee_db_row($conn, load_employee_sheet_data());

    if (isset($all[$employee_code])) {
        return $all[$employee_code];
    }

    foreach ($all as $emp) {
        if ((string)$emp['employee_code'] === (string)$employee_code) {
            return $emp;
        }
    }

    $stmt = $conn->prepare("SELECT employee_code, full_name, department FROM employees WHERE employee_code = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $employee_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return [
            'employee_code' => $row['employee_code'],
            'full_name' => $row['full_name'],
            'team' => '',
            'department' => $row['department'] ?? '',
            'designation' => 'Employee',
            'branch' => 'Main',
            'source' => 'database',
        ];
    }

    return null;
}

function suggest_email_from_name(string $full_name): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', trim($full_name)));
    $slug = trim($slug, '.');
    if ($slug === '') {
        return '';
    }
    return $slug . '@balitech.com';
}
