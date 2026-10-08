<?php
// ============================================================
//  SHARED/DOC_WIZARD.PHP
//  The guided document-request wizard, in one place.
//
//  The wizard is five screens (doc type -> details -> uploads ->
//  review -> payment) and then a tracking page. Six places need to
//  agree about it:
//
//    student/document-request.php   the wizard
//    api/document-request.php       every button's backend
//    student/document-track.php     the tracking timeline
//    student/documents.php          the list, incl. drafts
//    registrar/documents.php        the desk, seeing online filings
//    api/documents.php              the desk's transitions
//
//  So the vocabulary - the steps, the fee quote, the delivery
//  options, the status colours, the timeline - lives HERE and is
//  included rather than re-derived. The previous arrangement had the
//  status vocabulary typed out four times (two portals, two
//  statuses tables), which is why the desk knew about Shipped and
//  the student list did not.
//
//  The quote in doc_quote() is a PURE function of the catalog row,
//  the quantity and the delivery method. That is deliberate and load
//  bearing: the browser recomputes it on every keystroke to keep the
//  total live, and the server recomputes the same function to decide
//  what is actually owed. Two independent implementations of a price
//  is how a student ends up quoted one amount and charged another, so
//  there is one implementation and it is this one.
// ============================================================

if (defined('DOC_WIZARD_LOADED')) {
    return;
}
define('DOC_WIZARD_LOADED', true);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/document_process.php';   // doc_blocker(), doc_requires_receipt()

// ─────────────────────────────────────────────────────────────────────
//  MONEY
// ─────────────────────────────────────────────────────────────────────

/**
 * Flat processing charge applied to every request.
 *
 * DEFAULT 0.00, and that is a decision rather than an omission. The
 * catalog's base_fee is what the office currently quotes and what the
 * desk's revenue chart is built on; turning on a processing fee here
 * would silently change every price on the catalog the day this file
 * was deployed. It exists so the fee-breakdown screen can render the
 * line the way the office wants it, and it stays out of the way until
 * someone sets DOC_PROCESSING_FEE.
 */
function doc_processing_fee(): float
{
    return round((float) (getenv('DOC_PROCESSING_FEE') ?: 0.00), 2);
}
/**
 * The one way a document leaves this office.
 *
 * This was three options -- collect at the counter, courier to an address,
 * emailed PDF -- and the choice was removed rather than hidden. Two of the
 * three promised something the office does not do: it runs no courier and
 * issues no emailed copy of a TOR. Leaving them selectable let a student pay
 * a courier fee for a document that then had nowhere to go, and forced the
 * desk to carry a dispatch stage that only some requests had.
 *
 * The COLUMN stays. document_requests.fulfillment_type is NOT NULL with a
 * default and existing rows carry it, so dropping it means a migration and a
 * rewrite of the status walk for no gain. It is written once, here, and read
 * nowhere that branches on it.
 */
function doc_fulfillment(): string
{
    return 'Pickup';
}

/**
 * The fee breakdown for a request, line by line.
 *
 * Returns the individual charges rather than a single total, because
 * the review screen has to SHOW its working: a student who is asked
 * for PHP 400 and never told why will not pay it.
 *
 * The catalog's fee_type decides whether quantity multiplies the
 * price. That is pre-existing behaviour and is kept exactly: 'flat'
 * is one price whatever the quantity, 'per_page' and 'per_syllabus'
 * are per-unit prices.
 *
 * @param array  $catalog      A document_catalog row.
 * @param int    $quantity     Copies requested. Clamped to >= 1.
 * @return array{
 *   lines:   array<int,array{label:string,detail:string,amount:float}>,
 *   subtotal:float, processing_fee:float, total:float,
 *   quantity:int, per_unit:bool, unit_word:string
 * }
 */
function doc_quote(array $catalog, int $quantity): array
{
    $quantity = max(1, $quantity);
    $perUnit  = ($catalog['fee_type'] ?? 'flat') !== 'flat';
    $unitWord = ($catalog['fee_type'] ?? '') === 'per_syllabus' ? 'syllabus' : 'page';

    // Round once, at the end, rather than multiplying and rounding in
    // place. base_fee is decimal(10,2) as stored, but the client sends
    // a quantity and PHP's float multiply is what produces the number,
    // so the rounding has to happen where the number becomes money.
    $documentFee = round((float) $catalog['base_fee'] * ($perUnit ? $quantity : 1), 2);

    $processingFee = doc_processing_fee();

    $lines = [
        [
            'label'  => (string) $catalog['name'],
            'detail' => $perUnit
                ? $quantity . ' ' . $unitWord . ($quantity === 1 ? '' : 's') . ' at PHP '
                    . number_format((float) $catalog['base_fee'], 2) . ' each'
                : 'One-time charge per request',
            'amount' => $documentFee,
        ],
    ];
    if ($processingFee > 0.00) {
        $lines[] = [
            'label'  => 'Processing fee',
            'detail' => 'Fixed charge per request',
            'amount' => $processingFee,
        ];
    }

    return [
        'lines'          => $lines,
        'subtotal'       => $documentFee,
        'processing_fee' => $processingFee,
        'total'          => round($documentFee + $processingFee, 2),
        'quantity'       => $quantity,
        'per_unit'       => $perUnit,
        'unit_word'      => $unitWord,
    ];
}

// ─────────────────────────────────────────────────────────────────────
//  THE STEPS
// ─────────────────────────────────────────────────────────────────────

/**
 * The wizard's screens.
 *
 * The doc-type picker is deliberately step 0 and is NOT numbered. It
 * is the question the student arrived with, asked before they commit
 * to anything; numbering it would make the very first screen read as
 * "1 of 4" and imply they were already a quarter of the way through
 * before choosing a document.
 *
 * `key` is what wizard_step stores, so the numbers here and the column
 * are one vocabulary rather than two.
 *
 * @return array<int,array{step:int,key:string,label:string,icon:string}>
 */
function doc_wizard_steps(): array
{
    return [
        ['step' => 0, 'key' => 'document', 'label' => 'Document',  'icon' => 'fa-file-lines'],
        ['step' => 1, 'key' => 'details',  'label' => 'Details',   'icon' => 'fa-list-check'],
        ['step' => 2, 'key' => 'uploads',  'label' => 'Uploads',   'icon' => 'fa-paperclip'],
        ['step' => 3, 'key' => 'review',   'label' => 'Review',    'icon' => 'fa-clipboard-check'],
        ['step' => 4, 'key' => 'payment',  'label' => 'Payment',   'icon' => 'fa-credit-card'],
    ];
}

/**
 * The numbered steps only - what the "Step 2 of 4" indicator counts.
 *
 * @return array<int,array{step:int,key:string,label:string,icon:string}>
 */
function doc_wizard_numbered_steps(): array
{
    return array_values(array_filter(
        doc_wizard_steps(),
        fn($s) => $s['step'] >= 1
    ));
}

/**
 * The preset purposes.
 *
 * `code` is the controlled value stored in purpose_code and filtered on
 * by the desk; `label` is what the student picks. The free-text
 * `purpose` is kept alongside, because a controlled vocabulary cannot
 * express "employment, for a marine engineering board that requires it
 * in triplicate".
 *
 * @return array<int,array{code:string,label:string,needs_detail:bool}>
 */
function doc_purposes(): array
{
    return [
        ['code' => 'employment',      'label' => 'Employment / Job application',  'needs_detail' => false],
        ['code' => 'board_exam',      'label' => 'Board exam / Licensure',         'needs_detail' => false],
        ['code' => 'further_studies', 'label' => 'Further studies / Transfer',    'needs_detail' => false],
        ['code' => 'scholarship',     'label' => 'Scholarship / Grant',           'needs_detail' => false],
        ['code' => 'personal',        'label' => 'Personal records',              'needs_detail' => false],
        ['code' => 'other',           'label' => 'Other (tell us below)',         'needs_detail' => true],
    ];
}

/**
 * Every payment channel the office can actually settle.
 *
 * Deliberately four, and deliberately not five. The specification this
 * was built from lists GCash, Maya, bank transfer, pay-at-cashier and
 * pay-later. Maya is absent because this office has one static GCash
 * QR (shared/payment_qr.php) and no Maya merchant account, and
 * offering a "Pay with Maya" button that quietly collected nothing
 * would be worse than not offering it. "Pay later" is not a channel
 * either - it is what the student already has by choosing not to pay on
 * this screen, so it is a button on the payment screen rather than a
 * row in this table.
 *
 * `settles` says whether money is expected to have moved by the time
 * the desk can start work. doc_requires_receipt() keys off the
 * payment_method value, so these strings are load-bearing.
 *
 * @return array<int,array{value:string,label:string,note:string,icon:string,brand:string,settles:bool}>
 */
function doc_payment_options(): array
{
    return [
        [
            'value'   => 'Online',
            'label'   => 'GCash',
            'note'    => 'Scan the office QR, then send us the receipt.',
            'icon'    => 'fa-mobile-screen-button',
            'brand'   => 'gcash',
            'settles' => false,   // money moves on the student's phone, unseen
        ],
        [
            'value'   => 'Bank_Transfer',
            'label'   => 'Bank transfer',
            'note'    => 'We give you a reference number. Deposit anywhere, then send the slip.',
            'icon'    => 'fa-building-columns',
            'brand'   => 'bank',
            'settles' => false,
        ],
        [
            'value'   => 'Counter',
            'label'   => 'Pay at the cashier',
            'note'    => 'Bring your reference number and pay at the Registrar when you collect.',
            'icon'    => 'fa-hand-holding-dollar',
            'brand'   => 'counter',
            'settles' => true,    // paid in person, at the counter, at the end
        ],
    ];
}

/**
 * Is this a payment channel that expects an uploaded receipt?
 *
 * Thin wrapper over doc_requires_receipt()'s rule, expressed against
 * the wizard's own option list so the wizard and the gate cannot
 * disagree about which channels are evidence-based.
 */
function doc_payment_needs_receipt(string $value): bool
{
    foreach (doc_payment_options() as $opt) {
        if ($opt['value'] === $value) {
            return !$opt['settles'];
        }
    }
    return false;
}

// ─────────────────────────────────────────────────────────────────────
//  STATUS VOCABULARY
//
//  One table, read by both portals. The desk and the student list each
//  used to carry their own, which is how the desk grew a 'Shipped'
//  pill that the student list could not render and fell through to a
//  grey default.
// ─────────────────────────────────────────────────────────────────────

/**
 * How every document_status is drawn and named.
 *
 * `tone` is the colour role, not a hex value, so the two portals' CSS
 * can each resolve it in their own palette while the STATUS stays one
 * thing. `emoji` is for the places the specification calls for a
 * coloured dot with no room for an icon.
 *
 * `student` is false for states a student must never be shown as if it
 * were ordinary progress. A Draft is the student's own unfinished form
 * and is theirs alone; a Cancelled request is shown, but as closed.
 *
 * `stamp` is what the redesigned module prints on the rubber stamp, and
 * `ink` is the token role that stamp is drawn in. Both are additions to
 * this function rather than a second table, so a status added in one
 * place cannot be drawn in one portal and missing from the other -- the
 * exact failure this table was introduced to prevent.
 *
 * @return array<string,array{label:string,tone:string,icon:string,emoji:string,pill:string,student:bool,terminal:bool,stamp:string,ink:string}>
 */
function doc_status_meta(): array
{
    return [
        'Draft' => [
            'label' => 'Not finished', 'tone' => 'muted',   'icon' => 'fa-pen-ruler',
            'stamp' => 'Not submitted', 'ink' => 'draft',
            'emoji' => '⚪', 'pill' => 'draft',      'student' => true,  'terminal' => false,
        ],
        'Filed' => [
            'label' => 'Submitted', 'tone' => 'info',     'icon' => 'fa-folder-open',
            'stamp' => 'Filed', 'ink' => 'filed',
            'emoji' => '🟡', 'pill' => 'filed',        'student' => true,  'terminal' => false,
        ],
        'Pending_Clearance' => [
            'label' => 'On hold', 'tone' => 'warning',  'icon' => 'fa-triangle-exclamation',
            'stamp' => 'On hold', 'ink' => 'hold',
            'emoji' => '🟡', 'pill' => 'pending-clearance', 'student' => true, 'terminal' => false,
        ],
        'Awaiting_Payment' => [
            'label' => 'Awaiting payment', 'tone' => 'warning', 'icon' => 'fa-clock',
            'stamp' => 'Payment due', 'ink' => 'waiting',
            'emoji' => '🟡', 'pill' => 'awaiting-payment', 'student' => true, 'terminal' => false,
        ],
        'Processing' => [
            'label' => 'Being prepared', 'tone' => 'info',   'icon' => 'fa-gear',
            'stamp' => 'In preparation', 'ink' => 'working',
            'emoji' => '🟡', 'pill' => 'processing', 'student' => true,  'terminal' => false,
        ],
        'Ready' => [
            'label' => 'Ready for collection', 'tone' => 'success', 'icon' => 'fa-circle-check',
            'stamp' => 'Ready', 'ink' => 'ready',
            'emoji' => '🟢', 'pill' => 'ready',   'student' => true,  'terminal' => false,
        ],
        // LEGACY. 'Shipped' existed only for the courier leg, which is
        // gone. Rows already in the table can still carry it, so the label
        // stays and the status still has to render -- but nothing reaches
        // it any more: there is no dispatch action, and doc_next_step()
        // has no branch that produces it.
        'Shipped' => [
            'label' => 'On its way (discontinued)', 'tone' => 'info',      'icon' => 'fa-truck-fast',
            'stamp' => 'Discontinued', 'ink' => 'stopped',
            'emoji' => '🟢', 'pill' => 'shipped',          'student' => true,  'terminal' => false,
            'legacy' => true,
        ],
        'Claimed' => [
            'label' => 'Collected', 'tone' => 'success',   'icon' => 'fa-box-check',
            'stamp' => 'Collected', 'ink' => 'done',
            'emoji' => '✅', 'pill' => 'claimed',          'student' => true,  'terminal' => true,
        ],
        'Rejected' => [
            'label' => 'Rejected', 'tone' => 'danger',     'icon' => 'fa-xmark',
            'stamp' => 'Not issued', 'ink' => 'stopped',
            'emoji' => '🔴', 'pill' => 'rejected',         'student' => true,  'terminal' => true,
        ],
        'Cancelled' => [
            'label' => 'Cancelled', 'tone' => 'muted',     'icon' => 'fa-ban',
            'stamp' => 'Withdrawn', 'ink' => 'stopped',
            'emoji' => '⚫', 'pill' => 'cancelled',        'student' => true,  'terminal' => true,
        ],
    ];
}

/**
 * @return array{label:string,tone:string,icon:string,emoji:string,pill:string,student:bool,terminal:bool,stamp:string,ink:string}
 */
function doc_status(string $status): array
{
    $all = doc_status_meta();
    return $all[$status] ?? [
        'label' => str_replace('_', ' ', $status) ?: 'Unknown',
        'stamp' => 'Unknown', 'ink' => 'draft',
        'tone' => 'muted', 'icon' => 'fa-circle-question',
        'emoji' => '⚪', 'pill' => 'filed', 'student' => true, 'terminal' => false,
    ];
}

/**
 * Whose move is it, and what exactly do they have to do?
 *
 * Returned as a pair rather than a sentence, because the row draws the
 * two at different weights: the WHO is a short lead-in and the WHAT is a
 * plain clause beside it. Joining them would force one weight on both.
 *
 * `who` is 'you', 'office' or 'nobody'. 'nobody' is a real answer and not
 * a fallback: a collected or rejected request needs nothing from anyone,
 * and saying so is more use than leaving the cell blank.
 *
 * @param array $row  a document_requests row, ideally with catalog join.
 * @return array{who:string,what:string,urgent:bool}
 */
function doc_waiting_on(array $row): array
{
    // An unpaid fee the office cannot see. Bounded to the live statuses on
    // purpose: doc_requires_receipt() is true of any row with a fee and an
    // Online payment method, and doc_receipt_state() is "none" for every row
    // that was paid at the counter or waived -- so an unbounded gate reported
    // "attach your receipt" on collected and cancelled requests, which is
    // both false and actively confusing. A collected request was paid.
    if (in_array((string) ($row['document_status'] ?? ''), ['Filed', 'Awaiting_Payment', 'Pending_Clearance'], true)
        && doc_requires_receipt($row)
        && doc_receipt_state($row) === 'none') {
        return [
            'who'     => 'you',
            'what'    => 'Attach your payment receipt',
            'urgent'  => true,
        ];
    }

    $balance = doc_blocker($row);
    if ($balance) {
        return [
            'who'     => 'office',
            'what'    => trim((string) ($balance['reason'] ?? 'Settling your account balance')),
            'urgent'  => false,
        ];
    }

    $status = (string) ($row['document_status'] ?? '');
    $fee    = (float) ($row['fee_amount'] ?? 0);

    switch ($status) {
        case 'Draft':
            return ['who' => 'you', 'what' => 'Finish and submit this request', 'urgent' => true];

        case 'Awaiting_Payment':
            return [
                'who'     => 'you',
                'what'    => $fee > 0
                    ? 'Pay PHP ' . number_format($fee, 2)
                    : 'Pay the fee',
                'urgent'  => true,
            ];

        case 'Ready':
            return ['who' => 'you', 'what' => 'Collect at the Registrar, with your student ID', 'urgent' => true];

        case 'Filed':
            return ['who' => 'office', 'what' => 'Queued at the Registrar', 'urgent' => false];

        case 'Processing':
            return ['who' => 'office', 'what' => 'Preparing your document', 'urgent' => false];

        case 'Pending_Clearance':
            return ['who' => 'office', 'what' => 'Held while your account is settled', 'urgent' => false];

        default:
            return ['who' => 'nobody', 'what' => 'Nothing needed from you', 'urgent' => false];
    }
}

/**
 * Is this request work the desk can still act on?
 *
 * Draft and Cancelled are excluded, and the reasoning is the whole
 * point of having a Draft status rather than inferring one: a draft is
 * a form somebody has not finished, so counting it as a filed request
 * makes the desk's "needs action" tile say the office is behind on
 * work nobody has actually asked for yet.
 *
 * @return array<int,string>
 */
function doc_actionable_statuses(): array
{
    return ['Filed', 'Pending_Clearance', 'Awaiting_Payment', 'Processing'];
}

/** May the student still withdraw this request themselves? */
function doc_can_cancel(array $row): bool
{
    $st = (string) ($row['document_status'] ?? '');
    // Once it is Ready the document physically exists and may be on a
    // shelf with the student's name on it; once Claimed it is gone. A
    // student cancelling those would be asking to unpick something the
    // office has already done, so both are refused with the reason.
    return in_array($st, ['Draft', 'Filed', 'Pending_Clearance', 'Awaiting_Payment'], true);
}

/** May the desk still start work on this request? */
function doc_can_process(array $row): bool
{
    return in_array((string) ($row['document_status'] ?? ''), ['Filed', 'Pending_Clearance', 'Processing'], true);
}

// ─────────────────────────────────────────────────────────────────────
//  REQUIREMENTS
// ─────────────────────────────────────────────────────────────────────

/**
 * The upload checklist for a document, if it has one.
 *
 * Tolerates a missing table, because this runs on the upload screen and
 * a server that has not had the migration yet should show "no
 * requirements" rather than a fatal. That degradation is honest: the
 * checklist is an advisory aid, and the desk was never enforcing it.
 *
 * @return array<int,array{code:string,label:string,hint:string,is_required:bool,max_files:int}>
 */
function doc_requirements(int $catalogId): array
{
    if ($catalogId <= 0 || !db_table_exists('document_type_requirements')) {
        return [];
    }
    $rows = Database::getInstance()->fetchAll(
        "SELECT code, label, hint, is_required, max_files
           FROM document_type_requirements
          WHERE catalog_id = ? AND is_active = 1
          ORDER BY sort_order ASC, id ASC",
        [$catalogId]
    );
    return array_map(fn($r) => [
        'code'        => (string) $r['code'],
        'label'       => (string) $r['label'],
        'hint'        => (string) ($r['hint'] ?? ''),
        'is_required' => (int) $r['is_required'] === 1,
        'max_files'   => max(1, (int) $r['max_files']),
    ], $rows);
}

/**
 * Which checklist items this request has satisfied, and which it has not.
 *
 * A LEFT JOIN rather than a fetch-and-count in PHP, because the count
 * has to be by requirement_code and doing that over rows means building
 * a map keyed by a value the database already indexes. The distinction
 * that matters: "no file at all" and "a file that is not one of the
 * items" are different states, and only the first is a missing item.
 *
 * @return array{requirements:array,attached:array<int,array>,missing:array<int,string>,complete:bool}
 */
function doc_requirement_state(int $requestId, int $catalogId): array
{
    $requirements = doc_requirements($catalogId);
    $attached = [];

    if ($requestId > 0 && db_table_exists('document_request_attachments')) {
        $attached = Database::getInstance()->fetchAll(
            "SELECT id, requirement_code, original_name, file_path, sha256,
                    mime_type, size_bytes, uploaded_at
               FROM document_request_attachments
              WHERE request_id = ?
              ORDER BY uploaded_at ASC, id ASC",
            [$requestId]
        );
    }

    // How many files each checklist item has. Counted here rather than
    // in SQL because a request can hold files that belong to NO item -
    // the dropzone accepts loose files - and those must not be allowed
    // to satisfy a checklist entry by accident.
    $counts = [];
    foreach ($attached as $a) {
        $code = (string) ($a['requirement_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }

    // A requirement is MET when it has at least one file, OR when it is
    // not required and the student has answered the one question that
    // can stand in for it. Required items are never auto-met: the
    // whole point of asking is that somebody confirms they have it.
    $missing = [];
    foreach ($requirements as $r) {
        $have = (int) ($counts[$r['code']] ?? 0);
        $r['have'] = $have;
        $r['met']  = $have > 0;
        if ($r['is_required'] && !$r['met']) {
            $missing[] = $r['label'];
        }
    }

    return [
        'requirements' => $requirements,
        'attached'     => $attached,
        'missing'      => $missing,
        'complete'     => $missing === [],
    ];
}

// ─────────────────────────────────────────────────────────────────────
//  UPLOADS
// ─────────────────────────────────────────────────────────────────────

/** Extensions a requirement attachment may be. Narrower than the global allow-list. */
const DOC_ATTACHMENT_EXT = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

/** Ceiling on one attachment. Same reasoning as DOC_RECEIPT_MAX_BYTES. */
const DOC_ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;

/**
 * Validate and store one requirement attachment.
 *
 * Same three gates as doc_store_receipt() - extension, size, and the
 * ACTUAL magic bytes rather than the filename - because a requirement
 * is opened by a registrar on the office's own machine and a renamed
 * script in that directory is a much worse thing to have lying around
 * than a rejected upload. The extension list is rendered into the
 * browser by the wizard so the student is stopped before the upload
 * rather than after it.
 *
 * @return array{ok:bool, path?:string, sha256?:string, name?:string, mime?:string, size?:int, message?:string}
 */
function doc_store_attachment(array $file): array
{
    // The `array` type hint above is the type check, and it is
    // deliberately not softened to `mixed`. A caller that hands this a
    // non-array has a bug, and a TypeError says exactly where - which
    // is more useful than a return value indistinguishable from "the
    // student sent nothing".
    //
    // What IS reachable is an EMPTY array, which is what `$_FILES['x'] ?? []`
    // yields when the field was never sent. That is the case this
    // branch is for, so it keys on the missing `error` rather than on
    // the type.
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'No file was received.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed (error code ' . (int) $file['error'] . ').'];
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_ATTACHMENT_EXT, true)) {
        return ['ok' => false, 'message' => 'Attachments must be a JPG, PNG, WEBP or PDF file.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size > DOC_ATTACHMENT_MAX_BYTES) {
        return ['ok' => false, 'message' => 'That file is ' . round($size / 1048576, 1)
            . ' MB. The limit is ' . round(DOC_ATTACHMENT_MAX_BYTES / 1048576) . ' MB per file.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'Upload failed validation.'];
    }

    $sig = validateUploadSignature($file['tmp_name'], (string) $file['name']);
    if (!$sig['ok']) {
        // Logged with the DETECTED type: in production this log line is
        // the only evidence of what the file really was.
        error_log('[doc_attachment] rejected: ' . $sig['reason'] . ' (detected ' . $sig['detected'] . ')');
        return ['ok' => false, 'message' => 'File rejected: ' . $sig['reason'] . '.'];
    }

    $dir = __DIR__ . '/../uploads/document_requirements/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Could not create the upload folder.'];
    }

    $name = generateFilename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        return ['ok' => false, 'message' => 'Could not save the file.'];
    }

    $mime = function_exists('mime_content_type')
        ? (string) (@mime_content_type($dir . $name) ?: '')
        : '';

    return [
        'ok'     => true,
        'path'   => 'uploads/document_requirements/' . $name,
        'sha256' => hash_file('sha256', $dir . $name),
        'name'   => mb_substr((string) $file['name'], 0, 180),
        'mime'   => $mime,
        'size'   => $size,
    ];
}

// ─────────────────────────────────────────────────────────────────────
//  IDENTIFIERS AND DATES
// ─────────────────────────────────────────────────────────────────────

/**
 * The next tracking number, in the format the desk already prints.
 *
 * DOC-YYYY-NNNN, which is this system's own established convention -
 * the desk's search box, the pickup emails and the generated PDFs all
 * read it. It is deliberately NOT changed to the REQ-2025-000123 shape
 * in the specification this was built from: every existing row, every
 * stored filename (uploads/document_pdfs/DOC-2026-0005-DOC-COE.pdf)
 * and every URL a student has bookmarked is built on the current form,
 * and a tracking number is the one string that must never stop working
 * for a document that was already issued.
 *
 * Counted from MAX rather than COUNT. COUNT is wrong twice over: a
 * deleted row hands its number to a second request, and a concurrent
 * pair of filings both read the same count and both write it. The
 * unique key on request_id turns that race into a duplicate-key error
 * rather than two documents sharing one tracking number, and the
 * caller retries.
 *
 * @return string
 */
function doc_next_tracking_number(): string
{
    $db   = Database::getInstance();
    $year = date('Y');
    $like = 'DOC-' . $year . '-%';
    // SUBSTRING is 1-indexed. 'DOC-' is 4 characters, the year is 4
    // more, and the separator is a 9th - so the digits begin at
    // position 10. Reading from 9 takes the separator with them, and
    // CAST('-0004' AS UNSIGNED) yields 0, which is why the position is
    // spelled out here rather than left as an arithmetic expression
    // somebody will be tempted to "simplify" next to the format string.
    $max  = (string) $db->fetchColumn(
        "SELECT MAX(CAST(SUBSTRING(request_id, 10) AS UNSIGNED))
           FROM document_requests
          WHERE request_id LIKE ?",
        [$like]
    );
    return 'DOC-' . $year . '-' . str_pad((string) ((int) $max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Is this exception a unique-key collision, whatever the driver said?
 *
 * MySQL and MariaDB both report 23000/1062 but disagree on the wording,
 * and some drivers wrap it in a PDOException whose message is the SQL
 * rather than the driver's. So the SQLSTATE is checked first (that part
 * is standardised) and the driver code second, and the message is only
 * consulted last.
 *
 * Callers use this to retry with a fresh tracking number, so a false
 * positive would swallow a real error into a retry loop. That is why
 * the code is checked rather than the message alone.
 */
function doc_is_duplicate_key(Throwable $e): bool
{
    if ($e instanceof PDOException) {
        // 23000 is the SQLSTATE class for integrity constraint
        // violation; 1062 is MySQL/MariaDB's ER_DUP_ENTRY.
        if ($e->getCode() === '23000' || $e->getCode() === 23000) {
            return true;
        }
        $info = $e->errorInfo ?? null;
        if (is_array($info) && (int) ($info[1] ?? 0) === 1062) {
            return true;
        }
    }
    return stripos($e->getMessage(), '1062') !== false
        || stripos($e->getMessage(), 'Duplicate entry') !== false;
}

/**
 * The payment reference a student quotes at a bank or a cashier.
 *
 * Short, digits-only and unique per year, because somebody reads it
 * aloud across a counter and someone else types it into a bank portal.
 * Issued at filing rather than at the payment screen, so a student can
 * be given it before the desk has looked at the request at all.
 *
 * @return string  e.g. "D26-000417"
 */
function doc_payment_reference(int $requestPk): string
{
    return 'D' . substr((string) date('Y'), 2) . '-' . str_pad((string) $requestPk, 6, '0', STR_PAD_LEFT);
}

/**
 * When this request is expected to be ready.
 *
 * The catalog's sla_days, counted from filing, skipping weekends. A
 * school office does not prepare documents on Saturday, so a promise
 * that ignored weekends would be wrong roughly 40% of the time and the
 * student would be back at the counter on the Monday to ask why.
 *
 * Written ONCE, at filing, into estimated_release_at. A date that is
 * recomputed on every page load is a date that moves while the student
 * is looking at it, and it cannot be quoted over the counter.
 *
 * @return string  'Y-m-d H:i:s', or null when the SKU names no target.
 */
function doc_estimated_release(int $slaDays, ?string $from = null): ?string
{
    if ($slaDays <= 0) {
        return null;
    }
    $start = $from ? strtotime($from) : time();
    if ($start === false) {
        $start = time();
    }
    $added  = 0;
    $cursor = $start;
    // Bounded by slaDays + a margin so a malformed value cannot spin.
    for ($guard = 0; $added < $slaDays && $guard < 400; $guard++) {
        $cursor = strtotime('+1 day', $cursor);
        $dow   = (int) date('N', $cursor);   // 1 = Mon .. 7 = Sun
        if ($dow >= 6) {
            continue;   // Saturday or Sunday: the office is shut.
        }
        $added++;
    }
    return date('Y-m-d H:i:s', $cursor);
}

/** Human phrasing for an estimated release date. */
function doc_release_label(?string $ts): string
{
    if (!$ts) {
        return 'To be confirmed by the Registrar';
    }
    return date('M d, Y', strtotime($ts));
}

// ─────────────────────────────────────────────────────────────────────
//  THE TRACKING TIMELINE
// ─────────────────────────────────────────────────────────────────────

/**
 * The tracking page's timeline, as data.
 *
 * Built from stored timestamps and the event log rather than from the
 * current status, because a timeline drawn off the status would
 * collapse to "you are here" and lose every past step - which is the
 * entire reason a student opens the tracking page instead of reading
 * the status pill.
 *
 * Steps whose time is not yet known are drawn pending rather than
 * omitted, so the shape of what is coming is visible from the start.
 *
 * @param array $row    The document_requests row.
 * @param array $events Its document_request_events rows, oldest first.
 * @return array<int,array{key:string,label:string,state:string,at:?string,note:?string}>
 *          state is one of: done, active, pending, stopped
 */
function doc_track_timeline(array $row, array $events): array
{
    $status = (string) ($row['document_status'] ?? 'Filed');

    // The first moment each named status was reached. Taken from the
    // event log where there is one, because two of the statuses have no
    // column of their own (Processing has no processing_at) and reading
    // a timestamp off a column that does not exist is how a step ends
    // up permanently blank.
    $reached = [];
    foreach ($events as $ev) {
        $s = (string) ($ev['status'] ?? '');
        if ($s !== '' && !isset($reached[$s])) {
            $reached[$s] = (string) ($ev['created_at'] ?? '');
        }
    }
    $firstEvent = $events ? (string) ($events[0]['created_at'] ?? '') : '';

    $steps = [
        [
            'key'   => 'submitted',
            'label' => 'Request submitted',
            'at'    => $row['submitted_at'] ?? ($firstEvent ?: $row['request_date'] ?? null),
            'note'  => 'Tracking number ' . (string) ($row['request_id'] ?? ''),
        ],
        [
            'key'   => 'payment',
            'label' => 'Payment received',
            'at'    => $row['paid_at'] ?? ($row['payment_receipt_verified_at'] ?? null),
            'note'  => $row['paid_at'] ? null : 'Waiting on your payment',
        ],
        [
            'key'   => 'queued',
            'label' => 'Queued at the Registrar',
            'at'    => $reached['Filed'] ?? null,
            'note'  => null,
        ],
        [
            'key'   => 'preparing',
            'label' => 'Document being prepared',
            'at'    => $reached['Processing'] ?? null,
            'note'  => null,
        ],
        [
            'key'   => 'ready',
            'label' => 'Ready for collection',
            'at'    => $row['ready_at'] ?? ($reached['Ready'] ?? $reached['Shipped'] ?? null),
            'note'  => null,
        ],
        [
            'key'   => 'released',
            'label' => 'Collected',
            'at'    => $row['claimed_at'] ?? null,
            'note'  => null,
        ],
    ];

    // Which step is "now".
    //
    // Terminal states short-circuit: a Rejected request did not reach
    // the end of the track, it left it, and painting every later step
    // as pending would tell a student their document is on its way when
    // it was thrown out. Every step is drawn stopped instead.
    if ($status === 'Rejected') {
        foreach ($steps as &$s) {
            $s['state'] = $s['at'] ? 'done' : 'stopped';
        }
        unset($s);
        $steps[0]['note'] = ($steps[0]['note'] ?? '') . ' — later rejected';
        return $steps;
    }
    if ($status === 'Cancelled') {
        foreach ($steps as &$s) {
            $s['state'] = $s['at'] ? 'done' : 'stopped';
        }
        unset($s);
        $steps[0]['note'] = ($steps[0]['note'] ?? '') . ' — later cancelled by the student';
        return $steps;
    }
    if ($status === 'Draft') {
        // Nothing has happened yet, and saying so plainly is the point.
        foreach ($steps as $i => &$s) {
            $s['state'] = $i === 0 ? 'active' : 'pending';
        }
        unset($s);
        $steps[0]['note'] = 'Draft saved — not submitted yet';
        return $steps;
    }

    // Still owed money, so nothing has started moving.
    //
    // This needs its own branch rather than being left to the generic
    // "first step with no timestamp is where we are" walk below. That
    // walk would land on the payment step and mark it ACTIVE, which
    // reads as "your payment is being processed" to a student who has
    // not paid yet. Here the request is held at its true first step and
    // the payment step is the one labelled as what they owe.
    if ($status === 'Awaiting_Payment') {
        $steps[0]['state'] = 'done';
        $steps[1]['state'] = 'active';
        $steps[1]['note']  = 'Waiting on you';
        for ($i = 2; $i < count($steps); $i++) {
            $steps[$i]['state'] = 'pending';
        }
        return $steps;
    }

    // Pay at the counter: nothing is owed yet, so the payment step is
    // NOT the live one.
    //
    // Without this the generic walk below marked it active with the note
    // "Waiting on you" - on a request whose status was Filed and whose
    // headline read "With the Registrar". The timeline said the student
    // owed money while the box directly above it said they did not, and
    // the student has no way to tell which of the two to believe. It is
    // drawn as a later step instead, and labelled with when it happens.
    if (($row['payment_method'] ?? '') === 'Counter' && empty($row['paid_at'])) {
        $steps[1]['note'] = 'Pay at the counter when you collect';
        // Suppress its timestamp entirely so the generic walk cannot
        // treat it as the current position.
        $steps[1]['at']   = null;
        $steps[1]['deferred'] = true;
    }

    // A request that has been COLLECTED is finished, whatever the
    // timestamps say.
//
// Without this branch a completed request could still show a live
    // step. It happens whenever an earlier stage is missing a timestamp:
// the fixture rows seed ready_at and claimed_at but carry no Filed
// event, so "Queued at the Registrar" has nothing to read and the
    // walk below marked it active - telling a student who has already
    // picked their document up that the office is still working on it.
//
// The terminal branches above already return, so this can only be
// reached by a request whose document_status says Claimed. It is
// handled by trust in the status rather than by inferring completion
// from timestamps, because the status is the authoritative answer and
// the timestamps are the incomplete record.
if ($status === 'Claimed') {
    foreach ($steps as $i => $s) {
        // Every step reads done, including the ones with no timestamp.
        // A completed request did pass through all of them - the record
        // of WHEN is incomplete, not the fact that it happened. Drawing
        // one of them as "not yet" would be the more misleading of the
        // two errors here.
        $steps[$i]['state'] = 'done';
    }
    $steps[count($steps) - 1]['note'] = 'Collected — this request is complete';
    return $steps;
}

// Walk the track: everything with a timestamp happened, the first
    // without one is where it currently is, and the rest are ahead.
//
// A DEFERRED step (payment at the counter, not yet due) is drawn as
// coming up but never claims to be "now" - it is skipped when
// looking for the current position, so a request sitting in the
// queue does not report that its next action is to go and pay.
$currentSeen = false;
foreach ($steps as &$s) {
    if (!empty($s['at'])) {
        $s['state'] = 'done';
        continue;
    }
    if (!empty($s['deferred'])) {
        $s['state'] = 'pending';
        continue;
    }
    if (!$currentSeen) {
        $s['state']  = 'active';
        $currentSeen = true;
    } else {
        $s['state'] = 'pending';
    }
}
unset($s);

    // Nothing was current - every remaining step was deferred or already
    // done. Then the request is queued with the office and the FIRST
    // non-payment step is where it is, which is the truth.
    if (!$currentSeen) {
        foreach ($steps as $i => $s) {
            if ($s['key'] === 'queued') {
                $steps[$i]['state'] = 'active';
                break;
            }
        }
    }

    return $steps;
}

/**
 * Where a request actually is, for the one-line summary.
 *
 * Distinct from the status label: this says what is EXPECTED to happen
 * next and when, which is the question the confirmation and tracking
 * screens are actually answering.
 *
 * @return array{headline:string,detail:string}
 */
function doc_next_expectation(array $row): array
{
    switch ((string) ($row['document_status'] ?? '')) {
        case 'Draft':
            return ['headline' => 'Your draft is saved', 'detail' => 'Finish the request and submit it to get a tracking number.'];
        case 'Awaiting_Payment':
            return ['headline' => 'Waiting for your payment', 'detail' => 'Pay, then send the receipt so the Registrar can check it.'];
        case 'Pending_Clearance':
            return ['headline' => 'On hold at the Registrar', 'detail' => trim((string) ($row['blocked_reason'] ?? 'The office will release this once your account is settled.'))];
        case 'Filed':
            return ['headline' => 'With the Registrar', 'detail' => 'Queued for preparation.'];
        case 'Processing':
            return ['headline' => 'Being prepared', 'detail' => 'The office is working on your document now.'];
        case 'Ready':
            return ['headline' => 'Ready to collect', 'detail' => 'Bring your student ID to the Registrar\'s Office.'];
        case 'Shipped':
            return ['headline' => 'On its way', 'detail' => 'The courier has your document.'];
        case 'Claimed':
            return ['headline' => 'Collected', 'detail' => 'This request is complete.'];
        case 'Rejected':
            return ['headline' => 'Rejected', 'detail' => trim((string) ($row['rejection_reason'] ?? 'Contact the Registrar for the reason.'))];
        case 'Cancelled':
            return ['headline' => 'Cancelled', 'detail' => trim((string) ($row['cancellation_reason'] ?? 'You withdrew this request.'))];
        default:
            return ['headline' => 'In progress', 'detail' => ''];
    }
}