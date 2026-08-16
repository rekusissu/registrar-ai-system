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
        header('Location: ' . app_url('login.php?timeout=1'));
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
        header('Location: ' . app_url('login.php?timeout=1'));
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

/**
 * Guard for student-portal pages. Only a 'student' role (or admin,
 * which bypasses everything) may proceed. Anything else is bounced.
 */
function requireStudent() {
    requireLogin();
    $role = getCurrentUserRole();
    if ($role === 'admin' || $role === 'student') {
        return;
    }
    header('Location: dashboard.php?error=access_denied');
    exit;
}

/**
 * Resolve the linked students.id for the currently logged-in user.
 * Returns int|null — null when the account has no linked student record.
 * Requires the database to be available (pages call this after Database::getInstance()).
 */
function getCurrentStudentId() {
    if (!isLoggedIn()) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = Database::getInstance();
        $id = $db->fetchColumn(
            "SELECT student_id FROM users WHERE id = ?",
            [getCurrentUserId()]
        );
        $cached = $id !== null ? (int) $id : null;
    } catch (Throwable $e) {
        $cached = null;
    }
    return $cached;
}
?>