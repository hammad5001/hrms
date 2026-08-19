<?php
/**
 * Petty Cash Management API
 * Finance Portal — api/petty_cash_api.php
 *
 * Actions:
 *   GET  getDashboard       — Summary stats + chart data
 *   GET  getRequests        — Filtered list with pagination
 *   GET  getLedger          — Monthly approved-only ledger
 *   GET  exportCSV          — Export filtered results as CSV download
 *   GET  getUsers           — Users list for dropdowns
 *   POST submitRequest      — Create new request (multipart with bill upload)
 *   POST updateAction       — Approve / Reject / Need Correction
 *
 * Role hook: $PETTY_CASH_ROLES — add role restrictions here later.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once 'config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$portalRole = $_SESSION['portal_role'] ?? '';
// Role restriction hook — extend this array later for fine-grained control
$PETTY_CASH_ROLES = ['finance', 'admin', 'super_admin'];
if (!in_array($portalRole, $PETTY_CASH_ROLES, true)) {
    respond(false, null, 'Finance access required');
}

// Production: schema migrations are run manually during deployment.

// ── Constants ───────────────────────────────────────────────────────────────
define('PC_CATEGORIES', [
    'Kitchen / Pantry',
    'Office Supplies',
    'Cleaning',
    'Maintenance',
    'Transport',
    'Repair',
    'Internet / Utilities',
    'Emergency Purchase',
    'Other',
]);

define('PC_UPLOAD_BASE', dirname(__DIR__) . '/uploads/petty-cash/');

// ── Router ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {
    case 'getDashboard':
        pc_get_dashboard($conn);
        break;
    case 'getRequests':
        pc_get_requests($conn);
        break;
    case 'getLedger':
        pc_get_ledger($conn);
        break;
    case 'exportCSV':
        pc_export_csv($conn);
        break;
    case 'getUsers':
        pc_get_users($conn);
        break;
    case 'getCategories':
        pc_get_categories($conn);
        break;
    case 'submitRequest':
        if ($method !== 'POST') respond(false, null, 'POST required');
        pc_submit_request($conn);
        break;
    case 'updateAction':
        if ($method !== 'POST') respond(false, null, 'POST required');
        pc_update_action($conn);
        break;
    case 'deleteRequest':
        if ($method !== 'POST') respond(false, null, 'POST required');
        pc_delete_request($conn);
        break;
    default:
        respond(false, null, 'Unknown action: ' . htmlspecialchars($action));
}

// ═══════════════════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════
function pc_get_dashboard(mysqli $conn): void {
    $month = $_GET['month'] ?? date('Y-m');
    $branch = $_GET['branch'] ?? '';

    [$yr, $mo] = explode('-', $month . '-01');
    $start = "{$yr}-{$mo}-01";
    $end   = date('Y-m-t', strtotime($start));

    $bWhere = '';
    $bParams = [];
    $bTypes  = '';
    if ($branch !== '') {
        $bWhere = ' AND branch = ?';
        $bParams[] = $branch;
        $bTypes   .= 's';
    }

    // Stat counts & totals
    $stats = [
        'approved_amount'  => 0,
        'pending_amount'   => 0,
        'total_submitted'  => 0,
        'pending'          => 0,
        'approved'         => 0,
        'rejected'         => 0,
        'need_correction'  => 0,
    ];

    $types = 'ss' . $bTypes;
    $params = array_merge([$start, $end], $bParams);

    // Amounts by status this month
    $sql = "SELECT status, COALESCE(SUM(amount),0) as total_amt, COUNT(*) as cnt
            FROM petty_cash_requests
            WHERE expense_date BETWEEN ? AND ?{$bWhere}
            GROUP BY status";
    $stmt = $conn->prepare($sql);
    bindParams($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0;
    while ($row = $res->fetch_assoc()) {
        $total += (int)$row['cnt'];
        $st = $row['status'];
        if ($st === 'submitted') {
            $stats['pending']        = (int)$row['cnt'];
            $stats['pending_amount'] = (float)$row['total_amt'];
        }
        if ($st === 'approved') {
            $stats['approved']        = (int)$row['cnt'];
            $stats['approved_amount'] = (float)$row['total_amt'];
        }
        if ($st === 'rejected') {
            $stats['rejected']        = (int)$row['cnt'];
        }
        if ($st === 'need_correction') {
            $stats['need_correction'] = (int)$row['cnt'];
        }
    }
    $stats['total_submitted'] = $total;
    $stmt->close();

    // Category-wise approved amounts this month
    $sql = "SELECT category, COALESCE(SUM(amount),0) as total
            FROM petty_cash_requests
            WHERE status='approved' AND expense_date BETWEEN ? AND ?{$bWhere}
            GROUP BY category ORDER BY total DESC";
    $stmt = $conn->prepare($sql);
    bindParams($stmt, $types, $params);
    $stmt->execute();
    $categoryData = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categoryData[] = ['label' => $row['category'], 'value' => (float)$row['total']];
    }
    $stmt->close();

    // Branch-wise approved amounts this month
    $sql = "SELECT branch, COALESCE(SUM(amount),0) as total
            FROM petty_cash_requests
            WHERE status='approved' AND expense_date BETWEEN ? AND ?{$bWhere}
            GROUP BY branch ORDER BY total DESC";
    $stmt2 = $conn->prepare($sql);
    bindParams($stmt2, $types, $params);
    $stmt2->execute();
    $branchData = [];
    $res = $stmt2->get_result();
    while ($row = $res->fetch_assoc()) {
        $branchData[] = ['label' => ucfirst($row['branch']), 'value' => (float)$row['total']];
    }
    $stmt2->close();

    respond(true, [
        'stats'        => $stats,
        'categoryData' => $categoryData,
        'branchData'   => $branchData,
        'month'        => $month,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// GET REQUESTS (Filterable)
// ═══════════════════════════════════════════════════════════════════════════
function pc_get_requests(mysqli $conn): void {
    $filters = [
        'month'        => $_GET['month']        ?? '',
        'date_from'    => $_GET['date_from']    ?? '',
        'date_to'      => $_GET['date_to']      ?? '',
        'branch'       => $_GET['branch']       ?? '',
        'category'     => $_GET['category']     ?? '',
        'status'       => $_GET['status']       ?? '',
        'requested_by' => $_GET['requested_by'] ?? '',
        'action_by'    => $_GET['action_by']    ?? '',
    ];

    $where = [];
    $params = [];
    $types  = '';

    if ($filters['month'] !== '') {
        [$yr, $mo] = explode('-', $filters['month'] . '-01');
        $start = "{$yr}-{$mo}-01";
        $end   = date('Y-m-t', strtotime($start));
        $where[]  = 'expense_date BETWEEN ? AND ?';
        $params[] = $start;
        $params[] = $end;
        $types   .= 'ss';
    }
    if ($filters['date_from'] !== '') {
        $where[]  = 'expense_date >= ?';
        $params[] = $filters['date_from'];
        $types   .= 's';
    }
    if ($filters['date_to'] !== '') {
        $where[]  = 'expense_date <= ?';
        $params[] = $filters['date_to'];
        $types   .= 's';
    }
    if ($filters['branch'] !== '') {
        $where[]  = 'branch = ?';
        $params[] = $filters['branch'];
        $types   .= 's';
    }
    if ($filters['category'] !== '') {
        $where[]  = 'category = ?';
        $params[] = $filters['category'];
        $types   .= 's';
    }
    if ($filters['status'] !== '') {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
        $types   .= 's';
    }
    if ($filters['requested_by'] !== '') {
        $where[]  = 'requested_by LIKE ?';
        $params[] = '%' . $filters['requested_by'] . '%';
        $types   .= 's';
    }
    if ($filters['action_by'] !== '') {
        $where[]  = 'action_by LIKE ?';
        $params[] = '%' . $filters['action_by'] . '%';
        $types   .= 's';
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT * FROM petty_cash_requests {$whereClause} ORDER BY created_at DESC LIMIT 500";

    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        bindParams($stmt, $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    respond(true, $rows);
}

// ═══════════════════════════════════════════════════════════════════════════
// MONTHLY LEDGER (approved only)
// ═══════════════════════════════════════════════════════════════════════════
function pc_get_ledger(mysqli $conn): void {
    $month  = $_GET['month'] ?? date('Y-m');
    $branch = $_GET['branch'] ?? '';

    [$yr, $mo] = explode('-', $month . '-01');
    $start = "{$yr}-{$mo}-01";
    $end   = date('Y-m-t', strtotime($start));

    $bWhere  = '';
    $params  = [$start, $end];
    $types   = 'ss';

    if ($branch !== '') {
        $bWhere   = ' AND branch = ?';
        $params[] = $branch;
        $types   .= 's';
    }

    $sql  = "SELECT * FROM petty_cash_requests
             WHERE status='approved' AND expense_date BETWEEN ? AND ?{$bWhere}
             ORDER BY expense_date ASC, id ASC";
    $stmt = $conn->prepare($sql);
    bindParams($stmt, $types, $params);
    $stmt->execute();
    $res  = $stmt->get_result();

    $rows  = [];
    $total = 0.0;
    $categoryTotals = [];
    while ($row = $res->fetch_assoc()) {
        $rows[]  = $row;
        $amt     = (float)$row['amount'];
        $total  += $amt;
        $cat     = trim($row['category']) ?: 'Other';
        if (!isset($categoryTotals[$cat])) {
            $categoryTotals[$cat] = 0.0;
        }
        $categoryTotals[$cat] += $amt;
    }
    $stmt->close();

    // Format breakdown array
    $categoryBreakdown = [];
    foreach ($categoryTotals as $catName => $catTotal) {
        $categoryBreakdown[] = [
            'category' => $catName,
            'total'    => $catTotal
        ];
    }
    usort($categoryBreakdown, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });

    respond(true, [
        'rows'              => $rows,
        'total'             => $total,
        'categoryBreakdown' => $categoryBreakdown,
        'month'             => $month
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// EXPORT CSV
// ═══════════════════════════════════════════════════════════════════════════
function pc_export_csv(mysqli $conn): void {
    // Reuse the same filter logic — build identical query
    ob_end_clean();

    $filters = [
        'month'        => $_GET['month']        ?? '',
        'date_from'    => $_GET['date_from']    ?? '',
        'date_to'      => $_GET['date_to']      ?? '',
        'branch'       => $_GET['branch']       ?? '',
        'category'     => $_GET['category']     ?? '',
        'status'       => $_GET['status']       ?? '',
        'requested_by' => $_GET['requested_by'] ?? '',
        'action_by'    => $_GET['action_by']    ?? '',
    ];

    $where = [];
    $params = [];
    $types  = '';

    if ($filters['month'] !== '') {
        [$yr, $mo] = explode('-', $filters['month'] . '-01');
        $start = "{$yr}-{$mo}-01";
        $end   = date('Y-m-t', strtotime($start));
        $where[]  = 'expense_date BETWEEN ? AND ?';
        $params[] = $start;
        $params[] = $end;
        $types   .= 'ss';
    }
    if ($filters['date_from'] !== '') {
        $where[]  = 'expense_date >= ?';
        $params[] = $filters['date_from'];
        $types   .= 's';
    }
    if ($filters['date_to'] !== '') {
        $where[]  = 'expense_date <= ?';
        $params[] = $filters['date_to'];
        $types   .= 's';
    }
    if ($filters['branch'] !== '') {
        $where[]  = 'branch = ?';
        $params[] = $filters['branch'];
        $types   .= 's';
    }
    if ($filters['category'] !== '') {
        $where[]  = 'category = ?';
        $params[] = $filters['category'];
        $types   .= 's';
    }
    if ($filters['status'] !== '') {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
        $types   .= 's';
    }
    if ($filters['requested_by'] !== '') {
        $where[]  = 'requested_by LIKE ?';
        $params[] = '%' . $filters['requested_by'] . '%';
        $types   .= 's';
    }
    if ($filters['action_by'] !== '') {
        $where[]  = 'action_by LIKE ?';
        $params[] = '%' . $filters['action_by'] . '%';
        $types   .= 's';
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT id, expense_date, branch, category, item_name, vendor_name,
                   bill_number, amount, requested_by, remarks, status,
                   action_by, action_remarks, action_at, created_at
            FROM petty_cash_requests {$whereClause} ORDER BY expense_date DESC";

    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        bindParams($stmt, $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }

    $fname = 'petty_cash_export_' . date('Ymd_His') . '.csv';
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Expense Date','Branch','Category','Item Name','Vendor','Bill No','Amount (PKR)','Requested By','Remarks','Status','Action By','Action Remarks','Action At','Created At']);

    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['id'],
            $row['expense_date'],
            $row['branch'],
            $row['category'],
            $row['item_name'],
            $row['vendor_name'],
            $row['bill_number'],
            number_format((float)$row['amount'], 2, '.', ''),
            $row['requested_by'],
            $row['remarks'],
            $row['status'],
            $row['action_by'],
            $row['action_remarks'],
            $row['action_at'],
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// GET USERS (for dropdown)
// ═══════════════════════════════════════════════════════════════════════════
function pc_get_users(mysqli $conn): void {
    $res = $conn->query("SELECT id, full_name, portal_role FROM users WHERE status='active' ORDER BY full_name ASC");
    $users = [];
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    respond(true, $users);
}

// ═══════════════════════════════════════════════════════════════════════════
// GET CATEGORIES (Default + Custom categories from database)
// ═══════════════════════════════════════════════════════════════════════════
function pc_get_categories(mysqli $conn): void {
    $categories = PC_CATEGORIES;
    $res = $conn->query("SELECT DISTINCT category FROM petty_cash_requests WHERE category IS NOT NULL AND category != ''");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cat = trim($row['category']);
            if ($cat !== '' && !in_array($cat, $categories, true)) {
                $categories[] = $cat;
            }
        }
    }
    respond(true, array_values($categories));
}

// ═══════════════════════════════════════════════════════════════════════════
// SUBMIT REQUEST (multipart upload)
// ═══════════════════════════════════════════════════════════════════════════
function pc_submit_request(mysqli $conn): void {
    // Validate required fields
    $required = ['expense_date', 'branch', 'category', 'item_name', 'amount', 'requested_by'];
    foreach ($required as $f) {
        if (empty($_POST[$f])) {
            respond(false, null, "Field '{$f}' is required");
        }
    }

    $category = trim($_POST['category']);
    if ($category === '') {
        respond(false, null, 'Category is required');
    }

    // Bill upload (required)
    if (empty($_FILES['bill_file']) || $_FILES['bill_file']['error'] !== UPLOAD_ERR_OK) {
        respond(false, null, 'Bill/slip upload is required');
    }

    $file     = $_FILES['bill_file'];
    $allowed  = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf'];
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowed, true)) {
        respond(false, null, 'Only images (JPG, PNG, WEBP) and PDF files are allowed');
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        respond(false, null, 'File size must not exceed 10MB');
    }

    // Create monthly upload directory
    $subDir = date('Y-m') . '/';
    $uploadDir = PC_UPLOAD_BASE . $subDir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        // Security: block direct access
        file_put_contents(PC_UPLOAD_BASE . '.htaccess',
            "Options -Indexes\n<Files *.php>\n    Order deny,allow\n    Deny from all\n</Files>\n");
    }

    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName  = 'pc_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destPath  = $uploadDir . $safeName;
    $relPath   = 'uploads/petty-cash/' . $subDir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        respond(false, null, 'Failed to save uploaded file');
    }

    $expenseDate  = $_POST['expense_date'];
    $branch       = $_POST['branch'];
    $category     = trim($_POST['category']);
    $itemName     = $_POST['item_name'];
    $description  = $_POST['description']   ?? '';
    $vendorName   = $_POST['vendor_name']   ?? '';
    $billNumber   = $_POST['bill_number']   ?? '';
    $amount       = (float)$_POST['amount'];
    $requestedBy  = $_POST['requested_by'];
    $reqById      = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $remarks      = $_POST['remarks']       ?? '';
    $origFileName = $file['name'];

    $stmt = $conn->prepare(
        "INSERT INTO petty_cash_requests
            (expense_date, branch, category, item_name, description, vendor_name,
             bill_number, amount, bill_file_path, bill_file_name, requested_by,
             requested_by_id, remarks, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'submitted')"
    );
    $stmt->bind_param(
        'sssssssdsssis',
        $expenseDate, $branch, $category, $itemName, $description, $vendorName,
        $billNumber, $amount, $relPath, $origFileName, $requestedBy,
        $reqById, $remarks
    );
    if (!$stmt->execute()) {
        respond(false, null, 'Database error: ' . $conn->error);
    }
    $newId = $stmt->insert_id;
    $stmt->close();

    respond(true, ['id' => $newId, 'bill_file_path' => $relPath]);
}

// ═══════════════════════════════════════════════════════════════════════════
// UPDATE ACTION (Approve / Reject / Need Correction)
// ═══════════════════════════════════════════════════════════════════════════
function pc_update_action(mysqli $conn): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $id      = (int)($input['id']      ?? 0);
    $action  = trim($input['action']   ?? '');
    $remarks = trim($input['remarks']  ?? '');

    if ($id <= 0) {
        respond(false, null, 'Invalid request ID');
    }

    $allowedActions = ['approved', 'rejected', 'need_correction'];
    if (!in_array($action, $allowedActions, true)) {
        respond(false, null, 'Invalid action');
    }

    // Fetch current record
    $stmt = $conn->prepare("SELECT id, status FROM petty_cash_requests WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respond(false, null, 'Request not found');
    }

    // Lock check: approved/rejected cannot be changed
    if (in_array($row['status'], ['approved', 'rejected'], true)) {
        respond(false, null, 'This request is locked and cannot be modified');
    }

    $actionBy   = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
    $actionById = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $actionAt   = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "UPDATE petty_cash_requests
         SET status=?, action_by=?, action_by_id=?, action_remarks=?, action_at=?, updated_at=NOW()
         WHERE id=?"
    );
    $stmt->bind_param('ssissi', $action, $actionBy, $actionById, $remarks, $actionAt, $id);
    if (!$stmt->execute()) {
        respond(false, null, 'Update failed: ' . $conn->error);
    }
    $stmt->close();

    respond(true, ['id' => $id, 'new_status' => $action]);
}

// ═══════════════════════════════════════════════════════════════════════════
// DELETE REQUEST (only submitted / need_correction)
// ═══════════════════════════════════════════════════════════════════════════
function pc_delete_request(mysqli $conn): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id    = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        respond(false, null, 'Invalid ID');
    }

    $stmt = $conn->prepare("SELECT id, status, bill_file_path FROM petty_cash_requests WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respond(false, null, 'Request not found');
    }
    if (in_array($row['status'], ['approved', 'rejected'], true)) {
        respond(false, null, 'Cannot delete a locked request');
    }

    // Delete file
    $filePath = dirname(__DIR__) . '/' . $row['bill_file_path'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }

    $stmt = $conn->prepare("DELETE FROM petty_cash_requests WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    respond(true, ['deleted_id' => $id]);
}
