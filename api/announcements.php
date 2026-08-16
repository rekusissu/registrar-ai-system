<?php
// ============================================================
//  API/ANNOUNCEMENTS.PHP
//  Announcements CRUD (admin/registrar).
//    GET             → list (optional ?q= search)
//    POST            → create {title, body, is_published}
//    PUT ?id=N       → update {title, body, is_published}
//    DELETE ?id=N    → delete
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    $db = Database::getInstance();

    // ─── LIST ───────────────────────────────────────────────
    if ($method === 'GET') {
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $announcements = $db->fetchAll(
                "SELECT a.*, u.full_name AS author_name
                 FROM announcements a
                 LEFT JOIN users u ON u.id = a.author_id
                 WHERE a.title LIKE ? OR a.body LIKE ?
                 ORDER BY a.created_at DESC",
                ["%$q%", "%$q%"]
            );
        } else {
            $announcements = $db->fetchAll(
                "SELECT a.*, u.full_name AS author_name
                 FROM announcements a
                 LEFT JOIN users u ON u.id = a.author_id
                 ORDER BY a.created_at DESC"
            );
        }
        echo json_encode(['success' => true, 'data' => $announcements]);
        exit;
    }

    // ─── CREATE ─────────────────────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $title = trim($input['title'] ?? '');
        $body = trim($input['body'] ?? '');
        $isPublished = isset($input['is_published']) ? (int)(bool)$input['is_published'] : 1;

        if ($title === '') {
            echo json_encode(['success' => false, 'message' => 'Title is required.']);
            exit;
        }

        $newId = $db->insert('announcements', [
            'title'        => $title,
            'body'         => $body !== '' ? $body : null,
            'author_id'    => $_SESSION['user_id'],
            'is_published' => $isPublished,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        logActivity($_SESSION['user_id'], 'announcement_create', null, 'announcements', $newId);
        echo json_encode(['success' => true, 'message' => 'Announcement published.', 'data' => ['id' => $newId]]);
        exit;
    }

    // ─── UPDATE ─────────────────────────────────────────────
    if ($method === 'PUT' && $id) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $announcement = $db->fetchOne("SELECT * FROM announcements WHERE id = ?", [$id]);
        if (!$announcement) {
            echo json_encode(['success' => false, 'message' => 'Announcement not found.']);
            exit;
        }
        $title = trim($input['title'] ?? $announcement['title']);
        if ($title === '') {
            echo json_encode(['success' => false, 'message' => 'Title is required.']);
            exit;
        }
        $db->update('announcements', [
            'title'        => $title,
            'body'         => array_key_exists('body', $input) ? (trim($input['body']) !== '' ? trim($input['body']) : null) : $announcement['body'],
            'is_published' => array_key_exists('is_published', $input) ? (int)(bool)$input['is_published'] : (int)$announcement['is_published'],
            'updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        logActivity($_SESSION['user_id'], 'announcement_update', null, 'announcements', $id);
        echo json_encode(['success' => true, 'message' => 'Announcement updated.']);
        exit;
    }

    // ─── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE' && $id) {
        $announcement = $db->fetchOne("SELECT id FROM announcements WHERE id = ?", [$id]);
        if (!$announcement) {
            echo json_encode(['success' => false, 'message' => 'Announcement not found.']);
            exit;
        }
        $db->delete('announcements', 'id = ?', [$id]);
        logActivity($_SESSION['user_id'], 'announcement_delete', null, 'announcements', $id);
        echo json_encode(['success' => true, 'message' => 'Announcement deleted.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} catch (Throwable $e) {
    json_error($e, 'Unable to process the request.');
}
