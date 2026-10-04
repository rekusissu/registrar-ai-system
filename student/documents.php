<?php
// ============================================================
//  STUDENT/DOCUMENTS.PHP
//  Student document requests — catalog, new-request modal,
//  payment flow, request table with stepper + timeline.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
// For doc_requires_receipt() / doc_receipt_state(), which decide
// whether this request needs a receipt and where it stands. Loaded
// explicitly rather than relying on the chain: a page that silently
// loses these renders every request as if no receipt were needed.
require_once __DIR__ . '/../shared/document_process.php';
// The office's GCash QR, and whether it is actually on this server. The
// payment screen shows the code, or says it is not set up — never a
// broken image. See shared/payment_qr.php.
require_once __DIR__ . '/../shared/payment_qr.php';

$page_title = 'My Documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_documents';
$extra_css = ['student.css', 'documents.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();
$gcashQr = gcashQrImage();

// ── Catalog (active only)
$catalog = $db->fetchAll(
    "SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id ASC"
);

// ── Finance balance
$balance = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$student['id']]) ?? 0.00);

// ── Student requests
$requests = $db->fetchAll(
    "SELECT dr.*, c.name AS catalog_name, c.sku, c.fee_type, c.base_fee, c.requirement
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
      WHERE dr.student_id = ?
      ORDER BY dr.id DESC",
    [$student['id']]
);

// ── Status events (grouped by request)
$eventsByRequest = [];
if ($requests) {
    $ids = array_map('intval', array_column($requests, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach ($db->fetchAll("SELECT * FROM document_request_events WHERE request_id IN ($ph) ORDER BY id ASC", $ids) as $ev) {
        $eventsByRequest[(int) $ev['request_id']][] = $ev;
    }
}

// ── Display maps
$statusPill = [
    'Pending_Clearance' => ['pending-clearance', 'fa-triangle-exclamation'],
    'Awaiting_Payment'  => ['awaiting-payment',  'fa-clock'],
    'Filed'             => ['filed',             'fa-folder-open'],
    'Processing'        => ['processing',        'fa-gear'],
    'Ready'             => ['ready',             'fa-circle-check'],
    'Shipped'           => ['shipped',           'fa-truck-fast'],
    'Claimed'           => ['claimed',           'fa-box-check'],
    'Rejected'          => ['rejected',          'fa-xmark'],
];
$statusLabel = [
    'Pending_Clearance' => 'Pending Clearance',
    'Awaiting_Payment'  => 'Awaiting Payment',
    'Filed'             => 'Filed',
    'Processing'        => 'Being prepared',
    'Ready'             => 'Ready for collection',
    'Shipped'           => 'On its way',
    'Claimed'           => 'Collected',
    'Rejected'          => 'Rejected',
];
// One icon per SKU. This list used to stop at four, so Diploma Replacement,
// Honorable Dismissal and Course Description all fell through to the same
// grey placeholder — three of the seven documents a student can pick looked
// identical at a glance, which is the one thing the icon column exists to
// prevent. Kept in step with the registrar desk's map (documents.php:294).
$catIcon = [
    'DOC-TOR'     => ['linear-gradient(135deg,#2563eb,#1d4ed8)', 'fa-file-invoice'],
    'DOC-COE'     => ['linear-gradient(135deg,#16a34a,#15803d)', 'fa-certificate'],
    'DOC-GM'      => ['linear-gradient(135deg,#0d9488,#0f766e)', 'fa-handshake-angle'],
    'DOC-DIPLOMA' => ['linear-gradient(135deg,#7c3aed,#6d28d9)', 'fa-graduation-cap'],
    'DOC-CTC'     => ['linear-gradient(135deg,#4f46e5,#4338ca)', 'fa-copy'],
    'DOC-HD'      => ['linear-gradient(135deg,#ea580c,#c2410c)', 'fa-sign-out-alt'],
    'DOC-CD'      => ['linear-gradient(135deg,#db2777,#be185d)', 'fa-book-open'],
];

function feeLabel($c) {
    $p = '&#8369;' . number_format((float) $c['base_fee'], 2);
    if ($c['fee_type'] === 'per_page')     return $p . ' / page';
    if ($c['fee_type'] === 'per_syllabus') return $p . ' / syllabus';
    return $p . ' one-time';
}

/**
 * The steps a student watches, in order.
 *
 * "Payment" leads for a request that is waiting on money. Without it a
 * student in Awaiting_Payment matched no step, renderStepper returned an
 * empty string, and the row showed no progress at all — the one stage
 * where they personally have something to do was the one stage with
 * nothing drawn.
 *
 * A request paid at the counter never enters Awaiting_Payment, so the
 * step is never shown to a student who owes nothing. "On its way" is
 * drawn only for a courier request; a pickup request skips it.
 *
 * There was once a fifth "Clearance" step prepended for exit-clearance
 * documents, so a student could see three offices signing off. Exit
 * clearance has been removed, and with it any step the desk cannot
 * itself move — a student watching a step that no one at the counter
 * controls was told to wait for something the desk had no way to
 * report on.
 */
function renderStepper(string $status): string {
    $awaitingPayment = $status === 'Awaiting_Payment';
    $shipped         = $status === 'Shipped';

    $steps = [
        ['key' => 'Awaiting_Payment', 'label' => 'Payment',   'icon' => 'fa-credit-card'],
        ['key' => 'Filed',            'label' => 'Filed',     'icon' => 'fa-file-signature'],
        ['key' => 'Processing',       'label' => 'In progress','icon' => 'fa-gear'],
        ['key' => 'Ready',            'label' => 'Ready',     'icon' => 'fa-circle-check'],
        ['key' => 'Shipped',          'label' => 'On its way','icon' => 'fa-truck-fast'],
        ['key' => 'Claimed',          'label' => 'Collected', 'icon' => 'fa-box-check'],
    ];

    // Drop the steps that do not apply, so a pickup request is not shown a
    // courier leg it will never take and a paid request is not shown a
    // payment step it has already cleared.
    if (!$awaitingPayment) {
        $steps = array_values(array_filter($steps, fn($s) => $s['key'] !== 'Awaiting_Payment'));
    }
    if (!$shipped) {
        $steps = array_values(array_filter($steps, fn($s) => $s['key'] !== 'Shipped'));
    }

    $activeIdx = null;
    foreach ($steps as $i => $s) {
        if ($s['key'] === $status) { $activeIdx = $i; break; }
    }
    if ($activeIdx === null) return '';
    $html = '<div class="flow-track">';
    foreach ($steps as $i => $s) {
        $cls = $i < $activeIdx ? 'done' : ($i === $activeIdx ? 'active' : '');
        $html .= '<div class="flow-step ' . $cls . '"><div class="step-dot"><i class="fa-solid ' . $s['icon'] . '"></i></div><span class="step-label">' . $s['label'] . '</span></div>';
        if ($i < count($steps) - 1) $html .= '<div class="flow-link"></div>';
    }
    $html .= '</div>';
    return $html;
}

// ── Counts
$counts = array_fill_keys(array_keys($statusPill), 0);
foreach ($requests as $r) {
    if (isset($counts[$r['document_status']])) $counts[$r['document_status']]++;
}
$isBlocked = $balance > 0;
$hasHeld = $counts['Pending_Clearance'] > 0;
$totalRequests = count($requests);
$awaitingPay = $counts['Awaiting_Payment'];
$processing  = $counts['Processing'] + $counts['Ready'];
$claimed     = $counts['Claimed'];
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>My Documents</h1><p>Request and track documents from the Registrar.</p></div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> New Request</button>
            </div>
        </header>

        <!-- Stats cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);"><i class="fa-solid fa-file-lines"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $totalRequests ?></span><span class="stat-label">Total Requests</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="fa-solid fa-credit-card"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $awaitingPay ?></span><span class="stat-label">Awaiting Payment</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);"><i class="fa-solid fa-gear"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $processing ?></span><span class="stat-label">Processing</span></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a);"><i class="fa-solid fa-box-check"></i></div>
                <div class="stat-info"><span class="stat-value"><?= $claimed ?></span><span class="stat-label">Claimed</span></div>
            </div>
        </div>

        <?php if ($isBlocked): ?>
        <div class="block-banner">
            <div class="banner-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
                <div class="banner-title">Action required &mdash; outstanding balance of &#8369;<?= number_format($balance, 2) ?></div>
                <div class="banner-text">Your account has a balance due on record. A request is held at the desk until the Registrar's Office settles the balance.</div>
            </div>
        </div>
        <?php elseif ($hasHeld): ?>
        <div class="block-banner">
            <div class="banner-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="banner-title">Request on hold</div>
                <div class="banner-text">One or more of your requests is waiting on an outstanding balance. The Registrar's Office will release it once the account is settled. No action needed from you right now.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document Requirements Checklist -->
        <?php
        $studentId = $student['id'];
        $requiredDocs = [
            'form_137' => ['label' => 'Form 137', 'icon' => 'fa-file-alt', 'color' => '#2563eb'],
            'psa'      => ['label' => 'PSA Birth Certificate', 'icon' => 'fa-certificate', 'color' => '#7c3aed'],
            'photo'    => ['label' => '1x1 ID Photo', 'icon' => 'fa-camera', 'color' => '#db2777'],
        ];
        $studentDocs = $db->fetchAll("SELECT doc_type FROM documents WHERE student_id = ?", [$studentId]);
        $presentTypes = array_column($studentDocs, 'doc_type');
        $reqMissing = [];
        foreach ($requiredDocs as $type => $info) {
            if (!in_array($type, $presentTypes)) $reqMissing[$type] = $info;
        }
        ?>
        <?php if (!empty($reqMissing)): ?>
        <div class="panel" style="margin-bottom:16px;border-left:4px solid #f59e0b;">
            <div style="padding:16px;">
                <div style="font-weight:600;font-size:14px;color:#92400e;margin-bottom:10px;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Required Documents — Action Needed</div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <?php foreach ($requiredDocs as $type => $info): ?>
                    <?php $uploaded = in_array($type, $presentTypes); ?>
                    <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:<?= $uploaded ? '#f0fdf4' : '#fef3c7' ?>;border:1px solid <?= $uploaded ? '#bbf7d0' : '#fde68a' ?>;">
                        <i class="fas <?= $uploaded ? 'fa-circle-check' : $info['icon'] ?>" style="color:<?= $uploaded ? '#16a34a' : $info['color'] ?>;"></i>
                        <span style="font-size:13px;<?= $uploaded ? 'text-decoration:line-through;color:#16a34a;' : 'font-weight:500;color:#92400e;' ?>"><?= $info['label'] ?></span>
                        <?= $uploaded ? '<span style="font-size:10px;color:#16a34a;">✓ Uploaded</span>' : '<span style="font-size:10px;color:#dc2626;">Missing</span>' ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document Catalog -->
        <div class="panel">
            <div class="panel-header">
                <div><h3><i class="fa-solid fa-tags"></i> Document Catalog &amp; Pricing</h3>
                    <p style="font-size:12.5px;color:#64748b;margin-top:2px;">Select a document to request. Fees set by the Registrar's Office.</p></div>
            </div>
            <div class="catalog-grid">
                <?php foreach ($catalog as $c):
                    $ci = $catIcon[$c['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines']; ?>
                    <div class="catalog-card" onclick="pickFromCatalog(<?= (int) $c['id'] ?>)">
                        <div class="cat-top">
                            <div class="cat-icon" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                            <div>
                                <div class="cat-sku"><?= htmlspecialchars($c['sku']) ?></div>
                                <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
                            </div>
                        </div>
                        <div class="cat-desc"><?= htmlspecialchars($c['description'] ?? '') ?></div>
                        <div class="cat-meta">
                            <div class="cat-fee"><?= feeLabel($c) ?></div>
                        </div>
                        <?php if (!empty($c['requirement'])): ?>
                            <div class="cat-req"><i class="fa-solid fa-file-shield"></i> <?= htmlspecialchars($c['requirement']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- My requests -->
        <div class="panel">
            <div class="search-toolbar">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="docSearch" placeholder="Search by request ID, document, or purpose...">
                </div>
                <select id="statusFilter" class="form-control" style="width:auto;min-width:180px;">
                    <option value="">All statuses</option>
                    <?php foreach ($statusLabel as $k => $v): ?>
                        <option value="<?= $k ?>"><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="panel-actions" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
                    <span class="chip blue"><i class="fa-solid fa-file-lines"></i> <?= $totalRequests ?> request<?= $totalRequests === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="table-responsive" style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr><th>Request</th><th>Fee</th><th>Type</th><th>Fulfillment</th><th>Status</th><th>Submitted</th><th style="text-align:right;">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="7" class="empty-state"><i class="fa-solid fa-file-lines"></i><p>No document requests yet</p><span>Click "New Request" above to request a document.</span></td></tr>
                    <?php else: foreach ($requests as $r):
                        $pill = $statusPill[$r['document_status']] ?? ['awaiting-payment', 'fa-clock'];
                        $label = $statusLabel[$r['document_status']] ?? str_replace('_', ' ', $r['document_status']);
                        $ci = $catIcon[$r['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines'];
                        $reqEvents = $eventsByRequest[(int) $r['id']] ?? [];
                        $isRejected = $r['document_status'] === 'Rejected';
                        $payable = $r['document_status'] === 'Awaiting_Payment'
                            && (float) $r['fee_amount'] > 0
                            && ($r['payment_method'] ?? 'Online') !== 'Cash_on_Delivery';
                        $isCod = ($r['payment_method'] ?? 'Online') === 'Cash_on_Delivery';
                        // Receipt state, for requests that owe money and
                        // were paid online. 'none' means the student still
                        // has to attach one — that is the only state that
                        // shows them the upload button.
                        $needsReceipt = doc_requires_receipt($r);
                        $receiptState = $needsReceipt ? doc_receipt_state($r) : null;
                    ?>
                        <tr data-doc="<?= (int) $r['id'] ?>" data-status="<?= htmlspecialchars((string) $r['document_status']) ?>" class="doc-row" onclick="toggleDetail(<?= (int) $r['id'] ?>)">
                            <td>
                                <div class="student-info">
                                    <div class="student-avatar" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                                    <div>
                                        <div class="student-name"><?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type']))) ?></div>
                                        <div class="student-sub"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($r['request_id'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size:13px;font-weight:700;color:#0f172a;">&#8369;<?= number_format((float) ($r['fee_amount'] ?? 0), 2) ?></div>
                                <?php if ($isCod): ?>
                                    <div style="font-size:11px;color:#dc2626;"><i class="fa-solid fa-hand-holding-dollar"></i> Cash on delivery</div>
                                <?php endif; ?>
                            </td>
                            <td><span class="chip regular"><i class="fa-solid fa-clock"></i> Regular</span></td>
                            <td><span class="chip pickup"><i class="fa-solid fa-store"></i> Pickup</span></td>
                            <td><span class="pill <?= $pill[0] ?>"><i class="fa-solid <?= $pill[1] ?>"></i> <?= htmlspecialchars($label) ?></span></td>
                            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($r['request_date'])) ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <?php if ($payable): ?>
                                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation();openPaymentModal(<?= (int) $r['id'] ?>, '<?= htmlspecialchars($r['request_id']) ?>', <?= (float) $r['fee_amount'] ?>, '<?= htmlspecialchars($r['catalog_name'] ?? '', ENT_QUOTES) ?>');"><i class="fa-solid fa-qrcode"></i> Pay with GCash</button>
                                <?php elseif ($receiptState === 'none'): ?>
                                    <!-- Paid, no receipt yet. This button IS the
                                         next action, so it takes the primary
                                         styling the Pay button would have used. -->
                                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation();openReceiptModal(<?= (int) $r['id'] ?>, '<?= htmlspecialchars($r['request_id']) ?>');"><i class="fa-solid fa-receipt"></i> Attach Receipt</button>
                                <?php elseif ($receiptState === 'submitted'): ?>
                                    <span class="pill awaiting-payment" title="<?= htmlspecialchars($r['payment_receipt_filename'] ?? '') ?>"><i class="fa-solid fa-clock"></i> Receipt sent</span>
                                <?php elseif ($receiptState === 'verified'): ?>
                                    <span class="pill processing" title="Checked by the Registrar"><i class="fa-solid fa-circle-check"></i> Receipt OK</span>
                                <?php elseif ($receiptState === 'waived'): ?>
                                    <span class="pill processing" title="Not required &mdash; waived by the Registrar"><i class="fa-solid fa-circle-info"></i> Receipt waived</span>
                                <?php elseif ($isRejected): ?>
                                    <span class="pill rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;"><i class="fa-solid fa-chevron-down"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr class="doc-detail-row" id="detail-<?= (int) $r['id'] ?>" style="display:none;">
                            <td colspan="7" style="padding:0;">
                                <div class="doc-detail" style="padding:18px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                                    <?php if ($isRejected): ?>
                                        <div class="block-banner" style="margin-bottom:12px;">
                                            <div class="banner-icon"><i class="fa-solid fa-xmark"></i></div>
                                            <div>
                                                <div class="banner-title">Request rejected</div>
                                                <div class="banner-text"><?= htmlspecialchars($r['rejection_reason'] ?? 'No reason provided.') ?></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <?= renderStepper((string) $r['document_status']) ?>
                                    <?php endif; ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:14px;">
                                        <?php if ($isCod): ?>
                                            <div style="font-size:12.5px;color:#b45309;"><i class="fa-solid fa-hand-holding-dollar" style="color:#dc2626;"></i> <b>Cash on delivery</b> &mdash; pay at the office.</div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['purpose'])): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-note-sticky" style="color:#64748b;"></i> <b>Purpose:</b> <?= htmlspecialchars($r['purpose']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['quantity']) && (int) $r['quantity'] > 1): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-copy"></i> <b>Qty:</b> <?= (int) $r['quantity'] ?></div>
                                        <?php endif; ?>
                                        <?php if ($r['document_status'] === 'Ready' && $r['fulfillment_type'] === 'Pickup'): ?>
                                            <div style="font-size:12.5px;color:#16a34a;"><i class="fa-solid fa-store"></i> <b>Ready for pickup</b> at the Registrar's Office.</div>
                                        <?php endif; ?>
                                        <?php if ($payable): ?>
                                            <div style="font-size:12.5px;color:#2563eb;"><i class="fa-solid fa-credit-card"></i> <b>Payment needed.</b> Click "Pay Online" to pay via GCash.</div>
                                        <?php endif; ?>
                                        <?php if ($receiptState === 'none'): ?>
                                            <div style="font-size:12.5px;color:#b45309;"><i class="fa-solid fa-triangle-exclamation"></i> <b>Receipt needed.</b> Pay first, then attach your GCash receipt here so the Registrar can confirm the payment.</div>
                                        <?php elseif ($receiptState === 'submitted'): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-receipt"></i> <b>Receipt received</b><?= !empty($r['payment_receipt_ref']) ? ' &middot; GCash ref ' . htmlspecialchars($r['payment_receipt_ref']) : '' ?> &mdash; waiting for the Registrar to check it.</div>
                                        <?php elseif ($receiptState === 'verified'): ?>
                                            <div style="font-size:12.5px;color:#16a34a;"><i class="fa-solid fa-circle-check"></i> <b>Receipt verified</b> by the Registrar<?= !empty($r['payment_receipt_verified_at']) ? ' on ' . date('M d, Y', strtotime($r['payment_receipt_verified_at'])) : '' ?>.</div>
                                        <?php elseif ($receiptState === 'waived'): ?>
                                            <div style="font-size:12.5px;color:#6d28d9;"><i class="fa-solid fa-circle-info"></i> <b>Receipt not required</b> &mdash; waived by the Registrar<?= !empty($r['payment_receipt_waive_reason']) ? ': ' . htmlspecialchars($r['payment_receipt_waive_reason']) : '' ?>.</div>
                                        <?php endif; ?>
                                        <?php if ($r['document_status'] === 'Processing'): ?>
                                            <div style="font-size:12.5px;color:#6d28d9;"><i class="fa-solid fa-gear"></i> Being prepared by the Registrar.</div>
                                        <?php endif; ?>
                                    </div>
                                    <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin:14px 0 8px;"><i class="fa-solid fa-timeline"></i> Status timeline</h4>
                                    <ul class="timeline">
                                        <?php if (empty($reqEvents)): ?>
                                            <li><div class="tl-dot" style="background:#94a3b8;border-color:#e2e8f0;"></div><div class="tl-status">Submitted</div><div class="tl-when"><?= date('M d, Y h:i A', strtotime($r['request_date'])) ?></div></li>
                                        <?php else: foreach ($reqEvents as $ev): ?>
                                            <li>
                                                <div class="tl-dot"></div>
                                                <div class="tl-status"><?= htmlspecialchars($statusLabel[$ev['status']] ?? str_replace('_', ' ', $ev['status'])) ?></div>
                                                <?php if (!empty($ev['note'])): ?><div class="tl-note"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?>
                                                <div class="tl-when"><?= date('M d, Y h:i A', strtotime($ev['created_at'])) ?></div>
                                            </li>
                                        <?php endforeach; endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <div class="info-text">Showing <strong><?= $totalRequests ?></strong> of <strong><?= $totalRequests ?></strong> requests</div>
            </div>
        </div>

    </div>
</main>

<!-- New Request Modal

     A student's request is a different act from a clerk's, and this form
     is built for that difference rather than for parity with the desk.

     The clerk already knows the student, so their form opens with two
     selects and a fee. The student has to CHOOSE a document from a
     catalog of seven, so that choice is the first thing on screen and it
     is made from cards carrying a name and a price — the two things being
     compared. A dropdown hides the prices side by side and makes the
     student open each one to compare, which is the whole decision. -->
<div class="modal-overlay" id="requestModal">
    <div class="modal-content nq-dialog">
        <div class="modal-header nq-dialog-head">
            <div class="nq-mark"><i class="fa-solid fa-file-circle-plus"></i></div>
            <div>
                <h2>New Document Request</h2>
                <p>Choose a document, then how you want to receive it.</p>
            </div>
            <button class="modal-close" onclick="closeRequestModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form id="requestForm">
        <div class="modal-body nq-dialog-body">

            <div class="nq-field">
                <div class="nq-step-label" id="reqDocLabel">Which document</div>
                <?php // Real radio inputs, styled as cards. They were divs
                      // with an onclick, which put the catalog outside the
                      // form's accessibility tree entirely: no role, no
                      // checked state, unreachable by keyboard, and a screen
                      // reader read seven prices with no way to say which was
                      // chosen. The native input carries all of that for free;
                      // only the skin is custom. ?>
                <div class="catalog-picker" id="catalogPicker" role="radiogroup" aria-labelledby="reqDocLabel">
                    <?php foreach ($catalog as $c):
                        $ci = $catIcon[$c['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines'];
                        $hasReq = trim((string) ($c['requirement'] ?? '')) !== ''; ?>
                        <label class="co-card" data-id="<?= (int) $c['id'] ?>">
                            <input type="radio" name="catalog_pick" value="<?= (int) $c['id'] ?>"
                                   class="co-radio" data-req="<?= htmlspecialchars((string) $c['requirement'], ENT_QUOTES) ?>">
                            <span class="co-icon" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></span>
                            <span class="co-text">
                                <span class="co-name"><?= htmlspecialchars($c['name']) ?></span>
                                <span class="co-fee"><?= feeLabel($c) ?></span>
                                <?php if ($hasReq): ?>
                                    <span class="co-req"><i class="fa-solid fa-id-card"></i> Bring an ID</span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="catalog_id" id="catalogId">
            </div>

            <div class="nq-field nq-qty" id="qtyGroup" hidden>
                <label for="reqQty">How many</label>
                <input type="number" id="reqQty" class="form-control" min="1" max="100" value="1">
                <p class="nq-hint" id="qtyHint">This document is charged per page.</p>
            </div>
            <div class="nq-field">
                <?php // Fulfillment is no longer a question. The office runs
                      // no courier and issues no emailed copy, so "Collect at
                      // the office" was the only reachable answer among three
                      // — and two of the three promised something the office
                      // cannot do. A student choosing "Digital copy" would have
                      // been told to expect an email the registrar has no way
                      // to send. The choice is now a stated fact, sent as the
                      // fixed value the API validates, exactly as the desk's
                      // own form does it. ?>
                <div class="nq-facts nq-facts--inline">
                    <div class="nq-fact nq-fact--box">
                        <span class="get-icon get-icon--counter"><i class="fa-solid fa-building-columns"></i></span>
                        <span><b>Collect at the registrar counter</b><small>On your student ID, once it is marked ready</small></span>
                    </div>
                </div>
                <input type="hidden" name="fulfillment_type" id="reqFulfillment" value="Pickup">
            </div>

            <div class="nq-field">
                <div class="nq-step-label" id="reqPayLabel">How you'll pay</div>
                <div class="get-row" role="radiogroup" aria-labelledby="reqPayLabel">
                    <label class="get-choice">
                        <input type="radio" name="payment_method" value="Online" class="get-radio" checked>
                        <span class="get-icon get-icon--online"><i class="fa-solid fa-mobile-screen-button"></i></span>
                        <span class="get-text"><b>Pay online with GCash</b><small>Pay now, collect when it's ready</small></span>
                    </label>
                    <label class="get-choice">
                        <input type="radio" name="payment_method" value="Cash_on_Delivery" class="get-radio">
                        <span class="get-icon get-icon--counter"><i class="fa-solid fa-hand-holding-dollar"></i></span>
                        <span class="get-text"><b>Pay when you collect</b><small>Cash at the registrar counter</small></span>
                    </label>
                </div>
                <?php // The fee line below says "you can pay at the counter if you
                      // pick that above". Which is a claim the form has to keep
                      // true, so choosing GCash here rewrites that sentence
                      // rather than leaving a second thing to remember. ?>
                <p class="nq-hint" id="payHint">Paying online starts your request as soon as you submit.</p>
            </div>

            <div class="nq-field">
                <label for="reqPurpose">What it's for <span class="nq-req">*</span></label>
                <input type="text" name="purpose" id="reqPurpose" class="form-control" required
                       placeholder="Job application, transfer to another school">
                <p class="nq-hint">A sentence is enough. It tells the office who to prepare the document for.</p>
            </div>

            <div class="nq-field" id="reqFileGroup" hidden>
                <label for="reqFile">Attach the requirement</label>
                <input type="file" name="requirement_file" id="reqFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <div class="req-hint" id="reqHint" role="status"></div>
            </div>

            <?php // Priority is Regular for every request and the API
                 // validates it, so the field is sent but never offered. It
                 // used to be a readonly box labelled "Request Type" with a
                 // note under it, which read as a setting that had gone wrong
                 // rather than as a fact about the request. ?>
            <input type="hidden" name="request_type" value="Regular">

            <?php // The fee is the one number the student came for, so it is
                  // the one loud thing. Same perforated ticket the desk uses,
                  // for the same reason: both are read across a counter. ?>
            <div class="nq-facts">
                <div class="nq-fee">
                    <div>
                        <div class="nq-fee-cap">Total to pay</div>
                        <div class="nq-fee-note" id="feeNote">Pick a document to see its fee.</div>
                    </div>
                    <div class="nq-fee-amount" id="feePreview">&mdash;</div>
                </div>
                <p class="nq-fact"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <span id="feeFootNote">You can pay at the counter if you pick that above.</span></p>
            </div>
        </div>
        <div class="modal-footer nq-dialog-foot">
            <button type="button" class="btn btn-light" onclick="closeRequestModal()">Cancel</button>
            <button type="submit" class="btn btn-primary" id="submitReqBtn"><i class="fa-solid fa-paper-plane"></i> Submit request</button>
        </div>
        </form>
    </div>
</div>

<!-- Payment Modal

     Not a gateway. The office collects against one static GCash QR: the
     student scans it, pays, and sends the screenshot back for a
     registrar to check. There is no transaction to create, no status to
     poll and no provider to call — so this screen does none of that,
     and the request only advances once a person has looked at the
     receipt. See api/documents.php 'verify_receipt'. -->
<div class="modal-overlay" id="payModal">
    <div class="modal-content nq-dialog pay-dialog">
        <div class="modal-header nq-dialog-head">
            <div class="nq-mark nq-mark--gcash"><i class="fa-solid fa-qrcode"></i></div>
            <div>
                <h2>Pay with GCash</h2>
                <p>Scan the code, then send the amount.</p>
            </div>
            <button class="modal-close" onclick="closePayModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="modal-body nq-dialog-body pay-body">

            <?php // The amount sits ABOVE the code, not below it, and that is
                  // the whole reason this screen is laid out this way. After
                  // the student scans, GCash asks them to TYPE the amount —
                  // so the number and the code have to be readable at the
                  // same moment, without scrolling or looking away. With the
                  // amount underneath, the student scans, looks at their
                  // phone for the figure, and comes back to the screen. ?>
            <div class="pay-amount-card">
                <div class="pay-amount-cap">Amount to send</div>
                <div class="pay-amount" id="payAmount">&#8369;0.00</div>
                <dl class="pay-amount-rows">
                    <div><dt>Document</dt><dd id="payDocName">&mdash;</dd></div>
                    <div><dt>Request</dt><dd id="payReq">&mdash;</dd></div>
                </dl>
            </div>

            <div class="pay-qr-frame">
                <?php // Both states are rendered and the image is the one
                      // hidden. `exists` is a filesystem check, which is
                      // right for "has this host been given the file" but
                      // cannot see a web server that refuses to serve a
                      // file that IS there — wrong permissions, a deny rule
                      // in .htaccess, the file moved after the page was
                      // cached. That still renders a broken-image icon, and
                      // a broken icon inside a payment screen is worse than
                      // no icon: it looks like the student's phone failed.
                      // So the image also carries an onerror that reveals
                      // the same notice, and the notice is the resting
                      // state the image replaces. ?>
                <div class="pay-qr-missing" id="payQrMissing"<?= $gcashQr['exists'] ? ' hidden' : '' ?>>
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <b>GCash payment is not available right now</b>
                    <span>Please pay at the registrar counter instead.</span>
                </div>
                <?php if ($gcashQr['exists']): ?>
                    <img class="pay-qr" id="payQrImg" src="<?= htmlspecialchars($gcashQr['url']) ?>"
                         alt="GCash QR code — scan this with the GCash app"
                         onerror="document.getElementById('payQrImg').hidden=true;document.getElementById('payQrMissing').hidden=false;">
                <?php endif; ?>
            </div>

            <?php if ($gcashQr['exists']): ?>
                <ol class="pay-steps">
                    <li>Open GCash and choose <b>Scan QR</b>.</li>
                    <li>Enter the amount above.</li>
                    <li>Send it, then tap <b>I have paid</b> to send the screenshot.</li>
                </ol>
            <?php else: ?>
                <p class="pay-next">Pay at the counter, then attach the receipt so the office can record it.</p>
            <?php endif; ?>
        </div>

        <div class="modal-footer nq-dialog-foot pay-foot">
            <button type="button" class="btn btn-light" onclick="closePayModal()">Close</button>
            <button type="button" class="btn btn-primary" id="payDoneBtn" onclick="payDone()">
                <i class="fa-solid fa-receipt"></i> I have paid
            </button>
        </div>
    </div>
</div>

<!-- Receipt Upload Modal
     Reached from the "Attach Receipt" button on any paid-online row, so
     the student can (a) send the screenshot and (b) send it AGAIN after
     the registrar asks for a different one. Re-upload is allowed on
     purpose — the server resets verification on replace, so a corrected
     screenshot cannot ride in on the old one's approval. -->
<div class="modal-overlay" id="receiptModal">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h2><i class="fa-solid fa-receipt"></i> Attach GCash Receipt</h2>
            <button class="modal-close" onclick="closeReceiptModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <!-- targetPk / targetLabel are module-level, not form fields:
                 the request is already created and paid by the time this
                 runs, so the form carries only the receipt itself. -->
            <input type="hidden" id="receiptRequestPk" value="">
            <div id="receiptExisting"></div>
            <div class="form-group" style="margin-top:12px;">
                <label for="receiptFile">Receipt image or PDF <span style="color:#dc2626;">*</span></label>
                <!-- accept mirrors the server allow-list in
                     doc_store_receipt(): jpg/jpeg/png/webp/pdf. A
                     mismatch would only surface as a confusing toast
                     after the upload. -->
                <input type="file" id="receiptFile" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                <div class="form-hint">A screenshot of the GCash confirmation, or the emailed receipt PDF. <?= strtoupper(implode(', ', DOC_RECEIPT_EXT)) ?>, up to <?= round(DOC_RECEIPT_MAX_BYTES / 1048576) ?> MB.</div>
            </div>
            <div class="form-group">
                <label for="receiptRef">GCash reference number</label>
                <input type="text" id="receiptRef" class="form-control" placeholder="e.g. 9A2B3C4D5E" autocomplete="off">
                <div class="form-hint">Optional, but it is what Finance reconciles against — include it if your GCash app shows one.</div>
            </div>
            <div id="receiptPreview" style="display:none;margin-bottom:12px;">
                <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:6px;">Selected</div>
                <div id="receiptPreviewBody" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;font-size:12.5px;">
                    <i class="fa-solid fa-file-image" style="color:#2563eb;"></i>
                    <span id="receiptPreviewName" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                    <span id="receiptPreviewSize" style="margin-left:auto;color:#64748b;white-space:nowrap;"></span>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
                <button type="button" class="btn btn-light" onclick="closeReceiptModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="receiptSubmitBtn" onclick="submitReceipt()"><i class="fa-solid fa-paper-plane"></i> Send Receipt</button>
            </div>
        </div>
    </div>
</div>

<script>
const CATALOG = <?= json_encode(array_map(function ($c) {
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'base_fee' => (float) $c['base_fee'],
            'fee_type' => $c['fee_type'], 'requirement' => $c['requirement'] ?? ''];
}, $catalog)) ?>;
const STUDENT_ID = <?= (int) $student['id'] ?>;
let selectedCatalogId = 0;
// No currentTxn any more. Payment happens inside the GCash app on the
// student's phone, where this page cannot observe it — so there is no
// transaction to hold open, and nothing to poll for.

function openRequestModal(presetId) {
    document.getElementById('requestForm').reset();
    document.getElementById('requestModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    // reset() returns the radios to their markup defaults, so the styling
    // that keys off :checked comes back with them. Nothing to restore by
    // hand — which is the point of using real inputs.
    if (presetId) {
        const radio = document.querySelector('.co-radio[value="' + presetId + '"]');
        if (radio) { radio.checked = true; selectCatalogOption(radio); }
        else { selectedCatalogId = 0; document.getElementById('catalogId').value = ''; updateFeePreview(); }
    } else {
        selectedCatalogId = 0;
        document.getElementById('catalogId').value = '';
        updateFeePreview();
    }
    syncPayNote();
}
function closeRequestModal() { document.getElementById('requestModal').classList.remove('active'); document.body.style.overflow = ''; }
function pickFromCatalog(id) { openRequestModal(id); }

// Called on every catalog radio's change. The card's selected look comes
// from .co-radio:checked + .co-card, so there is no class to add or remove
// and nothing here can leave the highlight stranded on a card the student
// has already moved away from.
document.querySelectorAll('.co-radio').forEach(function (radio) {
    radio.addEventListener('change', function () { if (this.checked) selectCatalogOption(this); });
});
function selectCatalogOption(radio) {
    selectedCatalogId = parseInt(radio.value, 10) || 0;
    document.getElementById('catalogId').value = selectedCatalogId;
    updateFeePreview();
}
function updateFeePreview() {
    const opt = CATALOG.find(c => c.id === selectedCatalogId);
    const qtyGroup = document.getElementById('qtyGroup');
    const qtyHint  = document.getElementById('qtyHint');
    const qtyInput = document.getElementById('reqQty');
    if (!opt) {
        document.getElementById('feePreview').textContent = '—';
        document.getElementById('feeNote').textContent = 'Pick a document to see its fee.';
        qtyGroup.hidden = true;
        document.getElementById('reqFileGroup').hidden = true;
        return;
    }
    const perUnit = opt.fee_type !== 'flat';
    qtyGroup.hidden = !perUnit;
    if (perUnit && qtyHint) {
        const unit = opt.fee_type === 'per_syllabus' ? 'syllabus' : 'page';
        qtyHint.textContent = 'This document is charged per ' + unit + '.';
    }
    if (!perUnit) qtyInput.value = 1;
    const qty = Math.max(1, parseInt(qtyInput.value) || 1);
    const docFee = opt.base_fee * (perUnit ? qty : 1);
    document.getElementById('feePreview').textContent = '₱' + docFee.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('feeNote').textContent = opt.name + (perUnit ? ' × ' + qty : ' · one-time');
    const fileGroup = document.getElementById('reqFileGroup');
    const hint = document.getElementById('reqHint');
    if (opt.requirement) {
        fileGroup.hidden = false;
        hint.textContent = 'Bring: ' + opt.requirement;
        hint.classList.add('visible');
    } else {
        fileGroup.hidden = true;
        hint.classList.remove('visible');
    }
}
document.getElementById('reqQty').addEventListener('input', updateFeePreview);

// Fulfillment is fixed at Pickup, so there is nothing left to synchronise
// between a card and a field. What remains here is the one thing the fee
// ticket's footnote has to keep true: it states when the student pays, and
// that depends on the payment radio.
function syncPayNote() {
    const online = (document.querySelector('input[name="payment_method"]:checked') || {}).value === 'Online';
    document.getElementById('payHint').textContent = online
        ? "You'll pay by GCash right after you submit."
        : "You won't pay anything yet — settle it at the counter when you collect.";
    syncFeeFootnote();
}
function syncFeeFootnote() {
    const el = document.getElementById('feeFootNote');
    if (!el) return;
    const online = (document.querySelector('input[name="payment_method"]:checked') || {}).value === 'Online';
    el.textContent = online
        ? 'Pay online now, then collect at the counter when it is ready.'
        : 'Pay at the registrar counter when you collect.';
}
document.querySelectorAll('input[name="payment_method"]').forEach(function (r) {
    r.addEventListener('change', syncPayNote);
});
document.getElementById('requestModal').addEventListener('click', function(e) { if (e.target === this) closeRequestModal(); });
document.getElementById('payModal').addEventListener('click', function(e) { if (e.target === this) closePayModal(); });
document.getElementById('receiptModal').addEventListener('click', function(e) { if (e.target === this) closeReceiptModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeRequestModal(); closePayModal(); closeReceiptModal(); } });
</script>

<script>
// ── RECEIPT UPLOAD ────────────────────────────────────────
// Separate from the payment modal on purpose. Payment happens once, at
// the start; the receipt happens after the student has actually paid
// and found the screenshot in their GCash history — possibly minutes
// later, possibly on a different day, possibly twice. Coupling them
// would mean the upload UI only exists during the seconds the gateway
// redirect is on screen.
document.getElementById('receiptFile').addEventListener('change', function () {
    var f = this.files && this.files[0];
    var box = document.getElementById('receiptPreview');
    if (!f) { box.style.display = 'none'; return; }
    document.getElementById('receiptPreviewName').textContent = f.name;
    document.getElementById('receiptPreviewSize').textContent = (f.size / 1024).toFixed(0) + ' KB';
    box.style.display = 'block';
});

// Mirrors the server's limits so the student is told here rather than
// after a 5 MB upload has already crossed the wire. The values are
// rendered from DOC_RECEIPT_MAX_BYTES / DOC_RECEIPT_EXT rather than
// retyped: a limit enforced in only one of the two places is a limit
// that quietly stops being enforced.
var RECEIPT_MAX_BYTES = <?= (int) DOC_RECEIPT_MAX_BYTES ?>;
var RECEIPT_EXT = <?= json_encode(array_values(DOC_RECEIPT_EXT)) ?>;

function openReceiptModal(requestPk, requestLabel, existingName) {
    document.getElementById('receiptRequestPk').value = requestPk;
    document.getElementById('receiptFile').value = '';
    document.getElementById('receiptRef').value = '';
    document.getElementById('receiptPreview').style.display = 'none';
    // Say plainly that this replaces the old one. Silent replacement
    // would let a student believe they were adding a second receipt.
    document.getElementById('receiptExisting').innerHTML = existingName
        ? '<div style="font-size:12.5px;color:#b45309;"><i class="fa-solid fa-triangle-exclamation"></i> <b>Replacing</b> the receipt already on file (' +
          htmlEscape(existingName) + '). It will be checked again from scratch.</div>'
        : '<div style="font-size:12.5px;color:#475569;">Request <b>' + htmlEscape(requestLabel) + '</b></div>';
    document.getElementById('receiptModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeReceiptModal() {
    document.getElementById('receiptModal').classList.remove('active');
    document.body.style.overflow = '';
}
function htmlEscape(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

async function submitReceipt() {
    var pk = document.getElementById('receiptRequestPk').value;
    var input = document.getElementById('receiptFile');
    var f = input.files && input.files[0];
    if (!pk) { showToast('No document request selected.', 'error'); return; }
    if (!f) { showToast('Please choose your GCash receipt image or PDF.', 'error'); return; }

    // Same checks as the server, run first so the common mistake is a
    // local toast rather than a round-trip that rejects the file.
    var ext = (f.name.split('.').pop() || '').toLowerCase();
    if (RECEIPT_EXT.indexOf(ext) === -1) {
        // Built from RECEIPT_EXT rather than typed out. A hand-written list
        // is a second copy of the rule: when DOC_RECEIPT_EXT gained an
        // extension, the message here would keep rejecting the files the
        // server had started accepting.
        showToast('The receipt must be a ' + RECEIPT_EXT.join(', ').toUpperCase() + ' file.', 'error'); return;
    }
    if (f.size > RECEIPT_MAX_BYTES) {
        showToast('That file is ' + (f.size / 1024 / 1024).toFixed(1) + ' MB. The limit is ' + (RECEIPT_MAX_BYTES / 1048576) + ' MB.', 'error'); return;
    }

    var btn = document.getElementById('receiptSubmitBtn');
    var orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    try {
        var fd = new FormData();
        fd.set('action', 'upload_receipt');
        fd.set('request_id', pk);
        fd.set('gcash_ref', document.getElementById('receiptRef').value.trim());
        fd.set('payment_receipt', f);
        var res = await fetch('../api/student-documents.php', { method: 'POST', body: fd });
        var d = await res.json();
        if (d.success) {
            closeReceiptModal();
            showToast(d.message, 'success');
            // Reload rather than patching the row: the button that was
            // just clicked becomes a status pill, and which pill depends
            // on server-side state the client cannot safely guess.
            setTimeout(function () { location.reload(); }, 1200);
        } else {
            showToast(d.message || 'Could not upload the receipt.', 'error');
            btn.disabled = false; btn.innerHTML = orig;
        }
    } catch (err) {
        showToast('Network error — the receipt was not sent.', 'error');
        btn.disabled = false; btn.innerHTML = orig;
    }
}
</script>

<script>
document.getElementById('requestForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!selectedCatalogId) { showToast('Please select a document from the catalog.', 'error'); return; }
    const btn = document.getElementById('submitReqBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    try {
        const fd = new FormData(this);
        fd.set('quantity', document.getElementById('reqQty').value || '1');
        fd.set('request_type', 'Regular');
        // Read what the student actually chose. This used to be pinned to
        // 'Pickup' in JS, which silently discarded the payment radio they
        // had just clicked — the request was filed as paid at the counter
        // while the screen said GCash.
        //
        // fulfillment_type and request_type are hidden fields carrying fixed
        // values, so FormData already has them and they are not re-set here.
        // delivery_address is gone entirely: with no courier there is nowhere
        // to send one, and sending an empty string invited the API to store a
        // blank address against a pickup request.
        fd.set('payment_method', (document.querySelector('input[name="payment_method"]:checked') || {}).value || 'Online');
        const res = await fetch('../api/student-documents.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) { showToast(d.message, d.data && d.data.document_status === 'Pending_Clearance' ? 'warning' : 'success'); setTimeout(() => location.reload(), 900); }
        else { showToast(d.message || 'Submission failed.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
    } catch (err) { showToast('Network error.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
});

// The payment screen shows a QR code and a receipt. There is no
// transaction to create and no status to poll: the money moves inside
// the GCash app, on the student's own phone, where this page cannot see
// it. So opening the screen is pure display, and the only thing that
// happens next is the student sending the screenshot back.
let payTarget = null;

function openPaymentModal(requestId, requestLabel, amount, docName) {
    payTarget = { id: requestId, label: requestLabel };
    const pesos = '₱' + Number(amount || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    document.getElementById('payAmount').textContent = pesos;
    document.getElementById('payReq').textContent = requestLabel;
    document.getElementById('payDocName').textContent = docName || '—';
    document.getElementById('payModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('active');
    document.body.style.overflow = '';
}

// "I have paid" goes straight to the receipt form, because that is the
// only thing left to do. The student has the screenshot in their camera
// roll and the reference number in their GCash history; making them
// close this dialog and find another button would be a step with no
// reason behind it.
function payDone() {
    if (!payTarget) { closePayModal(); return; }
    closePayModal();
    openReceiptModal(payTarget.id, payTarget.label, '');
}
</script>
function toggleDetail(id) { const row = document.getElementById('detail-' + id); if (row) row.style.display = row.style.display === 'none' ? '' : 'none'; }

function applyFilters() {
    const q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    let visible = 0;
    document.querySelectorAll('table tbody tr[data-doc]').forEach(tr => {
        const matchQ = !q || tr.textContent.toLowerCase().includes(q);
        const matchS = !st || tr.dataset.status === st;
        tr.style.display = (matchQ && matchS) ? '' : 'none';
        const detail = document.getElementById('detail-' + tr.dataset.doc);
        if (detail) detail.style.display = 'none';
        if (matchQ && matchS) visible++;
    });
    document.querySelector('.table-footer .info-text').innerHTML = 'Showing <strong>' + visible + '</strong> of <strong>' + document.querySelectorAll('table tbody tr[data-doc]').length + '</strong> requests';
}
document.getElementById('docSearch').addEventListener('input', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
</script>

<?php include '../includes/footer.php'; ?>
