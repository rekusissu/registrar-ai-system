<?php
// shared/auth_actions.php - Adapted for your database (uses email for login)

require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf_guard.php';
require_once __DIR__ . '/login_throttle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// ─── LOGIN ACTION ──────────────────────────────────────────────
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        sendResponse(false, 'Please enter your email and password.');
    }

    // Login throttling: 5 failed attempts / 15 min per email+IP locks out for 15 min.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $throttle = loginThrottleStatus($email, (string) $ip);
    if ($throttle['blocked']) {
        http_response_code(429);
        sendResponse(false, 'Too many failed attempts. Try again in ' . (int) ceil($throttle['retry_after'] / 60) . ' minute(s).');
    }

    try {
        $db = Database::getInstance();
        
        // Find user by email (your table uses email, not username)
        $user = $db->fetchOne(
            "SELECT id, email, password_hash, full_name, role, is_active 
             FROM users 
             WHERE email = ?",
            [$email]
        );

        if (!$user) {
            loginThrottleRecord($email, (string) $ip, false);
            sendResponse(false, 'Invalid email or password.');
        }

        if (!$user['is_active']) {
            loginThrottleRecord($email, (string) $ip, false);
            sendResponse(false, 'Your account is disabled. Please contact admin.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            loginThrottleRecord($email, (string) $ip, false);
            sendResponse(false, 'Invalid email or password.');
        }

        // Login successful
        loginThrottleClear($email, (string) $ip);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();

        sendResponse(true, 'Login successful', [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);

    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── LOGOUT ACTION ──────────────────────────────────────────────
if ($action === 'logout') {
    session_unset();
    session_destroy();
    sendResponse(true, 'Logged out successfully');
}

// ─── CHECK SESSION ACTION ──────────────────────────────────────
if ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        sendResponse(true, 'Session is valid', [
            'user' => [
                'id' => $_SESSION['user_id'],
                'email' => $_SESSION['email'],
                'full_name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ]
        ]);
    } else {
        sendResponse(false, 'Session expired');
    }
}

sendResponse(false, 'Invalid action.');
?>
