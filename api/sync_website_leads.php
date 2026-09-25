<?php
require_once 'config.php';

if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Unauthorized: HR and Super Admin only');
}

$api_token = 'btk_crm_mWl9wKuxqfd5YwRgfd1ws6FvwbjVvRi3rtx7wdTm5Po';
$base_url  = 'https://balitech.org/api/leads';

$action = $_GET['action'] ?? 'sync'; // 'sync', 'status', 'queue_preview'
$user_id = getCurrentUserId();
$user_name = getCurrentUserName();
$active_branch = get_active_company_branch();

if ($action === 'status') {
    // Get last sync stats
    $last_sync = $conn->query("SELECT * FROM website_sync_logs ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    $total_website_leads = $conn->query("SELECT COUNT(*) as c FROM leads WHERE external_lead_id IS NOT NULL AND company_branch = '$active_branch'")->fetch_assoc()['c'] ?? 0;
    
    respond(true, [
        'last_sync' => $last_sync,
        'total_synced_in_branch' => (int)$total_website_leads
    ]);
}

// Perform live sync from balitech.org API
$max_pages = isset($_GET['pages']) ? max(1, min(20, intval($_GET['pages']))) : 5; // default fetch first 5 pages (250 leads) or all
$queue_filter = trim($_GET['queue'] ?? ''); // optional queue: recruitment, hr-employment-check

$total_fetched = 0;
$total_imported = 0;
$total_skipped = 0;
$errors = [];

for ($page = 1; $page <= $max_pages; $page++) {
    $target_url = "$base_url?page=$page&limit=50";
    if ($queue_filter) {
        $target_url .= "&queue=" . urlencode($queue_filter);
    }

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_token",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        $errors[] = "Page $page failed: HTTP $http_code $curl_error";
        break; // stop on failure
    }

    $res_data = json_decode($response, true);
    $leads_batch = $res_data['leads'] ?? $res_data['data'] ?? (is_array($res_data) && isset($res_data[0]) ? $res_data : []);

    if (empty($leads_batch)) {
        break; // No more leads
    }

    $total_fetched += count($leads_batch);

    // Prepare DB statements
    $chk_stmt = $conn->prepare("SELECT id FROM leads WHERE (external_lead_id = ? AND external_lead_id IS NOT NULL) OR (phone = ? AND phone != '') LIMIT 1");
    $ins_stmt = $conn->prepare("
        INSERT INTO leads (
            external_lead_id, full_name, phone, email, city, position_applied, 
            company_branch, source, current_stage, cv_file_url, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'website', 'new', ?, NOW(), NOW())
    ");
    $aud_stmt = $conn->prepare("
        INSERT INTO lead_audit (lead_id, user_id, user_name, action, new_value, notes, created_at)
        VALUES (?, ?, ?, 'create', 'new', 'Synced from Balitech Website', NOW())
    ");

    foreach ($leads_batch as $item) {
        $ext_id   = (string)($item['id'] ?? $item['_id'] ?? '');
        $name     = trim((string)($item['name'] ?? $item['full_name'] ?? ''));
        $raw_phone= trim((string)($item['phone'] ?? $item['contact'] ?? ''));
        $phone    = preg_replace('/[^0-9]/', '', $raw_phone);
        $email    = trim((string)($item['email'] ?? ''));
        $position = trim((string)($item['position'] ?? $item['department'] ?? 'General'));
        $city     = trim((string)($item['city'] ?? 'Islamabad'));
        $company_raw = strtolower(trim((string)($item['company'] ?? '')));
        $message  = trim((string)($item['message'] ?? ''));

        // Smart branch normalization
        $branch = 'main';
        if (strpos($company_raw, 'commercial') !== false) {
            $branch = 'commercial';
        } elseif (strpos($company_raw, 'i9') !== false || strpos($company_raw, 'i-9') !== false) {
            $branch = 'I9';
        } elseif (strpos($company_raw, 'v2') !== false || strpos($company_raw, '2.0') !== false) {
            $branch = 'v2';
        } elseif (strpos($company_raw, 'v3') !== false || strpos($company_raw, '3.0') !== false) {
            $branch = 'v3';
        }

        if (!$name || !$phone) {
            $total_skipped++;
            continue;
        }

        // Check if duplicate
        $chk_stmt->bind_param("ss", $ext_id, $phone);
        $chk_stmt->execute();
        $chk_res = $chk_stmt->get_result();

        if ($chk_res->num_rows > 0) {
            $total_skipped++;
            continue;
        }

        // Insert new lead
        $cv_url = $ext_id ? "api/fetch_lead_cv.php?external_id=" . urlencode($ext_id) : null;
        $ins_stmt->bind_param(
            "ssssssss",
            $ext_id, $name, $phone, $email, $city, $position, $branch, $cv_url
        );
        
        if ($ins_stmt->execute()) {
            $new_lid = $ins_stmt->insert_id;
            $aud_stmt->bind_param("iis", $new_lid, $user_id, $user_name);
            $aud_stmt->execute();

            // Log initial website message as first remark if present
            if ($message) {
                $rem_stmt = $conn->prepare("INSERT INTO lead_remarks (lead_id, added_by, added_by_name, added_by_role, remark, created_at) VALUES (?, ?, ?, 'Website Intake', ?, NOW())");
                $rem_stmt->bind_param("iiss", $new_lid, $user_id, $user_name, $message);
                $rem_stmt->execute();
            }

            $total_imported++;
        } else {
            $total_skipped++;
        }
    }
}

// Save sync record log
$status = empty($errors) ? 'success' : ($total_imported > 0 ? 'partial' : 'error');
$err_msg = !empty($errors) ? implode(" | ", $errors) : null;

$log_stmt = $conn->prepare("
    INSERT INTO website_sync_logs (synced_by_user_id, synced_by_name, total_fetched, total_imported, total_skipped_duplicate, status, error_message, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
");
$log_stmt->bind_param("isiiiss", $user_id, $user_name, $total_fetched, $total_imported, $total_skipped, $status, $err_msg);
$log_stmt->execute();

respond(true, [
    'total_fetched' => $total_fetched,
    'total_imported' => $total_imported,
    'total_skipped'  => $total_skipped,
    'pages_checked'  => $page - 1,
    'errors'         => $errors
], "Sync completed: $total_imported new leads imported, $total_skipped duplicates skipped.");
