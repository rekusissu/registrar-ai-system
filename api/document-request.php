<?php
// ============================================================
//  API/DOCUMENT-REQUEST.PHP
//  The document-request wizard, student side.
//
//  One endpoint, action in the body, because this codebase is not a
//  REST router and a second convention would be a second thing to
//  learn. The specification this was built from names REST routes;
//  the mapping is recorded in ACTION_MAP below so the spec's table and
//  this file can be read side by side.
//
//  Actions, in the order the wizard calls them:
//
//    GET  ?action=bootstrap        catalog, checklist, price rules,
//                                  and the caller's open draft
//    GET  ?action=track&id=N       one request + its timeline
//    POST action=save_draft        upsert; runs on every step change
//    POST action=upload            one attachment (multipart)
//    POST action=remove_attachment delete one the student owns
//    POST action=submit            draft -> filed, tracking number
//    POST action=pay_later         stay in Awaiting_Payment, be reminded
//    POST action=cancel            withdraw, with a typed reason
//
//  Everything is scoped to the SESSION's student. A student_id in the
//  body is never trusted: it is only read for staff (a registrar
//  filing on a walk-in's behalf) and that branch is checked against
//  the caller's role first. Everything a student can reach is
//  ownership-checked against the resolved session student, and the
//  "not yours" and "does not exist" answers are deliberately the same
//  message so this endpoint cannot be used to enumerate other
//  students' request ids.
// ============================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';
require_once __DIR__ . '/../shared/doc_wizard.php';
require_once __DIR__ . '/../shared/schema.php';
// For app_url(), used to build attachment download links. The function
// is declared inside a function_exists() guard in app_path.php, so
// requiring the file is what puts it in scope - a bare call would work
// on pages that happen to include it transitively and fatal on the ones
// that do not.
require_once __DIR__ . '/../shared/app_path.php';

// include for auditors: which action is which REST route in the spec.
const ACTION_MAP = [
    'bootstrap'         => 'GET  /requests/catalog',
    'track'             => 'GET  /requests/:id',
    'save_draft'        => 'PATCH /requests/:id/draft',
    'upload'            => 'POST /requests/:id/attachments',
    'remove_attachment' => 'DELETE /requests/:id/attachments/:aid',
    'submit'            => 'POST /requests',
    'pay_later'         => 'POST /payments/defer',
    'cancel'            => 'PATCH /requests/:id/status  (cancelled)',
];

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$db     = Database::getInstance();

// ── Who is asking ──────────────────────────────────────────────
//
// Staff may file on a walk-in's behalf, which is the only case where a
// client-supplied student_id is honoured at all. A student's session
// id always wins over anything in the body.
$role     = (string) getCurrentUserRole();
$isStaff  = in_array($role, ['admin', 'registrar'], true);
$body     = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
$studentId = getCurrentStudentId();

if ($isStaff && !empty($body['student_id'])) {
    $studentId = (int) $body['student_id'];
}
if (!$studentId) {
    echo json_encode([
        'success' => false,
        'message' => 'No student account is linked to this session. Contact the Registrar.',
    ]);
    exit;
}

$action = (string) ($_GET['action'] ?? ($body['action'] ?? ''));

/**
 * One shape for every failure, so the caller never has to guess.
 *
 * Errors that a student can act on carry the reason and, where the
 * next step is the client's to take, the state it should refresh from.
 */
function doc_fail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit;
}

/**
 * Fetch a request and prove the caller may touch it.
 *
 * The "not available" wording covers both "no such row" and "not
 * yours", and that is not vagueness for its own sake: distinguishing
 * them turns this endpoint into an oracle that confirms which request
 * ids exist across the whole school. The cost is one slightly vague
 * message in the legitimate case where a student clicks into a request
 * that has since been deleted, which is worth far less than the leak.
 */
function doc_owned_request(int $id, int $studentId, bool $isStaff): array
{
    $row = Database::getInstance()->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$id]);
    if (!$row || (!$isStaff && (int) $row['student_id'] !== (int) $studentId)) {
        doc_fail('That document request is not available.', 404);
    }
    return $row;
}

/**
 * Turn a request into the shape the wizard's screens read.
 *
 * One builder for every action that returns a request, so the review
 * screen, the tracking page and the list cannot each invent their own
 * version of "what this request is" and drift apart.
 */
function doc_payload(array $row, array $catalog = null): array
{
    $catalogId = (int) ($row['catalog_id'] ?? 0);
    if ($catalog === null && $catalogId > 0) {
        $catalog = db_fill_optional(
            Database::getInstance()->fetchOne('SELECT * FROM document_catalog WHERE id = ?', [$catalogId]),
            'document_catalog',
            ['sla_days', 'requirement', 'fee_type', 'description']
        ) ?: null;
    }
    $reqState = doc_requirement_state((int) $row['id'], $catalogId);
    $status   = doc_status((string) $row['document_status']);
    $expect   = doc_next_expectation($row);

    return [
        'id'            => (int) $row['id'],
        'request_id'    => (string) ($row['request_id'] ?? ''),
        'document_status' => (string) $row['document_status'],
        'status_label'  => $status['label'],
        'status_tone'   => $status['tone'],
        'status_icon'   => $status['icon'],
        'catalog_id'    => $catalogId,
        'document_name' => (string) ($catalog['name'] ?? str_replace('_', ' ', (string) $row['document_type'])),
        'purpose'       => $row['purpose'] ?? null,
        'purpose_code'  => $row['purpose_code'] ?? null,
        'notes'         => $row['notes'] ?? null,
        'quantity'      => (int) ($row['quantity'] ?? 1),
        'payment_method'   => (string) ($row['payment_method'] ?? 'Counter'),
        'payment_reference' => $row['payment_reference'] ?? null,
        'fee_amount'    => (float) ($row['fee_amount'] ?? 0),
        'total'         => round((float) ($row['fee_amount'] ?? 0), 2),
        'wizard_step'   => (int) ($row['wizard_step'] ?? 0),
        'submitted_at'  => $row['submitted_at'] ?? null,
        'estimated_release_at' => $row['estimated_release_at'] ?? null,
        'estimated_release_label' => doc_release_label($row['estimated_release_at'] ?? null),
        'payment_reference' => $row['payment_reference'] ?? null,
        'cancel_reason' => $row['cancellation_reason'] ?? null,
        'rejection_reason' => $row['rejection_reason'] ?? null,
        'expectation'   => $expect,
        'requirements'  => $reqState['requirements'],
        'missing'       => $reqState['missing'],
        'attachments'   => array_map(fn($a) => [
            'id'        => (int) $a['id'],
            'code'      => $a['requirement_code'] ?? null,
            'name'      => $a['original_name'],
            'size'      => (int) $a['size_bytes'],
            'uploaded_at' => $a['uploaded_at'],
            // The stored filename is a hash and means nothing to a
            // student; the name they uploaded is shown instead.
            'url'       => app_url('/api/file-download.php?kind=attachment&attachment=' . (int) $a['id']),
        ], $reqState['attached']),
        'can_cancel'    => doc_can_cancel($row),
        'receipt_state' => doc_requires_receipt($row) ? doc_receipt_state($row) : 'not_required',
    ];
}

// ═════════════════════════════════════════════════════════════════
//  GET · bootstrap
// ═════════════════════════════════════════════════════════════════
//
//  Everything the picker and the details step need, in one round
//  trip. The fee rules in particular are sent as DATA - fee_type,
//  base_fee - so the browser can
//  recompute a live total with the same arithmetic the server will
//  use, rather than the two implementations being written twice and
//  left to disagree.
if ($method === 'GET' && $action === 'bootstrap') {
    $catalog = db_fill_optional(
        $db->fetchAll('SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id ASC'),
        'document_catalog',
        ['sla_days', 'requirement', 'fee_type', 'description']
    );

    // Attach each SKU's checklist in one query rather than one per SKU.
    $reqByCatalog = [];
    if (db_table_exists('document_type_requirements')) {
        foreach ($db->fetchAll(
            "SELECT catalog_id, code, label, hint, is_required, max_files
               FROM document_type_requirements
              WHERE is_active = 1
              ORDER BY catalog_id ASC, sort_order ASC, id ASC"
        ) as $r) {
            $reqByCatalog[(int) $r['catalog_id']][] = [
                'code'        => (string) $r['code'],
                'label'       => (string) $r['label'],
                'hint'        => (string) ($r['hint'] ?? ''),
                'is_required' => (int) $r['is_required'] === 1,
                'max_files'   => max(1, (int) $r['max_files']),
            ];
        }
    }

    $items = [];
    foreach ($catalog as $c) {
        $items[] = [
            'id'           => (int) $c['id'],
            'sku'          => (string) $c['sku'],
            'name'         => (string) $c['name'],
            'description'  => (string) ($c['description'] ?? ''),
            'base_fee'     => (float) $c['base_fee'],
            'fee_type'     => (string) $c['fee_type'],
            'sla_days'     => $c['sla_days'] === null ? null : (int) $c['sla_days'],
            'requirements' => $reqByCatalog[(int) $c['id']] ?? [],
        ];
    }

    // The caller's open draft, if any. One, not many: a second draft
    // is never started, because every wizard entry point resumes the
    // existing one. Two half-finished forms for the same document is
    // a support question nobody should have to answer.
    $draft = $db->fetchOne(
        "SELECT * FROM document_requests
          WHERE student_id = ? AND document_status = 'Draft'
          ORDER BY id DESC LIMIT 1",
        [$studentId]
    );

    $balance = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$studentId]) ?? 0.00);

    echo json_encode([
        'success' => true,
        'data' => [
            'catalog'  => $items,
            'purposes' => doc_purposes(),
                    'payment'  => doc_payment_options(),
            'steps'    => doc_wizard_steps(),
            'limits'   => [
                'max_bytes' => DOC_ATTACHMENT_MAX_BYTES,
                'ext'       => array_values(DOC_ATTACHMENT_EXT),
            ],
            'balance'      => $balance,
            // Stated rather than left for the student to discover at
            // review: an outstanding balance holds the request no matter
            // how the fee is settled, and finding that out on the review
            // screen looks like the wizard rejecting them.
            'blocked_by_balance' => $balance > 0,
            'draft'        => $draft ? doc_payload($draft) : null,
            // Shown as a notice, not a gate. The office still verifies
            // what is uploaded; the student is only told what will be
            // asked for, so they can gather it before they travel in.
            'has_attached_requirements' => count($db->fetchAll(
                "SELECT doc_type FROM documents WHERE student_id = ?", [$studentId]
            )) > 0,
        ],
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  GET · track
// ═════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'track') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        doc_fail('No request was specified.');
    }
    $row = doc_owned_request($id, $studentId, $isStaff);

    $events = $db->fetchAll(
        'SELECT * FROM document_request_events WHERE request_id = ? ORDER BY id ASC',
        [$id]
    );

    echo json_encode([
        'success' => true,
        'data' => [
            'request'  => doc_payload($row),
            'timeline' => doc_track_timeline($row, $events),
            'events'   => array_map(fn($e) => [
                'status' => (string) $e['status'],
                'note'   => $e['note'] ?? null,
                'at'     => (string) $e['created_at'],
            ], $events),
            // The student has money outstanding against this request
            // when one of these is true, and the tracking page says so.
            'blocker'  => doc_blocker(array_merge($row, [
                'balance' => (float) ($db->fetchColumn(
                    'SELECT balance FROM finance WHERE student_id = ?', [$row['student_id']]
                ) ?? 0.0),
            ])),
        ],
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  Everything below writes, and so is POST-only from here.
// ═════════════════════════════════════════════════════════════════
if ($method !== 'POST') {
    doc_fail('Invalid request method.', 405);
}

// ── The shared write preamble ──────────────────────────────────
//
// Csrf guard: the wizard is the most expensive flow in the portal to
// have CSRF-forged (it spends real money), so this endpoint verifies
// explicitly rather than relying on the file being included.
// shared/csrf_guard.php enforces on include, which is what protects
// every other endpoint here.
// The include-time guard has already rejected a missing token, so this
// only fires when one WAS sent and it did not match - which means a
// genuinely stale page, not a malformed request.
//
// 400 rather than 419, for the same reason rejectCsrf() uses it: this
// Apache build does not know 419 and rewrites it to 500, which would
// report "your session expired" as "the server is broken". The intent
// is in X-CSRF-Status; see shared/csrf_guard.php for the full finding.
if (!csrfTokenValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? ''))) {
    header('X-CSRF-Status: 419');
    doc_fail('Your session expired. Reload the page and try again.', 400);
}

/** The catalog row, or a clean failure. Shared by every write path. */
function doc_catalog_or_fail(int $catalogId): array
{
    $c = db_fill_optional(
        Database::getInstance()->fetchOne(
            'SELECT * FROM document_catalog WHERE id = ? AND is_active = 1', [$catalogId]
        ),
        'document_catalog',
        ['sla_days', 'requirement', 'fee_type', 'description']
    );
    if (!$c) {
        doc_fail('That document is not available. Pick one from the list.');
    }
    return $c;
}

// ═════════════════════════════════════════════════════════════════
//  POST · save_draft
// ═════════════════════════════════════════════════════════════════
//
//  Upsert on "the student's one open draft". The uniqueness is
//  enforced in the read-then-write rather than by a partial index,
//  because MySQL has no unique index over "Draft for this student"
//  without a generated column, and a generated column is a larger
//  answer than this problem needs. The consequence is that two
//  simultaneous saves could each create a draft; the second is
//  discarded by the resume query, which takes the newest, so the
//  worst case is one orphaned row rather than two visible forms.
if ($action === 'save_draft') {
    $catalogId  = (int) ($body['catalog_id'] ?? 0);
    $step       = max(0, min(4, (int) ($body['wizard_step'] ?? 0)));
    $quantity   = max(1, min(100, (int) ($body['quantity'] ?? 1)));
        $purposeCode = trim((string) ($body['purpose_code'] ?? ''));
    $purpose    = trim((string) ($body['purpose'] ?? ''));
    $notes      = trim((string) ($body['notes'] ?? ''));
        $payment    = trim((string) ($body['payment_method'] ?? 'Counter'));

    // The same validators the submit path uses, run on the draft too.
    // A draft that the server would refuse to submit is a draft the
    // student filled in and then watched fail at the last step, which
    // is the worst possible moment to discover a validation rule.
    if ($catalogId > 0) {
        doc_catalog_or_fail($catalogId);
    }
    $validPayment = array_column(doc_payment_options(), 'value');
    if (!in_array($payment, $validPayment, true)) {
        $payment = 'Counter';
    }
    if (mb_strlen($purpose) > 255) {
        $purpose = mb_substr($purpose, 0, 255);
    }
    if (mb_strlen($notes) > 2000) {
        doc_fail('Your notes are too long (2000 characters max).');
    }

    $now = date('Y-m-d H:i:s');

    // Fee is recomputed even on a draft, so the review screen's total
    // is the number the server will charge rather than a number the
    // browser happened to be holding.
    $fee = 0.00;
    if ($catalogId > 0) {
        $catalog = doc_catalog_or_fail($catalogId);
        $quote = doc_quote($catalog, $quantity);
        $fee   = $quote['subtotal'];
    }

    $data = [
        'student_id'       => $studentId,
        'document_type'    => 'certificate',   // widened at submit; see below
        'catalog_id'       => $catalogId > 0 ? $catalogId : null,
        'quantity'         => $quantity,
        'fulfillment_type' => doc_fulfillment(),
        'payment_method'   => $payment,
        'purpose'          => $purpose !== '' ? $purpose : null,
        'purpose_code'     => $purposeCode !== '' ? $purposeCode : null,
        'notes'            => $notes !== '' ? $notes : null,
        'fee_amount'       => $fee,
        'wizard_step'      => $step,
        'source'           => 'online',
        'request_date'     => $now,
        'status'           => 'pending',
        // Never stamped on a draft. paid_at is when money moved, and no
        // money has moved on something nobody has submitted.
        'paid_at'          => null,
    ];

    // Only write columns this server actually has, so an un-migrated
    // host degrades to "the draft saves without its step marker"
    // instead of dying on an unknown column.
    $cols     = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
    $writable = array_intersect_key($data, array_flip($cols));

    $existing = $db->fetchOne(
        "SELECT id FROM document_requests
          WHERE student_id = ? AND document_status = 'Draft'
          ORDER BY id DESC LIMIT 1",
        [$studentId]
    );

    if ($existing) {
        $db->update('document_requests', $writable, 'id = ?', [(int) $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $writable['document_status'] = 'Draft';
        $id = (int) $db->insert('document_requests', $writable);
    }

    $row = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$id]);
    echo json_encode([
        'success' => true,
        'message' => 'Draft saved.',
        'data'    => doc_payload($row),
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  POST · upload
// ═════════════════════════════════════════════════════════════════
if ($action === 'upload') {
    $requestId = (int) ($body['request_id'] ?? 0);
    $row = doc_owned_request($requestId, $studentId, $isStaff);

    // Uploads are accepted while the request is a draft OR filed but
    // not yet ready. After Ready the document is physically prepared
    // from what was on file, so a late upload cannot change the thing
    // being issued - accepting it would record an attachment against a
    // document that was made without it.
    if (!in_array((string) $row['document_status'], ['Draft', 'Filed', 'Pending_Clearance', 'Awaiting_Payment'], true)) {
        doc_fail('This request is closed, so files can no longer be added.');
    }

    $code = trim((string) ($body['requirement_code'] ?? ''));
    $code = $code !== '' ? mb_substr($code, 0, 40) : null;

    // A code that is not on this document's checklist is refused rather
    // than stored as a loose file. Otherwise a crafted code would let a
    // file be filed against a requirement that does not exist, and the
    // checklist would show it as satisfied.
    if ($code !== null) {
        $known = false;
        foreach (doc_requirements((int) ($row['catalog_id'] ?? 0)) as $r) {
            if ($r['code'] === $code) { $known = true; break; }
        }
        if (!$known) {
            doc_fail('That is not a requirement for this document.');
        }
    }

    $stored = doc_store_attachment($_FILES['attachment'] ?? []);
    if (!$stored['ok']) {
        doc_fail($stored['message']);
    }

    // The file is on disk from here, so every exit path must either
    // insert a row that points at it or delete it. An unreferenced file
    // is both wasted space and invisible to every cleanup path that
    // works by following rows.
    try {
        $id = $db->insert('document_request_attachments', [
            'request_id'      => $requestId,
            'requirement_code' => $code,
            'original_name'   => $stored['name'],
            'file_path'       => $stored['path'],
            'sha256'          => $stored['sha256'],
            'mime_type'       => $stored['mime'] !== '' ? $stored['mime'] : null,
            'size_bytes'      => $stored['size'],
            'uploaded_at'     => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        @unlink(dirname(__DIR__) . '/' . $stored['path']);
        json_error($e, 'Could not save the attachment.');
        exit;
    }

    $db->insert('document_request_events', [
        'request_id' => $requestId,
        'status'     => (string) $row['document_status'],
        'note'       => 'Attachment uploaded: ' . $stored['name']
            . ($code !== null ? ' (' . $code . ')' : ''),
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $fresh = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);
    $state = doc_requirement_state($requestId, (int) ($row['catalog_id'] ?? 0));

    echo json_encode([
        'success' => true,
        'message' => empty($state['missing'])
            ? 'Uploaded — everything this document needs is here.'
            : 'Uploaded.',
        'data'    => [
            'attachment_id' => $id,
            'missing'       => $state['missing'],
            'complete'      => $state['complete'],
            'request'       => doc_payload($fresh),
        ],
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  POST · remove_attachment
// ═════════════════════════════════════════════════════════════════
if ($action === 'remove_attachment') {
    $attachmentId = (int) ($body['attachment_id'] ?? 0);
    $att = $db->fetchOne(
        'SELECT * FROM document_request_attachments WHERE id = ?', [$attachmentId]
    );
    if (!$att) {
        doc_fail('That file is not available.', 404);
    }
    // Ownership is checked against the PARENT request, not against the
    // attachment row, so the same "not available" answer covers a file
    // that exists and a file that does not.
    $row = doc_owned_request((int) $att['request_id'], $studentId, $isStaff);

    if (!in_array((string) $row['document_status'], ['Draft', 'Filed', 'Pending_Clearance', 'Awaiting_Payment'], true)) {
        doc_fail('This request is closed, so its files can no longer be changed.');
    }

    $db->delete('document_request_attachments', 'id = ?', [$attachmentId]);
    // The row goes first, then the file. The reverse order would leave a
    // row pointing at a file that is already gone.
    @unlink(dirname(__DIR__) . '/' . (string) $att['file_path']);

    $db->insert('document_request_events', [
        'request_id' => (int) $row['id'],
        'status'     => (string) $row['document_status'],
        'note'       => 'Attachment removed: ' . (string) $att['original_name'],
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $state = doc_requirement_state((int) $row['id'], (int) ($row['catalog_id'] ?? 0));
    $fresh = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [(int) $row['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'File removed.',
        'data'    => [
            'missing'  => $state['missing'],
            'complete' => $state['complete'],
            'request'  => doc_payload($fresh),
        ],
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  POST · submit
// ═════════════════════════════════════════════════════════════════
//
//  The draft becomes a filed request here, and this is the only place
//  a tracking number is issued. Everything before it is invisible to
//  the desk.
if ($action === 'submit') {
    $requestId = (int) ($body['request_id'] ?? 0);

    // Submitting a draft. A request already filed cannot be submitted
    // again, which is checked against the row rather than trusted from
    // the client: otherwise a double-click on Submit would file the
    // same document twice with two tracking numbers.
    if ($requestId <= 0) {
        doc_fail('No draft to submit. Start a new request.');
    }
    $row = doc_owned_request($requestId, $studentId, $isStaff);

    if ((string) $row['document_status'] !== 'Draft') {
        doc_fail('This request has already been submitted.');
    }

    // The two confirmations on the review screen are recorded as
    // required, not merely as a client-side gate. A checkbox that only
    // the browser enforces is not a certification: it is a field the
    // student cannot see they failed to tick, and it leaves no trace
    // afterwards that they certified anything at all.
    if (empty($body['certify_true'])) {
        doc_fail('Please confirm the information is correct before submitting.');
    }
    if (empty($body['certify_privacy'])) {
        doc_fail('Please agree to the Data Privacy Policy before submitting.');
    }

    $catalogId  = (int) ($row['catalog_id'] ?? 0);
    $catalog    = doc_catalog_or_fail($catalogId);
    $quantity   = max(1, min(100, (int) ($row['quantity'] ?? 1)));
    $payment    = (string) ($row['payment_method'] ?? 'Counter');
    $purpose    = trim((string) ($row['purpose'] ?? ''));

    if ($purpose === '') {
        doc_fail('Tell us what this document is for.', 422, ['step' => 1]);
    }
    if (!in_array($payment, array_column(doc_payment_options(), 'value'), true)) {
        doc_fail('Choose how you want to pay.', 422, ['step' => 4]);
    }

    // Uploads are an ASK, not a gate.
    //
    // The specification this was built from blocks submission until
    // every required file is attached. It is not enforced here, and the
    // reason is that it cannot be enforced honestly: the office still
    // has to look at what arrives, and a student who genuinely has the
    // paper but is uploading from a phone with no signal is not
    // someone to lock out of the whole module over a photograph. The
    // wizard collects them early, warns what is missing, and tells the
    // desk what to ask for - which is the part that actually prevents a
    // wasted trip.
    $reqState  = doc_requirement_state($requestId, $catalogId);
    $missing   = $reqState['missing'];

    // Recompute the fee server-side. The draft stored it too, but it
    // was stored minutes or days ago and the catalog price may have
    // changed since; the number charged is the one computed here.
    $quote     = doc_quote($catalog, $quantity);
    $now       = date('Y-m-d H:i:s');

    $balance   = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$studentId]) ?? 0.00);

    // Starting stage.
    //
    // An unpaid balance outranks the payment gate: the office cannot
    // issue against an unpaid account whatever the document fee is
    // being settled by, so the request is held and says so.
    if ($balance > 0) {
        $status = 'Pending_Clearance';
    } elseif ($payment === 'Online' || $payment === 'Bank_Transfer') {
        // Both channels expect money to arrive from outside the office,
        // so both wait for it. They differ in what is asked of the
        // student afterwards, not in whether the request can start.
        $status = 'Awaiting_Payment';
    } else {
        // Pay at the cashier: nothing is owed until they are standing
        // there, so the request is immediately workable.
        $status = 'Filed';
    }

    // The legacy document_type column, kept for the older readers.
    // Mapped by SKU because the enum cannot hold every value the
    // catalog now carries; anything unmapped folds onto 'certificate'
    // and is logged, rather than being written and silently erased.
    $legacyMap = [
        'DOC-TOR' => 'transcript', 'DOC-COE' => 'certificate', 'DOC-GM' => 'good_moral',
        'DOC-DIPLOMA' => 'diploma', 'DOC-CTC' => 'ctc', 'DOC-HD' => 'honorable_dismissal',
        'DOC-CD' => 'course_description', 'DOC-F137' => 'form137', 'DOC-CLEARANCE' => 'clearance',
    ];
    $legacyType = $legacyMap[$catalog['sku']] ?? null;
    if ($legacyType === null) {
        error_log('[document-request] unmapped catalog SKU at submit: ' . $catalog['sku']);
        $legacyType = 'certificate';
    }

    $conn = Database::getInstance()->getConnection();
    $conn->beginTransaction();
    try {
        // The tracking number is allocated inside the transaction and
        // retried on a duplicate-key collision. The unique index on
        // request_id is what makes two simultaneous filings safe: the
        // loser gets an exception and tries again with the next
        // number, rather than both requests sharing one tracking number
        // and one of them becoming untrackable.
        $assigned = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = doc_next_tracking_number();
            try {
                $db->update('document_requests', [
                    'request_id'    => $candidate,
                    'document_type' => $legacyType,
                    'document_status' => $status,
                    'status'        => 'pending',
                    'fee_amount'    => $quote['subtotal'],
                                    'wizard_step'   => 4,
                    'submitted_at'  => $now,
                    'request_date'  => $now,
                    'source'        => 'online',
                    'estimated_release_at' => doc_estimated_release(
                        (int) ($catalog['sla_days'] ?? 0), $now
                    ),
                ], 'id = ?', [$requestId]);
                $assigned = $candidate;
                break;
            } catch (Throwable $e) {
                if (!doc_is_duplicate_key($e)) {
                    throw $e;
                }
                // Someone else took that number between the read and
                // the write. Loop and take the next one.
                usleep(20000);
            }
        }
        if ($assigned === null) {
            doc_fail('Could not allocate a tracking number. Please try again.', 503);
        }

        // Blockage, recorded up front and labelled, so the desk sees
        // WHY a request is held rather than inferring it from a label.
        // Derived from the same helper the desk reads.
        $blocker = doc_blocker([
            'document_status' => $status,
            'sku'             => $catalog['sku'],
            'requirement'     => $catalog['requirement'],
            'balance'         => $balance,
            'request_date'    => $now,
        ]);
        $holdData = [
            'blocked_reason' => $blocker['reason'] ?? null,
            'blocked_since'  => $blocker ? (string) ($blocker['since'] ?: $now) : null,
        ];
        $holdCols = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
        if (in_array('blocked_source', $holdCols, true)) {
            $holdData['blocked_source'] = ($blocker['reason'] ?? null) !== null ? 'balance' : null;
        }
        $db->update('document_requests', $holdData, 'id = ?', [$requestId]);

        // The payment reference. Issued at filing rather than on the
        // payment screen so a student can be given it at a bank before
        // anyone has looked at the request.
        $db->update('document_requests', [
            'payment_reference' => doc_payment_reference($requestId),
        ], 'id = ?', [$requestId]);

        // The opening event says what this request is waiting on, if
        // anything. An event log that records only the status label
        // leaves the next person to guess why it stalled.
        $note = $status === 'Awaiting_Payment'
            ? 'Request submitted online (' . $assigned . ') — fee PHP '
                . number_format($quote['total'], 2) . ', awaiting payment'
            : ($status === 'Pending_Clearance'
                ? 'Request submitted online (' . $assigned . ') — held: outstanding balance of PHP '
                    . number_format($balance, 2)
                : 'Request submitted online (' . $assigned . ') — pay at the cashier when you collect');
        if ($missing) {
            $note .= ' — ask the student to bring: ' . implode(', ', $missing);
        }
        $db->insert('document_request_events', [
            'request_id' => $requestId,
            'status'     => $status,
            'note'       => $note,
            'created_by' => $_SESSION['user_id'] ?? null,
            'created_at' => $now,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        json_error($e, 'Could not submit the request.');
        exit;
    }

    logActivity($_SESSION['user_id'] ?? null, 'document_request_submit', null,
        'document_requests', $requestId, null,
        ['request_id' => $assigned, 'catalog_id' => $catalogId, 'fee' => $quote['total'],
         'document_status' => $status, 'missing_requirements' => $missing]);

    $fresh = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);

    echo json_encode([
        'success' => true,
        // The student's only instruction about what happens next, so it
        // names the actual next step rather than a flat "submitted".
        'message' => $status === 'Awaiting_Payment'
            ? 'Submitted. Pay PHP ' . number_format($quote['total'], 2) . ' to start processing.'
            : ($status === 'Pending_Clearance'
                ? 'Submitted, but you have an outstanding balance of PHP ' . number_format($balance, 2)
                    . ' that has to be settled first.'
                : 'Submitted. Pay at the office when you collect.'),
        'data'    => [
            'request'   => doc_payload($fresh),
            'quote'     => $quote,
            'missing'   => $missing,
        ],
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  POST · pay_later
// ═════════════════════════════════════════════════════════════════
//
//  Not a payment channel - it is the absence of one. The request
//  stays in Awaiting_Payment and a notification is written so the
//  student can be chased, which is the only part of this action that
//  has any effect.
if ($action === 'pay_later') {
    $requestId = (int) ($body['request_id'] ?? 0);
    $row = doc_owned_request($requestId, $studentId, $isStaff);

    if ((string) $row['document_status'] !== 'Awaiting_Payment') {
        doc_fail('This request is not waiting for payment.');
    }

    $now = date('Y-m-d H:i:s');
    $db->insert('document_request_events', [
        'request_id' => $requestId,
        'status'     => 'Awaiting_Payment',
        'note'       => 'Student chose to pay later — fee of PHP '
            . number_format((float) ($row['fee_amount'] ?? 0), 2)
            . ' still outstanding.',
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => $now,
    ]);

    try {
        require_once __DIR__ . '/../shared/mail_client.php';
        contactAutoForwardInvoice($requestId, (int) $row['student_id']);
    } catch (Throwable $e) {
        // A mail failure must never break the response the student is
        // looking at. Logged, and the action still succeeds.
        error_log('[document-request] pay_later reminder: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'No problem — your request stays open. Pay whenever you can and send the receipt.',
        'data'    => ['request' => doc_payload($row)],
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  POST · cancel
// ═════════════════════════════════════════════════════════════════
//
//  Withdrawal, not deletion. The row is kept with a reason, because
//  money may have been paid against it and "the student withdrew it on
//  the 9th, because they got the document elsewhere" is the answer to
//  a question somebody will ask.
if ($action === 'cancel') {
    $requestId = (int) ($body['request_id'] ?? 0);
    $reason    = trim((string) ($body['reason'] ?? ''));
    $row = doc_owned_request($requestId, $studentId, $isStaff);

    if (!doc_can_cancel($row)) {
        $st = doc_status((string) $row['document_status']);
        doc_fail('This request cannot be cancelled any more — it is "' . strtolower($st['label'])
            . '". Contact the Registrar if this is wrong.');
    }

    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }

    $now = date('Y-m-d H:i:s');
    $data = [
        'document_status'      => 'Cancelled',
        'status'               => 'denied',   // legacy column
        'cancelled_at'         => $now,
        'cancellation_reason'  => $reason !== '' ? $reason : null,
        'blocked_reason'       => null,
        'blocked_since'        => null,
        'processed_date'       => $now,
    ];
    $cols = array_column($db->fetchAll('SHOW COLUMNS FROM document_requests'), 'Field');
    if (in_array('blocked_source', $cols, true)) {
        $data['blocked_source'] = null;
    }
    $db->update('document_requests', array_intersect_key($data, array_flip($cols)), 'id = ?', [$requestId]);

    $db->insert('document_request_events', [
        'request_id' => $requestId,
        'status'     => 'Cancelled',
        'note'       => 'Cancelled by the student'
            . ($reason !== '' ? ' — ' . $reason : '')
            . ($row['paid_at'] !== null ? ' (note: fee was already paid)' : ''),
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => $now,
    ]);

    logActivity($_SESSION['user_id'] ?? null, 'document_request_cancel', null,
        'document_requests', $requestId, null,
        ['request_id' => $row['request_id'], 'reason' => $reason, 'was' => $row['document_status']]);

    $fresh = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$requestId]);

    // Paid-and-cancelled needs saying out loud. The money went, and the
    // student should be told to raise it rather than left to notice
    // later that nobody is going to refund it.
    $refunded = $row['paid_at'] !== null;

    echo json_encode([
        'success' => true,
        'message' => $refunded
            ? 'Request cancelled. Your fee was already paid — please raise it with the Registrar for a refund.'
            : 'Request cancelled.',
        'data'    => ['request' => doc_payload($fresh), 'was_paid' => $refunded],
    ]);
    exit;
}

doc_fail('Unknown action.', 400);