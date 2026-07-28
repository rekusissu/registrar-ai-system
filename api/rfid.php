<?php
// ============================================================
//  API/RFID.PHP
//  RFID card management API
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

    // ─── CHECK UID EXISTS ───────────────────────────────────────
    if ($method === 'GET' && isset($_GET['check_uid'])) {
        $uid = trim($_GET['check_uid']);
        $card = $db->fetchOne(
            "SELECT rf.id, CONCAT(s.first_name, ' ', s.last_name) AS student FROM rfid_cards rf LEFT JOIN students s ON rf.student_id = s.id WHERE rf.card_uid = ?",
            [$uid]
        );
        echo json_encode(['exists' => !!$card, 'student' => $card ? $card['student'] : null]);
        exit;
    }

    // ─── GET ALL RFID CARDS ────────────────────────────────────
    if ($method === 'GET' && !$id) {
        $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;
        if ($studentId) {
            $cards = $db->fetchAll(
                "SELECT
                    rf.*,
                    CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                    s.student_number,
                    s.course,
                    s.year_level
                FROM rfid_cards rf
                LEFT JOIN students s ON rf.student_id = s.id
                WHERE rf.student_id = ?
                ORDER BY rf.id DESC",
                [$studentId]
            );
        } else {
            $cards = $db->fetchAll("
                SELECT
                    rf.*,
                    CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                    s.student_number,
                    s.course,
                    s.year_level
                FROM rfid_cards rf
                LEFT JOIN students s ON rf.student_id = s.id
                ORDER BY rf.id DESC
            ");
        }
        echo json_encode(['success' => true, 'data' => $cards]);
        exit;
    }

    // ─── GET SINGLE RFID CARD ──────────────────────────────────
    if ($method === 'GET' && $id) {
        $card = $db->fetchOne(
            "SELECT 
                rf.*,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.student_number,
                s.course,
                s.year_level
            FROM rfid_cards rf
            LEFT JOIN students s ON rf.student_id = s.id
            WHERE rf.id = ?",
            [$id]
        );
        if ($card) {
            echo json_encode(['success' => true, 'data' => $card]);
        } else {
            echo json_encode(['success' => false, 'message' => 'RFID card not found.']);
        }
        exit;
    }

    // ─── ASSIGN RFID CARD ──────────────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $studentId = $input['student_id'] ?? null;
        $cardUid = trim($input['card_uid'] ?? '');
        $issuedDate = $input['issued_date'] ?? date('Y-m-d');
        $expiryDate = $input['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
        $notes = $input['notes'] ?? '';

        // Validate
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }

        if (empty($cardUid)) {
            echo json_encode(['success' => false, 'message' => 'Card UID is required.']);
            exit;
        }

        if (strlen($cardUid) !== 10) {
            echo json_encode(['success' => false, 'message' => 'Card UID must be exactly 10 digits.']);
            exit;
        }

        // Check if card UID already exists
        $existing = $db->fetchOne("SELECT id FROM rfid_cards WHERE card_uid = ?", [$cardUid]);
        if ($existing) {
            echo json_encode(['success' => false, 'message' => 'Card UID already exists.']);
            exit;
        }

        // Check if student already has an active card
        $existingStudent = $db->fetchOne(
            "SELECT id FROM rfid_cards WHERE student_id = ? AND status = 'active'",
            [$studentId]
        );
        if ($existingStudent) {
            echo json_encode(['success' => false, 'message' => 'Student already has an active card.']);
            exit;
        }

        // Insert new card
        $id = $db->insert('rfid_cards', [
            'student_id' => $studentId,
            'card_uid' => $cardUid,
            'card_type' => 'rfid',
            'status' => 'active',
            'issued_date' => $issuedDate,
            'expiry_date' => $expiryDate,
            'notes' => $notes
        ]);

        echo json_encode(['success' => true, 'message' => 'Card assigned successfully.', 'data' => ['id' => $id]]);
        exit;
    }

    // ─── UPDATE RFID CARD ──────────────────────────────────────
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $data = [];
        $allowedFields = ['student_id', 'status', 'issued_date', 'expiry_date', 'notes'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $data[$field] = $input[$field];
            }
        }

        if (empty($data)) {
            echo json_encode(['success' => false, 'message' => 'No data to update.']);
            exit;
        }

        $db->update('rfid_cards', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Card updated successfully.']);
        exit;
    }

    // ─── DELETE RFID CARD ──────────────────────────────────────
    if ($method === 'DELETE' && $id) {
        $db->delete('rfid_cards', 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Card deleted successfully.']);
        exit;
    }

    // ─── INVALID REQUEST ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>