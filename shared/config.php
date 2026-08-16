<?php
// shared/config.php - Adapted for your database

if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Database Configuration
// Database settings can be overridden per environment via env vars
// (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_CHARSET).
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'registrar_ai');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Application Configuration
define('APP_NAME', 'BCP Registrar System');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'development');
define('APP_ROOT', dirname(__DIR__) . '/');

// Idle session timeout in seconds. Users are logged out after this long
// with no activity (default 20 minutes).
define('SESSION_IDLE_TIMEOUT', 20 * 60);

// Error reporting
error_reporting(E_ALL);
// Only show errors on screen in development; log them everywhere else.
// Set APP_ENV to 'production' before going live.
ini_set('display_errors', APP_ENV === 'production' ? 0 : 1);
ini_set('display_startup_errors', APP_ENV === 'production' ? 0 : 1);
ini_set('log_errors', 1);
ini_set('error_log', APP_ROOT . 'logs/php_errors.log');

/** Maximum enrollees per section when auto-generating masterlists (e.g. BSIT 11001 = 50). Section codes are [year][semester][number], e.g. 11001 = yr 1 sem 1 section 1. */
define('MAX_STUDENTS_PER_SECTION', 50);

// Security
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-super-secret-key-change-in-production');
// Public RFID kiosk access token (overridable per environment). Set
// KIOSK_ACCESS_TOKEN on live stations; do not rely on the default.
define('KIOSK_ACCESS_TOKEN', getenv('KIOSK_ACCESS_TOKEN') ?: 'kiosk-tap-2024');

// ── AI (9Router local gateway, OpenAI-compatible) ──────────────
// Local 9Router gateway that fronts several free models via a single
// Bearer key. Endpoint verified live at /v1/models (OpenAI format).
// The 9Router gateway base URL can be overridden with NINEROUTER_URL
// (e.g. a VPS or tunnel URL); the app appends /v1/chat/completions.
$__aiBase = rtrim((string) getenv('NINEROUTER_URL') ?: 'http://localhost:20128', '/');
define('AI_API_URL', $__aiBase . '/v1/chat/completions');
define('AI_MODEL', 'ollama/minimax-m3');
// Ordered fallback list: the gateway tries each model in turn until one
// succeeds (handles transient 529/5xx overloads on a single backend).
define('AI_MODELS', [
    'ollama/minimax-m3',      // vision-capable (documents/vision flows prefer minimax/kimi)
    'ollama/kimi-k2.5',       // vision-capable
    'ollama/gpt-oss:120b',    // strong general-purpose fallback
    'ollama/glm-4.7-flash',   // fast flash-tier fallback
]);
// API key: read from env var first, then from a local (git-ignored) file.
// Never hardcode a real key in this committed file.
$__aiKey = getenv('AI_API_KEY') ?: (getenv('NINEROUTER_KEY') ?: '');
if ($__aiKey === '') {
    $__aiKeyFile = __DIR__ . '/ai_key.local';
    if (is_file($__aiKeyFile)) {
        $__aiKey = trim((string) file_get_contents($__aiKeyFile));
    }
}
define('AI_API_KEY', $__aiKey);
define('AI_CACHE_TTL', 86400); // seconds (1 day)

// Timezone
date_default_timezone_set('Asia/Manila');

/**
 * Return a generic JSON error to the client and log the real details
 * server-side. Never expose exception messages (table/column names,
 * connection details) to the browser.
 */
function json_error(Throwable $e, string $prefix = 'Internal server error.'): never {
    error_log('[json_error] ' . $prefix . ' ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => $prefix]);
    exit;
}
?>
