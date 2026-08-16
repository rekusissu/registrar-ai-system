<?php
// shared/auth_actions.php - Login hardend for Phase 5:
//   username / student_ID / email + password → OTP → session
//   with 10-minute lockout after every 5 failed attempts.
//   Shared hardening logic lives in shared/auth_security.php.
//   CSRF is enforced on every POST (csrf_guard.php); a second,
//   per-email/IP throttle layer lives in login_throttle.php.

require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth_security.php';
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
// Step 1: credential + password → on success return an OTP step
// (session NOT granted yet).
if ($action === 'login') {
    $credential = trim($_POST['username'] ?? ($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $credential = trim($credential);

    if ($credential === '' || $password === '') {
        sendResponse(false, 'Please enter your ID number / username and password.');
    }

    // Per-email/IP throttle (5 failures / 15 min → 15-min block). Distinct
    // from the per-account lockout: this is keyed on the *typed credential*
    // before any user lookup, so it also blunts brute force on unknown IDs.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $throttleKey = mb_strtolower(trim($credential));
    $throttle = loginThrottleStatus($throttleKey, (string) $ip);
    if ($throttle['blocked']) {
        http_response_code(429);
        sendResponse(false, 'Too many failed attempts. Try again in ' . (int) ceil($throttle['retry_after'] / 60) . ' minute(s).');
    }

    try {
        $db = Database::getInstance();
        $user = resolveLoginUser($db, $credential);

        if (!$user) {
            loginThrottleRecord($throttleKey, (string) $ip, false);
            sendResponse(false, 'Invalid ID / username or password.');
        }

        if (!$user['is_active']) {
            loginThrottleRecord($throttleKey, (string) $ip, false);
            sendResponse(false, 'Your account is disabled. Please contact admin.');
        }

        // Lockout check — if still locked, refuse before any work.
        $remaining = lockoutRemainingSeconds($db, (int) $user['id']);
        if ($remaining > 0) {
            $mins = (int) ceil($remaining / 60);
            sendResponse(false, 'Account temporarily locked. Try again in about ' . $mins . ' minute(s).', ['locked' => true, 'remaining_seconds' => $remaining]);
        }

        if (!password_verify($password, $user['password_hash'])) {
            handleFailedAttempt($db, (int) $user['id']);
            loginThrottleRecord($throttleKey, (string) $ip, false);
            sendResponse(false, 'Invalid ID / username or password.');
        }

        // Password correct — reset lockout/throttle counters, issue an OTP.
        resetLoginLockout($db, (int) $user['id']);
        loginThrottleClear($throttleKey, (string) $ip);
        $otp = issueOtp($db, (int) $user['id'], 'login', $user['email']);

        sendResponse(true, 'Password verified. Enter the one-time code to continue.', [
            'step'          => 'otp',
            'user_id'       => (int) $user['id'],
            'masked_email'  => $otp['masked_email'],
            'delivered'     => $otp['delivered'],
            'otp'           => $otp['otp'],   // on-screen dev fallback
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── RESEND OTP ACTION ─────────────────────────────────────────
// Re-issue a fresh code once the OTP screen is shown.
if ($action === 'resend_otp') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $purpose = $_POST['purpose'] ?? 'login';
    if ($userId <= 0) {
        sendResponse(false, 'Invalid request.');
    }
    try {
        $db = Database::getInstance();
        $otp = issueOtp($db, $userId, $purpose);
        sendResponse(true, 'A new code has been sent.', [
            'masked_email' => $otp['masked_email'],
            'otp'          => $otp['otp'],
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'Unable to resend the code. Please try again.');
    }
}

// ─── VERIFY OTP ACTION ─────────────────────────────────────────
// Step 2: confirm the code → establish the real session.
if ($action === 'verify_otp') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $purpose = ($_POST['purpose'] ?? 'login') === 'reset' ? 'reset' : 'login';
    $code = trim($_POST['otp'] ?? '');

    if ($userId <= 0 || $code === '') {
        sendResponse(false, 'Invalid request.');
    }

    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            sendResponse(false, 'Account not found. Please sign in again.');
        }
        if (!$user['is_active']) {
            sendResponse(false, 'Your account is disabled. Please contact admin.');
        }

        if (!verifyOtpCode($db, $userId, $purpose, $code)) {
            sendResponse(false, 'Invalid or expired code. Please try again.', ['otp_invalid' => true]);
        }

        if ($purpose === 'reset') {
            // Reset flow: return success with a marker — the client
            // then posts reset_password with the new password.
            sendResponse(true, 'Code verified. Set your new password.', ['step' => 'reset_password', 'user_id' => $userId]);
        }

        // Login flow: grant the session and redirect.
        $redirect = signInSession($user);
        sendResponse(true, 'Login successful.', [
            'user' => [
                'id'       => (int) $user['id'],
                'email'    => $user['email'],
                'full_name'=> $user['full_name'],
                'role'     => $user['role'],
            ],
            'redirect' => $redirect,
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── FORGOT PASSWORD ACTION ────────────────────────────────────
// Only here does the EMAIL field appear. Sends a reset OTP.
if ($action === 'forgot') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        sendResponse(false, 'Please enter your email address.');
    }
    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT id, email, is_active FROM users WHERE email = ?", [$email]);
        if (!$user) {
            // Don't reveal whether an account exists.
            sendResponse(false, 'If that email is registered, a reset code has been sent.');
        }
        if (!$user['is_active']) {
            sendResponse(false, 'Your account is disabled. Please contact admin.');
        }
        $otp = issueOtp($db, (int) $user['id'], 'reset', $user['email']);
        sendResponse(true, 'A reset code has been sent to your email.', [
            'step'          => 'otp',
            'user_id'       => (int) $user['id'],
            'purpose'       => 'reset',
            'masked_email'  => $otp['masked_email'],
            'otp'           => $otp['otp'],
        ]);
    } catch (Exception $e) {
        sendResponse(false, 'An error occurred. Please try again.');
    }
}

// ─── RESET PASSWORD ACTION ─────────────────────────────────────
// After the reset OTP verifies, set the new password.
if ($action === 'reset_password') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($userId <= 0 || $newPassword === '' || $confirm === '') {
        sendResponse(false, 'Please fill in all fields.');
    }
    if ($newPassword !== $confirm) {
        sendResponse(false, 'Passwords do not match.');
    }
    if (strlen($newPassword) < 6) {
        sendResponse(false, 'Password must be at least 6 characters.');
    }
    try {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT id, is_active FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            sendResponse(false, 'Account not found. Please start over.');
        }
        if (!$user['is_active']) {
            sendResponse(false, 'Your account is disabled. Please contact admin.');
        }
        $db->update('users', [
            'password_hash'  => password_hash($newPassword, PASSWORD_DEFAULT),
            'login_attempts' => 0,
            'locked_until'   => null,
        ], 'id = ?', [$userId]);
        logActivity($userId, 'password_reset');
        sendResponse(true, 'Your password has been reset. You can now sign in.', ['step' => 'done']);
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
