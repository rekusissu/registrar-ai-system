<?php
// ============================================================
//  REGISTRAR/DOCUMENTS-ADD.PHP
//  New document request — the standalone (phone / walk-in) form.
//
//  This page and the New Request modal on documents.php file the
//  same row through the same endpoint. They used to disagree: the
//  modal had been rebuilt and this one had not, so the modal spoke
//  the current vocabulary (pickup only, no Express, a Waiting on
//  note) while this page still offered a Digital download, an
//  Express priority and a Recipient field that all died with the
//  courier. Both now render from ONE design language (the nq-*
//  system on documents.css) and send ONE payload.
//
//  What the form does NOT offer is as deliberate as what it does.
//  A walk-in document is collected by the student it was filed for,
//  is always Regular, and is paid at the counter. Offering those as
//  choices implied a courier and a fee gate the office does not run.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/schema.php';
require_once __DIR__ . '/../shared/document_process.php';

$db = Database::getInstance();
// Same status filter as documents.php: the column default is 'enrolled', so
// `status = 'active'` hid most students from the picker. See the note there.
$students = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name, ' ', last_name) AS name
       FROM students
      WHERE status IS NULL OR status NOT IN ('archived')
      ORDER BY name"
);
$catalog = db_fill_optional(
    $db->fetchAll("SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id"),
    'document_catalog',
    ['sla_days', 'requirement']
);

$page_title = 'New Document Request';
$page_description = 'File a document request on behalf of a student';
$body_page = 'documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';
$extra_css = ['documents.css'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>New Document Request</h1>
        <p>File a request on behalf of a student who walked in or called.</p>
    </div>
    <div class="header-actions">
        <a href="documents.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to requests</a>
    </div>
</header>

<?php // The one loud thing on the page is the fee, and it is stated as a
      // FACT about the request rather than a field to fill in. The clerk
      // quotes it across the counter; it is not an input. ?>
<div class="nq-sheet">
    <form id="addDocumentForm">

        <ol class="nq-steps">
            <li class="nq-step">
                <div class="nq-step-mark" aria-hidden="true">1</div>
                <div class="nq-step-body">
                    <div class="nq-step-label" id="addStudentLabel">Student</div>
                    <select name="student_id" id="addStudent" class="form-control" data-searchable required aria-labelledby="addStudentLabel">
                        <option value="">Search or select a student&hellip;</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= (int) $student['id'] ?>">
                                <?php // A student_number is nullable, and an empty
                                      // one printed a bare " - Name" that looked
                                      // like a rendering fault. Show the name
                                      // alone when there is no number. ?>
                                <?= htmlspecialchars(trim((string) $student['student_number'] . ' - ' . $student['name'], ' -')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="nq-hint">The request is filed on this student's record and appears on the desk immediately.</p>
                </div>
            </li>

        <li class="nq-step">
                <div class="nq-step-mark" aria-hidden="true">2</div>
                <div class="nq-step-body">
                    <div class="nq-step-label" id="addDocLabel">Document</div>
                    <select name="catalog_id" id="catalogSelect" class="form-control" required aria-labelledby="addDocLabel">
                        <option value="">Select a document&hellip;</option>
                        <?php foreach ($catalog as $c):
                            // Name the unit, never the enum. The enum already
                            // carries its own preposition ("per_page"), so
                            // appending " per " to the de-underscored value
                            // printed "₱250.00 per per page" in the clerk's
                            // dropdown. The modal already fixed this with a
                            // lookup; this page was still doing it the old
                            // way, and the two disagreed on screen.
                            $units = ['per_page' => 'page', 'per_syllabus' => 'syllabus'];
                            $unit  = $units[$c['fee_type']] ?? '';
                            $feeTxt = '&#8369;' . number_format((float) $c['base_fee'], 2)
                                . ($unit !== '' ? ' per ' . $unit : ''); ?>
                            <option value="<?= (int) $c['id'] ?>"
                                data-fee="<?= (float) $c['base_fee'] ?>"
                                data-fee-type="<?= htmlspecialchars($c['fee_type']) ?>"
                                data-req="<?= htmlspecialchars((string) $c['requirement'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($c['name']) ?> (<?= $feeTxt ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- A requirement is a fact about the document, not an
                         error. It appears under the field it belongs to and
                         it never blocks the form. -->
                    <div id="catalogHint" class="req-hint" role="status"></div>

                    <div class="nq-field nq-qty" id="qtyGroup" hidden>
                        <label for="quantity">How many</label>
                        <input type="number" name="quantity" id="quantity" class="form-control" value="1" min="1" max="100" />
                        <p class="nq-hint" id="qtyHint">This document is charged per page.</p>
                    </div>
                </div>
            </li>

        <li class="nq-step">
                <div class="nq-step-mark" aria-hidden="true">3</div>
                <div class="nq-step-body">
                    <div class="nq-step-label" id="addWhyLabel">Why it is needed</div>
                    <div class="nq-fields">
                        <div class="nq-field">
                            <label for="addPurpose">Purpose<span class="nq-req">*</span></label>
                            <input type="text" name="purpose" id="addPurpose" class="form-control" placeholder="Employment requirement" required>
                        </div>
                        <div class="nq-field">
                            <?php // The one thing this form can record that the
                                  // system cannot derive: what the clerk knows
                                  // right now. Empty means the desk can start,
                                  // which is the right default. ?>
                            <label for="addWaitingOn">Waiting on</label>
                            <input type="text" name="waiting_on" id="addWaitingOn" class="form-control"
                                   list="addWaitingOnPresets" maxlength="160"
                                   placeholder="Nothing &mdash; ready to start">
                            <datalist id="addWaitingOnPresets">
                                <option value="Dean&rsquo;s office approval"></option>
                                <option value="Outstanding balance"></option>
                                <option value="Student ID being reprinted"></option>
                                <option value="Guidance Office signature"></option>
                                <option value="Original document not yet returned"></option>
                            </datalist>
                            <p class="nq-hint">Optional. Fill this in only if the desk genuinely cannot start yet.</p>
                        </div>
                    </div>
                </div>
            </li>
        </ol>

        <div class="nq-facts">
            <div class="nq-fee">
                <div>
                    <div class="nq-fee-cap">Fee due at filing</div>
                    <div class="nq-fee-note" id="feeNote">&#8369;0.00 &middot; take this when the student pays</div>
                </div>
                <div class="nq-fee-amount" id="feePreview">&#8369;0.00</div>
            </div>
            <p class="nq-fact"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> Collected at the registrar counter</p>
        </div>

        <?php // Priority is sent as a fixed value rather than offered as a
              // choice. The API validates it, so the field has to exist, but
              // a one-option control invites the clerk to wonder what the
              // other option did. A walk-in is always Regular.
              //
              // fulfillment_type went the same way and then further: there is
              // only one way to hand a document over, so the API records it
              // itself and this form does not send it at all. ?>
        <input type="hidden" name="request_type" value="Regular">
        <?php // payment_method is what decides the request's opening status at
              // the API (student-documents.php:320-326). Left unset it
              // defaults to 'Online', which files the request as
              // Awaiting_Payment — a stage the walk-in track does not have,
              // so doc_next_step() returned null and the row sat on the desk
              // with no action button and no way forward. 'Counter' is
              // mapped to Cash_on_Delivery and starts at Filed. ?>
        <input type="hidden" name="payment_method" value="Counter">

        <div class="nq-actions">
            <a href="documents.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary nq-submit">
                <i class="fa-solid fa-plus"></i> Add request
            </button>
        </div>
    </form>
</div>

</div>
</main>

<script>
(function () {
    const form   = document.getElementById('addDocumentForm');
    const studentSel = document.getElementById('addStudent');
    const catalogSel = document.getElementById('catalogSelect');
    const qtyGroup = document.getElementById('qtyGroup');
    const qtyHint  = document.getElementById('qtyHint');
    const quantity = document.getElementById('quantity');
    const purpose  = document.getElementById('addPurpose');
    const feePreview = document.getElementById('feePreview');
    const feeNote  = document.getElementById('feeNote');
    const hint     = document.getElementById('catalogHint');
    const submit   = form.querySelector('button[type="submit"]');

    // The wording of the per-unit hint follows the unit, so switching from a
    // TOR to a Course Description does not leave "per page" sitting under a
    // field that now counts syllabi.
    const UNITS = { per_page: 'page', per_syllabus: 'syllabus' };

    function pesos(n) {
        return '₱' + n.toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    function currentOpt() {
        const opt = catalogSel.selectedOptions[0];
        return opt && opt.value ? opt : null;
    }

    // The fee is recomputed here from the same arithmetic the API uses
    // (api/student-documents.php:290). The client's number is never sent or
    // trusted; this exists so the clerk can read the figure aloud before the
    // row exists.
    function updateFee() {
        const opt = currentOpt();
        if (!opt) {
            feePreview.textContent = pesos(0);
            feeNote.textContent = pesos(0) + ' · choose a document to see the fee';
            return;
        }
        const fee = parseFloat(opt.dataset.fee || '0');
        const type = opt.dataset.feeType;
        const unit = UNITS[type] || '';
        const qty  = Math.max(1, parseInt(quantity.value || '1', 10) || 1);
        const perUnit = type !== 'flat';

        const total = perUnit ? fee * qty : fee;
        feePreview.textContent = pesos(total);
        feeNote.textContent = perUnit
            ? pesos(fee) + ' per ' + unit + ' × ' + qty
            : 'flat fee · take this when the student pays';
    }

    catalogSel.addEventListener('change', function () {
        const opt = currentOpt();

        // The quantity field exists only for a per-unit document. It is
        // `hidden`, not display:none on a wrapper, so it leaves the tab order
        // and the accessibility tree when it is not wanted.
        const perUnit = !!opt && opt.dataset.feeType !== 'flat';
        qtyGroup.hidden = !perUnit;
        if (perUnit && qtyHint) {
            qtyHint.textContent = 'Charged per ' + (UNITS[opt.dataset.feeType] || 'unit') + '.';
        }

        if (opt && opt.dataset.req) {
            hint.textContent = 'Bring: ' + opt.dataset.req;
            hint.classList.add('visible');
        } else {
            hint.textContent = '';
            hint.classList.remove('visible');
        }
        updateFee();
    });

    quantity.addEventListener('input', updateFee);

    // The step rail fills in as the step below it becomes usable. That is the
    // only motion on this page, and it answers the clerk's next question:
    // "what can I do now?". Reduced motion is respected because the state
    // change is carried by colour and the is-done class, not by the
    // transition alone.
    function paintSteps() {
        const filled = [
            !!studentSel.value,
            !!catalogSel.value,
            !!(purpose && purpose.value.trim())
        ];
        form.querySelectorAll('.nq-step').forEach(function (step, i) {
            step.classList.toggle('is-done', filled[i]);
            // The active step is the first one still empty; with the form
            // complete, the last step is where the eye belongs.
            const firstEmpty = filled.indexOf(false);
            const active = firstEmpty === -1 ? 2 : firstEmpty;
            step.classList.toggle('is-active', i === active);
        });
    }

    [studentSel, catalogSel].forEach(function (el) {
        el.addEventListener('change', paintSteps);
    });
    if (purpose) purpose.addEventListener('input', paintSteps);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const data = Object.fromEntries(new FormData(form).entries());
        data.quantity = Math.max(1, parseInt(data.quantity || '1', 10) || 1);

        submit.disabled = true;
        submit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding…';
        try {
            const res = await fetch('../api/student-documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message || 'Request added.', 'success');
                window.location.href = 'documents.php';
            } else {
                showToast(result.message || 'Could not add the request.', 'error');
                submit.disabled = false;
                submit.innerHTML = '<i class="fa-solid fa-plus"></i> Add request';
            }
        } catch (error) {
            showToast('Could not reach the server. Try again.', 'error');
            submit.disabled = false;
            submit.innerHTML = '<i class="fa-solid fa-plus"></i> Add request';
        }
    });

    paintSteps();
    updateFee();
})();
</script>

<?php include '../includes/footer.php'; ?>
