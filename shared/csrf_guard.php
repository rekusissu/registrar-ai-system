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
//    (A GET query-param source is intentionally NOT accepted — tokens
//     in URLs leak into logs/referrers and enable cross-site smuggling.)
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
//
// 419 is not a code every server knows. Apache's own status table only
// carries the codes in its errorDocument family; anything it does not
// recognise falls through to 500, which turns "your session expired,
// reload" into "the server is broken" on every affected host. Verified
// on this XAMPP build: 418 and 419 both come back as 500 while 200,
// 400, 401, 403, 404, 409, 422, 429, 451 and 503 all pass through.
//
// So the intended status is sent as a header the JS reads, and the
// status line falls back to 400 - which is a true description of the
// request and is a code the server can actually emit. A client that
// understands 419 still gets it; one that does not still gets an
// honest 400 rather than a bogus 500.
function rejectCsrf(): void {
    // 400, deliberately.
    //
    // This used to send 419, the "page expired" status this project
    // standardised on. It cannot be sent from here: Apache's status
    // table does not contain 419, so it rewrites the status line to
    // 500 after PHP has finished - turning "your session expired, reload
    // the page" into "the server is broken". Verified against the live
    // server on this host: 200, 201, 204, 301, 302, 400, 401, 403, 404,
    // 409, 422, 429, 451, 500 and 503 all pass through unchanged, while
    // 418 and 419 both come back as 500.
    //
    // It is not detectable from PHP. http_response_code(419) reports 419
    // back immediately - the rejection happens on the way out - so a
    // probe that reads the code back sees what it just set and wrongly
    // concludes the server supports it. (A buffered probe was tried and
    // removed: it cannot work, for exactly that reason.)
    //
    // So the status is one the server can actually emit and which is a
    // TRUE description of the request: a POST arriving without the token
    // it is required to carry is a bad request. The intent is still
    // carried in X-CSRF-Status so the browser can tell a stale-session
    // refusal apart from a genuinely malformed request.
    http_response_code(400);
    header('X-CSRF-Status: 419');
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
    // Token comes from the X-CSRF-Token header (JS fetch) or the
    // csrf_token POST field (regular HTML forms). The GET-query param
    // fallback was removed: tokens in URLs leak into logs/referrers
    // and made cross-site requests able to smuggle a token.
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!csrfTokenValid($token)) {
        rejectCsrf();
    }
}

// Enforce on include: any endpoint/page that loads this file is protected.
requireCsrf();
