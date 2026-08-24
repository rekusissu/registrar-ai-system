<?php
// ============================================================
//  API/AUTH.PHP
//  Authentication API endpoints — mirror of shared/auth_actions.php
//  for JSON clients. Same Phase 5 hardening (OTP + lockout),
//  plus the session-level CSRF + per-email/IP throttle layers.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/login_throttle.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function failJson(string $msg, bool $locked = false, ?array $extra = null): void {
    $payload = ['success' => false, 'message' => $msg];
    if ($locked) $payload['locked'] = true;
    if ($extra)  $payload = array_merge($payload, $extra);
    echo json_encode($payload);
    exit;
}

// ─── LOGIN ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $credential = trim($input['username'] ?? ($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if ($credential === '' || $password === '') {
        failJson('ID number / username and password are required.');
    }

    try {
        $db = Database::getInstance();
        $user = resolveLoginUser($db, $credential);
        if (!$user) {
            failJson('Invalid ID / username or password.');
        }
        if (!$user['is_active']) {
            failJson('Your account is disabled. Please contact admin.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            failJson('Invalid ID / username or password.');
        }

        resetLoginLockout($db, (int) $user['id']);
        $redirect = signInSession($user);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => [
                    'id'        => (int) $user['id'],
                    'email'     => $user['email'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ],
                'redirect' => $redirect,
            ],
        ]);
    } catch (Exception $e) {
        failJson('Login failed. Please try again.');
    }
    exit;
}

// ─── RESEND OTP ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'resend_otp') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);
    $purpose = $input['purpose'] ?? 'login';
    if ($userId <= 0) failJson('Invalid request.');
    try {
        $db = Database::getInstance();
        $otp = issueOtp($db, $userId, $purpose);
        echo json_encode([
            'success' => true,
            'message' => 'A new code has been sent.',
            'data' => ['masked_email' => $otp['masked_email'], 'otp' => $otp['otp']],
        ]);
    } catch (Exception $e) { failJson('Unable to resend the code. Please try again.'); }
    exit;
}

// ─── VERIFY OTP ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'verify_otp') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);
    $purpose = ($input['purpose'] ?? 'login') === 'reset' ? 'reset' : 'login';
    $code = trim($input['otp'] ?? '');
    if ($userId <= 0 || $code === '') failJson('Invalid request.');
    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) failJson('Account not found. Please sign in again.');
        if (!$user['is_active']) failJson('Your account is disabled. Please contact admin.');
        if (!verifyOtpCode($db, $userId, $purpose, $code)) {
            failJson('Invalid or expired code. Please try again.', false, ['otp_invalid' => true]);
        }
        if ($purpose === 'reset') {
            echo json_encode(['success' => true, 'message' => 'Code verified. Set your new password.', 'data' => ['step' => 'reset_password', 'user_id' => $userId]]);
        } else {
            $redirect = signInSession($user);
            echo json_encode([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'full_name' => $user['full_name'], 'role' => $user['role']],
                    'redirect' => $redirect,
                ],
            ]);
        }
    } catch (Exception $e) { failJson('An error occurred. Please try again.'); }
    exit;
}

// ─── FORGOT PASSWORD ────────────────────────────────────────────
if ($method === 'POST' && $action === 'forgot') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim($input['email'] ?? '');
    if ($email === '') failJson('Please enter your email address.');
    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT id, email, is_active FROM users WHERE email = ?", [$email]);
        if (!$user) {
            failJson('If that email is registered, a reset code has been sent.');
        }
        if (!$user['is_active']) failJson('Your account is disabled. Please contact admin.');
        $otp = issueOtp($db, (int) $user['id'], 'reset', $user['email']);
        echo json_encode([
            'success' => true,
            'message' => 'A reset code has been sent to your email.',
            'data' => ['step' => 'otp', 'user_id' => (int) $user['id'], 'purpose' => 'reset', 'masked_email' => $otp['masked_email'], 'otp' => $otp['otp']],
        ]);
    } catch (Exception $e) { failJson('An error occurred. Please try again.'); }
    exit;
}

// ─── RESET PASSWORD ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'reset_password') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);
    $newPassword = $input['new_password'] ?? '';
    $confirm = $input['confirm_password'] ?? '';
    if ($userId <= 0 || $newPassword === '' || $confirm === '') failJson('Please fill in all fields.');
    if ($newPassword !== $confirm) failJson('Passwords do not match.');
    if (strlen($newPassword) < 6) failJson('Password must be at least 6 characters.');
    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT id, is_active FROM users WHERE id = ?", [$userId]);
        if (!$user) failJson('Account not found. Please start over.');
        if (!$user['is_active']) failJson('Your account is disabled. Please contact admin.');
        $db->update('users', [
            'password_hash'  => password_hash($newPassword, PASSWORD_DEFAULT),
            'login_attempts' => 0,
            'locked_until'   => null,
        ], 'id = ?', [$userId]);
        logActivity($userId, 'password_reset');
        echo json_encode(['success' => true, 'message' => 'Your password has been reset. You can now sign in.', 'data' => ['step' => 'done']]);
    } catch (Exception $e) { failJson('An error occurred. Please try again.'); }
    exit;
}

// ─── LOGOUT ─────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    exit;
}

// ─── CHECK SESSION ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'session') {
    if (isLoggedIn()) {
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'email' => $_SESSION['email'] ?? '',
                    'full_name' => $_SESSION['full_name'] ?? 'User',
                    'role' => $_SESSION['role'] ?? '',
                ]
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Session expired.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);