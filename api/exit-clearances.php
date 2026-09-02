<?php
// ============================================================
//  API/EXIT-CLEARANCES.PHP
//  Registrar — mark an exit-clearance office CLEARED (or reset).
//
//    POST {request_id, office, action: clear|reset}
//
//  Only admin / registrar may act. Drives the "hard stop": the
//  Approve/Mark-Ready transition stays disabled until Alumni,
//  Dean, and Property are all CLEARED.
// ============================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$requestId = (int) ($input['request_id'] ?? 0);
$office    = trim($input['office'] ?? '');
$action    = $input['action'] ?? 'clear';

if (!$requestId || !in_array($office, ['Alumni', 'Dean', 'Property'], true)) {
    echo json_encode(['success' => false, 'message' => 'request_id and office (Alumni|Dean|Property) are required.']);
    exit;
}

try {
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');
    $userId = $_SESSION['user_id'];

    $req = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Document request not found.']);
        exit;
    }

    if ($action === 'reset') {
        $db->update('exit_clearances', [
            'status'     => 'PENDING',
            'cleared_by' => null,
            'cleared_at' => null,
        ], 'request_id = ? AND office = ?', [$requestId, $office]);
        $note = $office . ' office clearance reopened';
    } else {
        $db->update('exit_clearances', [
            'status'     => 'CLEARED',
            'cleared_by' => $userId,
            'cleared_at' => $now,
        ], 'request_id = ? AND office = ?', [$requestId, $office]);
        $note = $office . ' office cleared';
    }

    $db->insert('document_request_events', [
        'request_id' => $requestId,
        'status'     => $req['document_status'],
        'note'       => $note,
        'created_by' => $userId,
        'created_at' => $now,
    ]);

    logActivity($userId, 'exit_clearance_' . $action, null, 'exit_clearances', $requestId,
        null, ['office' => $office]);

    $clearances = $db->fetchAll(
        'SELECT office, status, cleared_at FROM exit_clearances WHERE request_id = ? ORDER BY FIELD(office, "Alumni", "Dean", "Property")',
        [$requestId]
    );
    $allCleared = $clearances && count($clearances) === 3
        && count(array_filter($clearances, fn($c) => $c['status'] === 'CLEARED')) === 3;

    echo json_encode([
        'success' => true,
        'message' => $note . '.',
        'data'    => ['clearances' => $clearances, 'all_cleared' => $allCleared],
    ]);
} catch (Throwable $e) {
    json_error($e, 'Unable to update exit clearance.');
}
