<?php
/**
 * Company branch definitions (Main, 2.0, 3.0, Commercial)
 */
define('COMPANY_BRANCHES', [
    'main'         => ['label' => 'Main Branch',       'short' => 'Main',       'color' => '#f97316'],
    'v2'           => ['label' => '2.0 Branch',        'short' => '2.0',        'color' => '#3b82f6'],
    'v3'           => ['label' => '3.0 Branch',        'short' => '3.0',        'color' => '#8b5cf6'],
    'commercial'   => ['label' => 'Commercial Branch', 'short' => 'Commercial', 'color' => '#10b981'],
    'I9'           => ['label' => 'I-9 Branch',        'short' => 'I-9',        'color' => '#6366f1'],
    'workfromhome' => ['label' => 'Work From Home',    'short' => 'WFH',        'color' => '#ea580c'],
]);

function company_branch_keys(): array {
    return array_keys(COMPANY_BRANCHES);
}

function is_valid_company_branch(?string $key): bool {
    if ($key === null || $key === '') return false;
    if (isset(COMPANY_BRANCHES[$key])) return true;
    // Check case-insensitive match for valid keys
    foreach (COMPANY_BRANCHES as $k => $v) {
        if (strcasecmp($k, $key) === 0) return true;
    }
    return false;
}

function company_branch_label(?string $key): string {
    if ($key !== null && $key !== '') {
        foreach (COMPANY_BRANCHES as $k => $v) {
            if (strcasecmp($k, $key) === 0) {
                return $v['label'];
            }
        }
    }
    return 'Main Branch';
}

function normalize_company_branch(?string $input): string {
    if ($input === null || $input === '') {
        return 'main';
    }
    $input_clean = strtolower(trim($input));
    $map = [
        'main' => 'main', 'main branch' => 'main',
        '2.0' => 'v2', '2' => 'v2', 'v2' => 'v2', 'branch 2.0' => 'v2',
        '3.0' => 'v3', '3' => 'v3', 'v3' => 'v3', 'branch 3.0' => 'v3',
        'commercial' => 'commercial', 'commercial branch' => 'commercial',
        'i9' => 'I9', 'i-9' => 'I9', 'i 9' => 'I9', 'i-9 branch' => 'I9', 'i9 branch' => 'I9', 'branch i9' => 'I9', 'branch i-9' => 'I9',
        'workfromhome' => 'workfromhome', 'wfh' => 'workfromhome',
    ];
    if (isset($map[$input_clean])) {
        return $map[$input_clean];
    }
    foreach (COMPANY_BRANCHES as $k => $v) {
        if (strcasecmp($k, $input_clean) === 0) {
            return $k;
        }
    }
    return 'main';
}

function ensure_company_branch_schema(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $tables = [
        'users' => "ALTER TABLE `users` ADD COLUMN `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main' AFTER `branch`",
        'leads' => "ALTER TABLE `leads` ADD COLUMN `company_branch` VARCHAR(32) NOT NULL DEFAULT 'main' AFTER `source`",
    ];

    foreach ($tables as $table => $sql) {
        $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_branch'");
        if ($check && $check->num_rows === 0) {
            @$conn->query($sql);
            @$conn->query("ALTER TABLE `$table` ADD INDEX `idx_company_branch` (`company_branch`)");
        }
    }
}

function get_active_company_branch(): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    $b = $_SESSION['company_branch'] ?? 'main';
    return is_valid_company_branch($b) ? $b : 'main';
}

/** Only Super Admin may sign in from any company branch. WFH branch users are isolated to WFH portal. */
function user_can_access_branch(?string $user_branch, string $selected_branch, ?string $portal_role = null): bool {
    if ($portal_role === 'super_admin' || $portal_role === 'finance') {
        return true;
    }
    $user_branch = normalize_company_branch($user_branch ?: 'main');
    $selected_branch = normalize_company_branch($selected_branch);
    
    // If selected branch is workfromhome, the user's account must also be workfromhome
    if ($selected_branch === 'workfromhome') {
        return $user_branch === 'workfromhome';
    }
    
    // WFH account is not allowed to access standard branches
    if ($user_branch === 'workfromhome') {
        return $selected_branch === 'workfromhome';
    }
    
    return $user_branch === $selected_branch;
}

/** User-facing message when login branch does not match the account. */
function branch_login_mismatch_message(?string $user_branch): string {
    $label = company_branch_label(normalize_company_branch($user_branch ?: 'main'));
    return 'This account is registered at ' . $label . '. Select ' . $label . ' on the login page, then sign in again.';
}

function branch_sql_filter(mysqli $conn, string $alias = ''): array {
    ensure_company_branch_schema($conn);
    $col = ($alias ? $alias . '.' : '') . 'company_branch';
    if (isset($_SESSION['portal_role']) && in_array($_SESSION['portal_role'], ['admin', 'super_admin'], true)) {
        $branch = get_active_company_branch();
        return ["$col = ?", 's', [$branch]];
    }
    $branch = get_active_company_branch();
    return ["$col = ?", 's', [$branch]];
}
