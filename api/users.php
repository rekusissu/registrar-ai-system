<?php
// ============================================================
//  API/USERS.PHP
//  User management API (admin-only).
//  CRUD: list, create, update, toggle is_active, reset password,
//  soft-delete (disable). Guards against self-disable and
//  removing the last active admin.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (getCurrentUserRole() !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$roles = ['admin', 'registrar', 'staff', 'teacher', 'student'];

// ─── LIST ─────────────────────────────────────────────────────
if ($method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    $role = trim($_GET['role'] ?? '');
    $params = [];
    $sql = "SELECT u.id, u.email, u.full_name, u.role, u.rfid_uid, u.is_active, u.created_at, u.updated_at,
                   u.student_id,
                   CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                   s.student_number
            FROM users u
            LEFT JOIN students s ON s.id = u.student_id";
    $where = [];
    if ($q !== '') {
        $where[] = "(u.email LIKE ? OR u.full_name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($role !== '' && in_array($role, $roles, true)) {
        $where[] = "u.role = ?";
        $params[] = $role;
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY u.id ASC";
    $users = $db->fetchAll($sql, $params);
    echo json_encode(['success' => true, 'data' => $users]);
    exit;
}

// ─── CREATE ────────────────────────────────────────────────────
if ($method === 'POST') {
    $email = trim($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');
    $fullName = trim($input['full_name'] ?? '');
    $role = $input['role'] ?? 'staff';

    if (!isValidEmail($email)) {
        echo json_encode(['success' => false, 'message' => 'A valid email is required.']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }
    if ($fullName === '') {
        echo json_encode(['success' => false, 'message' => 'Full name is required.']);
        exit;
    }
    if (!in_array($role, $roles, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role.']);
        exit;
    }

    // Optional link to a student record (only meaningful for student accounts).
    $studentId = isset($input['student_id']) && $input['student_id'] !== '' ? (int) $input['student_id'] : null;
    if ($studentId) {
        $student = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$studentId]);
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Linked student record not found.']);
            exit;
        }
    }

    $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'An account with that email already exists.']);
        exit;
    }

    $id = $db->insert('users', [
        'email'         => strtolower($email),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name'     => $fullName,
        'role'          => $role,
        'student_id'    => $studentId,
        'is_active'     => 1,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ]);

    logActivity($_SESSION['user_id'], 'user_create', null, 'users', $id);
    echo json_encode(['success' => true, 'message' => 'User created.', 'data' => ['id' => $id]]);
    exit;
}

// ─── UPDATE / TOGGLE / RESET PASSWORD ─────────────────────────
if ($method === 'PUT' || $method === 'PATCH') {
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'User ID is required.']);
        exit;
    }
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    $action = $input['action'] ?? null;

    // ── Reset password (PATCH action=password) ──
    if ($action === 'password' || ($method === 'PATCH' && isset($input['password']))) {
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            exit;
        }
        $db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        logActivity($_SESSION['user_id'], 'user_password_reset', null, 'users', $id);
        echo json_encode(['success' => true, 'message' => 'Password updated.']);
        exit;
    }

    // ── Toggle is_active (PATCH action=toggle or disable/enable) ──
    if ($method === 'PATCH' && in_array($action, ['toggle', 'enable', 'disable'], true)) {
        $newActive = $action === 'toggle' ? (int)(!$user['is_active']) : ($action === 'enable' ? 1 : 0);
        // Guard: cannot disable the currently logged-in account
        if ((int)$user['id'] === (int)$_SESSION['user_id'] && !$newActive) {
            echo json_encode(['success' => false, 'message' => 'You cannot disable your own account.']);
            exit;
        }
        // Guard: cannot disable the last active admin
        if ($user['role'] === 'admin' && !$newActive) {
            $adminCount = (int) $db->fetchColumn(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$id]
            );
            if ($adminCount === 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot disable the last active admin account.']);
                exit;
            }
        }
        $db->update('users', [
            'is_active'  => $newActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        logActivity($_SESSION['user_id'], $newActive ? 'user_enable' : 'user_disable', null, 'users', $id);
        echo json_encode(['success' => true, 'message' => $newActive ? 'User enabled.' : 'User disabled.']);
        exit;
    }

    // ── Update full_name / role (PUT) ──
    $fullName = trim($input['full_name'] ?? $user['full_name']);
    $role = $input['role'] ?? $user['role'];
    if ($fullName === '') {
        echo json_encode(['success' => false, 'message' => 'Full name is required.']);
        exit;
    }
    if (!in_array($role, $roles, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role.']);
        exit;
    }
    // Guard: cannot demote the last active admin away from admin
    if ($user['role'] === 'admin' && $role !== 'admin') {
        $adminCount = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$id]
        );
        if ($adminCount === 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot demote the last active admin account.']);
            exit;
        }
    }
    // Optional relink to a student record (only meaningful for student accounts).
    $studentId = $user['student_id'];
    if (array_key_exists('student_id', $input)) {
        $newStudentId = $input['student_id'] !== '' ? (int) $input['student_id'] : null;
        if ($newStudentId) {
            $student = $db->fetchOne("SELECT id FROM students WHERE id = ?", [$newStudentId]);
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Linked student record not found.']);
                exit;
            }
        }
        $studentId = $newStudentId;
    }

    // If editing self, keep session name in sync
    if ((int)$user['id'] === (int)$_SESSION['user_id']) {
        $_SESSION['full_name'] = $fullName;
        $_SESSION['role'] = $role;
    }
    $db->update('users', [
        'full_name'  => $fullName,
        'role'       => $role,
        'student_id' => $studentId,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);
    logActivity($_SESSION['user_id'], 'user_update', null, 'users', $id);
    echo json_encode(['success' => true, 'message' => 'User updated.']);
    exit;
}

// ─── DELETE (soft: disable) ───────────────────────────────────
if ($method === 'DELETE' && $id) {
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }
    if ((int)$user['id'] === (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
        exit;
    }
    if ($user['role'] === 'admin' && $user['is_active']) {
        $adminCount = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$id]
        );
        if ($adminCount === 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete the last active admin account.']);
            exit;
        }
    }
    $db->update('users', [
        'is_active'  => 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);
    logActivity($_SESSION['user_id'], 'user_delete', null, 'users', $id);
    echo json_encode(['success' => true, 'message' => 'User deleted (disabled).']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
