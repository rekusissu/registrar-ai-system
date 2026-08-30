<?php
// ============================================================
//  SHARED/PAYMONGO_CLIENT.PHP
//  Real GCash payments via PayMongo (sandbox/test mode) for the
//  Document Request module.
//
//  Provides:
//    paymongoConfigured()          — true when a secret key is set
//    paymongoRequest()             — low-level cURL call (Basic auth)
//    paymongoCreateSource()        — POST /v1/sources (gcash, hosted checkout)
//    paymongoGetSource()           — GET /v1/sources/{id}
//    paymongoCreatePayment()       — POST /v1/payments (capture a source)
//    paymongoCaptureAndStore()     — capture a chargeable source, record pay_ id
//    paymongoVerifyWebhookSignature() — HMAC-SHA256 over the raw body
//    confirmGatewayPayment()       — idempotent txn+request confirmation
//
//  cURL pattern mirrors shared/ai_client.php (aiHttpChat). PayMongo
//  amounts are the smallest currency unit (centavos for PHP) and the
//  API uses HTTP Basic auth with the secret key as the username and
//  an empty password.
// ============================================================

if (defined('PAYMONGO_CLIENT_LOADED')) {
    return;
}
define('PAYMONGO_CLIENT_LOADED', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// True when a PayMongo secret key is configured — this flips the
// document-request payment gateway from the mock to the real one.
function paymongoConfigured(): bool {
    return defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY !== '';
}

/**
 * Low-level PayMongo HTTP call. Returns the decoded JSON envelope, or
 * null on any failure (curl error, non-2xx, non-JSON). Failures are
 * logged to error_log; callers treat null as "gateway unavailable".
 */
function paymongoRequest(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): ?array {
    if (!paymongoConfigured()) {
        return null;
    }
    $url = PAYMONGO_API_BASE . $path;

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $headers[] = 'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':');
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        error_log('paymongo: curl error: ' . $err);
        return null;
    }
    if ($status < 200 || $status >= 300) {
        error_log('paymongo: HTTP ' . $status . ' for ' . $path . ': ' . mb_substr((string) $result, 0, 500));
        return null;
    }
    $decoded = json_decode((string) $result, true);
    if (!is_array($decoded)) {
        error_log('paymongo: non-JSON response for ' . $path);
        return null;
    }
    return $decoded;
}

/**
 * Create a GCash payment source — the hosted checkout flow. Returns the
 * response `data` object ({id: src_..., attributes}), or null on failure.
 * The URL the student is sent to lives at attributes.redirect.checkout_url
 * (Payment Intents do NOT return a hosted checkout_url in sandbox —
 * verified live 2026-08-29).
 */
function paymongoCreateSource(int $amountCentavos, string $description, string $successUrl, string $failedUrl, string $idempotencyKey): ?array {
    $res = paymongoRequest('POST', '/v1/sources', [
        'data' => [
            'attributes' => [
                'type'        => 'gcash',
                'amount'      => $amountCentavos,
                'currency'    => 'PHP',
                'description' => mb_substr($description, 0, 255),
                'redirect'    => ['success' => $successUrl, 'failed' => $failedUrl],
            ],
        ],
    ], $idempotencyKey);
    return is_array($res) && isset($res['data']) ? $res['data'] : null;
}

/**
 * Fetch a payment source's current state. Returns the response `data`
 * object, or null on failure.
 */
function paymongoGetSource(string $sourceId): ?array {
    $res = paymongoRequest('GET', '/v1/sources/' . rawurlencode($sourceId));
    return is_array($res) && isset($res['data']) ? $res['data'] : null;
}

/**
 * Charge a source by creating a payment. GCash sources are authorized
 * first (hosted redirect checkout), then must be captured via
 * POST /v1/payments. The `source` attribute is an OBJECT, not a string:
 *   ['id' => 'src_…', 'type' => 'source']
 * where `type` is the Source resource type — 'source' or 'token' — NOT
 * the payment method ('gcash' is rejected as invalid). Verified live
 * 2026-08-29: `{id}` alone → 400 "source.type is required";
 * `{id, type:"gcash"}` → 400 "source.type … is invalid";
 * `{id, type:"source"}` → 200 and a paid payment. Returns the response
 * `data` object ({id: pay_..., attributes}), or null on failure.
 */
function paymongoCreatePayment(int $amountCentavos, string $description, string $sourceId, string $idempotencyKey): ?array {
    $res = paymongoRequest('POST', '/v1/payments', [
        'data' => [
            'attributes' => [
                'amount'      => $amountCentavos,
                'currency'    => 'PHP',
                'description' => mb_substr($description, 0, 255),
                'source'      => ['id' => $sourceId, 'type' => 'source'],
            ],
        ],
    ], $idempotencyKey);
    return is_array($res) && isset($res['data']) ? $res['data'] : null;
}

/**
 * Capture a chargeable source and record the resulting pay_… id on the
 * transaction row (so the payment.paid webhook can match it later). No-op
 * when the source isn't chargeable yet or was already captured. Returns
 * the pay_… id, or null when nothing was captured.
 */
function paymongoCaptureAndStore(array $txnRow): ?string {
    $sourceId = (string) ($txnRow['paymongo_intent_id'] ?? '');
    if ($sourceId === '') {
        return null;
    }
    $source = paymongoGetSource($sourceId);
    if (!is_array($source) || (string) ($source['attributes']['status'] ?? '') !== 'chargeable') {
        return null;
    }
    $pay = paymongoCreatePayment(
        (int) round((float) ($txnRow['amount'] ?? 0) * 100),
        'Document request',
        $sourceId,
        'cap-' . ($txnRow['transaction_id'] ?? '')
    );
    if (!is_array($pay) || empty($pay['id'])) {
        return null;
    }
    Database::getInstance()->update(
        'mock_payment_transactions',
        ['paymongo_payment_id' => (string) $pay['id']],
        'id = ?',
        [(int) ($txnRow['id'] ?? 0)]
    );
    return (string) $pay['id'];
}

/**
 * Verify a PayMongo webhook signature. PayMongo signs the exact raw
 * request body with HMAC-SHA256 using the webhook secret (whsec_…).
 */
function paymongoVerifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool {
    if ($webhookSecret === '' || $signature === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), $signature);
}

/**
 * Confirm a gateway payment (idempotent). Marks the transaction and —
 * on success — records payment on the linked document request and
 * advances it Awaiting_Payment → Processing. Safe to call from the mock
 * webhook, the real PayMongo webhook, and the status poll without
 * double-applying (a terminal status is never re-applied).
 *
 * @return array{confirmed: bool, already: bool, blocked_clearance: bool, request_advanced: bool}
 */
function confirmGatewayPayment(int $txnRowId, string $newStatus, ?string $paidAt, string $ref, ?int $createdBy = null, ?string $rawResponse = null): array {
    $db = Database::getInstance();
    $row = $db->fetchOne('SELECT * FROM mock_payment_transactions WHERE id = ?', [$txnRowId]);
    if (!$row) {
        return ['confirmed' => false, 'already' => false, 'blocked_clearance' => false, 'request_advanced' => false];
    }
    // Idempotency guard: never re-apply a terminal status (webhook +
    // poll racing each other is harmless).
    if (in_array($row['status'], ['completed', 'failed'], true)) {
        return ['confirmed' => false, 'already' => true, 'blocked_clearance' => false, 'request_advanced' => false];
    }

    $now = date('Y-m-d H:i:s');
    $db->update('mock_payment_transactions', [
        'status'       => $newStatus,
        'paid_at'      => $newStatus === 'completed' ? ($paidAt ?: $now) : null,
        'raw_response' => $rawResponse,
    ], 'id = ?', [$txnRowId]);

    $blockedClearance = false;
    $requestAdvanced = false;
    $requestId = (int) $row['request_id'];

    if ($requestId && $newStatus === 'completed') {
        $req = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
        if ($req && in_array($req['document_status'], ['Awaiting_Payment', 'Pending_Clearance', 'Processing', 'Rejected'], true)) {
            if ($req['document_status'] === 'Pending_Clearance') {
                // Defensive: create blocks clearance-pending requests, so this
                // is normally unreachable. The txn is still marked completed
                // above (matches the mock's prior behavior).
                $blockedClearance = true;
            } else {
                $db->update('document_requests', [
                    'paid_at'     => $paidAt ?: $now,
                    'payment_ref' => $ref,
                ], 'id = ?', [$requestId]);
                if ($req['document_status'] === 'Awaiting_Payment') {
                    $db->update('document_requests', ['document_status' => 'Processing'], 'id = ?', [$requestId]);
                    $db->insert('document_request_events', [
                        'request_id' => $requestId,
                        'status'     => 'Processing',
                        'note'       => 'Payment completed (' . $ref . ')',
                        'created_by' => $createdBy,
                        'created_at' => $now,
                    ]);
                    $requestAdvanced = true;
                }
            }
        }
    }

    return [
        'confirmed'         => true,
        'already'           => false,
        'blocked_clearance' => $blockedClearance,
        'request_advanced'  => $requestAdvanced,
    ];
}
