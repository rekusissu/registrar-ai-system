<?php
// ============================================================
//  API/STUDENT-DOCUMENTS.PHP
//  Student portal — submit a document request (v2 workflow).
//
//  Accepts multipart/form-data (requirement file) or JSON.
//  Server-side responsibilities:
//    * resolve student_id from the session (never from the client)
//    * validate the catalog item + workflow fields
//    * compute the fee (flat / per_page / per_syllabus)
//    * generate request_id (DOC-YYYY-NNNN) + qr_hash
//    * clearance gate: finance.balance > 0 → Pending_Clearance
//    * seed 3 exit_clearances (Alumni/Dean/Property) for
//      exit-clearance documents
//    * persist requirement upload + status event + audit log
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
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$role = getCurrentUserRole();
if (!in_array($role, ['student', 'admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Accept both multipart/form-data and raw JSON bodies.
$input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);

// Student is resolved from the session for portal users; a registrar/admin
// filing on behalf of a walk-in may pass student_id explicitly.
$studentId = (int) ($input['student_id'] ?? 0);
if (in_array($role, ['admin', 'registrar'], true) && $studentId > 0) {
    $exists = Database::getInstance()->fetchOne('SELECT id FROM students WHERE id = ?', [$studentId]);
    if (!$exists) {
        echo json_encode(['success' => false, 'message' => 'Selected student not found.']);
        exit;
    }
} else {
    $studentId = getCurrentStudentId();
}
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'No student account is linked to this session. Contact the Registrar.']);
    exit;
}

$catalogId    = (int) ($input['catalog_id'] ?? 0);
$quantity     = max(1, (int) ($input['quantity'] ?? 1));
$requestType  = trim($input['request_type'] ?? 'Regular');
$fulfillment  = trim($input['fulfillment_type'] ?? 'Pickup');
$purpose      = trim($input['purpose'] ?? '');
$recipient    = trim($input['recipient'] ?? '');
$address      = trim($input['delivery_address'] ?? '');
// Payment method: Online (mock GCash/Maya gateway) or Cash on Delivery.
// Keep the canonical DB casing ('Cash_on_Delivery') — do not uppercase, the
// enum is case-sensitive.
$paymentMethod = trim($input['payment_method'] ?? 'Online');
if (!in_array($paymentMethod, ['Online', 'Cash_on_Delivery'], true)) {
    $paymentMethod = 'Online';
}

if (!in_array($requestType, ['Express', 'Regular'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request type.']);
    exit;
}
if (!in_array($fulfillment, ['Pickup', 'Digital', 'Courier'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid fulfillment type.']);
    exit;
}
if ($purpose === '') {
    echo json_encode(['success' => false, 'message' => 'Purpose is required.']);
    exit;
}
if ($fulfillment === 'Courier' && $address === '') {
    echo json_encode(['success' => false, 'message' => 'Delivery address is required for courier fulfillment.']);
    exit;
}

try {
    $db = Database::getInstance();

    $catalog = $db->fetchOne('SELECT * FROM document_catalog WHERE id = ? AND is_active = 1', [$catalogId]);
    if (!$catalog) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive document in the catalog.']);
        exit;
    }

    // Fee: flat → base_fee; per_page / per_syllabus → base_fee × quantity.
    $fee = round((float) $catalog['base_fee'] * ($catalog['fee_type'] === 'flat' ? 1 : $quantity), 2);

    // Courier delivery fee — quoted up-front and borne by the student.
    // Never trust the client's number: the server recomputes the same
    // deterministic quote the student saw in the fee preview.
    $deliveryFee = null;
    if ($fulfillment === 'Courier') {
        $deliveryFee = mockDeliveryQuote($address)['total_fee'];
    }

    // Per-year sequence: DOC-2026-0001, DOC-2026-0002, …
    $year = date('Y');
    $seq = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM document_requests WHERE request_id LIKE ?",
        ['DOC-' . $year . '-%']
    );
    $requestId = 'DOC-' . $year . '-' . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);

    $qrHash = hash('sha256', $requestId . '|' . random_bytes(16));

    // ── Clearance gate (spec: SELECT balance FROM finance WHERE student_id = …) ──
    $balance = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$studentId]) ?? 0.00);
    if ($balance > 0) {
        // Financial block — nothing proceeds until the balance is settled.
        $status = 'Pending_Clearance';
    } elseif ($paymentMethod === 'Cash_on_Delivery') {
        // No online step: work starts immediately; the student pays the
        // courier (document fee + delivery fee) when the document arrives.
        $status = 'Processing';
    } else {
        $status = 'Awaiting_Payment';
    }

    // Legacy document_type vocabulary, kept for the old column.
    $legacyTypeMap = [
        'DOC-TOR'     => 'transcript',
        'DOC-COE'     => 'certificate',
        'DOC-GM'      => 'good_moral',
        'DOC-DIPLOMA' => 'diploma',
        'DOC-CTC'     => 'ctc',
        'DOC-HD'      => 'honorable_dismissal',
        'DOC-CD'      => 'course_description',
    ];
    $legacyType = $legacyTypeMap[$catalog['sku']] ?? strtolower($catalog['sku']);

    // Requirement file upload (scanned ID / affidavit) — optional but expected.
    $reqFilePath = null;
    if (!empty($_FILES['requirement_file']) && ($_FILES['requirement_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['requirement_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Requirement file upload failed.']);
            exit;
        }
        if (!isAllowedFile($_FILES['requirement_file']['name'])) {
            echo json_encode(['success' => false, 'message' => 'Requirement file must be a PDF, JPG, or PNG image.']);
            exit;
        }
        $dir = __DIR__ . '/../uploads/document_requirements/';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = generateFilename($_FILES['requirement_file']['name']);
        if (!move_uploaded_file($_FILES['requirement_file']['tmp_name'], $dir . $name)) {
            echo json_encode(['success' => false, 'message' => 'Could not save the requirement file.']);
            exit;
        }
        $reqFilePath = 'uploads/document_requirements/' . $name;
    } elseif ($catalog['requirement']) {
        // Catalog row advertises a requirement but none was uploaded — soft warn.
        $reqFilePath = null;
    }

    $now = date('Y-m-d H:i:s');
    $conn = $db->getConnection();
    $conn->beginTransaction();

    try {
        $data = [
            'request_date'          => $now,
            'student_id'            => $studentId,
            'document_type'         => $legacyType,
            'purpose'               => $purpose,
            'recipient'             => $recipient !== '' ? $recipient : null,
            'status'                => 'pending',
            'fee_amount'            => $fee,
            'official_receipt'      => null,
            // v2 workflow fields
            'request_id'            => $requestId,
            'catalog_id'            => $catalogId,
            'quantity'              => $quantity,
            'request_type'          => $requestType,
            'fulfillment_type'      => $fulfillment,
            'delivery_address'      => $fulfillment === 'Courier' ? $address : null,
            'payment_method'        => $paymentMethod,
            'delivery_fee'          => $deliveryFee,
            'document_status'       => $status,
            'qr_hash'               => $qrHash,
            'requirement_file_path' => $reqFilePath,
        ];

        $id = $db->insert('document_requests', $data);

        // Cash on Delivery — record the amount owed up front so there is a
        // payment trail; it is marked completed when the document is claimed.
        if ($paymentMethod === 'Cash_on_Delivery' && $balance <= 0) {
            $codTxn = 'TXN-MOCK-' . mt_rand(1000, 9999);
            $db->insert('mock_payment_transactions', [
                'transaction_id' => $codTxn,
                'request_id'     => $id,
                'student_id'     => $studentId,
                'amount'         => round($fee + ($deliveryFee ?? 0), 2),
                'currency'       => 'PHP',
                'status'         => 'pending',
                'method'         => 'Cash_on_Delivery',
                'due_on'         => 'delivery',
                'payment_url'    => null,
                'created_at'     => $now,
            ]);
        }

        // Initial status event.
        $db->insert('document_request_events', [
            'request_id' => $id,
            'status'     => $status,
            'note'       => $status === 'Pending_Clearance'
                ? 'Request submitted (' . $requestId . ') — held pending clearance'
                : ($paymentMethod === 'Cash_on_Delivery'
                    ? 'Request submitted (' . $requestId . ') — cash on delivery, pay courier on receipt'
                    : 'Request submitted (' . $requestId . ') — awaiting payment'),
            'created_by' => $_SESSION['user_id'] ?? null,
            'created_at' => $now,
        ]);

        // Exit clearance hard stop for TOR-final / Honorable Dismissal.
        if ((int) $catalog['triggers_exit_clearance'] === 1) {
            foreach (['Alumni', 'Dean', 'Property'] as $office) {
                $db->insert('exit_clearances', [
                    'request_id' => $id,
                    'office'     => $office,
                    'status'     => 'PENDING',
                    'created_at' => $now,
                ]);
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        // Clean up the uploaded file so nothing orphaned is left behind.
        if ($reqFilePath && file_exists(__DIR__ . '/../' . $reqFilePath)) {
            @unlink(__DIR__ . '/../' . $reqFilePath);
        }
        throw $e;
    }

    logActivity($_SESSION['user_id'], 'document_request_submit', null, 'document_requests', $id,
        null, ['request_id' => $requestId, 'catalog_id' => $catalogId, 'fee' => $fee, 'document_status' => $status]);

    echo json_encode([
        'success' => true,
        'message' => $status === 'Pending_Clearance'
            ? 'Request submitted, but you have an outstanding balance (₱' . number_format($balance, 2) . ') pending clearance.'
            : 'Document request submitted.',
        'data' => ['id' => $id, 'request_id' => $requestId, 'document_status' => $status,
                   'fee' => $fee, 'delivery_fee' => $deliveryFee, 'payment_method' => $paymentMethod],
    ]);
} catch (Throwable $e) {
    json_error($e, 'Unable to submit the request.');
}
