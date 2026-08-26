<?php
// ============================================================
//  API/DOCUMENTS.PHP
//  Document management API
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/functions.php';

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
// Admin + registrar only
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    // ─── DIGITAL FILE STORAGE (Subsystem 9) ─────────────────────
    // Routed FIRST so ?section=files requests are never captured by
    // the generic document-request handlers below.
    // GET ?section=files&student_id=N            → list student's stored files
    // GET ?section=files&all=1                   → list all stored files
    // POST ?section=files (multipart)            → upload a file
    // POST ?section=files&action=delete          → delete a file
    if (isset($_GET['section']) && $_GET['section'] === 'files') {
        $db = Database::getInstance();

        // ── LIST FILES ──
        if ($method === 'GET') {
            $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
            if ($studentId) {
                $files = $db->fetchAll(
                    "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
                     FROM documents d
                     LEFT JOIN students s ON d.student_id = s.id
                     WHERE d.student_id = ?
                     ORDER BY d.created_at DESC",
                    [$studentId]
                );
            } else {
                $files = $db->fetchAll(
                    "SELECT d.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number
                     FROM documents d
                     LEFT JOIN students s ON d.student_id = s.id
                     ORDER BY d.created_at DESC"
                );
            }
            echo json_encode(['success' => true, 'data' => $files]);
            exit;
        }

        // ── UPLOAD FILE ──
        if ($method === 'POST' && !isset($_GET['action'])) {
            $studentId = intval($_POST['student_id'] ?? 0);
            $docType   = trim($_POST['doc_type'] ?? 'other');
            $category  = trim($_POST['category'] ?? '');
            $desc      = trim($_POST['description'] ?? '');

            if (!$studentId || !isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'message' => 'Student and file are required.']);
                exit;
            }
            $file = $_FILES['file'];
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp','txt','odt','ods','zip','rar'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'File type not allowed.']);
                exit;
            }
            if ($file['size'] > 25 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File too large (max 25 MB).']);
                exit;
            }
            if (!in_array($docType, ['enrollment','transcript','health','photo','clearance','other'], true)) {
                $docType = 'other';
            }

            $dir = __DIR__ . '/../uploads/student_files/' . $studentId;
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $filename = $studentId . '_' . time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '-', basename($file['name']));
            $dest = $dir . '/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $filePath = '../uploads/student_files/' . $studentId . '/' . $filename;
                // Guard against missing columns on older schemas
                $cols = $db->fetchAll("SHOW COLUMNS FROM documents");
                $colNames = array_column($cols, 'Field');
                $ins = [
                    'student_id'  => $studentId,
                    'doc_type'    => $docType,
                    'filename'    => basename($file['name']),
                    'file_path'   => $filePath,
                    'file_size'   => $file['size'],
                    'file_type'   => $ext,
                    'description' => $desc,
                    'uploaded_by' => $_SESSION['user_id'] ?? null,
                    'created_at'  => date('Y-m-d H:i:s')
                ];
                if (in_array('category', $colNames, true)) {
                    $ins['category'] = $category !== '' ? $category : $docType;
                }
                $id = $db->insert('documents', $ins);
                echo json_encode(['success' => true, 'message' => 'File uploaded.', 'data' => ['id' => $id]]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Upload failed.']);
            }
            exit;
        }

        // ── DELETE FILE ──
        if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete') {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'File ID required.']);
                exit;
            }
            $row = $db->fetchOne("SELECT file_path FROM documents WHERE id = ?", [$id]);
            if ($row) {
                $abs = __DIR__ . '/../' . ltrim($row['file_path'], './');
                $abs = str_replace(['\\', '//'], ['/', '/'], $abs);
                if (file_exists($abs)) @unlink($abs);
                $db->delete('documents', 'id = ?', [$id]);
            }
            echo json_encode(['success' => true, 'message' => 'File deleted.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown file action.']);
        exit;
    }

    // ─── GET ALL DOCUMENT REQUESTS ─────────────────────────────
    if ($method === 'GET' && !$id) {
        $documents = $db->fetchAll("
            SELECT 
                dr.*,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.student_number,
                u.full_name AS processed_by_name
            FROM document_requests dr
            LEFT JOIN students s ON dr.student_id = s.id
            LEFT JOIN users u ON dr.processed_by = u.id
            ORDER BY dr.id DESC
        ");
        echo json_encode(['success' => true, 'data' => $documents]);
        exit;
    }

    // ─── GET SINGLE DOCUMENT REQUEST ───────────────────────────
    if ($method === 'GET' && $id) {
        $document = $db->fetchOne(
            "SELECT 
                dr.*,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.student_number,
                u.full_name AS processed_by_name
            FROM document_requests dr
            LEFT JOIN students s ON dr.student_id = s.id
            LEFT JOIN users u ON dr.processed_by = u.id
            WHERE dr.id = ?",
            [$id]
        );
        if ($document) {
            echo json_encode(['success' => true, 'data' => $document]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Document request not found.']);
        }
        exit;
    }

    // ─── CREATE DOCUMENT REQUEST ───────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $studentId = $input['student_id'] ?? null;
        $documentType = $input['document_type'] ?? null;
        $purpose = $input['purpose'] ?? null;
        $recipient = $input['recipient'] ?? null;
        $feeAmount = isset($input['fee_amount']) && $input['fee_amount'] !== '' ? (float) $input['fee_amount'] : 0.00;
        $officialReceipt = trim($input['official_receipt'] ?? '');

        if (!$studentId || !$documentType) {
            echo json_encode(['success' => false, 'message' => 'Student ID and document type are required.']);
            exit;
        }

        $data = [
            'student_id' => $studentId,
            'document_type' => $documentType,
            'purpose' => $purpose,
            'recipient' => $recipient,
            'status' => 'pending',
            'fee_amount' => $feeAmount,
            'official_receipt' => $officialReceipt !== '' ? $officialReceipt : null,
            'request_date' => date('Y-m-d H:i:s')
        ];

        // Only insert fee/receipt columns if they exist (idempotent migration guard)
        $cols = $db->fetchAll("SHOW COLUMNS FROM document_requests");
        $colNames = array_column($cols, 'Field');
        if (!in_array('fee_amount', $colNames, true)) unset($data['fee_amount']);
        if (!in_array('official_receipt', $colNames, true)) unset($data['official_receipt']);

        $id = $db->insert('document_requests', $data);

        echo json_encode(['success' => true, 'message' => 'Document request submitted.', 'data' => ['id' => $id]]);
        exit;
    }

    // ─── UPDATE DOCUMENT REQUEST STATUS ────────────────────────
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $v2Action = $input['action'] ?? null;

        // ── v2 workflow transitions (document_status) ──────────────
        if (in_array($v2Action, ['process', 'ready', 'reject', 'claim'], true)) {
            $req = $db->fetchOne(
                "SELECT dr.*, c.triggers_exit_clearance
                   FROM document_requests dr
                   LEFT JOIN document_catalog c ON c.id = dr.catalog_id
                  WHERE dr.id = ?",
                [$id]
            );
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Document request not found.']);
                exit;
            }
            $cur = $req['document_status'];
            $now = date('Y-m-d H:i:s');
            $userId = $_SESSION['user_id'];

            $newStatus = null; $legacy = null; $note = null; $reason = null;
            switch ($v2Action) {
                case 'process':
                    if (!in_array($cur, ['Awaiting_Payment', 'Pending_Clearance', 'Processing'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Only Awaiting Payment / Pending Clearance requests can be processed.']);
                        exit;
                    }
                    $newStatus = 'Processing'; $legacy = 'processing'; $note = 'Started processing';
                    break;
                case 'ready':
                    if ($cur !== 'Processing') {
                        echo json_encode(['success' => false, 'message' => 'Only Processing requests can be marked Ready.']);
                        exit;
                    }
                    // Exit-clearance hard stop: all three offices must be CLEARED.
                    if ((int) $req['triggers_exit_clearance'] === 1) {
                        $pending = (int) $db->fetchColumn(
                            "SELECT COUNT(*) FROM exit_clearances WHERE request_id = ? AND status = 'PENDING'",
                            [$id]
                        );
                        if ($pending > 0) {
                            echo json_encode(['success' => false, 'message' => 'Cannot mark ready — exit clearance incomplete (Alumni / Dean / Property must all be CLEARED).']);
                            exit;
                        }
                    }
                    $newStatus = 'Ready'; $legacy = 'approved'; $note = 'Ready for release';
                    break;
                case 'reject':
                    $reason = trim($input['rejection_reason'] ?? '');
                    if ($reason === '') {
                        echo json_encode(['success' => false, 'message' => 'Rejection reason is required.']);
                        exit;
                    }
                    $newStatus = 'Rejected'; $legacy = 'denied'; $note = 'Rejected — ' . $reason;
                    break;
                case 'claim':
                    if (!in_array($cur, ['Ready', 'Shipped'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Only Ready / Shipped requests can be marked claimed.']);
                        exit;
                    }
                    $newStatus = 'Claimed'; $legacy = 'released'; $note = 'Released / claimed';
                    break;
            }

            $data = ['document_status' => $newStatus, 'status' => $legacy];
            if ($v2Action === 'ready')  $data['ready_at'] = $now;
            if ($v2Action === 'claim')  $data['claimed_at'] = $now;
            if ($v2Action === 'reject') $data['rejection_reason'] = $reason;
            if (in_array($v2Action, ['ready', 'reject', 'claim'], true)) {
                $data['processed_date'] = $now;
                $data['processed_by']   = $userId;
            }
            if ($v2Action === 'claim') {
                $data['release_date']   = $now;
                $data['completed_date'] = $now;
            }

            // Guard: only update columns that exist in the table.
            $cols = $db->fetchAll('SHOW COLUMNS FROM document_requests');
            $colNames = array_column($cols, 'Field');
            foreach (array_keys($data) as $k) {
                if (!in_array($k, $colNames, true)) unset($data[$k]);
            }

            $db->update('document_requests', $data, 'id = ?', [$id]);
            $db->insert('document_request_events', [
                'request_id' => $id,
                'status'     => $newStatus,
                'note'       => $note,
                'created_by' => $userId,
                'created_at' => $now,
            ]);

            // Cash on Delivery — the money is collected when the student
            // receives the document, so claiming the request settles the
            // pending COD transaction.
            if ($v2Action === 'claim') {
                $txnCols = array_column($db->fetchAll('SHOW COLUMNS FROM mock_payment_transactions'), 'Field');
                if (in_array('method', $txnCols, true)) {
                    $cod = $db->fetchOne(
                        "SELECT id FROM mock_payment_transactions
                          WHERE request_id = ? AND method = 'Cash_on_Delivery' AND status = 'pending'
                          ORDER BY id DESC LIMIT 1",
                        [$id]
                    );
                    if ($cod) {
                        $db->update('mock_payment_transactions', [
                            'status'       => 'completed',
                            'paid_at'      => $now,
                            'raw_response' => json_encode(['collected_on' => $now, 'collected_by' => $userId, 'note' => 'COD collected at claim']),
                        ], 'id = ?', [$cod['id']]);
                    }
                }
            }

            logActivity($userId, 'document_request_' . $v2Action, null, 'document_requests', $id);

            echo json_encode(['success' => true, 'message' => 'Request updated.', 'data' => ['id' => $id, 'document_status' => $newStatus]]);
            exit;
        }

        $status = $input['status'] ?? null;
        $denialReason = $input['denial_reason'] ?? null;

        if (!$status) {
            echo json_encode(['success' => false, 'message' => 'Status is required.']);
            exit;
        }

        $data = ['status' => $status];
        // Optional fee / receipt / release fields (guarded for old schema)
        if (array_key_exists('fee_amount', $input) && $input['fee_amount'] !== '') {
            $data['fee_amount'] = (float) $input['fee_amount'];
        }
        if (array_key_exists('official_receipt', $input)) {
            $data['official_receipt'] = trim($input['official_receipt']) !== '' ? trim($input['official_receipt']) : null;
        }
        if ($status === 'approved' || $status === 'completed' || $status === 'released') {
            $data['processed_date'] = date('Y-m-d H:i:s');
            $data['processed_by'] = $_SESSION['user_id'];
        }
        if ($status === 'denied') {
            $data['denial_reason'] = $denialReason;
            $data['processed_date'] = date('Y-m-d H:i:s');
            $data['processed_by'] = $_SESSION['user_id'];
        }
        if ($status === 'completed' || $status === 'released') {
            $data['completed_date'] = date('Y-m-d H:i:s');
        }
        if ($status === 'released') {
            $data['release_date'] = date('Y-m-d H:i:s');
        }

        // Guard: only update columns that exist in the table
        $cols = $db->fetchAll("SHOW COLUMNS FROM document_requests");
        $colNames = array_column($cols, 'Field');
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $colNames, true)) unset($data[$k]);
        }

        $db->update('document_requests', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Document request updated.']);
        exit;
    }

    // ─── DELETE DOCUMENT REQUEST ───────────────────────────────
    if ($method === 'DELETE' && $id) {
        $db->delete('document_requests', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Document request deleted.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    json_error($e);
}
?>
