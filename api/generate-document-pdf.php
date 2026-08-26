<?php
// ============================================================
//  API/GENERATE-DOCUMENT-PDF.PHP
//  Registrar — build the encrypted, signed digital PDF for a
//  Digital-fulfillment document request (spec §7).
//
//    POST {request_id}
//
//  Saves to uploads/document_pdfs/, records pdf_path +
//  pdf_fingerprint (SHA-256) + qr_hash on the request, emits an
//  event + audit log. User password on the PDF = the student's
//  birth date; verification QR links to /verify.php?qr=<hash>.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_pdf.php';

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
if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'request_id is required.']);
    exit;
}

try {
    $db = Database::getInstance();

    $req = $db->fetchOne(
        "SELECT dr.*, c.sku, c.name AS catalog_name
           FROM document_requests dr
           LEFT JOIN document_catalog c ON c.id = dr.catalog_id
          WHERE dr.id = ?",
        [$requestId]
    );
    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Document request not found.']);
        exit;
    }
    if ($req['document_status'] === 'Rejected') {
        echo json_encode(['success' => false, 'message' => 'Rejected requests cannot generate a PDF.']);
        exit;
    }
    if (($req['fulfillment_type'] ?? '') !== 'Digital') {
        echo json_encode(['success' => false, 'message' => 'PDF generation is only for Digital fulfillment requests.']);
        exit;
    }

    $student = $db->fetchOne('SELECT * FROM students WHERE id = ?', [$req['student_id']]);
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student record not found.']);
        exit;
    }

    // Acting registrar for the signature block.
    $signatory = trim((string) ($db->fetchColumn('SELECT full_name FROM users WHERE id = ?', [$_SESSION['user_id']]) ?? ''));
    if ($signatory === '') {
        $signatory = 'Office of the Registrar';
    }

    $built = buildDocumentPdf($req, $student, [
        'sku'   => $req['sku'] ?? null,
        'name'  => $req['catalog_name'] ?? null,
    ], $signatory);

    $dir = __DIR__ . '/../uploads/document_pdfs/';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . $built['filename'];
    if (file_put_contents($dest, $built['bytes']) === false) {
        echo json_encode(['success' => false, 'message' => 'Could not save the generated PDF.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $upd = [
        'pdf_path'        => 'uploads/document_pdfs/' . $built['filename'],
        'pdf_fingerprint' => $built['fingerprint'],
    ];
    if (trim((string) ($req['qr_hash'] ?? '')) === '') {
        $upd['qr_hash'] = $built['qr_hash'];
    }
    // Guard: only touch columns that exist.
    $cols = $db->fetchAll('SHOW COLUMNS FROM document_requests');
    $colNames = array_column($cols, 'Field');
    foreach (array_keys($upd) as $k) {
        if (!in_array($k, $colNames, true)) unset($upd[$k]);
    }
    $db->update('document_requests', $upd, 'id = ?', [$requestId]);

    $db->insert('document_request_events', [
        'request_id' => $requestId,
        'status'     => $req['document_status'],
        'note'       => 'Digital PDF generated · SHA-256 ' . substr($built['fingerprint'], 0, 12) . '…',
        'created_by' => $_SESSION['user_id'],
        'created_at' => $now,
    ]);

    logActivity($_SESSION['user_id'], 'document_pdf_generate', null, 'document_requests', $requestId,
        null, ['filename' => $built['filename'], 'fingerprint' => $built['fingerprint']]);

    echo json_encode([
        'success' => true,
        'message' => 'Digital PDF generated.',
        'data'    => [
            'filename'    => $built['filename'],
            'path'        => 'uploads/document_pdfs/' . $built['filename'],
            'fingerprint' => $built['fingerprint'],
            'qr_hash'     => $upd['qr_hash'] ?? $built['qr_hash'],
        ],
    ]);
} catch (Throwable $e) {
    json_error($e, 'Unable to generate the PDF.');
}
