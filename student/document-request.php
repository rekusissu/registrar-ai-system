<?php
// ============================================================
//  STUDENT/DOCUMENT-REQUEST.PHP
//  The guided request wizard: document -> details -> uploads ->
//  review -> payment -> done, then tracking.
//
//  One page, five screens, driven by a step index. Not five URLs:
//  a wizard split across five addresses loses its state the moment a
//  student taps Back in their browser, and "Back" is the one control
//  everybody reaches for. The step is in the URL hash so a refresh
//  keeps its place, but the page is always this page.
//
//  The wizard is server-driven on first paint and client-driven after
//  that: PHP renders what it can (the catalog, any open draft), and
//  every change is autosaved to api/document-request.php so a refresh
//  or a dropped connection never costs the student their uploads.
//
//  The fee arithmetic is NOT reimplemented in the JS. shared/doc_wizard.php
//  computes it server-side, and the constants the browser needs to
//  pre-render a live total are sent in the bootstrap payload, so there
//  is one implementation of a price in this system.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';
require_once __DIR__ . '/../shared/doc_wizard.php';
// The office's GCash QR, and whether this host actually has it. The
// payment step shows the code or says plainly that it is not set up -
// never a broken image, which inside a payment screen reads as the
// student's phone failing.
require_once __DIR__ . '/../shared/payment_qr.php';

$page_title = 'Request a Document';
$page_description = 'Request a registrar document and track it';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_documents';
$extra_css = ['student.css', 'documents.css', 'doc-request.css', 'docket.css'];
$body_page = 'docket-request';   // scopes css/docket.css

require_once __DIR__ . '/_guard.php';

$db       = Database::getInstance();
$gcashQr  = gcashQrImage();
$balance  = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$student['id']]) ?? 0.00);

// ── Bootstrap payload ───────────────────────────────────────
//
// Rendered into the page as JSON so the wizard is usable the instant
// it paints, with no fetch and no spinner. The endpoint exists for
// autosave and for the case where this render is stale.
$catalogRows = db_fill_optional(
    $db->fetchAll('SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id ASC'),
    'document_catalog',
    ['sla_days', 'requirement', 'fee_type', 'description']
);

// One query for every SKU's checklist, not one per SKU.
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

// The SKU icon map. Kept identical to the registrar desk's and to the
// student list's - three copies of this list used to drift, and a
// document that looked identical to three others is the one thing an
// icon column exists to prevent.
$catIcon = [
    'DOC-TOR'     => ['linear-gradient(135deg,#2563eb,#1d4ed8)', 'fa-file-invoice'],
    'DOC-COE'     => ['linear-gradient(135deg,#16a34a,#15803d)', 'fa-certificate'],
    'DOC-GM'      => ['linear-gradient(135deg,#0d9488,#0f766e)', 'fa-handshake-angle'],
    'DOC-DIPLOMA' => ['linear-gradient(135deg,#7c3aed,#6d28d9)', 'fa-graduation-cap'],
    'DOC-CTC'     => ['linear-gradient(135deg,#4f46e5,#4338ca)', 'fa-copy'],
    'DOC-HD'      => ['linear-gradient(135deg,#ea580c,#c2410c)', 'fa-sign-out-alt'],
    'DOC-CD'      => ['linear-gradient(135deg,#db2777,#be185d)', 'fa-book-open'],
];

$catalog = [];
foreach ($catalogRows as $c) {
    $catalog[] = [
        'id'           => (int) $c['id'],
        'sku'          => (string) $c['sku'],
        'name'         => (string) $c['name'],
        'description'  => (string) ($c['description'] ?? ''),
        'base_fee'     => (float) $c['base_fee'],
        'fee_type'     => (string) $c['fee_type'],
        'sla_days'     => $c['sla_days'] === null ? null : (int) $c['sla_days'],
        'requirements' => $reqByCatalog[(int) $c['id']] ?? [],
        'icon'         => $catIcon[$c['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines'],
    ];
}

// ── The open draft, if there is one ────────────────────────
//
// Resuming rather than starting fresh is deliberate: two half-filled
// forms for the same student is a support question nobody should have
// to answer, and losing an upload to a closed tab is worse.
$draftRow = $db->fetchOne(
    "SELECT * FROM document_requests
      WHERE student_id = ? AND document_status = 'Draft'
      ORDER BY id DESC LIMIT 1",
    [$student['id']]
);

$draft = null;
if ($draftRow) {
    $catalogId = (int) ($draftRow['catalog_id'] ?? 0);
    $draft = [
        'id'            => (int) $draftRow['id'],
        'catalog_id'    => $catalogId,
        'wizard_step'   => (int) ($draftRow['wizard_step'] ?? 0),
        'quantity'      => (int) ($draftRow['quantity'] ?? 1),
        'payment_method'   => (string) ($draftRow['payment_method'] ?? 'Counter'),
        'purpose_code'  => (string) ($draftRow['purpose_code'] ?? ''),
        'purpose'       => (string) ($draftRow['purpose'] ?? ''),
        'notes'         => (string) ($draftRow['notes'] ?? ''),
        'requirements'  => doc_requirement_state((int) $draftRow['id'], $catalogId),
    ];
}

$bootstrap = [
    'catalog'   => $catalog,
    'purposes'  => doc_purposes(),
    'payment'   => doc_payment_options(),
    'steps'     => doc_wizard_steps(),
    'numbered'  => doc_wizard_numbered_steps(),
    'limits'    => [
        'max_bytes' => DOC_ATTACHMENT_MAX_BYTES,
        'ext'       => array_values(DOC_ATTACHMENT_EXT),
    ],
    // Sent rather than left to the browser's own idea of it, because the
    // browser's quote and the server's quote must be the same quote.
    'processing_fee' => doc_processing_fee(),
    'balance'      => $balance,
    'blocked_by_balance' => $balance > 0,
    'draft'        => $draft,
];
?>

<main class="dashboard-main">
<div class="dashboard-container">
<div class="wr-shell">

    <div class="dk-mast">
        <div>
            <h1>Request a document</h1>
            <p>Pick what you need, tell us who it is for, and attach what you already have.
               Nothing reaches the Registrar until you submit, and your progress is kept.</p>
        </div>
        <div class="dk-mast-act">
            <a class="dk-btn dk-btn--sm" href="<?= $APP_ROOT ?>student/documents.php">My requests</a>
        </div>
    </div>

    <?php if ($draftRow): ?>
    <div class="dk-note is-you">
        <i class="fa-solid fa-pen-ruler"></i>
        <div><b>You have an unfinished request.</b>
            Nothing has reached the Registrar yet and your uploads are still saved.
            Pick up where you left off.</div>
    </div>
    <?php endif; ?>

    <?php if ($balance > 0): ?>
    <div class="dk-note is-wait">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div><b>Your account has a balance of &#8369;<?= number_format($balance, 2) ?>.</b>
            You can still submit this request. The Registrar will hold it until the balance is
            settled, and it will start on its own once it is.</div>
    </div>
    <?php endif; ?>

    <!-- The sheet. One piece of stock with a stub down the left margin, the
         way a triplicate form has one. The stub carries the facts you would
         otherwise have to scroll back for: the control number, what you
         asked for, what it costs, and how far you have got. -->
    <div class="dk-sheet">

        <aside class="dk-stub">
            <div class="dk-facts" id="wrStubFacts"></div>
            <div>
                <span class="dk-fact-k">Progress</span>
                <ol class="dk-prog" id="wrStepper" aria-label="Request progress"></ol>
            </div>
        </aside>

        <div class="dk-body">

        <!-- ── Step 0 · Document ────────────────────────────────────── -->
    <section class="wr-panel" data-screen="0" hidden>
        <div class="wr-panel-head">
            <h2 class="wr-panel-title"><i class="fa-solid fa-file-lines"></i> What document do you need?</h2>
            <p class="wr-panel-sub">Fees and turnaround are set by the Registrar's Office. Pick one to begin.</p>
        </div>

        <div class="wr-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="wrDocSearch" placeholder="Search or describe your need — e.g. &ldquo;proof of enrollment&rdquo;"
                   aria-label="Search documents" autocomplete="off">
        </div>

        <div class="wr-doc-grid" id="wrDocGrid" role="list"></div>

        <div class="wr-actions">
            <button type="button" class="btn btn-primary" id="wrPickNext" disabled>
                Continue <i class="fa-solid fa-arrow-right"></i>
            </button>
            <span class="wr-spacer"></span>
            <button type="button" class="btn btn-light" onclick="if(window.toggleStudentChat) window.toggleStudentChat();">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Not sure? Ask the Registrar AI
            </button>
        </div>
    </section>

    <!-- ── Step 1 · Details ────────────────────────────── -->
    <section class="wr-panel" data-screen="1" hidden>
        <div class="wr-panel-head">
            <h2 class="wr-panel-title"><i class="fa-solid fa-list-check"></i> Request details</h2>
            <p class="wr-panel-sub" id="wrDocEcho"></p>
        </div>

        <div class="wr-field">
            <label class="wr-label" for="wrPurposeCode">Purpose <span class="wr-req">*</span></label>
            <select id="wrPurposeCode" class="form-control"></select>
            <p class="wr-hint" id="wrPurposeHint">This tells the office who to prepare the document for.</p>
        </div>

        <div class="wr-field">
            <label class="wr-label" for="wrPurpose">
                <span id="wrPurposeLabel">Tell us more</span> <span class="wr-req" id="wrPurposeRequired">*</span>
            </label>
            <input type="text" id="wrPurpose" class="form-control" maxlength="255"
                   placeholder="Job application, transfer to another school">
            <p class="wr-hint">A sentence is enough.</p>
        </div>

        <div class="wr-field" id="wrQtyField">
            <span class="wr-label">How many</span>
            <div class="wr-qty">
                <button type="button" id="wrQtyMinus" aria-label="One fewer copy">&minus;</button>
                <input type="number" id="wrQty" value="1" min="1" max="100" aria-label="Number of copies">
                <button type="button" id="wrQtyPlus" aria-label="One more copy">+</button>
            </div>
            <p class="wr-hint" id="wrQtyHint"></p>
        </div>

        <div class="wr-field">
            <label class="wr-label" for="wrNotes">Anything else we should know? <span style="font-weight:500;color:var(--text-muted);">(optional)</span></label>
            <textarea id="wrNotes" class="form-control" rows="3" maxlength="2000"
                      placeholder="Anything unusual about this request that we should know."></textarea>
        </div>

        <!-- The live total. Recomputed from the same constants the
             server quotes from, so the number here is the number that
             will be charged. -->
        <div class="wr-quote" id="wrQuote"></div>

        <div class="wr-actions">
            <button type="button" class="btn btn-light" data-goto="0"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <span class="wr-spacer"></span>
            <button type="button" class="btn btn-primary" id="wrDetailsNext">Continue <i class="fa-solid fa-arrow-right"></i></button>
        </div>
    </section>

    <!-- ── Step 2 · Uploads ────────────────────────────── -->
    <section class="wr-panel" data-screen="2" hidden>
        <div class="wr-panel-head">
            <h2 class="wr-panel-title"><i class="fa-solid fa-paperclip"></i> Upload requirements</h2>
            <p class="wr-panel-sub">Attach what you have now. You can add or remove files until the office marks it ready.</p>
        </div>

        <div id="wrMissingNotice"></div>

        <div class="wr-reqs" id="wrReqList"></div>

        <label class="wr-drop" id="wrDrop">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span class="wr-drop-title">Drag files here, or click to browse</span>
            <span class="wr-drop-note" id="wrDropNote"></span>
            <input type="file" id="wrDropInput" multiple>
        </label>

        <div class="wr-files" id="wrFileList"></div>

        <div class="wr-actions">
            <button type="button" class="btn btn-light" data-goto="1"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <span class="wr-spacer"></span>
            <button type="button" class="btn btn-light" id="wrSaveDraftBtn">Save draft</button>
            <button type="button" class="btn btn-primary" data-goto="3">Continue <i class="fa-solid fa-arrow-right"></i></button>
        </div>
    </section>

    <!-- ── Step 3 · Review ─────────────────────────────── -->
    <section class="wr-panel" data-screen="3" hidden>
        <div class="wr-panel-head">
            <h2 class="wr-panel-title"><i class="fa-solid fa-clipboard-check"></i> Review your request</h2>
            <p class="wr-panel-sub">Check this over. Nothing is sent until you submit.</p>
        </div>

        <div class="wr-quote" id="wrReviewQuote" style="margin-bottom:20px;"></div>

        <div class="wr-sum" id="wrReviewSummary" style="margin-bottom:20px;"></div>

        <div class="wr-field" style="margin-bottom:0;">
            <span class="wr-label">Before you submit</span>
            <div class="wr-certify">
                <label class="wr-check">
                    <input type="checkbox" id="wrCertifyTrue">
                    <span class="wr-check-text">
                        I certify that all the information I have provided is true and correct.
                    </span>
                </label>
                <label class="wr-check">
                    <input type="checkbox" id="wrCertifyPrivacy">
                    <span class="wr-check-text">
                        I agree to the school's <a href="#" onclick="return false;">Data Privacy Policy</a> and consent to the
                        Registrar's Office processing this request.
                    </span>
                </label>
            </div>
            <p class="wr-hint">
                Both boxes must be ticked. They are recorded with your submission, so they are not just a
                click-through.
            </p>
        </div>

        <div class="wr-actions">
            <button type="button" class="btn btn-light" data-goto="2"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <span class="wr-spacer"></span>
            <button type="button" class="btn btn-primary" id="wrSubmitBtn" disabled>
                <i class="fa-solid fa-paper-plane"></i> Submit request
            </button>
        </div>
    </section>

    <!-- ── Step 4 · Payment ────────────────────────────── -->
    <section class="wr-panel" data-screen="4" hidden>
        <div class="wr-panel-head">
            <h2 class="wr-panel-title"><i class="fa-solid fa-credit-card"></i> Payment</h2>
            <p class="wr-panel-sub">Your request is in. Settle the fee whenever suits you — it will not expire.</p>
        </div>

        <div class="wr-trackno" style="margin-bottom:20px;">
            <span class="wr-trackno-value" id="wrPayTracking">&mdash;</span>
            <button type="button" class="wr-trackno-copy" id="wrPayCopy">
                <i class="fa-solid fa-copy"></i> Copy
            </button>
        </div>

        <div class="wr-quote" id="wrPayQuote" style="margin-bottom:20px;"></div>

        <div id="wrPayBody"></div>

        <div class="wr-actions">
            <button type="button" class="btn btn-light" data-goto="3"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <span class="wr-spacer"></span>
            <button type="button" class="btn btn-primary" id="wrPayLaterBtn">
                Pay later &mdash; I'll settle it at the office
            </button>
        </div>
    </section>

    <!-- ── Confirmation ────────────────────────────────── -->
    <section class="wr-panel" data-screen="5" hidden>
        <div class="wr-done">
            <div class="wr-done-mark"><i class="fa-solid fa-check"></i></div>
            <h2 class="wr-done-title">Request submitted</h2>
            <p class="wr-done-text" id="wrDoneText"></p>

            <div class="wr-trackno" style="margin-bottom:22px;">
                <span class="wr-trackno-value" id="wrDoneTracking">&mdash;</span>
                <button type="button" class="wr-trackno-copy" id="wrDoneCopy">
                    <i class="fa-solid fa-copy"></i> Copy
                </button>
            </div>

            <div class="wr-sum" id="wrDoneFacts" style="margin-bottom:22px; text-align:left;"></div>

            <h3 class="wr-label" style="text-align:center; margin-bottom:8px;">What happens next</h3>
            <ol class="wr-next" id="wrDoneNext"></ol>

            <div class="wr-done-actions">
                <a href="#" class="btn btn-primary" id="wrTrackLink"><i class="fa-solid fa-location-crosshairs"></i> Track my request</a>
                <a href="<?= $APP_ROOT ?>student/documents.php" class="btn btn-light">Back to My Requests</a>
            </div>
        </div>
    </section>

    <!-- ── Error / offline ────────────────────────────── -->
    <section class="wr-panel" data-screen="err" hidden>
        <div class="wr-notice is-danger" style="margin:0;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <b id="wrErrTitle">Something went wrong</b>
                <span id="wrErrText">Your request has not been lost. Go back and try again.</span>
            </div>
        </div>
        <div class="wr-actions">
            <a href="<?= $APP_ROOT ?>student/documents.php" class="btn btn-light">Back to My Requests</a>
            <span class="wr-spacer"></span>
            <button type="button" class="btn btn-primary" onclick="location.reload()">Try again</button>
        </div>
    </section>

        </div>
    </div>

</div>
</div>
</main>

<script>
// ══════════════════════════════════════════════════════════════════
//  The wizard.
//
//  The server rendered the catalog and any open draft into the page;
//  everything after that is client state mirrored to the server on
//  every change. The rule the whole file is built around: a step
//  change ALWAYS autosaves, so a refresh, a crash or a closed tab
//  costs nothing.
// ══════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    var BOOT = <?= json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var GCASH_QR = <?= json_encode($gcashQr, JSON_UNESCAPED_SLASHES) ?>;
    var API = <?= json_encode(app_url('/api/document-request.php'), JSON_UNESCAPED_SLASHES) ?>;
    var TRACK_PAGE = <?= json_encode(app_url('/student/document-track.php'), JSON_UNESCAPED_SLASHES) ?>;
    var MONEY = 'PHP ';
    var TOTAL_STEPS = BOOT.numbered.length;

    // ── State ─────────────────────────────────────────────
    //  `dirty` is what makes the autosave skip a request: re-saving on
    //  every render would put a database write behind every keystroke.
    var state = {
        step: 0,
        catalogId: 0,
        draftId: 0,
        quantity: 1,
        purposeCode: '',
        purpose: '',
        notes: '',
        address: { street: '', city: '', province: '', zip: '', contact: '' },
        paymentMethod: 'Counter',
        requirements: [],
        attachments: [],
        missing: [],
        submitted: null,
        dirty: false,
        saving: false,
        uploading: 0
    };

    // ── Small helpers ─────────────────────────────────────
    var $ = function (id) { return document.getElementById(id); };

    function peso(n) {
        return MONEY + Number(n || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function chosenDoc() {
        for (var i = 0; i < BOOT.catalog.length; i++) {
            if (BOOT.catalog[i].id === state.catalogId) { return BOOT.catalog[i]; }
        }
        return null;
    }

    function toast(msg, type) {
        if (window.showToast) { window.showToast(msg, type || 'info'); }
        else { console.warn('[wizard]', msg); }
    }

    function fail(title, text) {
        $('wrErrTitle').textContent = title;
        $('wrErrText').textContent = text;
        go(999, 'err');
    }

    // ── The fee quote ─────────────────────────────────────
    //
    //  The same arithmetic as doc_quote() in shared/doc_wizard.php:
    //  base_fee x quantity for a per-unit SKU, once for a flat one,
    //  then the processing fee. Written once
    //  here and once there, deliberately - the values driving both are
    //  the ones BOOT carries from PHP, so a price change moves both
    //  at once. What must NOT happen is the arithmetic itself drifting,
    //  which is why the constants are sent rather than retyped.
    function quoteNow() {
        var doc = chosenDoc();
        if (!doc) { return null; }

        var perUnit = doc.fee_type !== 'flat';
        var unit = doc.fee_type === 'per_syllabus' ? 'syllabus' : 'page';
        var qty = Math.max(1, Math.min(100, state.quantity || 1));

        var documentFee = round2(doc.base_fee * (perUnit ? qty : 1));

        var processingFee = BOOT.processing_fee || 0;

        var lines = [{
            label: doc.name,
            detail: perUnit
                ? qty + ' ' + unit + (qty === 1 ? '' : 's') + ' at ' + peso(doc.base_fee) + ' each'
                : 'One-time charge per request',
            amount: documentFee
        }];
        if (processingFee > 0) {
            lines.push({ label: 'Processing fee', detail: 'Fixed charge per request', amount: processingFee });
        }

        return {
            lines: lines,
            quantity: qty,
            per_unit: perUnit,
            unit_word: unit,
            total: round2(documentFee + processingFee)
        };
    }

    function round2(n) { return Math.round((Number(n) + Number.EPSILON) * 100) / 100; }

// A bill, not a summary box.
//
// The amounts right-align in the mono so the digits line up, and the
// total carries the heaviest weight on the page, because it is the one
// number a student is actually here for.
    function renderQuote(el) {
        var q = quoteNow();
        if (!q) { el.innerHTML = ''; return ''; }

        var h = '<div class="dk-bill">';
        q.lines.forEach(function (l) {
            h += '<div class="dk-bill-row">'
                + '<span class="dk-bill-k">' + escapeHtml(l.label)
                + '<span class="dk-bill-d">' + escapeHtml(l.detail) + '</span></span>'
                + '<span class="dk-bill-a">' + peso(l.amount) + '</span></div>';
        });
        h += '<div class="dk-bill-row is-total">'
            + '<span class="dk-bill-k">Total to pay</span>'
            + '<span class="dk-bill-a" data-total>' + peso(q.total) + '</span></div>';
        h += '</div>';

        el.innerHTML = h;
        return q.total;
    }

    // ── Navigation ───────────────────────────────────────
    function go(step, screenOverride) {
        state.step = step;

        // Hide every screen, then show one. Screen 'err' is a sentinel,
        // so -1 matches nothing and only the error panel shows.
        var panels = document.querySelectorAll('[data-screen]');
        for (var i = 0; i < panels.length; i++) {
            panels[i].hidden = true;
        }
        var target = screenOverride !== undefined
            ? screenOverride
            : String(step);
        var show = document.querySelector('[data-screen="' + target + '"]');
        if (show) { show.hidden = false; }

        renderStepper();
        window.scrollTo({ top: 0, behavior: 'smooth' });

        // The hash keeps a refresh on the right screen. Replaced rather
        // than pushed so Back leaves the wizard instead of walking it
        // backwards - inside the wizard, Back is the on-screen control.
        if (window.history && history.replaceState) {
            history.replaceState(null, '', '#step-' + target);
        }
    }

    // The progress list. It lives in the stub, not across the top of the
    // sheet, because that is where a form carries its own progress - and
    // because it lets the circular stepper go, which was the loudest
    // generic thing on the page.
    //
    // Done steps are struck through rather than ticked. A crossed-off line
    // is the oldest progress indicator there is and it needs no icon.
    function renderStepper() {
        var el = $('wrStepper');
        if (!el) { return; }

        if (state.step <= 0) {
            el.innerHTML = '<li class="is-now"><span class="dk-prog-n">1</span>'
                + '<span class="dk-prog-t">Choose a document</span></li>';
            return;
        }

        var h = '';
        BOOT.numbered.forEach(function (s, idx) {
            var pos = idx + 1;
            var cls = state.step > s.step ? 'is-done' : (state.step === s.step ? 'is-now' : '');
            var mark = state.step > s.step ? '&#10003;' : String(pos);
            h += '<li class="' + cls + '">'
                  + '<span class="dk-prog-n">' + mark + '</span>'
                  + '<span class="dk-prog-t">' + escapeHtml(s.label) + '</span></li>';
        });
        el.innerHTML = h;
    }

    // ── API ──────────────────────────────────────────────
    //
    //  The CSRF header is normally added by js/csrf.js, which patches
    //  window.fetch. It is added explicitly here anyway: this wizard is
    //  the most expensive flow in the portal to have forged (it spends
    //  real money), and a wizard that silently breaks if a cache-busted
    //  copy of csrf.js fails to load is a wizard nobody can use.
    function csrfToken() {
        var m = document.querySelector('meta[name=csrf-token]');
        return m ? (m.getAttribute('content') || '') : '';
    }

    function api(action, fields, isMultipart) {
        var url = API + '?action=' + encodeURIComponent(action);
        var opts = { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() } };
        var body;

        if (isMultipart) {
            body = new FormData();
            Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        } else {
            opts.headers['Content-Type'] = 'application/json';
            body = JSON.stringify(fields);
        }
        opts.body = body;

        return fetch(url, opts).then(function (res) {
            return res.json().then(function (d) { return d; });
        });
    }

    // ── Autosave ─────────────────────────────────────────
    //
    //  Debounced. A draft save on every keystroke would be a database
    //  write per character, and the student gains nothing from a draft
    //  saved two seconds before the one that replaced it.
    var saveTimer = null;
    function scheduleSave() {
        state.dirty = true;
        if (saveTimer) { clearTimeout(saveTimer); }
        saveTimer = setTimeout(saveNow, 700);
    }

    function saveNow() {
        if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
        if (!state.dirty || state.saving || !state.catalogId) { return Promise.resolve(); }

        state.saving = true;
        return api('save_draft', {
            catalog_id: state.catalogId,
            wizard_step: state.step,
            quantity: state.quantity,
            purpose_code: state.purposeCode,
            purpose: state.purpose,
            notes: state.notes,
            payment_method: state.paymentMethod
        }).then(function (d) {
            state.saving = false;
            if (d && d.success) {
                state.draftId = d.data.id;
                state.dirty = false;
                if (BOOT.draft) { BOOT.draft.id = d.data.id; }
            }
            return d;
        }).catch(function () {
            // A failed autosave is not worth interrupting the student
            // for: they are mid-flow and the next change will retry.
            // The Save Draft button reports it explicitly.
            state.saving = false;
            return null;
        });
    }

    // ── Step 0 · Document picker ─────────────────────────
// The catalog as a register: one row per document, code on the left,
    // name and description in the middle, price right-aligned on the same
    // baseline.
    //
    // A grid of tiles gave every document the same visual weight and made
    // a price list into a set of pictures. A register lets it be read
    // DOWN, which is how a price list is actually read.
    function renderDocGrid(filter) {
        var grid = $('wrDocGrid');
        if (!grid) { return; }
        var f = (filter || '').trim().toLowerCase();
        var html = '';

        BOOT.catalog.forEach(function (doc) {
            var hay = (doc.name + ' ' + (doc.description || '') + ' '
                + (doc.keywords || '') + ' ' + (doc.sku || '')).toLowerCase();
            if (f && hay.indexOf(f) === -1) { return; }

            var on = doc.id === state.catalogId;
            var perUnit = doc.fee_type !== 'flat';
            var unit = doc.fee_type === 'per_syllabus' ? 'syllabus' : 'page';

            html += '<button type="button" class="dk-choice' + (on ? ' is-on' : '') + '"'
                + ' data-id="' + doc.id + '"'
                + ' data-sku="' + escapeHtml(doc.sku) + '"'
                + ' aria-pressed="' + (on ? 'true' : 'false') + '">'
                + '<span class="dk-choice-code">' + escapeHtml(doc.sku) + '</span>'
                + '<span>'
                + '<span class="dk-choice-name">' + escapeHtml(doc.name) + '</span>'
                + (doc.description
                    ? '<span class="dk-choice-desc">' + escapeHtml(doc.description) + '</span>'
                    : '')
                + (doc.requirement
                    ? '<span class="dk-choice-desc">Bring: ' + escapeHtml(doc.requirement) + '</span>'
                    : '')
                + '</span>'
                + '<span class="dk-choice-fee">' + peso(doc.base_fee)
                + '<small>' + (perUnit ? 'per ' + unit : 'one-time') + '</small></span>'
                + '</button>';
        });

        if (!html) {
            html = '<div class="dk-note">'
                + '<i class="fa-solid fa-magnifying-glass"></i>'
                + '<div>Nothing matches that. Try a shorter word, or clear the search to see the whole list.</div>'
                + '</div>';
        }
        grid.innerHTML = html;
    }

    function pickDoc(id) {
        state.catalogId = id;
        state.dirty = true;

        // Choosing a document changes the checklist, so any requirement
        // list and attachments belonging to the PREVIOUS document must
        // not be carried over. Clearing them here is what stops a
        // student picking TOR after Good Moral and submitting a photo of
        // their good moral certificate as the Dean's endorsement.
        state.requirements = chosenDoc() ? chosenDoc().requirements.slice() : [];
        state.attachments = [];
        state.missing = state.requirements.filter(function (r) { return r.is_required; })
                                .map(function (r) { return r.label; });

        renderDocGrid($('wrDocSearch').value);
        renderRequirements();
        renderSummaryFields();

        if (state.catalogId) {
            go(1);
            scheduleSave();
        }
    }

    // ── Step 1 · Details ─────────────────────────────────
    function renderPurposes() {
        var sel = $('wrPurposeCode');

        // A placeholder, so the control is never rendered empty. It
        // used to be: state.purposeCode starts as '' and a <select>
        // with no matching option shows BLANK, which reads as a broken
        // control rather than as "choose one" - and the first purpose
        // was silently selected in the DOM while the student saw
        // nothing at all.
        sel.innerHTML = '<option value="">Choose a purpose…</option>'
            + BOOT.purposes.map(function (p) {
                return '<option value="' + p.code + '">' + escapeHtml(p.label) + '</option>';
            }).join('');
        sel.value = state.purposeCode || '';
        updatePurposeField();
    }

    function updatePurposeField() {
        var opt = null;
        BOOT.purposes.forEach(function (p) { if (p.code === state.purposeCode) { opt = p; } });

        // Until a purpose is chosen, the free-text box says so rather
        // than implying the placeholder was a real choice.
        if (!opt) {
            $('wrPurposeLabel').textContent = 'Tell us what it is for';
            $('wrPurposeRequired').hidden = false;
            $('wrPurpose').placeholder = 'Choose a purpose above first';
            $('wrPurposeHint').textContent = 'Pick a purpose so the office knows who to prepare the document for.';
            return;
        }

        var needsDetail = opt.needs_detail;
        var input = $('wrPurpose');
        var label = $('wrPurposeLabel');
        var req = $('wrPurposeRequired');

        if (needsDetail) {
            label.textContent = 'Tell us what it is for';
            req.hidden = false;
            input.hidden = false;
            input.placeholder = 'Describe what you need this document for';
            $('wrPurposeHint').textContent = 'You picked "Other", so a sentence here is required.';
        } else {
            label.textContent = 'Anything to add about the purpose?';
            req.hidden = true;
            input.hidden = false;
            input.placeholder = 'Optional — e.g. which employer, which school';
            $('wrPurposeHint').textContent = 'You picked "' + (opt ? opt.label : '')
                + '". Add a detail only if it helps.';
        }
    }

    function renderQuantity() {
        var doc = chosenDoc();
        var field = $('wrQtyField');
        if (!doc) { field.hidden = true; return; }

        var perUnit = doc.fee_type !== 'flat';
        field.hidden = !perUnit;
        if (!perUnit) {
            state.quantity = 1;
            $('wrQty').value = 1;
            return;
        }
        var unit = doc.fee_type === 'per_syllabus' ? 'syllabus' : 'page';
        $('wrQtyHint').textContent = 'This document is charged per ' + unit + '.';
        $('wrQty').max = doc.sku === 'DOC-TOR' ? 50 : 100;
    }

    // The stub. Four facts and nothing else, because a form margin that
    // grows stops being a margin.
    //
    // The control number is set in the mono at the largest size on the
    // page, because it is the identity of the request. Before a draft has
    // ever been filed there is no number, and the stub says so in the faint
    // ink rather than showing a dash or an empty cell.
    function renderStub() {
        var el = $('wrStubFacts');
        if (!el) { return; }
        var doc = chosenDoc();
        var q = quoteNow();
        var no = '';
        if (state.submitted && state.submitted.request_id) {
            no = state.submitted.request_id;
        } else if (BOOT.draft && BOOT.draft.request_id) {
            no = BOOT.draft.request_id;
        }

        var rows = [['Control no.', no || 'Not assigned yet', true]];

        if (doc) {
            rows.push(['Document',
                '<span class="dk-sku">' + escapeHtml(doc.sku) + '</span>'
                    + '<span class="dk-docname">' + escapeHtml(doc.name) + '</span>' ]);
            rows.push(['Copies', doc.fee_type === 'flat'
                ? 'One' : String(state.quantity || 1)]);
            if (doc.sla_days) {
                rows.push(['Turnaround', doc.sla_days + (doc.sla_days === 1
                    ? ' working day' : ' working days')]);
            }
        } else {
            rows.push(['Document', 'Not chosen yet']);
        }

        rows.push(['Fee', q ? peso(q.total) : '—']);

        el.innerHTML = rows.map(function (r) {
            var cls = r[2] === true ? ' dk-fact--no' : '';
            var no2 = (r[2] === true && !r[1]) ? ' is-none' : '';
            return '<div class="dk-fact' + cls + '">'
                + '<span class="dk-fact-k">' + r[0] + '</span>'
                + '<span class="dk-fact-v' + no2 + '">' + r[1] + '</span></div>';
        }).join('');
    }

    function renderSummaryFields() {
        var doc = chosenDoc();
        if (!doc) { renderStub(); return; }
        $('wrQty').max = doc.sku === 'DOC-TOR' ? 50 : 100;
        renderQuantity();
        renderQuote($('wrQuote'));
        renderStub();
    }

    // ── Step 2 · Uploads ─────────────────────────────────
// A requirement is a line in a checklist with a box to tick, which is
    // what a clerk marks on a paper form. The box is a real empty square
    // that fills when the file is there.
    //
    // "Required" is a word, not a badge: a red pill next to every row
    // reads as three alarms, and only the first is true at a time.
    function renderRequirements() {
        var list = $('wrReqList');
        var doc = chosenDoc();
        if (!list) { return; }

        if (!doc || state.requirements.length === 0) {
            list.innerHTML = '<div class="dk-note is-done">'
                + '<i class="fa-solid fa-circle-check"></i>'
                + '<div><b>Nothing to upload for this document.</b>'
                + 'Just bring a valid student ID when you collect it. You can still attach extra files below if you want to.</div>'
                + '</div>';
            renderFiles();
            return;
        }

        var have = {};
        state.attachments.forEach(function (a) {
            if (a.code) { have[a.code] = (have[a.code] || 0) + 1; }
        });

        list.innerHTML = '<div class="dk-reqs">' + state.requirements.map(function (r) {
            var met = (have[r.code] || 0) >= r.max_files;
            var cls = met ? 'is-have' : (r.is_required ? 'is-missing' : 'is-opt');

            var right = met
                ? '<span class="dk-req-flag">Attached</span>'
                : '<button type="button" class="dk-btn dk-btn--sm" data-upload="' + escapeHtml(r.code) + '">'
                  + 'Upload</button>';

            return '<div class="dk-req-row ' + cls + '">'
                + '<span class="dk-req-box">' + (met ? '&#10003;' : '') + '</span>'
                + '<span>'
                + '<span class="dk-req-t">' + escapeHtml(r.label)
                + '<span class="dk-req-flag">' + (r.is_required ? 'Required' : 'Optional') + '</span>'
                + '</span>'
                + (r.hint ? '<span class="dk-req-d">' + escapeHtml(r.hint) + '</span>' : '')
                + '</span>'
                + '<span>' + right + '</span>'
                + '</div>';
        }).join('') + '</div>';

        var buttons = list.querySelectorAll('[data-upload]');
        for (var i = 0; i < buttons.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    pickAndUpload(btn.dataset.upload);
                });
            })(buttons[i]);
        }

        renderFiles();
    }

    function renderFiles() {
        var el = $('wrFileList');
        if (state.attachments.length === 0) { el.innerHTML = ''; return; }

        var byLabel = {};
        state.requirements.forEach(function (r) { byLabel[r.code] = r.label; });

        el.innerHTML = state.attachments.map(function (a) {
            var label = a.code ? (byLabel[a.code] || a.code) : 'Extra file';
            var kb = (a.size / 1024).toFixed(0);
            return '<div class="wr-file">'
                 + '<i class="fa-solid fa-file ' + (a.code ? 'fa-file-circle-check' : 'fa-file-lines') + ' wr-file-icon"></i>'
                 + '<span class="wr-file-name" title="' + escapeHtml(a.name) + '">'
                 + escapeHtml(a.name)
                 + ' <span style="color:var(--text-faint);">&middot; ' + escapeHtml(label) + '</span></span>'
                 + '<span class="wr-file-size">' + kb + ' KB</span>'
                 + '<button type="button" class="wr-file-drop" data-remove="' + a.id + '" aria-label="Remove ' + escapeHtml(a.name) + '">'
                 + '<i class="fa-solid fa-xmark"></i></button>'
                 + '</div>';
        }).join('');

        var drops = el.querySelectorAll('[data-remove]');
        for (var i = 0; i < drops.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () { removeFile(parseInt(btn.dataset.remove, 10)); });
            })(drops[i]);
        }
    }

    function pickAndUpload(code) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = BOOT.limits.ext.map(function (e) { return '.' + e; }).join(',');
        if (!code) { input.multiple = true; }
        input.addEventListener('change', function () {
            if (input.files && input.files.length) {
                uploadFiles(Array.prototype.slice.call(input.files), code);
            }
        });
        input.click();
    }

    function uploadFiles(files, code) {
        state.uploading += files.length;
        var done = 0;
        var problems = [];

        files.forEach(function (file) {
            // Checked here for the same reason the server checks it:
            // a limit enforced in only one of the two places is a limit
            // that quietly stops being enforced. The server message is
            // what a student sees if this is bypassed.
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            if (BOOT.limits.ext.indexOf(ext) === -1) {
                problems.push(file.name + ' — must be ' + BOOT.limits.ext.join(', ').toUpperCase());
                done++; state.uploading--;
                renderUploading();
                return;
            }
            if (file.size > BOOT.limits.max_bytes) {
                problems.push(file.name + ' — ' + (file.size / 1048576).toFixed(1)
                    + ' MB, limit is ' + (BOOT.limits.max_bytes / 1048576) + ' MB');
                done++; state.uploading--;
                renderUploading();
                return;
            }

            var fd = new FormData();
            fd.set('request_id', String(state.draftId || 0));
            if (code) { fd.set('requirement_code', code); }
            fd.set('attachment', file);

            fetch(API + '?action=upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken() },
                body: fd
            }).then(function (res) { return res.json(); })
              .then(function (d) {
                done++; state.uploading--;
                if (d && d.success) {
                    state.attachments = d.data.request.attachments;
                    state.missing = d.data.missing;
                    renderRequirements();
                    // Re-rendered on every successful upload, not just
                    // on entry to the step: the whole point of the
                    // notice is to shrink as files land, and a counter
                    // that only updates when you leave and come back
                    // makes uploading feel like it is not working.
                    renderMissingNotice();
                    renderUploading();
                    if (done === files.length) {
                        toast(d.message, 'success');
                    }
                } else {
                    problems.push(file.name + ' — ' + ((d && d.message) || 'upload failed'));
                    if (done === files.length) { reportUploads(problems); }
                    renderUploading();
                }
              })
              .catch(function () {
                done++; state.uploading--;
                problems.push(file.name + ' — network error');
                if (done === files.length) { reportUploads(problems); }
                renderUploading();
              });
        });
    }

    function reportUploads(problems) {
        if (problems.length === 0) { return; }
        // One message listing everything that failed, rather than a
        // toast per file: five red toasts in a row is unreadable and
        // the student cannot tell which file was the problem.
        toast(problems.length === 1
            ? problems[0]
            : problems.length + ' files were not uploaded. First problem: ' + problems[0],
            'error');
    }

    function renderUploading() {
        if (state.uploading <= 0) { return; }
        var note = $('wrDropNote');
        if (note) {
            note.textContent = state.uploading + ' file' + (state.uploading === 1 ? '' : 's')
                + ' uploading…';
        }
    }

    function removeFile(id) {
        api('remove_attachment', { attachment_id: id }).then(function (d) {
            if (d && d.success) {
                state.attachments = d.data.request.attachments;
                state.missing = d.data.missing;
                renderRequirements();
                renderMissingNotice();
                toast(d.message, 'success');
            } else {
                toast((d && d.message) || 'Could not remove that file.', 'error');
            }
        });
    }

    function renderMissingNotice() {
        var el = $('wrMissingNotice');
        if (!state.missing || state.missing.length === 0) {
            el.innerHTML = '<div class="wr-notice is-ok">'
                + '<i class="fa-solid fa-circle-check"></i><div>'
                + '<b>Everything this document needs is uploaded</b>'
                + 'You can still add more, or submit now.'
                + '</div></div>';
            return;
        }
        el.innerHTML = '<div class="wr-notice is-warn">'
            + '<i class="fa-solid fa-triangle-exclamation"></i><div>'
            + '<b>Still to attach: ' + state.missing.length + '</b>'
            + escapeHtml(state.missing.join(' · '))
            + '<br><span style="opacity:.85">You can submit without these — the office will simply ask you for them when you arrive.</span>'
            + '</div></div>';
    }

    // ── Step 3 · Review ──────────────────────────────────
    function renderReview() {
        var doc = chosenDoc();
        if (!doc) { return; }

        var total = renderQuote($('wrReviewQuote'));

        var byLabel = {};
        state.requirements.forEach(function (r) { byLabel[r.code] = r.label; });

        var rows = [
            ['Document', doc.name],
            ['Purpose', state.purpose
                ? state.purpose
                : (purposeLabel() || '—')],
            ['Copies', state.perUnitText()],
        ];
        rows.push(['Attachments', state.attachments.length === 0
            ? 'None'
            : state.attachments.length + ' file' + (state.attachments.length === 1 ? '' : 's')
              + (state.missing.length ? ' (' + state.missing.length + ' requirement still missing)' : '')]);

        $('wrReviewSummary').innerHTML = rows.map(function (r) {
            return '<div class="wr-sum-row"><span class="wr-sum-key">' + escapeHtml(r[0]) + '</span>'
                 + '<span class="wr-sum-val">' + escapeHtml(r[1]) + '</span></div>';
        }).join('');

        updateSubmitEnabled();
    }

    state.perUnitText = function () {
        var doc = chosenDoc();
        if (!doc) { return '1'; }
        if (doc.fee_type === 'flat') { return '1 (flat fee)'; }
        var unit = doc.fee_type === 'per_syllabus' ? 'syllabus' : 'page';
        return state.quantity + ' ' + unit + (state.quantity === 1 ? '' : 's');
    };

    function purposeLabel() {
        var out = '';
        BOOT.purposes.forEach(function (p) { if (p.code === state.purposeCode) { out = p.label; } });
        return out;
    }

    function updateSubmitEnabled() {
        // Disabled rather than hidden, so the button is visibly there
        // and the reason is obvious - a Submit that appears only once
        // both boxes are ticked makes people hunt for it.
        $('wrSubmitBtn').disabled = !($('wrCertifyTrue').checked && $('wrCertifyPrivacy').checked);
    }

    // ── Step 4 · Payment ─────────────────────────────────
    function renderPayment() {
        var r = state.submitted;
        if (!r) { return; }

        $('wrPayTracking').textContent = r.request_id;
        renderQuote($('wrPayQuote'));

        var body = $('wrPayBody');

        if (r.status_tone === 'warning' && r.receipt_state === 'none') {
            // Still owed money. The channel is chosen here, and the
            // request waits for it - which is why the screen leads
            // with the amount rather than with the buttons.
            body.innerHTML =
                '<div class="wr-notice is-warn"><i class="fa-solid fa-clock"></i><div>'
                + '<b>' + peso(r.total) + ' to pay</b>'
                + 'Pay with any channel below, then send us the receipt so the Registrar can confirm it.'
                + '</div></div>'
                + paymentChoicesHtml()
                + gcashPanelHtml()
                + bankPanelHtml(r);
        } else if (r.status_tone === 'muted' && r.document_status === 'Cancelled') {
            body.innerHTML = '<div class="wr-notice is-danger"><i class="fa-solid fa-ban"></i><div>'
                + '<b>This request was cancelled</b>There is nothing to pay.</div></div>';
        } else {
            body.innerHTML = '<div class="wr-notice is-ok"><i class="fa-solid fa-circle-check"></i><div>'
                + '<b>Nothing to pay right now</b>'
                + (r.document_status === 'Pending_Clearance'
                    ? 'Your request is held until your outstanding balance is settled at the office.'
                    : 'Pay at the Registrar\'s counter when you collect.')
                + '</div></div>';
        }
    }

    function paymentChoicesHtml() {
        return '<span class="wr-label" id="wrPayMethodLabel">How would you like to pay?</span>'
             + '<div class="wr-choices" role="radiogroup" aria-labelledby="wrPayMethodLabel">'
             + BOOT.payment.map(function (o) {
                 var icon = o.brand === 'gcash'
                     ? 'linear-gradient(135deg,#0074e0,#0057b8)'
                     : (o.brand === 'bank' ? 'linear-gradient(135deg,#0f766e,#115e59)'
                                          : 'linear-gradient(135deg,#64748b,#475569)');
                 return '<label class="wr-choice">'
                      + '<input type="radio" name="wr_payment" value="' + o.value + '"'
                      + (state.paymentMethod === o.value ? ' checked' : '') + '>'
                      + '<span class="wr-choice-mark"></span>'
                      + '<span class="wr-choice-icon" style="background:' + icon + '"><i class="fa-solid ' + o.icon + '"></i></span>'
                      + '<span class="wr-choice-text">'
                      + '<span class="wr-choice-name">' + escapeHtml(o.label) + '</span>'
                      + '<span class="wr-choice-note">' + escapeHtml(o.note) + '</span>'
                      + '</span></label>';
             }).join('')
             + '</div>';
    }

    function gcashPanelHtml() {
        // Both states rendered, with the MISSING notice as the resting
        // state the image replaces. A broken-image icon inside a
        // payment screen is worse than no icon: it looks like the
        // student's phone failed.
        return '<div id="wrGcashPanel" style="margin-top:16px;">'
             + '<div class="wr-notice is-danger" id="wrGcashMissing"' + (GCASH_QR.exists ? ' hidden' : '') + '>'
             + '<i class="fa-solid fa-triangle-exclamation"></i><div>'
             + '<b>GCash payment is not available right now</b>'
             + 'Please pay at the Registrar counter instead.'
             + '</div></div>'
             + (GCASH_QR.exists
                 ? '<div class="wr-quote"><div class="wr-quote-head">Scan with GCash</div>'
                   + '<div style="padding:20px; text-align:center;">'
                   + '<img id="wrGcashImg" src="' + escapeHtml(GCASH_QR.url) + '" alt="GCash QR code" '
                   + 'style="max-width:240px; width:100%; border-radius:12px;" '
                   + 'onerror="document.getElementById(\'wrGcashImg\').hidden=true;'
                   + 'document.getElementById(\'wrGcashMissing\').hidden=false;">'
                   + '</div></div>'
                   + '<ol class="wr-next" style="max-width:none; margin-top:14px;">'
                   + '<li>Open GCash and choose <b>Scan QR</b>.</li>'
                   + '<li>Enter the amount shown above.</li>'
                   + '<li>Pay, then attach the receipt under <b>My Requests</b>.</li>'
                   + '</ol>'
                 : '')
             + '</div>';
    }

    function bankPanelHtml(r) {
        if (state.paymentMethod !== 'Bank_Transfer') { return ''; }
        return '<div class="wr-notice is-info" style="margin-top:16px;"><i class="fa-solid fa-building-columns"></i><div>'
             + '<b>Bank transfer</b>'
             + 'Send ' + peso(r.total) + ' to the school account, quoting reference '
             + '<b>' + escapeHtml(r.payment_reference || '—') + '</b>, then attach the deposit slip under My Requests. '
             + 'The reference is what Finance matches against.'
             + '</div></div>';
    }

    // ── Confirmation ─────────────────────────────────────
    function renderDone() {
        var r = state.submitted;
        if (!r) { return; }

        $('wrDoneTracking').textContent = r.request_id;
        $('wrDoneText').textContent = r.expectation.detail
            || 'Your request has been submitted and is now with the Registrar.';

        $('wrDoneFacts').innerHTML = [
            ['Document', r.document_name],
            ['Tracking number', r.request_id],
            ['Amount', peso(r.total)],
            ['Estimated release', r.estimated_release_label],
        ].map(function (row) {
            return '<div class="wr-sum-row"><span class="wr-sum-key">' + escapeHtml(row[0]) + '</span>'
                 + '<span class="wr-sum-val">' + escapeHtml(row[1]) + '</span></div>';
        }).join('');

        // The next steps, stated as what will happen rather than as a
        // status label the student has to interpret.
        //
        // The release line reuses the same date as the facts table
        // above it. It used to read "prepared (about Oct 13, 2026)",
        // which is a date with a hedge bolted in front of it. Where the
        // date is known it is stated plainly and the estimate is not
        // hedged twice.
        var steps = [];
        if (r.document_status === 'Awaiting_Payment') {
            steps.push('Pay ' + peso(r.total) + ' and send us the receipt.');
        } else if (r.document_status === 'Pending_Clearance') {
            steps.push('Settle your outstanding balance at the office.');
        }
        steps.push('The Registrar verifies your documents (about 1 day).');
        var confirmed = r.estimated_release_label
            && r.estimated_release_label !== 'To be confirmed by the Registrar';
        steps.push(confirmed
            ? 'Your document is prepared and ready to collect by ' + r.estimated_release_label + '.'
            : 'Your document is prepared - the Registrar will confirm a collection date.');
        steps.push("You'll be notified when it's ready to collect.");
        if (state.missing && state.missing.length) {
            steps.push('Bring these if you have them: ' + state.missing.join(', ') + '.');
        }

        $('wrDoneNext').innerHTML = steps.map(function (s) {
            return '<li>' + escapeHtml(s) + '</li>';
        }).join('');

        $('wrTrackLink').setAttribute('href', TRACK_PAGE + '?id=' + encodeURIComponent(r.id));

        // The stepper is meaningless once there is nothing to advance.
        $('wrStepper').style.display = 'none';
    }

    // ── Submit ───────────────────────────────────────────
    function submit() {
        if (!($('wrCertifyTrue').checked && $('wrCertifyPrivacy').checked)) {
            toast('Tick both boxes to submit.', 'error');
            return;
        }

        // Save first, so the submit is judged against what is actually
        // stored rather than against whatever is in a form field the
        // autosave has not caught up with.
        var btn = $('wrSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting…';

        state.dirty = true;
        saveNow().then(function () {
            return api('submit', {
                request_id: state.draftId,
                certify_true: 1,
                certify_privacy: 1
            });
        }).then(function (d) {
            if (!d || !d.success) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit request';
                var step = (d && d.step) || null;
                if (step !== null) {
                    go(step);
                    toast((d && d.message) || 'Please fix the highlighted step.', 'error');
                } else {
                    toast((d && d.message) || 'Could not submit. Please try again.', 'error');
                }
                return;
            }
            state.submitted = d.data.request;
            state.missing = d.data.missing || [];
            state.dirty = false;
            if (BOOT.draft) { BOOT.draft = null; }
            renderDone();
            go(5);
            toast(d.message, 'success');
        }).catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit request';
            toast('Network error — your request is saved as a draft. Try again.', 'error');
        });
    }

    function copyTracking(btn, value) {
        if (!value) { return; }
        var done = function () {
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
            setTimeout(function () {
                btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
            }, 1600);
        };

        // navigator.clipboard needs a secure context, which plain-HTTP
        // XAMPP is not. Falling back to a temporary textarea is what
        // makes the button work on the machine it is actually developed
        // on rather than only in production.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(done).catch(function () { legacyCopy(value, done); });
        } else {
            legacyCopy(value, done);
        }
    }

    function legacyCopy(value, done) {
        var ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            toast('Copy it by hand: ' + value, 'info');
        }
        document.body.removeChild(ta);
    }

    // ── Wiring ───────────────────────────────────────────
    function wire() {
        // Delegated, not bound per row: the register is re-rendered on
        // every keystroke of the search box, so per-row handlers would be
        // re-attached dozens of times a second.
        $('wrDocGrid').addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('.dk-choice') : null;
            if (!row) { return; }
            pickDoc(parseInt(row.dataset.id, 10));
        });

        $('wrDocSearch').addEventListener('input', function () {
            renderDocGrid(this.value);
        });

        $('wrPickNext').addEventListener('click', function () {
            if (state.catalogId) { go(1); scheduleSave(); }
        });

        $('wrPurposeCode').addEventListener('change', function () {
            state.purposeCode = this.value;
            updatePurposeField();
            scheduleSave();
        });

        $('wrPurpose').addEventListener('input', function () {
            state.purpose = this.value;
            scheduleSave();
        });

        $('wrNotes').addEventListener('input', function () {
            state.notes = this.value;
            scheduleSave();
        });

        $('wrQty').addEventListener('input', function () {
            var v = parseInt(this.value, 10);
            var max = parseInt(this.max, 10) || 100;
            state.quantity = Math.max(1, Math.min(max, isNaN(v) ? 1 : v));
            renderQuote($('wrQuote'));
            scheduleSave();
        });
        $('wrQtyPlus').addEventListener('click', function () {
            $('wrQty').value = Math.min(parseInt($('wrQty').max, 10) || 100, state.quantity + 1);
            $('wrQty').dispatchEvent(new Event('input'));
        });
        $('wrQtyMinus').addEventListener('click', function () {
            $('wrQty').value = Math.max(1, state.quantity - 1);
            $('wrQty').dispatchEvent(new Event('input'));
        });

        // Any [data-goto] moves the stepper. Wired by delegation because
        // the buttons live inside panels that are re-rendered.
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-goto]') : null;
            if (!b) { return; }
            var to = parseInt(b.dataset.goto, 10);
            if (isNaN(to)) { return; }
            // Leaving a step always persists what is on it.
            saveNow();
            if (to === 2) {
                // The notice lives on the UPLOADS step, so it is
                // rendered on entry to 2 - not to 3. Rendering it only
                // on the way to review left it blank for the whole time
                // the student was looking at the checklist it describes,
                // which is precisely when they need to read it.
                renderMissingNotice();
                renderRequirements();
            }
            if (to === 3) { renderReview(); }
            if (to === 4) { renderPayment(); }
            go(to);
        });

        $('wrCertifyTrue').addEventListener('change', updateSubmitEnabled);
        $('wrCertifyPrivacy').addEventListener('change', updateSubmitEnabled);
        $('wrSubmitBtn').addEventListener('click', submit);

        $('wrSaveDraftBtn').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            state.dirty = true;
            saveNow().then(function (d) {
                btn.disabled = false;
                toast(d && d.success ? 'Draft saved. Come back any time.' : 'Could not save the draft.', d && d.success ? 'success' : 'error');
            });
        });

        // Dropzone. click is on the LABEL, so the hidden input inside it
        // opens natively; only drag-and-drop needs wiring.
        var drop = $('wrDrop');
        var dropInput = $('wrDropInput');
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                drop.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                drop.classList.remove('is-over');
            });
        });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                uploadFiles(Array.prototype.slice.call(e.dataTransfer.files), null);
            }
        });
        dropInput.addEventListener('change', function () {
            if (this.files && this.files.length) {
                uploadFiles(Array.prototype.slice.call(this.files), null);
                this.value = '';
            }
        });

        $('wrPayCopy').addEventListener('click', function () {
            copyTracking(this, state.submitted ? state.submitted.request_id : '');
        });
        $('wrDoneCopy').addEventListener('click', function () {
            copyTracking(this, state.submitted ? state.submitted.request_id : '');
        });

        $('wrPayLaterBtn').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            api('pay_later', { request_id: state.draftId }).then(function (d) {
                btn.disabled = false;
                if (d && d.success) {
                    toast(d.message, 'success');
                    if (state.submitted) {
                        state.submitted.receipt_state = 'not_required';
                        state.submitted.document_status = 'Awaiting_Payment';
                    }
                    renderPayment();
                } else {
                    toast((d && d.message) || 'Could not record that.', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                toast('Network error.', 'error');
            });
        });

        // The payment radio group is re-rendered by renderPayment(), so
        // it is bound by delegation rather than per render.
        document.addEventListener('change', function (e) {
            if (e.target && e.target.name === 'wr_payment') {
                state.paymentMethod = e.target.value;
                renderPayment();
                scheduleSave();
            }
        });

        // Before unloading with unsaved edits, save synchronously
        // enough to be useful: the debounce may not have fired.
        window.addEventListener('beforeunload', function () {
            if (state.dirty && state.catalogId) {
                state.dirty = false;
                // sendBeacon survives the page going away; fetch does not
                // reliably, and a lost draft is exactly what the
                // autosave exists to prevent.
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(API + '?action=save_draft', new Blob([
                        JSON.stringify({
                            catalog_id: state.catalogId, wizard_step: state.step,
                            quantity: state.quantity, purpose_code: state.purposeCode,
                            purpose: state.purpose, notes: state.notes,
                            payment_method: state.paymentMethod
                        })
                    ], { type: 'application/json' }));
                }
            }
        });

        // A hash on the URL is honoured, so a refresh lands on the same
        // step rather than back at the document picker.
        var m = /^#step-(\d+)$/.exec(window.location.hash || '');
        if (m && state.catalogId) {
            var target = parseInt(m[1], 10);
            if (target >= 1 && target <= 4) {
                go(target);
                if (target === 3) { renderMissingNotice(); renderRequirements(); renderReview(); }
                if (target === 4) { renderPayment(); }
            }
        }
    }

    // ── Boot ─────────────────────────────────────────────
    function boot() {
        $('wrDropNote').textContent = 'Accepted: ' + BOOT.limits.ext.join(', ').toUpperCase()
            + ' · up to ' + (BOOT.limits.max_bytes / 1048576) + ' MB each';

        // Resume the draft before rendering, so the picker shows the
        // previously chosen document as selected.
        if (BOOT.draft) {
            state.draftId = BOOT.draft.id;
            state.catalogId = BOOT.draft.catalog_id;
            state.quantity = BOOT.draft.quantity || 1;
            state.paymentMethod = BOOT.draft.payment_method || 'Counter';
            state.purposeCode = BOOT.draft.purpose_code || '';
            state.purpose = BOOT.draft.purpose || '';
            state.notes = BOOT.draft.notes || '';

            var doc = chosenDoc();
            state.requirements = doc ? doc.requirements.slice() : [];
            state.attachments = BOOT.draft.requirements.attached || [];
            state.missing = BOOT.draft.requirements.missing || [];
        }

        renderPurposes();
        $('wrPurpose').value = state.purpose;
        $('wrNotes').value = state.notes;
        $('wrQty').value = state.quantity;

        renderDocGrid('');
        renderSummaryFields();
        renderRequirements();

        if (BOOT.draft && BOOT.draft.wizard_step >= 1) {
            go(Math.min(BOOT.draft.wizard_step, 3));
            renderMissingNotice();
            if (BOOT.draft.wizard_step >= 3) { renderReview(); }
        } else {
            go(0);
        }

        wire();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>

<?php include '../includes/footer.php'; ?>