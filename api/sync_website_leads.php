<?php
require_once 'config.php';

$portal_role = strtolower(trim((string)($_SESSION['portal_role'] ?? '')));
$is_reception = in_array($portal_role, ['receptionist', 'agent'], true);

if (!isAuthenticated() || (!isSuperRecruiter() && !$is_reception)) {
    respond(false, null, 'Unauthorized: HR, Super Admin, and Reception only');
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

    // Experience dictionary mapping
    $exp_map = [
        'none'  => 'Fresh / No experience',
        'lt6m'  => 'Less than 6 months',
        '6m1y'  => '6 months to 1 year',
        '1y2y'  => '1 to 2 years',
        '2y3y'  => '2 to 3 years',
        '3y5y'  => '3 to 5 years',
        'gt5y'  => '5 years or more'
    ];

    // Prepare DB statements
    $chk_stmt = $conn->prepare("SELECT id, source, current_stage FROM leads WHERE (external_lead_id = ? AND external_lead_id IS NOT NULL) OR (phone = ? AND phone != '') LIMIT 1");
    $ins_stmt = $conn->prepare("
        INSERT INTO leads (
            external_lead_id, reference_id, full_name, cnic, phone, email, city, education,
            experience, position_applied, queue_name, duplicate_flags, company_branch,
            source, heard_about, current_stage, cv_file_url, applicant_notes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $aud_stmt = $conn->prepare("
        INSERT INTO lead_audit (lead_id, user_id, user_name, action, new_value, notes, created_at)
        VALUES (?, ?, ?, 'create', 'new', 'Synced from Balitech Website', NOW())
    ");

    foreach ($leads_batch as $item) {
        $ext_id       = (string)($item['id'] ?? $item['_id'] ?? '');
        $reference_id = trim((string)($item['referenceId'] ?? ''));
        $name         = trim((string)($item['name'] ?? $item['full_name'] ?? ''));
        $raw_phone    = trim((string)($item['phone'] ?? $item['contact'] ?? ''));
        $phone        = preg_replace('/[^0-9]/', '', $raw_phone);
        $email        = trim((string)($item['email'] ?? ''));
        $position     = trim((string)($item['position'] ?? $item['department'] ?? 'General'));
        $city         = trim((string)($item['city'] ?? 'Islamabad'));
        $company_raw  = strtolower(trim((string)($item['company'] ?? '')));
        $message      = trim((string)($item['message'] ?? ''));
        $queue_name   = trim((string)($item['queue'] ?? 'recruitment'));
        
        $flags_raw = $item['flags'] ?? null;
        $duplicate_flags = '';
        if (is_array($flags_raw)) {
            $duplicate_flags = implode(', ', $flags_raw);
        } elseif (is_string($flags_raw) && !empty($flags_raw) && $flags_raw !== '[]') {
            $duplicate_flags = trim($flags_raw, '[]"\' ');
        }
        
        // Deep parsing of CNIC, education, experience and details from website payload
        $cnic = trim((string)($item['cnic'] ?? ''));
        $education = trim((string)($item['education'] ?? $item['qualification'] ?? ''));
        $experience = '';
        $heard_about = '';

        // Check if details JSON string is present
        if (!empty($item['details'])) {
            $details = is_array($item['details']) ? $item['details'] : json_decode($item['details'], true);
            if (!empty($details['answers'])) {
                $ans = $details['answers'];
                if (empty($cnic) && !empty($ans['cnic'])) {
                    $cnic = trim((string)$ans['cnic']);
                }
                if (empty($education) && !empty($ans['qualification'])) {
                    $education = trim((string)$ans['qualification']);
                }
                if (!empty($ans['heardAbout'])) {
                    $heard_about = trim((string)$ans['heardAbout']);
                }
                if (!empty($ans['experience'])) {
                    $exp_code = trim((string)$ans['experience']);
                    $experience = $exp_map[$exp_code] ?? $exp_code;
                }
            }

            // Check details.summary for clean human labels
            if (!empty($details['summary']) && is_array($details['summary'])) {
                foreach ($details['summary'] as $step) {
                    if (!empty($step['items']) && is_array($step['items'])) {
                        foreach ($step['items'] as $summaryItem) {
                            $lbl = strtolower((string)($summaryItem['label'] ?? ''));
                            if (str_contains($lbl, 'experience') && !empty($summaryItem['value'])) {
                                $experience = (string)$summaryItem['value'];
                            }
                        }
                    }
                }
            }

            // Check if duplicates list has entries
            if (!empty($details['duplicates']) && is_array($details['duplicates']) && empty($duplicate_flags)) {
                $duplicate_flags = 'possible-duplicate';
            }
        }

        // Check source JSON string or field
        $source_data = [];
        if (!empty($item['source'])) {
            $source_data = is_array($item['source']) ? $item['source'] : json_decode($item['source'], true);
            if (is_array($source_data)) {
                if (empty($heard_about) && !empty($source_data['heardAbout'])) {
                    $heard_about = (string)$source_data['heardAbout'];
                }
            } else {
                $source_data = [];
            }
        }

        // Determine walk-in vs regular website intake
        $raw_source_str = strtolower(trim((string)(is_string($item['source'] ?? '') ? $item['source'] : '')));
        $is_walkin = (
            !empty($item['is_walkin']) || 
            str_contains(strtolower($heard_about), 'walk') || 
            str_contains($raw_source_str, 'walk') ||
            strtolower(trim((string)($item['apply_type'] ?? ''))) === 'walkin' ||
            (isset($source_data['channel']) && strtolower($source_data['channel']) === 'walk-in') ||
            (isset($source_data['medium']) && strtolower($source_data['medium']) === 'reception-qr') ||
            (isset($source_data['landing']) && str_contains(strtolower($source_data['landing']), 'source=walk-in'))
        );

        if ($is_walkin) {
            $lead_source = 'walkin';
            $heard_about = 'Walk-in (Website QR)';
            $init_stage  = 'interview_scheduled';
        } else {
            $lead_source = 'website';
            $heard_about = $heard_about ?: 'Website';
            $init_stage  = 'new';
        }

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

        $created_timestamp = !empty($item['createdAt']) ? date('Y-m-d H:i:s', strtotime($item['createdAt'])) : date('Y-m-d H:i:s');
        $applicant_notes = $message ?: ($experience ? "Experience: $experience" : '');
        $cv_url = $ext_id ? "api/fetch_lead_cv.php?external_id=" . urlencode($ext_id) : null;

        // Check if duplicate
        $chk_stmt->bind_param("ss", $ext_id, $phone);
        $chk_stmt->execute();
        $chk_res = $chk_stmt->get_result();

        if ($chk_res->num_rows > 0) {
            $existing_lead = $chk_res->fetch_assoc();
            $upd_existing = $conn->prepare("
                UPDATE leads SET 
                    reference_id = COALESCE(NULLIF(reference_id, ''), ?),
                    experience = COALESCE(NULLIF(experience, ''), ?),
                    heard_about = COALESCE(NULLIF(heard_about, ''), ?),
                    queue_name = COALESCE(NULLIF(queue_name, ''), ?),
                    duplicate_flags = COALESCE(NULLIF(duplicate_flags, ''), ?),
                    cv_file_url = COALESCE(NULLIF(cv_file_url, ''), ?),
                    email = COALESCE(NULLIF(email, ''), ?),
                    cnic = COALESCE(NULLIF(cnic, ''), ?),
                    education = COALESCE(NULLIF(education, ''), ?),
                    applicant_notes = COALESCE(NULLIF(applicant_notes, ''), ?)
                WHERE id = ?
            ");
            $upd_existing->bind_param(
                "ssssssssssi",
                $reference_id, $experience, $heard_about, $queue_name, $duplicate_flags,
                $cv_url, $email, $cnic, $education, $applicant_notes, $existing_lead['id']
            );
            $upd_existing->execute();

            // If it's a walkin lead, ensure its interview entry has proper labels
            if ($is_walkin) {
                $conn->query("
                    UPDATE interviews 
                    SET interviewer_name = 'Walk-in Desk', location = 'Reception Desk' 
                    WHERE lead_id = {$existing_lead['id']} AND (interviewer_name = 'HR Manager' OR interviewer_name IS NULL)
                ");
            }

            $total_skipped++;
            continue;
        }

        // Insert new lead with rich metadata
        $ins_stmt->bind_param(
            "sssssssssssssssssss",
            $ext_id, $reference_id, $name, $cnic, $phone, $email, $city, $education,
            $experience, $position, $queue_name, $duplicate_flags, $branch,
            $lead_source, $heard_about, $init_stage, $cv_url, $applicant_notes, $created_timestamp
        );
        
        if ($ins_stmt->execute()) {
            $new_lid = $ins_stmt->insert_id;
            $aud_stmt->bind_param("iis", $new_lid, $user_id, $user_name);
            $aud_stmt->execute();

            // Log initial website message or summary as first remark
            $initial_note = $applicant_notes ?: ($is_walkin ? 'Walk-in applicant arrived via website portal' : '');
            if ($initial_note) {
                $rem_stmt = $conn->prepare("INSERT INTO lead_remarks (lead_id, added_by, added_by_name, added_by_role, remark, created_at) VALUES (?, ?, ?, 'Website Intake', ?, NOW())");
                $rem_stmt->bind_param("iiss", $new_lid, $user_id, $user_name, $initial_note);
                $rem_stmt->execute();
            }

            // Ensure scheduled/walkin queue entry if walk-in
            if ($is_walkin) {
                $chk_int = $conn->prepare("SELECT id FROM interviews WHERE lead_id = ? LIMIT 1");
                $chk_int->bind_param("i", $new_lid);
                $chk_int->execute();
                if ($chk_int->get_result()->num_rows === 0) {
                    $cur_date = !empty($item['createdAt']) ? date('Y-m-d', strtotime($item['createdAt'])) : date('Y-m-d');
                    $cur_time = !empty($item['createdAt']) ? date('H:i:s', strtotime($item['createdAt'])) : date('H:i:s');
                    $walkin_location = 'Reception Desk';
                    $walkin_interviewer = 'Walk-in Desk';
                    $walkin_notes = 'Walk-in applicant arrived via QR code' . ($reference_id ? " (Ref: $reference_id)" : '');
                    
                    $ins_int = $conn->prepare("
                        INSERT INTO interviews (lead_id, scheduled_by, scheduled_date, scheduled_time, location, interviewer_name, status, notes, company_branch, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW(), NOW())
                    ");
                    $ins_int->bind_param("iissssss", $new_lid, $user_id, $cur_date, $cur_time, $walkin_location, $walkin_interviewer, $walkin_notes, $branch);
                    $ins_int->execute();
                }
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
