<?php
require_once __DIR__ . '/config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$interview_id = (int)($input['interview_id'] ?? $input['id'] ?? 0);

if (!$interview_id) {
    respond(false, null, 'Interview ID required');
}

$stmt = $conn->prepare("UPDATE interviews SET status = 'completed', updated_at = NOW() WHERE id = ?");
$stmt->bind_param('i', $interview_id);
if (!$stmt->execute()) {
    respond(false, null, $conn->error);
}

$lead_stmt = $conn->prepare("
    UPDATE leads l
    INNER JOIN interviews i ON i.lead_id = l.id
    SET l.current_stage = 'interview_conducted', l.updated_at = NOW()
    WHERE i.id = ?
");
$lead_stmt->bind_param('i', $interview_id);
$lead_stmt->execute();

respond(true, ['interview_id' => $interview_id], 'Interview marked completed');
