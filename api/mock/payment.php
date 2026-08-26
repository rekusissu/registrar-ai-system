<?php
// ============================================================
//  API/MOCK/PAYMENT.PHP
//  Mock online-payment gateway (GCash / Maya style) for the
//  Document Request Module demo.
//
//  Simulates the school's payment provider so the whole flow can
//  run end-to-end without a real gateway:
//    action=create   → {payment_url, transaction_id: TXN-MOCK-####, status: pending}
//    action=webhook  → {transaction_id, status: COMPLETED, paid_at}  ("Simulate Successful Payment")
//    action=status   → transaction lookup helper
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../shared/config.php';
require_once __DIR__ . '/../../shared/database.php';
require_once __DIR__ . '/../../shared/session_config.php';
require_once __DIR__ . '/../../shared/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($input['action'] ?? ($_GET['action'] ?? ''));

try {
    $db = Database::getInstance();
    $now = date('Y-m-d H:i:s');
    $role = getCurrentUserRole();

    if ($action === 'create') {
        $studentId = (int) ($input['student_id'] ?? 0);
        $requestId = (int) ($input['request_id'] ?? 0);
        $currency  = strtoupper(trim($input['currency'] ?? 'PHP'));
        $callback  = trim($input['callback_url'] ?? '');
        // Payment method: Online (GCash/Maya gateway) or Cash_on_Delivery.
        // Keep the canonical DB casing — do not uppercase, the enum is case-sensitive.
        $method = trim($input['method'] ?? 'Online');
        if (!in_array($method, ['Online', 'Cash_on_Delivery'], true)) {
            $method = 'Online';
        }

        // A student may only create payments against their own record.
        if ($role === 'student') {
            $studentId = getCurrentStudentId();
        } elseif (!in_array($role, ['admin', 'registrar'], true)) {
            echo json_encode(['success' => false, 'message' => 'Forbidden.']);
            exit;
        }
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'A valid student_id is required.']);
            exit;
        }

        $amount = round((float) ($input['amount'] ?? 0), 2);

        if ($requestId) {
            $req = $db->fetchOne(
                "SELECT dr.*, c.fee_type, c.base_fee
                   FROM document_requests dr
                   LEFT JOIN document_catalog c ON c.id = dr.catalog_id
                  WHERE dr.id = ?",
                [$requestId]
            );
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Document request not found.']);
                exit;
            }
            if ((int) $req['student_id'] !== $studentId && !in_array($role, ['admin', 'registrar'], true)) {
                echo json_encode(['success' => false, 'message' => 'Forbidden.']);
                exit;
            }
            if (($req['document_status'] ?? '') === 'Pending_Clearance') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot pay while clearance is pending.',
                    'data'    => ['blocked' => true],
                ]);
                exit;
            }
            if (($req['document_status'] ?? '') !== 'Awaiting_Payment') {
                echo json_encode(['success' => false, 'message' => 'This request is not awaiting payment.']);
                exit;
            }
            // Derive the amount from the catalog when the client didn't supply one.
            // The student also pays the courier delivery fee, so it is included here.
            if ($amount <= 0 && isset($req['base_fee'])) {
                $amount = round((float) $req['base_fee'] * max(1, (int) $req['quantity']), 2);
                if (isset($req['delivery_fee']) && (float) $req['delivery_fee'] > 0) {
                    $amount += round((float) $req['delivery_fee'], 2);
                }
            }
        }

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'A positive amount is required.']);
            exit;
        }

        $txn = 'TXN-MOCK-' . mt_rand(1000, 9999);
        for ($i = 0; $i < 5 && $db->fetchOne('SELECT id FROM mock_payment_transactions WHERE transaction_id = ?', [$txn]); $i++) {
            $txn = 'TXN-MOCK-' . mt_rand(1000, 9999);
        }
        // COD has no gateway link — the money is handed to the courier on delivery.
        $paymentUrl = $method === 'Cash_on_Delivery' ? null : 'http://mock-gateway.com/pay/' . $txn;

        $db->insert('mock_payment_transactions', [
            'transaction_id' => $txn,
            'request_id'     => $requestId ?: null,
            'student_id'     => $studentId,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => 'pending',
            'method'         => $method,
            'due_on'         => $method === 'Cash_on_Delivery' ? 'delivery' : 'now',
            'payment_url'    => $paymentUrl,
            'callback_url'   => $callback !== '' ? $callback : null,
            'raw_request'    => json_encode($input),
            'created_at'     => $now,
        ]);

        $resp = [
            'payment_url'    => $paymentUrl,
            'transaction_id' => $txn,
            'status'         => 'pending',
            'amount'         => $amount,
            'currency'       => $currency,
            'method'         => $method,
            'due_on'         => $method === 'Cash_on_Delivery' ? 'delivery' : 'now',
        ];
        echo json_encode(['success' => true, 'message' => 'Payment created.', 'data' => $resp]);
        exit;
    }

    if ($action === 'webhook') {
        $txn    = trim($input['transaction_id'] ?? '');
        $status = strtoupper(trim($input['status'] ?? ''));
        $paidAt = trim($input['paid_at'] ?? '') !== '' ? trim($input['paid_at']) : $now;

        if ($txn === '') {
            echo json_encode(['success' => false, 'message' => 'transaction_id is required.']);
            exit;
        }
        if ($status !== 'COMPLETED' && $status !== 'FAILED') {
            echo json_encode(['success' => false, 'message' => 'Invalid status (expected COMPLETED or FAILED).']);
            exit;
        }

        $row = $db->fetchOne('SELECT * FROM mock_payment_transactions WHERE transaction_id = ?', [$txn]);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Unknown transaction.']);
            exit;
        }

        $db->update('mock_payment_transactions', [
            'status'       => $status === 'COMPLETED' ? 'completed' : 'failed',
            'paid_at'      => $status === 'COMPLETED' ? $paidAt : null,
            'raw_response' => json_encode($input),
        ], 'id = ?', [$row['id']]);

        $requestId = (int) $row['request_id'];
        if ($requestId && $status === 'COMPLETED') {
            $req = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
            if ($req && in_array($req['document_status'], ['Awaiting_Payment', 'Pending_Clearance', 'Processing', 'Rejected'], true)) {
                if ($req['document_status'] === 'Pending_Clearance') {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Request still pending clearance; payment cannot complete.',
                        'data'    => ['blocked' => true],
                    ]);
                    exit;
                }
                $db->update('document_requests', [
                    'paid_at'     => $paidAt,
                    'payment_ref' => $txn,
                ], 'id = ?', [$requestId]);
                if ($req['document_status'] === 'Awaiting_Payment') {
                    $db->update('document_requests', ['document_status' => 'Processing'], 'id = ?', [$requestId]);
                    $db->insert('document_request_events', [
                        'request_id' => $requestId,
                        'status'     => 'Processing',
                        'note'       => 'Payment completed (' . $txn . ')',
                        'created_by' => $_SESSION['user_id'] ?? null,
                        'created_at' => $now,
                    ]);
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Payment ' . strtolower($status) . '.',
            'data'    => ['transaction_id' => $txn, 'status' => $status, 'paid_at' => $status === 'COMPLETED' ? $paidAt : null],
        ]);
        exit;
    }

    if ($action === 'cod_collected') {
        // Cash on Delivery — the student hands the amount to the courier when
        // the document arrives. Registrar/admin triggers this at hand-off.
        $requestId = (int) ($input['request_id'] ?? 0);
        if (!$requestId) {
            echo json_encode(['success' => false, 'message' => 'request_id is required.']);
            exit;
        }
        $row = $db->fetchOne(
            "SELECT id FROM mock_payment_transactions
              WHERE request_id = ? AND method = 'Cash_on_Delivery' AND status = 'pending'
              ORDER BY id DESC LIMIT 1",
            [$requestId]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'No pending COD transaction found for this request.']);
            exit;
        }
        $db->update('mock_payment_transactions', [
            'status'       => 'completed',
            'paid_at'      => $now,
            'raw_response' => json_encode(['collected_on' => $now, 'collected_by' => $_SESSION['user_id'] ?? null]),
        ], 'id = ?', [$row['id']]);

        echo json_encode(['success' => true, 'message' => 'COD payment collected.', 'data' => ['paid_at' => $now]]);
        exit;
    }

    if ($action === 'status') {
        $txn = trim($input['transaction_id'] ?? ($_GET['transaction_id'] ?? ''));
        if ($txn === '') {
            echo json_encode(['success' => false, 'message' => 'transaction_id is required.']);
            exit;
        }
        $row = $db->fetchOne(
            'SELECT transaction_id, status, method, due_on, paid_at, amount, currency FROM mock_payment_transactions WHERE transaction_id = ?',
            [$txn]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Unknown transaction.']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    json_error($e, 'Unable to process the mock payment.');
}
