<?php
// shared/config.php - Adapted for your database

if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

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

/** Maximum enrollees per section when auto-generating masterlists (e.g. BSIT 11001 = 50). Section codes are [year][semester][number], e.g. 11001 = yr 1 sem 1 section 1. */
define('MAX_STUDENTS_PER_SECTION', 50);

// Security
define('JWT_SECRET', 'your-super-secret-key-change-in-production');

// ── AI (9Router local gateway, OpenAI-compatible) ──────────────
// Local 9Router gateway that fronts several free models via a single
// Bearer key. Endpoint verified live at /v1/models (OpenAI format).
define('AI_API_URL', 'http://localhost:20128/v1/chat/completions');
define('AI_MODEL', 'nvidia/deepseek-ai/deepseek-v4-flash');
// Ordered fallback list: the gateway tries each model in turn until one
// succeeds (handles transient 529/5xx overloads on a single backend).
define('AI_MODELS', [
    'nvidia/deepseek-ai/deepseek-v4-flash',
    'nvidia/minimaxai/minimax-m3',
    'nvidia/z-ai/glm-5.2',
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
?>