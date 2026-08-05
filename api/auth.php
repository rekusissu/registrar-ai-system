<?php
// ============================================================
//  API/AUTH.PHP
//  Authentication API endpoints (login, logout, session check)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── LOGIN ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        exit;
    }

    try {
        $db = Database::getInstance();
        $user = $db->fetchOne(
            "SELECT id, email, password_hash, full_name, role, is_active 
             FROM users 
             WHERE email = ?",
            [$email]
        );

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit;
        }

        if (!$user['is_active']) {
            echo json_encode(['success' => false, 'message' => 'Your account is disabled.']);
            exit;
        }

        if (!password_verify($password, $user['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit;
        }

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_name('BCP_REGISTRAR_SESSION');
            session_start();
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        echo json_encode([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role']
                ]
            ]
        ]);

    } catch (Exception $e) {
        json_error($e, 'Login failed. Please try again.');
    }
    exit;
}

// ─── LOGOUT ─────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('BCP_REGISTRAR_SESSION');
        session_start();
    }
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    exit;
}

// ─── CHECK SESSION ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'session') {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('BCP_REGISTRAR_SESSION');
        session_start();
    }

    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'email' => $_SESSION['email'],
                    'full_name' => $_SESSION['full_name'],
                    'role' => $_SESSION['role']
                ]
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Session expired.']);
    }
    exit;
}

// ─── INVALID REQUEST ───────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid request.']);
?>