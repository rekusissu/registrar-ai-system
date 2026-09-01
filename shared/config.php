<?php
// shared/config.php - Adapted for your database

if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Database Configuration
// Database settings can be overridden per environment via env vars:
//   DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_CHARSET, DB_NAME
// PaaS platforms often use the Laravel-style names instead, so those are
// accepted as fallbacks: DB_DATABASE (→ DB_NAME), DB_USERNAME (→ DB_USER).
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'registrar_ai';
$port = (int)(getenv('DB_PORT') ?: 3306);

if (!extension_loaded('mysqli')) {
    http_response_code(500);
    echo '<h1>Server Configuration Error</h1>'
        . '<p>The <code>mysqli</code> PHP extension is not installed or enabled.</p>'
        . '<p>Please install it: <code>docker-php-ext-install mysqli</code> in the Dockerfile, '
        . 'or <code>sudo apt install php-mysql && sudo systemctl restart apache2</code> on bare metal.</p>';
    error_log('[config.php] FATAL: mysqli extension not loaded — cannot connect to database.');
    exit(1);
}

$conn = new mysqli($host, $user, $pass, $db, $port);

// Application Configuration
define('APP_NAME', 'BCP Registrar System');
define('APP_VERSION', '1.0.0');
// Environment: 'production' or 'development' (default: development)
// Override with APP_ENV environment variable for deployment
define('APP_ENV', getenv('APP_ENV') ?: 'development');
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
// with no activity (default 20 minutes). Override with the
// SESSION_IDLE_TIMEOUT env var, or a shared/session_timeout.local file
// (gitignored). Set to 0 to disable idle logout entirely.
$__sessionTimeout = getenv('SESSION_IDLE_TIMEOUT');
if ($__sessionTimeout === false || $__sessionTimeout === '') {
    $__sessionTimeoutFile = __DIR__ . '/session_timeout.local';
    if (is_file($__sessionTimeoutFile)) {
        $__sessionTimeout = trim((string) file_get_contents($__sessionTimeoutFile));
    }
}
define('SESSION_IDLE_TIMEOUT', $__sessionTimeout !== false && $__sessionTimeout !== '' ? max(0, (int) $__sessionTimeout) : 20 * 60);

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

// ── AI (Gateway, OpenAI-compatible via Antigravity / 9Router) ──
// Local gateway that fronts models via a single Bearer key.
// Verified live at /v1/models (OpenAI format).
// Gateway base URL can be overridden with NINEROUTER_URL
// (e.g. a VPS or tunnel URL); the app appends /v1/chat/completions.
$__aiBase = rtrim((string) getenv('NINEROUTER_URL') ?: 'http://localhost:20128', '/');
define('AI_API_URL', $__aiBase . '/v1/chat/completions');
define('AI_MODEL', 'ag/gemini-3.7-flash-high');
// Ordered fallback list: the gateway tries each model in turn until one
// succeeds (handles transient 529/5xx overloads on a single backend).
define('AI_MODELS', [
    'ag/gemini-3.7-flash-high',     // Gemini 3.7 Flash High (Google AI via Antigravity)
    'ag/gemini-3.7-flash-medium',   // Gemini 3.7 Flash Medium fallback
    'ag/gemini-3.6-flash-high',     // Gemini 3.6 Flash High fallback
    'ollama/minimax-m3',            // vision-capable fallback
    'ollama/kimi-k2.5',             // vision-capable fallback
    'ollama/gpt-oss:120b',          // general-purpose fallback
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

// ── PayMongo (real GCash payments, sandbox/test mode) ─────────
// Empty secret key ⇒ the document-request payment gateway stays on
// the built-in mock (the demo works without any keys). Keys are
// read from env vars first, then from a git-ignored KEY=VALUE file
// shared/paymongo_secret.local (see .gitignore *.local rule):
//   PAYMONGO_SECRET_KEY=sk_test_...
//   PAYMONGO_PUBLIC_KEY=pk_test_...
//   PAYMONGO_WEBHOOK_SECRET=whsec_...
function paymongoSecretFromLocal(string $key): string {
    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $file = __DIR__ . '/paymongo_secret.local';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($lines) ? $lines : [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pair = array_pad(explode('=', $line, 2), 2, '');
                $parsed[trim($pair[0])] = trim($pair[1]);
            }
        }
    }
    return $parsed[$key] ?? '';
}
define('PAYMONGO_SECRET_KEY',    getenv('PAYMONGO_SECRET_KEY') ?: paymongoSecretFromLocal('PAYMONGO_SECRET_KEY'));
define('PAYMONGO_PUBLIC_KEY',    getenv('PAYMONGO_PUBLIC_KEY') ?: paymongoSecretFromLocal('PAYMONGO_PUBLIC_KEY'));
define('PAYMONGO_WEBHOOK_SECRET',getenv('PAYMONGO_WEBHOOK_SECRET') ?: paymongoSecretFromLocal('PAYMONGO_WEBHOOK_SECRET'));
define('PAYMONGO_API_BASE', rtrim((string) getenv('PAYMONGO_API_BASE') ?: 'https://api.paymongo.com', '/'));

// ── Email (SMTP) — Gmail App Password transport ────────────────
// Used by the Emergency & Contacts module (shared/mail_client.php)
// to deliver verification, invoice, grade-snapshot, transcript and
// emergency-blast emails. Read from env vars first, then from the
// git-ignored shared/email_secret.local (KEY=VALUE lines, same layout
// as paymongo_secret.local):
//   SMTP_HOST=smtp.gmail.com
//   SMTP_PORT=587
//   SMTP_USER=you@gmail.com
//   SMTP_PASS=xxxx xxxx xxxx xxxx   (Gmail App Password, not the login password)
//   MAIL_FROM=you@gmail.com
//   MAIL_FROM_NAME=BCP Registrar System
// Missing credentials ⇒ EMAIL_CONFIGURED=false and every sender no-ops
// (the app keeps working, exactly like the PayMongo mock fallback).
function emailSecretFromLocal(string $key): string {
    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $file = __DIR__ . '/email_secret.local';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($lines) ? $lines : [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pair = array_pad(explode('=', $line, 2), 2, '');
                $parsed[trim($pair[0])] = trim($pair[1]);
            }
        }
    }
    return $parsed[$key] ?? '';
}
define('SMTP_HOST',     getenv('SMTP_HOST') ?: emailSecretFromLocal('SMTP_HOST'));
define('SMTP_PORT',     (int) (getenv('SMTP_PORT') ?: emailSecretFromLocal('SMTP_PORT')) ?: 587);
define('SMTP_USER',     getenv('SMTP_USER') ?: emailSecretFromLocal('SMTP_USER'));
define('SMTP_PASS',     getenv('SMTP_PASS') ?: emailSecretFromLocal('SMTP_PASS'));
define('MAIL_FROM',     getenv('MAIL_FROM') ?: emailSecretFromLocal('MAIL_FROM'));
define('MAIL_FROM_NAME',getenv('MAIL_FROM_NAME') ?: emailSecretFromLocal('MAIL_FROM_NAME'));
define('EMAIL_CONFIGURED', SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '');

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
