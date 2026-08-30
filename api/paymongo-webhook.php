<?php
// ============================================================
//  API/PAYMONGO-WEBHOOK.PHP
//  Real PayMongo webhook receiver for the document-request
//  module. Server-to-server (PayMongo → this app): no session,
//  no CSRF — authenticity comes from the Paymongo-Signature HMAC.
//
//  Confirms Payment Sources / Payments and advances the linked
//  document request via the shared confirmGatewayPayment() helper
//  (idempotent — safe alongside the frontend's status poll).
//
//  Events handled:
//    source.chargeable → the student authorized the GCash source —
//                        capture now (create the payment, store the
//                        pay_… id so payment.paid can match it).
//    payment.paid      → mark the transaction + request completed.
//    payment.failed    → mark the transaction failed (request stays
//                        Awaiting_Payment so the student can retry).
//
//  Modeled on verify.php's minimal no-auth bootstrap: do NOT
//  include security_headers.php / session_config.php / csrf_guard.php
//  here.
// ============================================================

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/paymongo_client.php';

header('Content-Type: application/json');

try {
    $rawBody   = (string) file_get_contents('php://input');
    $signature = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');

    if (!paymongoVerifyWebhookSignature($rawBody, $signature, defined('PAYMONGO_WEBHOOK_SECRET') ? PAYMONGO_WEBHOOK_SECRET : '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid signature.']);
        exit;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || !isset($payload['data'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Malformed payload.']);
        exit;
    }

    $eventData = $payload['data'];
    $eventType = (string) ($eventData['attributes']['type'] ?? '');

    // Only accept test-mode events (we run on sandbox keys). Lenient:
    // a missing flag passes; an explicit live event is rejected.
    if (($eventData['attributes']['livemode'] ?? false) === true) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unexpected livemode event.']);
        exit;
    }

    $db = Database::getInstance();

    // source.chargeable → capture (create the payment) and remember the
    // pay_… id so the payment.paid event can match this transaction.
    if ($eventType === 'source.chargeable') {
        $sourceId = (string) ($eventData['attributes']['data']['id'] ?? '');
        $row = $db->fetchOne(
            'SELECT * FROM mock_payment_transactions
              WHERE paymongo_intent_id = ? AND status = \'pending\'',
            [$sourceId]
        );
        if ($row) {
            paymongoCaptureAndStore($row);
        } else {
            error_log('paymongo-webhook: chargeable source for unknown txn ' . $sourceId);
        }
        echo json_encode(['success' => true, 'message' => 'Charge attempted.']);
        exit;
    }

    // Terminal events drive the confirmation; everything else is a no-op.
    // source.failed/cancelled cover GCash authorization failures (no
    // payment is ever created); payment.failed covers capture failures.
    $terminalEvents = [
        'payment.paid'     => 'completed',
        'payment.failed'   => 'failed',
        'source.failed'    => 'failed',
        'source.cancelled' => 'failed',
    ];
    if (!isset($terminalEvents[$eventType])) {
        echo json_encode(['success' => true, 'message' => 'Ignored non-terminal event.']);
        exit;
    }

    // Match the transaction by the captured pay_… id (payment events),
    // falling back to the source id — either the event object itself for
    // source events, or the source embedded in the payment object.
    $eventId   = (string) ($eventData['attributes']['data']['id'] ?? '');
    $eventKind = (string) ($eventData['attributes']['data']['type'] ?? '');
    $paymentId = $eventKind === 'payment' ? $eventId : '';
    $sourceId  = $eventKind === 'source'
        ? $eventId
        : (string) ($eventData['attributes']['data']['attributes']['source']['id'] ?? '');
    $row = $paymentId !== ''
        ? $db->fetchOne('SELECT id FROM mock_payment_transactions WHERE paymongo_payment_id = ?', [$paymentId])
        : null;
    if (!$row && $sourceId !== '') {
        $row = $db->fetchOne('SELECT id FROM mock_payment_transactions WHERE paymongo_intent_id = ?', [$sourceId]);
    }
    if (!$row) {
        error_log('paymongo-webhook: unknown payment/source ' . $eventId . ' (event ' . $eventType . ')');
        echo json_encode(['success' => true, 'message' => 'Unknown payment; ignored.']);
        exit;
    }

    $newStatus = $terminalEvents[$eventType];
    confirmGatewayPayment(
        (int) $row['id'],
        $newStatus,
        $newStatus === 'completed' ? date('Y-m-d H:i:s') : null,
        $paymentId !== '' ? $paymentId : $sourceId,
        null,
        $rawBody
    );

    echo json_encode(['success' => true, 'message' => 'Handled.']);
} catch (Throwable $e) {
    json_error($e, 'Unable to process the payment webhook.');
}
