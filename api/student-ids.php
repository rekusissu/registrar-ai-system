<?php
// ============================================================
//  API/STUDENT-IDS.PHP
//  Student ID card management (school ID / library / cafeteria)
//  Generates QR codes for each issued ID.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
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

    // Auto-generate an ID number if none provided: school year + zero-padded
    if ($idNumber === '') {
        $year = date('Y');
        $last = $db->fetchColumn(
            "SELECT id_number FROM student_ids WHERE id_number LIKE ? ORDER BY id DESC LIMIT 1",
            [$year . '%']
        );
        $num = $last ? intval(substr($last, -4)) + 1 : 1;
        $idNumber = $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    // Uniqueness
    $existingNum = $db->fetchOne("SELECT id FROM student_ids WHERE id_number = ?", [$idNumber]);
    if ($existingNum) {
        echo json_encode(['success' => false, 'message' => 'ID number already exists.']);
        exit;
    }

    $data = [
        'student_id'  => $studentId,
        'id_number'   => $idNumber,
        'id_type'     => $idType,
        'issue_date'  => $issueDate,
        'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
        'status'      => $status
    ];

    // Generate QR code payload and save to uploads/ids/
    $qrPath = generateQrFile($idNumber, $studentId);
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

/**
 * Generate a QR code SVG for the given student ID and store it in uploads/ids/.
 * Returns the web-relative path (e.g. ../uploads/ids/xyz.svg) or null on failure.
 * Uses chillerlan/php-qrcode when Composer is installed; otherwise returns null
 * gracefully (ID still issues, QR column just stays null) rather than 500ing.
 */
function generateQrFile($idNumber, $studentId) {
    $dir = __DIR__ . '/../uploads/ids/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $filename = 'id_' . $studentId . '_' . time() . '.svg';
    $path = $dir . $filename;

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('QR generation skipped: vendor/autoload.php missing (run composer install).');
        return null;
    }

    try {
        require_once $autoload;
        // Raw SVG output (works without the GD extension)
        $opts = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'outputBase64'    => false,
            'eccLevel'        => 'M',
            'scale'           => 6,
        ]);
        $qrcode = new \chillerlan\QRCode\QRCode($opts);
        // Payload: a stable identifier the system can scan back
        $payload = json_encode(['type' => 'student_id', 'id_number' => $idNumber, 'student_id' => $studentId]);
        $svg = $qrcode->render($payload);
        if (file_put_contents($path, $svg) === false) {
            error_log('QR generation failed: could not write ' . $path);
            return null;
        }
        return '../uploads/ids/' . $filename;
    } catch (\Throwable $e) {
        error_log('QR generation failed: ' . $e->getMessage());
        return null;
    }
}
