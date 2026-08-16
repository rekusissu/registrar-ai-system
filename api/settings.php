<?php
// ============================================================
//  API/SETTINGS.PHP
//  Settings API (authenticated) — update own profile + password.
//  PUT profile   { full_name }
//  PUT password  { current_password, new_password }
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

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($method !== 'PUT') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$section = $input['section'] ?? null;

// ─── UPDATE PROFILE ────────────────────────────────────────────
if ($section === 'profile') {
    $fullName = trim($input['full_name'] ?? '');
    if ($fullName === '') {
        echo json_encode(['success' => false, 'message' => 'Full name is required.']);
        exit;
    }
    $db->update('users', [
        'full_name'  => $fullName,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$userId]);
    $_SESSION['full_name'] = $fullName;
    logActivity($userId, 'settings_profile_update', null, 'users', $userId);
    echo json_encode(['success' => true, 'message' => 'Profile updated.']);
    exit;
}

// ─── CHANGE PASSWORD ───────────────────────────────────────────
if ($section === 'password') {
    $current = (string)($input['current_password'] ?? '');
    $new     = (string)($input['new_password'] ?? '');

    if (strlen($new) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit;
    }
    if ($current === '') {
        echo json_encode(['success' => false, 'message' => 'Current password is required.']);
        exit;
    }

    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user || !password_verify($current, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    $db->update('users', [
        'password_hash' => password_hash($new, PASSWORD_DEFAULT),
        'updated_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$userId]);
    logActivity($userId, 'settings_password_change', null, 'users', $userId);
    echo json_encode(['success' => true, 'message' => 'Password updated.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid settings section.']);
