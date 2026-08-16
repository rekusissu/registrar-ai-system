<?php
// ============================================================
//  API/STUDENT-DOCUMENTS.PHP
//  Student portal — submit a document request as the logged-in
//  student. Only the caller's own linked student record is used;
//  student_id is never accepted from the client.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$role = getCurrentUserRole();
if (!in_array($role, ['student', 'admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$studentId = getCurrentStudentId();
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Your account is not linked to a student record. Contact the Registrar.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$documentType = trim($input['document_type'] ?? '');
$purpose = trim($input['purpose'] ?? '');
$recipient = trim($input['recipient'] ?? '');

$allowedTypes = ['form137', 'good_moral', 'transcript', 'certificate', 'clearance'];
if (!in_array($documentType, $allowedTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid document type.']);
    exit;
}
if ($purpose === '') {
    echo json_encode(['success' => false, 'message' => 'Purpose is required.']);
    exit;
}

try {
    $db = Database::getInstance();

    $data = [
        'student_id'    => $studentId,
        'document_type' => $documentType,
        'purpose'       => $purpose,
        'recipient'     => $recipient !== '' ? $recipient : null,
        'status'        => 'pending',
        'request_date'  => date('Y-m-d H:i:s'),
    ];

    // Only include fee columns if present in the schema (idempotent guard)
    $cols = $db->fetchAll("SHOW COLUMNS FROM document_requests");
    $colNames = array_column($cols, 'Field');
    if (in_array('fee_amount', $colNames, true)) $data['fee_amount'] = 0.00;
    if (in_array('official_receipt', $colNames, true)) $data['official_receipt'] = null;

    $id = $db->insert('document_requests', $data);
    logActivity($_SESSION['user_id'], 'document_request_submit', null, 'document_requests', $id);

    echo json_encode(['success' => true, 'message' => 'Document request submitted.', 'data' => ['id' => $id]]);
} catch (Throwable $e) {
    json_error($e, 'Unable to submit the request.');
}
