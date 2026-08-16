<?php
// ============================================================
//  API/NOTIFICATIONS.PHP
//  Fetch recent notifications from audit_logs + other sources
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $db = Database::getInstance();

    // Get recent audit logs
    $logs = $db->fetchAll("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 20");

    $notifications = [];

    foreach ($logs as $log) {
        $icon = 'fa-circle-info';
        $title = $log['action'];
        $action = strtolower($log['action']);

        if (strpos($action, 'insert') !== false || strpos($action, 'create') !== false || strpos($action, 'add') !== false) {
            $icon = 'fa-plus-circle';
        } elseif (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) {
            $icon = 'fa-pen';
        } elseif (strpos($action, 'delete') !== false || strpos($action, 'archive') !== false) {
            $icon = 'fa-trash-alt';
        } elseif (strpos($action, 'login') !== false) {
            $icon = 'fa-right-to-bracket';
        } elseif (strpos($action, 'assign') !== false) {
            $icon = 'fa-credit-card';
        }

        $table = $log['table_name'] ?? '';
        $tableLabel = '';
        if ($table === 'students') $tableLabel = 'Student';
        elseif ($table === 'rfid_cards') $tableLabel = 'RFID Card';
        elseif ($table === 'users') $tableLabel = 'User';
        elseif ($table === 'guardians') $tableLabel = 'Guardian';
        elseif ($table === 'documents') $tableLabel = 'Document';
        else $tableLabel = ucfirst(str_replace('_', ' ', $table));

        $time = $log['created_at'];
        $timeAgo = '';
        if ($time) {
            $diff = time() - strtotime($time);
            if ($diff < 60) $timeAgo = 'Just now';
            elseif ($diff < 3600) $timeAgo = floor($diff / 60) . 'm ago';
            elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . 'h ago';
            else $timeAgo = date('M d', strtotime($time));
        }

        $notifications[] = [
            'id' => $log['id'],
            'title' => $tableLabel ? $tableLabel . ' ' . $log['action'] : $log['action'],
            'message' => $tableLabel ? $tableLabel . ' record ' . $log['action'] : $log['action'],
            'time' => $timeAgo,
            'unread' => true,
            'icon' => $icon
        ];
    }

    echo json_encode(['success' => true, 'data' => $notifications]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error.']);
}
?>
