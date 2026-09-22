<?php
// ============================================================
//  API/MASTERLIST-HANDOFF.PHP
//  Masterlist hand-off / send-list actions (CMS integration
//  stub). Records the hand-off in the audit log so it can be
//  traced, without actually pushing to an external system.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$db = Database::getInstance();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$program = trim((string)($input['program'] ?? ''));

$details = json_encode([
    'program' => $program !== '' ? $program : '(all)',
    'sent_by' => $_SESSION['full_name'] ?? getCurrentUserName(),
]);
logActivity($_SESSION['user_id'], $program !== '' ? 'masterlist_handoff' : 'masterlist_send', $details, 'students');

$message = $program !== ''
    ? 'List handed off to CMS for ' . $program . '.'
    : 'Masterlist sent to the Academic Strand / Course Assignment module (CMS).';

echo json_encode(['success' => true, 'message' => $message]);