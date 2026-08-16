<?php
// shared/config.php - Adapted for your database

if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'registrar_ai');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

// Application Configuration
define('APP_NAME', 'BCP Registrar System');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'development');
define('APP_ROOT', dirname(__DIR__) . '/');

/**
 * Root-relative URL path to the application base, e.g. "/registrar-ai-system".
 * Used for Header('Location: ...') redirects so they resolve from any depth
 * (root pages, registrar/, student/, api/, ai/). Falls back to "/" when the
 * doc root mapping can't be inferred.
 */
function app_base_path(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $script  = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // SCRIPT_NAME may already be root-relative; if DOCUMENT_ROOT is usable,
    // derive base by stripping the doc root off APP_ROOT.
    $fsBase = str_replace('\\', '/', APP_ROOT);               // …/htdocs/registrar-ai-system/
    if ($docRoot !== '' && str_starts_with($fsBase, $docRoot)) {
        $base = rtrim(substr($fsBase, strlen($docRoot)), '/'); // /registrar-ai-system
        return $base;
    }
    // Fallback: strip the leading filename component off SCRIPT_NAME.
    if ($script !== '' && $script !== '/') {
        $pos = strrpos($script, '/');
        $base = ($pos !== false && $pos > 0) ? substr($script, 0, $pos) : '';
        return $base;
    }
    return $base = '';
}

/**
 * Root-relative URL to a resource, e.g. app_url('/login.php') → "/registrar-ai-system/login.php".
 */
function app_url(?string $path = null): string {
    $base = rtrim(app_base_path(), '/');
    if ($path === null || $path === '' || $path === '/') {
        return ($base === '' ? '/' : $base . '/');
    }
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

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
define('JWT_SECRET', 'your-super-secret-key-change-in-production');

// ── AI (9Router local gateway, OpenAI-compatible) ──────────────
// Local 9Router gateway that fronts several free models via a single
// Bearer key. Endpoint verified live at /v1/models (OpenAI format).
define('AI_API_URL', 'http://localhost:20128/v1/chat/completions');
define('AI_MODEL', 'ollama/minimax-m3');
// Ordered fallback list: the gateway tries each model in turn until one
// succeeds (handles transient 529/5xx overloads on a single backend).
// These are models confirmed live on the local gateway (/v1/models) —
// minmax-m3 and gpt-oss:120b both stream real answers. Some prior
// nvidia/* entries were retired upstream (404 "No active credentials").
define('AI_MODELS', [
    'ollama/minimax-m3',
    'ollama/gpt-oss:120b',
    'ollama/kimi-k2.5',
]);
// API key: read from env var first, then from a local (git-ignored) file.
// Never hardcode a real key in this committed file.
$__aiKey = getenv('AI_API_KEY') ?: '';
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