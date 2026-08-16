<?php
// ============================================================
//  API/AUDIT-LOGS.PHP
//  Audit log viewer API (admin-only).
//  GET list with filters (user, action, date range) + pagination.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
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

// ─── METADATA: distinct users + actions for filter dropdowns ──
if ($method === 'GET' && isset($_GET['meta']) && $_GET['meta'] === '1') {
    $users = $db->fetchAll("SELECT DISTINCT user_id, full_name FROM audit_logs LEFT JOIN users ON users.id = audit_logs.user_id WHERE user_id IS NOT NULL ORDER BY full_name");
    $actions = $db->fetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action");
    echo json_encode(['success' => true, 'data' => ['users' => $users, 'actions' => $actions]]);
    exit;
}

// ─── LIST ─────────────────────────────────────────────────────
if ($method === 'GET') {
    $q      = trim($_GET['q'] ?? '');
    $user   = trim($_GET['user'] ?? '');
    $action = trim($_GET['action'] ?? '');
    $from   = trim($_GET['from'] ?? '');
    $to     = trim($_GET['to'] ?? '');
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = min(100, max(10, intval($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(al.action LIKE ? OR al.table_name LIKE ? OR al.ip_address LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($user !== '') {
        $where[] = "al.user_id = ?";
        $params[] = (int) $user;
    }
    if ($action !== '') {
        $where[] = "al.action = ?";
        $params[] = $action;
    }
    if ($from !== '') {
        $where[] = "al.created_at >= ?";
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $where[] = "al.created_at <= ?";
        $params[] = $to . ' 23:59:59';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM audit_logs al $whereSql", $params
    );

    $sql = "SELECT al.*, u.email AS user_email, u.full_name AS user_name
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            $whereSql
            ORDER BY al.id DESC
            LIMIT $limit OFFSET $offset";
    $rows = $db->fetchAll($sql, $params);

    // Decode JSON values client-side-friendly
    foreach ($rows as &$row) {
        $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
        $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
        $row['action_label'] = actionLabel($row['action']);
    }
    unset($row);

    echo json_encode(['success' => true, 'data' => $rows, 'meta' => [
        'total' => $total, 'page' => $page, 'limit' => $limit,
        'pages' => max(1, (int) ceil($total / $limit)),
    ]]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);

/**
 * Map an action code to a friendly label.
 */
function actionLabel($action) {
    $map = [
        'login'               => 'Login',
        'logout'              => 'Logout',
        'user_create'         => 'User Created',
        'user_update'         => 'User Updated',
        'user_enable'         => 'User Enabled',
        'user_disable'        => 'User Disabled',
        'user_delete'         => 'User Deleted',
        'user_password_reset' => 'Password Reset',
        'student_create'      => 'Student Created',
        'student_update'      => 'Student Updated',
        'student_delete'      => 'Student Deleted',
        'student_status'      => 'Student Status Changed',
        'document_create'     => 'Document Requested',
        'document_update'     => 'Document Updated',
        'clearance_issue'     => 'Clearance Issued',
        'clearance_revoke'    => 'Clearance Revoked',
    ];
    return $map[$action] ?? ucwords(str_replace('_', ' ', $action));
}
