<?php
// ============================================================
//  API/RFID-SCAN.PHP
//  RFID scanning API - processes card taps
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
$input = json_decode(file_get_contents('php://input'), true);

// ─── GET SCAN LOGS ─────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $db = Database::getInstance();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        
        $logs = $db->fetchAll("
            SELECT 
                l.*,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.student_number
            FROM rfid_scan_logs l
            LEFT JOIN students s ON l.student_id = s.id
            ORDER BY l.scanned_at DESC
            LIMIT ?
        ", [$limit]);
        
        echo json_encode(['success' => true, 'data' => $logs]);
    } catch (Exception $e) {
        json_error($e);
    }
    exit;
}

// ─── PROCESS SCAN ──────────────────────────────────────────────
if ($method === 'POST') {
    $cardUid = trim($input['card_uid'] ?? $input['uid'] ?? '');
    $readerId = null;
    if (isset($input['reader_id']) && $input['reader_id'] !== '' && $input['reader_id'] !== null) {
        $readerId = intval($input['reader_id']);
    }

    // Auto-detect reader from local config file if no reader_id POSTed
    if ($readerId === null || $readerId <= 0) {
        $localConfig = __DIR__ . '/../shared/config.local.php';
        if (file_exists($localConfig)) {
            include_once $localConfig;
            if (defined('SCANNER_READER_ID') && SCANNER_READER_ID > 0) {
                $readerId = intval(SCANNER_READER_ID);
            }
        }
    }

    if (empty($cardUid)) {
        echo json_encode(['success' => false, 'message' => 'Card UID is required.']);
        exit;
    }

    try {
        $db = Database::getInstance();

        // Auto-expire cards past their expiry
        $db->query("UPDATE rfid_cards SET status = 'expired' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status = 'active'");

        // Find the card and student
        $card = $db->fetchOne("
            SELECT 
                rf.*,
                s.id AS student_id,
                s.first_name,
                s.last_name,
                s.student_number,
                s.course
            FROM rfid_cards rf
            LEFT JOIN students s ON rf.student_id = s.id
            WHERE rf.card_uid = ?
        ", [$cardUid]);

        if ($card && $card['status'] === 'active') {
            // Check if expired
            if ($card['expiry_date'] && $card['expiry_date'] < date('Y-m-d')) {
                $status = 'denied';
                $message = 'Card has expired.';
                $studentData = null;
            } else {
                $status = 'success';
                $message = 'Access granted.';
                $studentData = [
                    'id' => $card['student_id'],
                    'first_name' => $card['first_name'],
                    'last_name' => $card['last_name'],
                    'student_number' => $card['student_number'],
                    'course' => $card['course']
                ];
            }
        } elseif ($card && $card['status'] === 'lost') {
            $status = 'denied';
            $message = 'Card reported as lost.';
            $studentData = null;
        } elseif ($card && $card['status'] === 'expired') {
            $status = 'denied';
            $message = 'Card has expired.';
            $studentData = null;
        } elseif ($card && $card['status'] === 'inactive') {
            $status = 'denied';
            $message = 'Card is inactive.';
            $studentData = null;
        } else {
            $status = 'denied';
            $message = 'Card not recognized.';
            $studentData = null;
        }

        // Look up reader for location
        $location = $input['location'] ?? 'Main Gate';
        $eventType = $input['event_type'] ?? 'entry';
        $reader = null;
        if ($readerId && $readerId > 0) {
            $reader = $db->fetchOne("SELECT * FROM card_readers WHERE id = ? AND status = 'active'", [$readerId]);
            if ($reader) {
                $location = $reader['location'];
                // Auto-detect event from reader type
                if ($reader['reader_type'] === 'entrance') $eventType = 'entry';
                else if ($reader['reader_type'] === 'exit') $eventType = 'exit';
                // 'both' keeps whatever $input sent
            }
        }

        // Log the scan
        $db->insert('rfid_scan_logs', [
            'card_uid' => $cardUid,
            'student_id' => $card['student_id'] ?? null,
            'location' => $location,
            'event_type' => $eventType,
            'status' => $status,
            'scanner_id' => $input['scanner_id'] ?? 'web-api',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        // Return response
        $resp = [
            'success' => $status === 'success',
            'status' => $status,
            'message' => $message,
            'card_uid' => $cardUid,
            'event_type' => $eventType,
            'student' => $studentData
        ];
        if ($reader) {
            $resp['actual_reader'] = [
                'name' => $reader['name'],
                'location' => $reader['location'],
                'reader_type' => $reader['reader_type']
            ];
        }
        echo json_encode($resp);

    } catch (Exception $e) {
        json_error($e);
    }
    exit;
}

// ─── INVALID REQUEST ───────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid request.']);
?>