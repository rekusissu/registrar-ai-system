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

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

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

        if (!$studentId || !$documentType) {
            echo json_encode(['success' => false, 'message' => 'Student ID and document type are required.']);
            exit;
        }

        $id = $db->insert('document_requests', [
            'student_id' => $studentId,
            'document_type' => $documentType,
            'purpose' => $purpose,
            'recipient' => $recipient,
            'status' => 'pending',
            'request_date' => date('Y-m-d H:i:s')
        ]);

        echo json_encode(['success' => true, 'message' => 'Document request submitted.', 'data' => ['id' => $id]]);
        exit;
    }

    // ─── UPDATE DOCUMENT REQUEST STATUS ────────────────────────
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $status = $input['status'] ?? null;
        $denialReason = $input['denial_reason'] ?? null;

        if (!$status) {
            echo json_encode(['success' => false, 'message' => 'Status is required.']);
            exit;
        }

        $data = ['status' => $status];
        if ($status === 'approved' || $status === 'completed') {
            $data['processed_date'] = date('Y-m-d H:i:s');
            $data['processed_by'] = $_SESSION['user_id'];
        }
        if ($status === 'denied') {
            $data['denial_reason'] = $denialReason;
            $data['processed_date'] = date('Y-m-d H:i:s');
            $data['processed_by'] = $_SESSION['user_id'];
        }
        if ($status === 'completed') {
            $data['completed_date'] = date('Y-m-d H:i:s');
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
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>