<?php
// ============================================================
//  API/CLEARANCE.PHP
//  Clearance workflow API (registrar+).
//  GET   list students with clearance status (q/status filters)
//  POST  { student_id }                 create/clear
//  PUT   { id, status, notes }          update status
//  GET   ?id=N&slip=1                   slip payload
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
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

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$validStatuses = ['pending', 'partial', 'cleared'];

// ─── LIST (students + clearance status) ────────────────────────
if ($method === 'GET' && !isset($_GET['id'])) {
    $q      = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $params = [];

    $sql = "SELECT s.id AS student_id, s.student_number,
                   CONCAT(s.last_name, ', ', s.first_name) AS student_name,
                   s.course, s.year_level, s.status AS student_status,
                   c.id AS clearance_id, c.status AS clearance_status,
                   c.notes, c.issued_at,
                   u.full_name AS issued_by
            FROM students s
            LEFT JOIN clearances c ON c.student_id = s.id
            LEFT JOIN users u ON u.id = c.issued_by
            WHERE s.status != 'archived'";
    $where = [];

    if ($q !== '') {
        $where[] = "(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        $p = "%$q%";
        array_push($params, $p, $p, $p, $p);
    }
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[] = "COALESCE(c.status, 'pending') = ?";
        $params[] = $status;
    }
    if ($where) $sql .= " AND " . implode(' AND ', $where);
    $sql .= " ORDER BY s.last_name, s.first_name";

    $rows = $db->fetchAll($sql, $params);

    $stats = [
        'total'    => count($rows),
        'pending'  => 0,
        'partial'  => 0,
        'cleared'  => 0,
    ];
    foreach ($rows as $r) {
        $cs = $r['clearance_status'] ?? 'pending';
        if (isset($stats[$cs])) $stats[$cs]++;
    }

    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);
    exit;
}

// ─── SLIP payload ─────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['id']) && isset($_GET['slip']) && $_GET['slip'] === '1') {
    $row = $db->fetchOne(
        "SELECT s.id AS student_id, s.student_number, s.course, s.year_level, s.address,
                CONCAT(s.last_name, ', ', s.first_name) AS student_name,
                c.status AS clearance_status, c.notes, c.issued_at,
                u.full_name AS issued_by
         FROM students s
         LEFT JOIN clearances c ON c.student_id = s.id
         LEFT JOIN users u ON u.id = c.issued_by
         WHERE s.id = ?", [(int)$_GET['id']]
    );
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $row]);
    exit;
}

// ─── CREATE / MARK CLEARED (POST) ─────────────────────────────
if ($method === 'POST') {
    $studentId = (int)($input['student_id'] ?? 0);
    $notes     = trim($input['notes'] ?? '');

    if ($studentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'A student is required.']);
        exit;
    }
    $student = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$studentId]);
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }

    $existing = $db->fetchOne("SELECT * FROM clearances WHERE student_id = ?", [$studentId]);
    if ($existing) {
        // Re-issue / update existing clearance
        $db->update('clearances', [
            'status'    => 'cleared',
            'issued_by' => $_SESSION['user_id'],
            'issued_at' => date('Y-m-d H:i:s'),
            'notes'     => $notes !== '' ? $notes : $existing['notes'],
            'updated_at'=> date('Y-m-d H:i:s'),
        ], 'id = ?', [$existing['id']]);
        logActivity($_SESSION['user_id'], 'clearance_issue', null, 'clearances', $existing['id'], ['status' => $existing['status']], ['status' => 'cleared']);
        echo json_encode(['success' => true, 'message' => 'Clearance updated to cleared.', 'data' => ['id' => $existing['id']]]);
        exit;
    }

    $id = $db->insert('clearances', [
        'student_id' => $studentId,
        'status'     => 'cleared',
        'issued_by'  => $_SESSION['user_id'],
        'issued_at'  => date('Y-m-d H:i:s'),
        'notes'      => $notes,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    logActivity($_SESSION['user_id'], 'clearance_issue', null, 'clearances', $id, ['status' => 'pending'], ['status' => 'cleared']);
    echo json_encode(['success' => true, 'message' => 'Clearance issued.', 'data' => ['id' => $id]]);
    exit;
}

// ─── UPDATE STATUS (PUT) ──────────────────────────────────────
if ($method === 'PUT') {
    $id = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $notes  = trim($input['notes'] ?? '');

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Clearance ID is required.']);
        exit;
    }
    if (!in_array($status, $validStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    $clearance = $db->fetchOne("SELECT * FROM clearances WHERE id = ?", [$id]);
    if (!$clearance) {
        echo json_encode(['success' => false, 'message' => 'Clearance not found.']);
        exit;
    }

    $update = [
        'status'     => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($status === 'cleared') {
        $update['issued_by'] = $_SESSION['user_id'];
        $update['issued_at'] = $clearance['issued_at'] ?: date('Y-m-d H:i:s');
    }
    if ($notes !== '') $update['notes'] = $notes;

    $db->update('clearances', $update, 'id = ?', [$id]);
    $logAction = $status === 'cleared' ? 'clearance_issue' : 'clearance_status_change';
    logActivity($_SESSION['user_id'], $logAction, null, 'clearances', $id, ['status' => $clearance['status']], ['status' => $status]);
    echo json_encode(['success' => true, 'message' => 'Clearance updated.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
