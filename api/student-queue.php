<?php
// ============================================================
//  API/STUDENT-QUEUE.PHP
//  Student portal — look up the caller's own queue ticket for
//  today by identity (users.student_id), never by a client
//  supplied number. Mirrors the my_ticket response shape from
//  api/queue-public.php so the portal can reuse the UI logic.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$role = getCurrentUserRole();
if (!in_array($role, ['student', 'admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

$studentId = getCurrentStudentId();
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Your account is not linked to a student record.', 'data' => ['ticket' => null]]);
    exit;
}

$db = Database::getInstance();

function padNumber(int $n): string {
    return str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

$today = date('Y-m-d');

// ──────────────────────────────────────────────────────────────
// action=cancel — self-cancel the caller's own waiting ticket.
// The student may withdraw their ticket before they are called;
// after serving/call it can no longer be cancelled.
// ──────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action === 'cancel') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    $own = $db->fetchOne(
        "SELECT * FROM queue_tickets
         WHERE queue_date = ? AND student_id = ? AND status = 'waiting'
         ORDER BY joined_at DESC LIMIT 1",
        [$today, $studentId]
    );
    if (!$own) {
        echo json_encode(['success' => false, 'message' => 'No waiting ticket to cancel.']);
        exit;
    }
    $db->update(
        'queue_tickets',
        ['status' => 'cancelled', 'served_at' => date('Y-m-d H:i:s')],
        'id = ?',
        [(int) $own['id']]
    );
    logActivity(getCurrentUserId(), 'queue_cancel', 'Cancelled own ticket #' . str_pad((string) $own['id'], 3, '0', STR_PAD_LEFT), 'queue_tickets', (int) $own['id']);

    // Log queue cancelled event to rfid_scan_logs (best-effort, non-fatal)
    try {
        $db->insert('rfid_scan_logs', [
            'card_uid'   => $own['card_uid'] ?? '',
            'student_id' => $own['student_id'] ?? null,
            'location'   => 'Student Portal',
            'event_type' => 'queue_cancelled',
            'status'     => 'denied',
            'scanner_id' => 'student-portal',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    } catch (Throwable $e) {
        // Non-fatal — surface nothing to the caller.
        error_log('queue cancel log failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Your queue ticket was cancelled.']);
    exit;
}

try {
    // Latest ticket today: active first, then any terminal ticket so a
    // cancelled/completed ticket still renders (with served_at).
    $ticket = $db->fetchOne(
        "SELECT * FROM queue_tickets
         WHERE queue_date = ? AND student_id = ?
         ORDER BY (status IN ('waiting','serving')) DESC, joined_at DESC LIMIT 1",
        [$today, $studentId]
    );

    if (!$ticket) {
        echo json_encode(['success' => true, 'data' => ['ticket' => null]]);
        exit;
    }

    $serving = $db->fetchOne(
        "SELECT ticket_number, student_name FROM queue_tickets
         WHERE queue_date = ? AND status = 'serving'
         ORDER BY id DESC LIMIT 1",
        [$today]
    );

    $position = 0;
    $waitingAhead = 0;
    $nextUp = false;
    if ($ticket['status'] === 'waiting') {
        $position = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
            [$today, (int) $ticket['ticket_number']]
        );
        $waitingAhead = max(0, $position - 1);
        $nextUp = $position === 1;
    }

    $ordering = ['waiting' => 0, 'serving' => 1, 'completed' => 2, 'no-show' => 3, 'cancelled' => 4, 'removed' => 5];

    $joinedDate = $ticket['joined_at'] ? date('M d, Y', strtotime($ticket['joined_at'])) : null;
    $joinedTime = $ticket['joined_at'] ? date('h:i A', strtotime($ticket['joined_at'])) : null;
    $servedDate = $ticket['served_at'] ? date('M d, Y', strtotime($ticket['served_at'])) : null;
    $servedTime = $ticket['served_at'] ? date('h:i A', strtotime($ticket['served_at'])) : null;

    echo json_encode([
        'success' => true,
        'data'    => [
            'ticket' => [
                'ticket_id'       => (int) $ticket['id'],
                'ticket_number'   => (int) $ticket['ticket_number'],
                'display_number'  => padNumber((int) $ticket['ticket_number']),
                'student_name'    => $ticket['student_name'],
                'status'          => $ticket['status'],
                'status_order'    => $ordering[$ticket['status']] ?? 6,
                'position'        => $position,
                'waiting_ahead'   => $waitingAhead,
                'next_up'         => $nextUp,
                'counter'         => (int) $ticket['counter'],
                'joined_at'       => $ticket['joined_at'],
                'joined_date'     => $joinedDate,
                'joined_time'     => $joinedTime,
                'called_at'       => $ticket['called_at'],
                'served_at'       => $ticket['served_at'],
                'served_date'     => $servedDate,
                'served_time'     => $servedTime,
            ],
            'serving' => $serving
                ? ['number' => padNumber((int) $serving['ticket_number']), 'name' => $serving['student_name']]
                : null,
        ],
    ]);
} catch (Throwable $e) {
    json_error($e, 'Unable to load your queue status.');
}
