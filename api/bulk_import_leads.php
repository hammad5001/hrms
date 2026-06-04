<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: Super Admin only');
}

$data = json_decode(file_get_contents('php://input'), true);
$leads = $data['leads'] ?? [];
$file_name = $data['file_name'] ?? 'unknown.xlsx';

if (empty($leads) || !is_array($leads)) {
    respond(false, null, 'No leads provided');
}

$user_id = getCurrentUserId();

// Create bulk import record
$import_stmt = $conn->prepare("
    INSERT INTO bulk_imports (imported_by, file_name, total_rows, created_at)
    VALUES (?, ?, ?, NOW())
");
$total = count($leads);
$import_stmt->bind_param("isi", $user_id, $file_name, $total);
$import_stmt->execute();
$bulk_import_id = $conn->insert_id;

$inserted = 0;
$skipped = 0;
$errors = [];

$conn->begin_transaction();

try {
    $check_stmt  = $conn->prepare("SELECT id FROM leads WHERE phone = ?");
    $insert_stmt = $conn->prepare("
        INSERT INTO leads (full_name, father_name, phone, email, cnic, city, dob, education,
                          position_applied, referred_by, source, bulk_import_id, current_stage, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, 'new', NOW())
    ");

    foreach ($leads as $idx => $lead) {
        $name  = trim($lead['full_name'] ?? $lead['name'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', trim($lead['phone'] ?? $lead['contact'] ?? ''));
        
        if (!$name || !$phone) {
            $skipped++;
            continue;
        }

        // Check duplicate
        $check_stmt->bind_param("s", $phone);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $skipped++;
            continue;
        }

        $father   = trim($lead['father_name'] ?? '');
        $email    = trim($lead['email'] ?? '');
        $cnic     = trim($lead['cnic'] ?? '');
        $city     = trim($lead['city'] ?? '');
        $dob      = !empty($lead['dob']) ? date('Y-m-d', strtotime($lead['dob'])) : null;
        $edu      = trim($lead['education'] ?? '');
        $pos      = trim($lead['position_applied'] ?? $lead['position'] ?? '');
        $ref      = trim($lead['referred_by'] ?? 'Import');

        $insert_stmt->bind_param("ssssssssssi",
            $name, $father, $phone, $email, $cnic, $city, $dob, $edu,
            $pos, $ref, $bulk_import_id
        );

        if ($insert_stmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    // Update import record
    $upd = $conn->prepare("UPDATE bulk_imports SET inserted_rows=?, skipped_rows=? WHERE id=?");
    $upd->bind_param("iii", $inserted, $skipped, $bulk_import_id);
    $upd->execute();

    $conn->commit();

    respond(true, [
        'import_id'  => $bulk_import_id,
        'total'      => $total,
        'inserted'   => $inserted,
        'skipped'    => $skipped,
        'message'    => "$inserted leads imported, $skipped skipped (duplicates or invalid)"
    ]);

} catch (Exception $e) {
    $conn->rollback();
    respond(false, null, 'Import failed: ' . $e->getMessage());
}
?>
