<?php
// ============================================================
//  STUDENT/DOCUMENT-TRACK.PHP
//  One request, followed: status, timeline, what to bring, and what
//  the student can still do about it.
//
//  The status pill answers "what is this?" in one word. That is not
//  what somebody opens this page for. They want to know how far along
//  it is, when it will be ready, and whether there is anything left
//  for THEM to do - so the timeline is the page, and the pill is a
//  header above it.
//
//  The timeline is built by doc_track_timeline() from stored
//  timestamps and the event log, not from the current status. A
//  timeline drawn off the status collapses to "you are here" and loses
//  every past step, which is the entire reason to come here rather
//  than read a badge.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/document_process.php';
require_once __DIR__ . '/../shared/doc_wizard.php';

$page_title = 'Track Request';
$page_description = 'Follow a document request from filing to collection';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_documents';
$extra_css = ['student.css', 'documents.css', 'doc-request.css', 'docket.css'];
$body_page = 'docket-track';   // scopes css/docket.css

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// ── Which request ───────────────────────────────────────────
//
// From the query string, and ownership-checked. The id is never taken
// at face value: a student who edits the number gets "not available",
// not somebody else's document.
$id = (int) ($_GET['id'] ?? 0);
$row = null;

if ($id > 0) {
    $row = $db->fetchOne('SELECT * FROM document_requests WHERE id = ?', [$id]);
    if (!$row || (int) $row['student_id'] !== (int) $student['id']) {
        // Same message for "no such row" and "not yours". Differing
        // them turns this page into an oracle that confirms which
        // request ids exist across the whole school.
        $row = null;
        $notAvailable = true;
    }
}

$events = [];
$timeline = [];
$requirements = ['requirements' => [], 'attached' => [], 'missing' => [], 'complete' => true];
$expectation = ['headline' => '', 'detail' => ''];
$blocker = null;
$receiptState = 'not_required';
$receiptRequired = false;

if ($row) {
    $events = $db->fetchAll(
        'SELECT * FROM document_request_events WHERE request_id = ? ORDER BY id ASC',
        [(int) $row['id']]
    );
    $timeline = doc_track_timeline($row, $events);

    $catalogId = (int) ($row['catalog_id'] ?? 0);
    $catalog = $catalogId > 0 ? db_fill_optional(
        $db->fetchOne('SELECT * FROM document_catalog WHERE id = ?', [$catalogId]),
        'document_catalog',
        ['sla_days', 'requirement', 'fee_type', 'description']
    ) : null;

    $requirements = doc_requirement_state((int) $row['id'], $catalogId);
    $expectation  = doc_next_expectation($row);
    $receiptRequired = doc_requires_receipt($row);
    $receiptState   = $receiptRequired ? doc_receipt_state($row) : 'not_required';

    $balance = (float) ($db->fetchColumn(
        'SELECT balance FROM finance WHERE student_id = ?', [(int) $row['student_id']]
    ) ?? 0.0);
    $blocker = doc_blocker(array_merge($row, ['balance' => $balance]));
}

$status = $row ? doc_status((string) $row['document_status']) : null;
$docName = $row
    ? (string) ($catalog['name'] ?? str_replace('_', ' ', (string) $row['document_type']))
    : '';

/**
 * Human phrasing for the timeline's timestamps.
 *
 * "Oct 08, 02:00 PM" for anything recent, and a date for anything old
 * enough that the time is noise. The full stamp is in the title
 * attribute, so nothing is actually lost.
 */
function trackWhen(?string $ts): string
{
    if (!$ts) { return ''; }
    $t = strtotime($ts);
    if ($t === false) { return ''; }
    $days = (time() - $t) / 86400;
    if ($days < 1) {
        return 'Today, ' . date('g:i A', $t);
    }
    if ($days < 7) {
        return date('l, g:i A', $t);
    }
    return date('M d, Y', $t);
}

function trackWhenTitle(?string $ts): string
{
    return $ts ? date('M d, Y g:i A', strtotime($ts)) : '';
}
?>

<main class="dashboard-main">
<div class="dashboard-container">
<div class="wr-shell">

    <?php if (!$row): ?>
        <!-- ── Not available ─────────────────────────── -->
        <header class="header" style="margin-bottom:18px;">
            <div class="title">
                <h1>Request not available</h1>
                <p>That request could not be found.</p>
            </div>
            <div class="header-actions">
                <a href="<?= $APP_ROOT ?>student/documents.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left"></i> My Requests
                </a>
            </div>
        </header>

        <div class="wr-panel">
            <div class="wr-notice is-warn" style="margin:0;">
                <i class="fa-solid fa-magnifying-glass"></i>
                <div>
                    <b>We could not find that request</b>
                    <?= isset($notAvailable)
                        ? 'It may have been removed, or the link may be out of date. Your own requests are all listed under My Requests.'
                        : 'Open a request from My Requests to track it.' ?>
                </div>
            </div>
            <div class="wr-actions">
                <a href="<?= $APP_ROOT ?>student/documents.php" class="btn btn-primary">
                    <i class="fa-solid fa-list"></i> See my requests
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- ── Header ─────────────────────────────────── -->
        <header class="header" style="margin-bottom:18px;">
            <div class="title">
                <h1><?= htmlspecialchars($docName) ?></h1>
                <p>
                    <?= (int) ($row['quantity'] ?? 1) ?> cop<?= (int) ($row['quantity'] ?? 1) === 1 ? 'y' : 'ies' ?>
                    &middot;
                    Collected at the Registrar's Office
                    &middot;
                    Filed <?= htmlspecialchars(date('M d, Y', strtotime((string) $row['request_date']))) ?>
                </p>
            </div>
            <div class="header-actions">
                <a href="<?= $APP_ROOT ?>student/documents.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left"></i> My Requests
                </a>
            </div>
        </header>

        <!-- The tracking number is the student's anchor, so it is the
             biggest thing on the page and copyable in one tap. -->
        <div class="wr-panel" style="padding:20px 24px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex:1 1 240px; min-width:0;">
                    <div style="font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">
                        Tracking number
                    </div>
                    <div class="wr-trackno" style="justify-content:flex-start; padding:12px 16px;">
                        <span class="wr-trackno-value"><?= htmlspecialchars((string) $row['request_id']) ?></span>
                        <button type="button" class="wr-trackno-copy" id="trackCopy">
                            <i class="fa-solid fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">
                        Status
                    </div>
                    <?php // A stamp, not a badge: this is a mark on a
                          // document, not a status chip in a web app. Same
                          // vocabulary the ledger's stamp column reads. ?>
                    <span class="dk-stamp<?= !empty($status['terminal']) ? ' is-final' : '' ?>"
                          data-s="<?= htmlspecialchars($status['ink']) ?>">
                        <span><?= htmlspecialchars($status['stamp']) ?></span></span>
                </div>
            </div>
        </div>

        <!-- ── What is happening, and what it means ──────── -->
        <div class="wr-panel">
            <div class="wr-panel-head" style="margin-bottom:14px;">
                <h2 class="wr-panel-title" style="font-size:16px;">
                    <i class="fa-solid fa-circle-info"></i> Right now
                </h2>
            </div>

            <p style="margin:0 0 6px;font-size:16px;font-weight:800;color:var(--text-strong);">
                <?= htmlspecialchars($expectation['headline']) ?>
            </p>
            <p style="margin:0 0 14px;font-size:13.5px;line-height:1.7;color:var(--text-muted);">
                <?= htmlspecialchars($expectation['detail']) ?>
            </p>

            <?php if ($row['estimated_release_at'] && !in_array((string) $row['document_status'], ['Claimed', 'Rejected', 'Cancelled'], true)): ?>
            <div class="wr-notice is-info" style="margin:0;">
                <i class="fa-regular fa-calendar"></i>
                <div>
                    <b>Estimated release: <?= htmlspecialchars(doc_release_label($row['estimated_release_at'])) ?></b>
                    Counted in business days from filing, and set when you submitted — so it is a date
                    you can quote at the counter rather than one that shifts every time you look at it.
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Actionable notices ───────────────────────────
             Each one only appears when it is true, because a panel of
             standing warnings is a panel nobody reads. -->
        <?php if ($blocker && (string) $row['document_status'] === 'Pending_Clearance'): ?>
        <div class="wr-notice is-warn">
            <i class="fa-solid fa-hand-holding-dollar"></i>
            <div>
                <b>Held while your account is settled</b>
                <?= htmlspecialchars((string) $blocker['reason']) ?>.
                The Registrar's Office releases this automatically once the balance is paid — nothing
                else is needed from you.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($receiptRequired && $receiptState === 'none'): ?>
        <div class="wr-notice is-warn">
            <i class="fa-solid fa-receipt"></i>
            <div>
                <b>Payment received — receipt still needed</b>
                Attach your payment screenshot under <b>My Requests</b> so a Registrar can confirm it.
                Until then the office cannot start preparing your document.
            </div>
        </div>
        <?php elseif ($receiptState === 'submitted'): ?>
        <div class="wr-notice is-info">
            <i class="fa-solid fa-clock"></i>
            <div>
                <b>Receipt received — waiting to be checked</b>
                A Registrar will confirm it. You do not need to do anything else.
            </div>
        </div>
        <?php elseif ($receiptState === 'verified'): ?>
        <div class="wr-notice is-ok">
            <i class="fa-solid fa-circle-check"></i>
            <div><b>Payment confirmed</b>The office has checked your receipt and can proceed.</div>
        </div>
        <?php endif; ?>

        <?php if (!empty($requirements['missing'])): ?>
        <div class="wr-notice is-warn">
            <i class="fa-solid fa-file-circle-exclamation"></i>
            <div>
                <b>Bring these when you collect</b>
                <?= htmlspecialchars(implode(' · ', $requirements['missing'])) ?>.
                <?= count($requirements['missing']) === 1 ? 'The office asks' : 'The office asks' ?>
                for these before handing a document over.
            </div>
        </div>
        <?php endif; ?>

        <?php if ((string) $row['document_status'] === 'Rejected' && !empty($row['rejection_reason'])): ?>
        <div class="wr-notice is-danger">
            <i class="fa-solid fa-xmark"></i>
            <div>
                <b>This request was rejected</b>
                <?= htmlspecialchars((string) $row['rejection_reason']) ?>.
                If you think this is wrong, visit the Registrar's Office with your tracking number.
            </div>
        </div>
        <?php endif; ?>

        <!-- ── The timeline ───────────────────────────────── -->
        <div class="wr-panel">
            <div class="wr-panel-head">
                <h2 class="wr-panel-title"><i class="fa-solid fa-timeline"></i> Progress</h2>
                <p class="wr-panel-sub">Every step, in order, with the time it happened.</p>
            </div>

            <ol class="wr-timeline">
                <?php foreach ($timeline as $t):
                    $when = trackWhen($t['at']);
                    $mark = $t['state'] === 'done' ? '<i class="fa-solid fa-check"></i>' : '';
                ?>
                <li class="wr-tl-item is-<?= htmlspecialchars($t['state']) ?>">
                    <span class="wr-tl-dot"><?= $mark ?></span>
                    <div class="wr-tl-label"><?= htmlspecialchars($t['label']) ?></div>
                    <?php if ($when): ?>
                        <div class="wr-tl-when" title="<?= htmlspecialchars(trackWhenTitle($t['at'])) ?>">
                            <?= htmlspecialchars($when) ?>
                        </div>
                    <?php elseif ($t['state'] === 'pending'): ?>
                        <div class="wr-tl-when" style="color:var(--text-faint);">Not yet</div>
                    <?php endif; ?>
                    <?php if (!empty($t['note'])): ?>
                        <div class="wr-tl-note"><?= htmlspecialchars($t['note']) ?></div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>

        <!-- ── What was filed ─────────────────────────────── -->
        <div class="wr-panel">
            <div class="wr-panel-head">
                <h2 class="wr-panel-title" style="font-size:16px;">
                    <i class="fa-solid fa-file-lines"></i> What you submitted
                </h2>
            </div>
            <div class="wr-sum">
                <?php
                $facts = [
                    'Document'  => $docName,
                    'Purpose'   => (string) ($row['purpose'] ?: '—'),
                    'Copies'    => (string) (int) ($row['quantity'] ?? 1),
                    'Payment'   => match ((string) $row['payment_method']) {
                        'Online'        => 'GCash',
                        'Bank_Transfer' => 'Bank transfer',
                        default         => 'Paid at the counter',
                    },
                    'Fee'       => 'PHP ' . number_format((float) ($row['fee_amount'] ?? 0), 2),
                ];
                if ((string) ($row['payment_reference'] ?? '') !== '') {
                    $facts['Reference'] = (string) $row['payment_reference'];
                }
                if ((string) ($row['notes'] ?? '') !== '') {
                    $facts['Notes'] = (string) $row['notes'];
                }
                foreach ($facts as $k => $v): ?>
                <div class="wr-sum-row">
                    <span class="wr-sum-key"><?= htmlspecialchars($k) ?></span>
                    <span class="wr-sum-val"><?= htmlspecialchars($v) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($requirements['attached'])): ?>
            <h3 class="wr-label" style="margin:20px 0 8px;">Files you attached</h3>
            <div class="wr-files" style="margin-top:0;">
                <?php foreach ($requirements['attached'] as $a):
                    $byCode = [];
                    foreach ($requirements['requirements'] as $r) { $byCode[$r['code']] = $r['label']; }
                ?>
                <div class="wr-file">
                    <i class="fa-solid fa-file wr-file-icon"></i>
                    <span class="wr-file-name" title="<?= htmlspecialchars($a['original_name']) ?>">
                        <?= htmlspecialchars($a['original_name']) ?>
                        <?php if (!empty($a['requirement_code'])): ?>
                            <span style="color:var(--text-faint);">&middot; <?= htmlspecialchars($byCode[$a['requirement_code']] ?? $a['requirement_code']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="wr-file-size"><?= number_format((int) $a['size_bytes'] / 1024, 0) ?> KB</span>
                    <a class="wr-file-drop" style="text-decoration:none;"
                       href="<?= htmlspecialchars(app_url('/api/file-download.php?kind=attachment&attachment=' . (int) $a['id'])) ?>"
                       aria-label="Download <?= htmlspecialchars($a['original_name']) ?>">
                        <i class="fa-solid fa-download"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── The full activity log ──────────────────────────
             Below the timeline rather than in it. The timeline is the
             story; this is the evidence, and nobody needs it unless
             something has gone wrong. -->
        <?php if (!empty($events)): ?>
        <div class="wr-panel">
            <div class="wr-panel-head">
                <h2 class="wr-panel-title" style="font-size:16px;">
                    <i class="fa-solid fa-clock-rotate-left"></i> Activity log
                </h2>
                <p class="wr-panel-sub">Every change recorded against this request.</p>
            </div>
            <div class="wr-sum">
                <?php foreach ($events as $ev): ?>
                <div class="wr-sum-row" style="align-items:flex-start;">
                    <span class="wr-sum-key" style="min-width:150px;">
                        <?= htmlspecialchars(trackWhen((string) $ev['created_at'])) ?>
                    </span>
                    <span class="wr-sum-val is-quiet" style="max-width:62%;">
                        <?= htmlspecialchars((string) ($ev['note'] !== null && trim((string) $ev['note']) !== ''
                            ? $ev['note']
                            : (doc_status((string) $ev['status'])['label']))) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── What the student can do ──────────────────────── -->
        <div class="wr-panel">
            <div class="wr-actions" style="margin-top:0; padding-top:0; border-top:0;">
                <?php if (!empty($requirements['missing']) && in_array((string) $row['document_status'], ['Draft', 'Filed', 'Pending_Clearance', 'Awaiting_Payment'], true)): ?>
                <a href="<?= $APP_ROOT ?>student/document-request.php#step-2" class="btn btn-primary">
                    <i class="fa-solid fa-paperclip"></i> Add missing files
                </a>
                <?php endif; ?>

                <?php if ($receiptState === 'none'): ?>
                <a href="<?= $APP_ROOT ?>student/documents.php" class="btn btn-primary">
                    <i class="fa-solid fa-receipt"></i> Attach payment receipt
                </a>
                <?php endif; ?>

                <button type="button" class="btn btn-light" onclick="if(window.toggleStudentChat) window.toggleStudentChat();">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Ask about this request
                </button>

                <span class="wr-spacer"></span>

                <?php if (doc_can_cancel($row)): ?>
                <button type="button" class="btn btn-light btn-danger-text" id="trackCancel">
                    <i class="fa-solid fa-ban"></i> Cancel request
                </button>
                <?php else: ?>
                <?php // Disabled rather than hidden. A Cancel button that
                     // vanishes leaves a student wondering whether they
                     // missed it; a disabled one says plainly that the
                     // window has closed, and why. ?>
                <button type="button" class="btn btn-light" disabled
                        title="This request has progressed past the point where it can be withdrawn.">
                    <i class="fa-solid fa-ban"></i> Cancel request
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cancel confirmation. A dialog rather than window.confirm
             because it asks for a reason: "cancelled" with no reason
             teaches the office nothing, and the reason is often the
             whole point of cancelling. -->
        <?php if (doc_can_cancel($row)): ?>
        <div class="modal-overlay" id="cancelModal">
            <div class="modal-content" style="max-width:520px;">
                <div class="modal-header">
                    <h2><i class="fa-solid fa-ban"></i> Cancel this request?</h2>
                    <button class="modal-close" onclick="closeCancel()"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body">
                    <p style="margin:0 0 14px;font-size:13.5px;line-height:1.65;color:#475569;">
                        This withdraws request <b><?= htmlspecialchars((string) $row['request_id']) ?></b>
                        from the Registrar's queue. It stays on record, and you can file a new one
                        whenever you like.
                        <?php if ($row['paid_at'] !== null): ?>
                        <br><br>
                        <span style="color:#b45309;">
                            <b>Your fee was already paid.</b> Raise a refund with the Registrar's Office
                            and quote your tracking number.
                        </span>
                        <?php endif; ?>
                    </p>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="cancelReason">Why are you cancelling? <span style="color:#94a3b8;font-weight:500;">(optional, but it helps the office)</span></label>
                        <input type="text" id="cancelReason" class="form-control" maxlength="255"
                               placeholder="Found a copy elsewhere, wrong document, no longer needed…">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" onclick="closeCancel()">Keep it</button>
                    <button type="button" class="btn btn-danger" id="cancelConfirm">Yes, cancel it</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
</div>
</main>

<script>
(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };

    function toast(msg, type) {
        if (window.showToast) { window.showToast(msg, type || 'info'); }
    }

    function csrfToken() {
        var m = document.querySelector('meta[name=csrf-token]');
        return m ? (m.getAttribute('content') || '') : '';
    }

    // Copy the tracking number.
    var copyBtn = $('trackCopy');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var value = <?= json_encode($row ? (string) $row['request_id'] : '') ?>;
            var done = function () {
                copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
                setTimeout(function () { copyBtn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy'; }, 1600);
            };
            // navigator.clipboard needs a secure context, which plain-HTTP
            // XAMPP is not - so the textarea fallback is what makes this
            // work on the machine it is developed on.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(done).catch(function () { legacy(); });
            } else { legacy(); }

            function legacy() {
                var ta = document.createElement('textarea');
                ta.value = value;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); }
                catch (e) { toast('Copy it by hand: ' + value, 'info'); }
                document.body.removeChild(ta);
            }
        });
    }

    // ── Cancel ──────────────────────────────────────────
    var openBtn = $('trackCancel');
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            $('cancelModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            $('cancelReason').focus();
        });
    }

    var confirmBtn = $('cancelConfirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cancelling…';

            fetch(<?= json_encode(app_url('/api/document-request.php?action=cancel'), JSON_UNESCAPED_SLASHES) ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({
                    request_id: <?= (int) ($row['id'] ?? 0) ?>,
                    reason: ($('cancelReason') || {}).value || ''
                })
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  if (d && d.success) {
                      toast(d.message, 'success');
                      setTimeout(function () { location.reload(); }, 1400);
                  } else {
                      confirmBtn.disabled = false;
                      confirmBtn.innerHTML = 'Yes, cancel it';
                      toast((d && d.message) || 'Could not cancel the request.', 'error');
                  }
              })
              .catch(function () {
                  confirmBtn.disabled = false;
                  confirmBtn.innerHTML = 'Yes, cancel it';
                  toast('Network error — nothing was cancelled.', 'error');
              });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeCancel(); }
    });

    function closeCancel() {
        var m = $('cancelModal');
        if (!m) { return; }
        m.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Backdrop click closes, matching every other modal in the portal.
    var overlay = $('cancelModal');
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) { closeCancel(); }
        });
    }
})();
</script>

<?php include '../includes/footer.php'; ?>