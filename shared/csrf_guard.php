<?php
// ============================================================
//  SHARED/CSRF_GUARD.PHP
//  Session-bound CSRF tokens, enforced on every non-safe HTTP
//  method. Include this file (or rely on includes/header.php /
//  the API endpoints) and the guard validates automatically.
//
//  Token sources, in order:
//    1. X-CSRF-Token request header  (JS fetch calls)
//    2. csrf_token POST field         (regular HTML forms)
//    3. csrf_token GET query param    (rare fallback)
// ============================================================

if (defined('CSRF_GUARD_LOADED')) {
    require_once __DIR__ . '/session_config.php';
    return;
}
define('CSRF_GUARD_LOADED', true);

require_once __DIR__ . '/session_config.php';

// Get (or create) the session-bound CSRF token.
function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Constant-time check of a submitted token against the session token.
function csrfTokenValid($token): bool {
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($expected) && $expected !== ''
        && hash_equals($expected, $token);
}

// Reject a request with a 419 status (page-expired semantics).
function rejectCsrf(): void {
    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing CSRF token. Reload the page and try again.',
    ]);
    exit;
}

// Enforce CSRF for all non-safe methods. Safe (read-only) methods pass.
function requireCsrf(): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? null));
    if (!csrfTokenValid($token)) {
        rejectCsrf();
    }
}

// Enforce on include: any endpoint/page that loads this file is protected.
requireCsrf();
