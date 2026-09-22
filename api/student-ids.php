<?php
// ============================================================
//  API/STUDENT-IDS.PHP
//  Student ID card management (school ID / library / cafeteria)
//  Generates QR codes for each issued ID.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

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
$db = Database::getInstance();

// ─── GET ALL STUDENT IDS (with student info) ────────────────
if ($method === 'GET') {
    $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
    if ($studentId) {
        $ids = $db->fetchAll("
            SELECT si.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_number, s.course, s.photo
            FROM student_ids si
            LEFT JOIN students s ON si.student_id = s.id
            WHERE si.student_id = ?
            ORDER BY si.id DESC
        ", [$studentId]);
    } else {
        $ids = $db->fetchAll("
            SELECT si.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_number, s.course, s.photo
            FROM student_ids si
            LEFT JOIN students s ON si.student_id = s.id
            ORDER BY si.id DESC
        ");
    }
    echo json_encode(['success' => true, 'data' => $ids]);
    exit;
}

// ─── UPDATE STATUS / EXPIRY ─────────────────────────────────
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID required.']);
        exit;
    }
    $data = [];
    foreach (['status', 'expiry_date', 'issue_date', 'id_type'] as $f) {
        if (array_key_exists($f, $input)) $data[$f] = $input[$f];
    }
    if (empty($data)) {
        echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
        exit;
    }
    $db->update('student_ids', $data, 'id = ?', [$id]);
    echo json_encode(['success' => true, 'message' => 'Student ID updated.']);
    exit;
}

// ─── DELETE ─────────────────────────────────────────────────
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID required.']);
        exit;
    }
    // Remove the QR file if it exists
    $row = $db->fetchOne("SELECT qr_code_path FROM student_ids WHERE id = ?", [$id]);
    if ($row && !empty($row['qr_code_path'])) {
        $abs = __DIR__ . '/../' . $row['qr_code_path'];
        if (file_exists($abs)) @unlink($abs);
    }
    $db->delete('student_ids', 'id = ?', [$id]);
    echo json_encode(['success' => true, 'message' => 'Student ID deleted.']);
    exit;
}

// ─── CREATE / UPDATE STUDENT ID ─────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $studentId = intval($input['student_id'] ?? 0);
    $idType    = trim($input['id_type'] ?? 'school_id');
    $status    = trim($input['status'] ?? 'active');
    $issueDate = $input['issue_date'] ?? date('Y-m-d');
    $expiryDate = $input['expiry_date'] ?? '';
    $idNumber  = trim($input['id_number'] ?? '');

    if (!$studentId) {
        echo json_encode(['success' => false, 'message' => 'Student is required.']);
        exit;
    }
    if (!in_array($idType, ['school_id', 'library', 'cafeteria'], true)) {
        $idType = 'school_id';
    }
    if (!in_array($status, ['active', 'inactive', 'lost'], true)) {
        $status = 'active';
    }

    // Auto-generate an ID number if none provided
    if ($idNumber === '') {
        require_once __DIR__ . '/../shared/qr_generator.php';
        $idNumber = generateNextIdNumber($db);
    }

    // Uniqueness
    $existingNum = $db->fetchOne("SELECT id FROM student_ids WHERE id_number = ?", [$idNumber]);
    if ($existingNum) {
        echo json_encode(['success' => false, 'message' => 'ID number already exists.']);
        exit;
    }
    // Optional photo upload (base64 data URL) - used on the ID card.
    $photoPath = null;
    $photoDataRaw = $input['photo_data'] ?? '';
    if (is_string($photoDataRaw) && $photoDataRaw !== '' && strpos($photoDataRaw, 'data:image/') === 0) {
        $mime = null;
        if (preg_match('#^data:image/(png|jpeg|webp|gif);base64,#i', $photoDataRaw, $mimeM)) { $mime = $mimeM[1]; }
        if ($mime) {
            $bin = base64_decode(preg_replace('#^data:image/[a-z0-9+]+;base64,#i', '', $photoDataRaw), true);
            if ($bin !== false && $bin !== '' && strlen($bin) <= 5 * 1024 * 1024) {
                $dir = __DIR__ . '/../uploads/ids/';
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $ext = $mime === 'jpeg' ? 'jpg' : $mime;
                $pname = 'photo_' . $studentId . '_' . time() . '.' . $ext;
                if (file_put_contents($dir . $pname, $bin) !== false) {
                    $photoPath = '../uploads/ids/' . $pname;
                }
            }
        }
    }

    $data = [
        'student_id'  => $studentId,
        'id_number'   => $idNumber,
        'id_type'     => $idType,
        'issue_date'  => $issueDate,
        'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
        'status'      => $status,
        'photo_path'  => $photoPath
    ];

    // Generate QR code and save to uploads/ids/
    require_once __DIR__ . '/../shared/qr_generator.php';
    $qrPath = generateStudentQrFile($idNumber, $studentId);
    if ($qrPath) {
        $data['qr_code_path'] = $qrPath;
    }

    $id = $db->insert('student_ids', $data);
    echo json_encode([
        'success' => true,
        'message' => 'Student ID issued.',
        'data'    => ['id' => $id, 'id_number' => $idNumber, 'qr_code_path' => $qrPath]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
