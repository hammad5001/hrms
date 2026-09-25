<?php
require_once 'config.php';

if (!isAuthenticated()) {
    http_response_code(401);
    die('Unauthorized');
}

$ext_id = trim($_GET['external_id'] ?? '');
if (!$ext_id) {
    http_response_code(400);
    die('Missing lead external ID');
}

$api_token = 'btk_crm_mWl9wKuxqfd5YwRgfd1ws6FvwbjVvRi3rtx7wdTm5Po';
$target_url = "https://balitech.org/api/leads/" . urlencode($ext_id) . "/cv";

$ch = curl_init($target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $api_token"
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$cv_data = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/pdf';
curl_close($ch);

if ($http_code === 200 && $cv_data) {
    header("Content-Type: $content_type");
    header("Content-Disposition: inline; filename=\"candidate_cv_{$ext_id}.pdf\"");
    header("Content-Length: " . strlen($cv_data));
    echo $cv_data;
    exit;
} else {
    http_response_code(404);
    echo "CV file not found or unavailable on website server.";
}
