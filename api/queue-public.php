<?php
// ============================================================
//  API/QUEUE-PUBLIC.PHP
//  Public (NO login) Queue endpoints for the kiosk + monitor.
//    POST ?action=join                    kiosk tap-in
//    GET  ?action=board                   full-lineup feed (monitor/kiosk/portal)
//    GET  ?action=my_ticket&number=N      standing lookup (portal-ready)
//
//  Join is evaluated strictly in this order:
//    1. 2 s global anti-bounce (double-read / rapid re-tap)
//    2. card validation (exists, linked, active)
//    3. 5 min per-student cooldown (already has a ticket that is < 5 min old)
//    4. join from the back (always) — new number appended, prior ticket stays
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/rfid_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db   = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function padNumber(int $n): string {
    return str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

// queue_date / joined_at are written in PHP's Asia/Manila wall clock,
// but the MySQL session may run at +00:00, so CURDATE()/NOW() can be
// 8 h behind. Always bind the PHP-computed date for "today" comparisons.
$today = date('Y-m-d');

// ─── JOIN (kiosk tap) ─────────────────────────────────────────
if ($action === 'join') {
    // must be a POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $cardUid = trim($input['card_uid'] ?? $input['uid'] ?? '');

    if ($cardUid === '') {
        echo json_encode(['success' => false, 'message' => 'Card UID is required.']);
        exit;
    }

    try {
        // ── Lightweight throttle (~15 joins/min/IP) ─────────────
        $joinCount = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM rfid_scan_logs
             WHERE event_type = 'queue_join' AND ip_address = ?
               AND scanned_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            [$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']
        );
        if ($joinCount >= 15) {
            echo json_encode(['success' => false, 'message' => 'Too many join attempts. Please try again shortly.']);
            exit;
        }

        // ── 1. 2 s global anti-bounce ──────────────────────────
        $lastJoin = $db->fetchColumn(
            "SELECT MAX(joined_at) FROM queue_tickets WHERE queue_date = ?",
            [$today]
        );
        if ($lastJoin && (time() - strtotime($lastJoin) < 2)) {
            echo json_encode(['success' => false, 'code' => 'bounce', 'message' => 'Please wait a moment before tapping.']);
            exit;
        }

        // ── 2. Card validation ────────────────────────────────
        $card = lookupCardByUid($db, $cardUid);
        if (!$card) {
            $db->insert('rfid_scan_logs', [
                'card_uid'   => $cardUid,
                'student_id' => null,
                'location'   => 'Registrar Kiosk',
                'event_type' => 'queue_join',
                'status'     => 'denied',
                'scanner_id' => 'kiosk',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
            echo json_encode(['success' => false, 'code' => 'not_found', 'message' => 'Card not recognized.']);
            exit;
        }
        $cardStatus = $card['status'] ?? '';
        $deniedMessage = null;
        if ($card['student_id'] === null) {
            $deniedMessage = 'Card is not linked to a student.';
        } elseif ($cardStatus === 'lost') {
            $deniedMessage = 'Card reported as lost.';
        } elseif ($cardStatus === 'expired') {
            $deniedMessage = 'Card has expired.';
        } elseif ($cardStatus === 'inactive') {
            $deniedMessage = 'Card is inactive.';
        } elseif (!empty($card['expiry_date']) && $card['expiry_date'] < date('Y-m-d')) {
            $deniedMessage = 'Card has expired.';
        }
        if ($deniedMessage) {
            $db->insert('rfid_scan_logs', [
                'card_uid'   => $cardUid,
                'student_id' => $card['student_id'],
                'location'   => 'Registrar Kiosk',
                'event_type' => 'queue_join',
                'status'     => 'denied',
                'scanner_id' => 'kiosk',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
            echo json_encode(['success' => false, 'code' => $cardStatus, 'message' => $deniedMessage]);
            exit;
        }

        $studentId = (int) $card['student_id'];
        $studentName = trim(($card['first_name'] ?? '') . ' ' . ($card['last_name'] ?? '')) ?: 'Student';
        $studentNumber = $card['student_number'] ?? null;
        $course = $card['course'] ?? null;

        // ── 3. 5 min per-student cooldown ─────────────────────
        $existing = $db->fetchOne(
            "SELECT * FROM queue_tickets
             WHERE queue_date = ? AND student_id = ?
             ORDER BY joined_at DESC LIMIT 1",
            [$today, $studentId]
        );
        if ($existing && (time() - strtotime($existing['joined_at']) < 300)) {
            // Already have a (recent) ticket today
            $position = 0;
            if ($existing['status'] === 'waiting') {
                $position = (int) $db->fetchColumn(
                    "SELECT COUNT(*) FROM queue_tickets
                     WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
                    [$today, (int) $existing['ticket_number']]
                );
            }
            echo json_encode([
                'success' => false,
                'code'    => 'cooldown',
                'message' => 'You already have number ' . padNumber((int) $existing['ticket_number'])
                             . ' — you can get a new number after the 5-minute cooldown.',
                'data'    => [
                    'ticket_id'      => (int) $existing['id'],
                    'ticket_number'  => (int) $existing['ticket_number'],
                    'display_number' => padNumber((int) $existing['ticket_number']),
                    'student_name'   => $studentName,
                    'position'       => $position,
                    'waiting_ahead'  => max(0, $position - 1),
                ],
            ]);
            exit;
        }

        // ── 4. Join from the back (always) ────────────────────
        [$reader, $location] = resolveReaderLocation($db, null);

        $nextNumber = (int) $db->fetchColumn(
            "SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM queue_tickets WHERE queue_date = ?",
            [$today]
        );
        $now = date('Y-m-d H:i:s');
        $ticketId = $db->insert('queue_tickets', [
            'queue_date'     => $today,
            'ticket_number'  => $nextNumber,
            'student_id'     => $studentId,
            'student_name'   => $studentName,
            'student_number' => $studentNumber,
            'course'         => $course,
            'status'         => 'waiting',
            'counter'        => 1,
            'card_uid'       => $cardUid,
            'joined_at'      => $now,
        ]);

        $db->insert('rfid_scan_logs', [
            'card_uid'   => $cardUid,
            'student_id' => $studentId,
            'location'   => $location,
            'event_type' => 'queue_join',
            'status'     => 'success',
            'scanner_id' => $reader ? (string) $reader['id'] : 'kiosk',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        $position = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting' AND ticket_number <= ?",
            [$today, $nextNumber]
        );

        $reQueued = $existing !== null && $existing; // had a prior ticket today already

        echo json_encode([
            'success' => true,
            'message' => 'Ticket ready — please wait for your number to be called.',
            'data'    => [
                'ticket_id'      => (int) $ticketId,
                'ticket_number'  => $nextNumber,
                'display_number' => padNumber($nextNumber),
                'student_name'   => $studentName,
                'position'       => $position,
                'waiting_ahead'  => max(0, $position - 1),
                're_queued'      => $reQueued,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to join the queue.');
    }
    exit;
}

// ─── BOARD (full-lineup public feed) ──────────────────────────
if ($action === 'board') {
    try {
        $serving = $db->fetchOne(
            "SELECT id, ticket_number, student_name FROM queue_tickets
             WHERE queue_date = ? AND status = 'serving'
             ORDER BY id DESC LIMIT 1",
            [$today]
        );

        $waitingRows = $db->fetchAll(
            "SELECT id, ticket_number, student_name FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
             ORDER BY ticket_number ASC",
            [$today]
        );
        $waiting = [];
        foreach ($waitingRows as $i => $w) {
            $waiting[] = [
                'ticket_id'     => (int) $w['id'],
                'position'      => $i + 1,
                'number'        => padNumber((int) $w['ticket_number']),
                'ticket_number' => (int) $w['ticket_number'],
                'name'          => $w['student_name'],
                'next_up'       => $i === 0,
            ];
        }

        $recent = $db->fetchAll(
            "SELECT ticket_number, student_name, status FROM queue_tickets
             WHERE queue_date = ? AND status IN ('completed','no-show','removed','cancelled')
             ORDER BY COALESCE(served_at, joined_at) DESC, id DESC
             LIMIT 5",
            [$today]
        );
        $recentMapped = array_map(static function ($r) {
            return [
                'number' => padNumber((int) $r['ticket_number']),
                'name'   => $r['student_name'],
                'status' => $r['status'],
            ];
        }, $recent);

        $lastNumber = (int) $db->fetchColumn(
            "SELECT COALESCE(MAX(ticket_number), 0) FROM queue_tickets WHERE queue_date = ?",
            [$today]
        );
        $waitingCount = count($waiting);

        echo json_encode([
            'success' => true,
            'data'    => [
                'serving'       => $serving
                    ? ['number' => padNumber((int) $serving['ticket_number']), 'name' => $serving['student_name']]
                    : null,
                'waiting'       => $waiting,
                'recently_served' => $recentMapped,
                'waiting_count' => $waitingCount,
                'last_number'   => $lastNumber,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to load the queue.');
    }
    exit;
}

// ─── MY TICKET (standing lookup, portal-ready) ────────────────
if ($action === 'my_ticket') {
    $number = (int) ($_GET['number'] ?? 0);
    if ($number <= 0) {
        echo json_encode(['success' => false, 'message' => 'A valid number is required.']);
        exit;
    }
    try {
        $ticket = $db->fetchOne(
            "SELECT * FROM queue_tickets WHERE queue_date = ? AND ticket_number = ?",
            [$today, $number]
        );
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Number not found for today.', 'code' => 'not_found']);
            exit;
        }

        $ordering = ['waiting' => 0, 'serving' => 1, 'completed' => 2, 'no-show' => 3, 'cancelled' => 4, 'removed' => 5];
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

        $lineup = array_map(static function ($w) {
            return ['number' => padNumber((int) $w['ticket_number']), 'name' => $w['student_name']];
        }, $db->fetchAll(
            "SELECT ticket_number, student_name FROM queue_tickets
             WHERE queue_date = ? AND status = 'waiting'
             ORDER BY ticket_number ASC",
            [$today]
        ));

        echo json_encode([
            'success' => true,
            'data'    => [
                'ticket_id'       => (int) $ticket['id'],
                'ticket_number'   => (int) $ticket['ticket_number'],
                'display_number'  => padNumber((int) $ticket['ticket_number']),
                'student_name'    => $ticket['student_name'],
                'status'          => $ticket['status'],
                'status_order'    => $ordering[$ticket['status']] ?? 5,
                'position'        => $position,
                'waiting_ahead'   => $waitingAhead,
                'next_up'         => $nextUp,
                'serving_ticket'  => $serving ? ['number' => padNumber((int) $serving['ticket_number']), 'name' => $serving['student_name']] : null,
                'joined_at'       => $ticket['joined_at'],
                'called_at'       => $ticket['called_at'],
                'served_at'       => $ticket['served_at'],
                'lineup'          => $lineup,
            ],
        ]);
    } catch (Throwable $e) {
        json_error($e, 'Unable to look up the number.');
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);