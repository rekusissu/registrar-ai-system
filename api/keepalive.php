<?php
// ============================================================
//  API/KEEPALIVE.PHP
//  Session keep-alive for the idle-timeout warning.
//  Including session_config.php both enforces the idle timeout
//  (JSON 401 when expired) and refreshes last_activity so the
//  sliding window resets when the user clicks "Stay signed in".
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'OK']);
