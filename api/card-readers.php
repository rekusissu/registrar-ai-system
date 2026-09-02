<?php
// ============================================================
//  API/CARD-READERS.PHP
//  Card reader management API
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
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    if ($method === 'GET' && !$id) {
        $readers = $db->fetchAll("SELECT * FROM card_readers WHERE status = 'active' ORDER BY name");
        echo json_encode(['success' => true, 'data' => $readers]);
        exit;
    }
    if ($method === 'GET' && $id) {
        $reader = $db->fetchOne("SELECT * FROM card_readers WHERE id = ?", [$id]);
        echo json_encode(['success' => true, 'data' => $reader]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $db->insert('card_readers', [
            'name' => $input['name'],
            'location' => $input['location'],
            'reader_type' => $input['reader_type'] ?? 'both',
            'reader_code' => $input['reader_code'],
            'status' => 'active'
        ]);
        echo json_encode(['success' => true, 'message' => 'Reader added.', 'data' => ['id' => $id]]);
        exit;
    }

    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $data = [];
        foreach (['name','location','reader_type','reader_code','status'] as $f) {
            if (isset($input[$f])) $data[$f] = $input[$f];
        }
        if (!empty($data)) $db->update('card_readers', $data, 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Reader updated.']);
        exit;
    }

    if ($method === 'DELETE' && $id) {
        $db->update('card_readers', ['status' => 'inactive'], 'id = ?', [$id]);
        echo json_encode(['success' => true, 'message' => 'Reader deactivated.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} catch (Exception $e) {
    json_error($e);
}
?>
