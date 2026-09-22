<?php
// ============================================================
//  API/STATUS-HISTORY.PHP
//  Returns status_tracker entries for a student (JSON).
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array(getCurrentUserRole(), ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'student_id required.']);
    exit;
}

try {
    $db = Database::getInstance();
    $entries = $db->fetchAll(
        "SELECT st.id, st.student_id, st.previous_status, st.current_status,
                st.reason, st.effective_date, st.end_date, st.created_at,
                u.full_name AS changed_by_name
         FROM status_tracker st
         LEFT JOIN users u ON st.changed_by = u.id
         WHERE st.student_id = ?
         ORDER BY st.created_at DESC, st.id DESC",
        [$studentId]
    );
    echo json_encode(['success' => true, 'data' => $entries]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load history.']);
}
