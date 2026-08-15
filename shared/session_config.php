<?php
// shared/session_config.php

if (defined('SESSION_CONFIG_LOADED')) {
    return;
}
define('SESSION_CONFIG_LOADED', true);

// config.php provides SESSION_IDLE_TIMEOUT (and DB constants used by pages).
// Load it here so this file works standalone regardless of include order.
require_once __DIR__ . '/config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('BCP_REGISTRAR_SESSION');
    session_start();
}

// ─── Idle session timeout ────────────────────────────────────
// Log the user out if they've been inactive past SESSION_IDLE_TIMEOUT.
// Touching last_activity on every request keeps the window sliding.
$idleTimeout = defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 1200;
if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
        $_SESSION = array();
        session_destroy();

        // Resolve the app's web base (e.g. '' or '/registrar-ai-system') so the
        // logout redirect works from any folder, not just the web root.
        $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $appDir  = str_replace('\\', '/', dirname(__DIR__));
        $appBase = '';
        if ($docRoot !== '' && strpos($appDir . '/', $docRoot . '/') === 0) {
            $appBase = substr($appDir, strlen($docRoot));
        }

        // API calls must receive JSON 401, not an HTML redirect.
        if (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'api') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.', 'timeout' => true]);
            exit;
        }

        header('Location: ' . $appBase . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function getCurrentUserName() {
    return $_SESSION['full_name'] ?? 'User';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php?timeout=1');
        exit;
    }
}

function requireRole($requiredRole) {
    requireLogin();
    $role = getCurrentUserRole();
    if ($role === 'admin' || $role === $requiredRole) {
        return;
    }
    header('Location: dashboard.php?error=access_denied');
    exit;
}
?>