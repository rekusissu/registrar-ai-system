<?php
// ============================================================
//  STUDENT/DOCUMENTS.PHP
//  Student's own document requests — v2 workflow.
//  Catalog + pricing, new-request modal, clearance-gate block
//  banner, request list with workflow stepper + event timeline,
//  mock payment flow, and digital-PDF download.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'My Documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_documents';
$extra_css = ['student.css', 'documents.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// ── Catalog (active items only) ────────────────────────────────
$catalog = $db->fetchAll(
    "SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id ASC"
);

// ── Finance balance for the clearance-gate block banner ────────
$balance = (float) ($db->fetchColumn('SELECT balance FROM finance WHERE student_id = ?', [$student['id']]) ?? 0.00);

// ── The student's requests ─────────────────────────────────────
$requests = $db->fetchAll(
    "SELECT dr.*, c.name AS catalog_name, c.sku, c.fee_type, c.base_fee, c.requirement, c.triggers_exit_clearance
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
      WHERE dr.student_id = ?
      ORDER BY dr.id DESC",
    [$student['id']]
);

// ── Status events (one pass, grouped in PHP) ───────────────────
$eventsByRequest = [];
if ($requests) {
    $ids = array_map('intval', array_column($requests, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $events = $db->fetchAll(
        "SELECT * FROM document_request_events WHERE request_id IN ($placeholders) ORDER BY id ASC",
        $ids
    );
    foreach ($events as $ev) {
        $eventsByRequest[(int) $ev['request_id']][] = $ev;
    }
}

// ── Display vocabulary ─────────────────────────────────────────
$statusPill = [
    'Pending_Clearance' => ['pending-clearance', 'fa-triangle-exclamation'],
    'Awaiting_Payment'  => ['awaiting-payment',  'fa-credit-card'],
    'Processing'        => ['processing',        'fa-gear'],
    'Ready'             => ['ready',             'fa-circle-check'],
    'Shipped'           => ['shipped',           'fa-truck-fast'],
    'Claimed'           => ['claimed',           'fa-box-check'],
    'Rejected'          => ['rejected',          'fa-xmark'],
];
$statusLabel = [
    'Pending_Clearance' => 'Pending Clearance',
    'Awaiting_Payment'  => 'Awaiting Payment',
    'Processing'        => 'Processing',
    'Ready'             => 'Ready for Release',
    'Shipped'           => 'Shipped',
    'Claimed'           => 'Claimed',
    'Rejected'          => 'Rejected',
];
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
    $p = '₱' . number_format((float) $c['base_fee'], 2);
    if ($c['fee_type'] === 'per_page')     return $p . ' / page';
    if ($c['fee_type'] === 'per_syllabus') return $p . ' / syllabus';
    return $p . ' one-time';
}

function renderStepper(string $status, bool $hasClearanceStep): string {
    $steps = [
        ['key' => 'Payment',    'label' => 'Payment',    'icon' => 'fa-credit-card'],
        ['key' => 'Processing', 'label' => 'Processing', 'icon' => 'fa-gear'],
        ['key' => 'Ready',      'label' => 'Ready',      'icon' => 'fa-circle-check'],
        ['key' => 'Shipping',   'label' => 'Shipping',   'icon' => 'fa-truck-fast'],
        ['key' => 'Claimed',    'label' => 'Claimed',    'icon' => 'fa-box-check'],
    ];
    if ($hasClearanceStep) {
        array_unshift($steps, ['key' => 'Clearance', 'label' => 'Clearance', 'icon' => 'fa-shield-halved']);
    }
    $statusKey = $status === 'Shipped' ? 'Shipping' : $status;
    $activeIdx = null;
    foreach ($steps as $i => $s) {
        if ($s['key'] === $statusKey) { $activeIdx = $i; break; }
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

// ── Counts for the status strip ────────────────────────────────
$counts = ['Pending_Clearance' => 0, 'Awaiting_Payment' => 0, 'Processing' => 0, 'Ready' => 0, 'Shipped' => 0, 'Claimed' => 0, 'Rejected' => 0];
foreach ($requests as $r) {
    if (isset($counts[$r['document_status']])) $counts[$r['document_status']]++;
}
$studentName = getStudentFullName($student);
$isBlocked = $balance > 0;
$hasHeld = $counts['Pending_Clearance'] > 0;
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>My Documents</h1><p>Request and track documents from the Registrar.</p></div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> New Request</button>
            </div>
        </header>

        <?php if ($isBlocked): ?>
        <div class="block-banner">
            <div class="banner-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
                <div class="banner-title">Action required — outstanding balance of ₱<?= number_format($balance, 2) ?></div>
                <div class="banner-text">Your account has a balance due on record. New document requests are held at the <strong>Clearance</strong> step until the Registrar's Office clears your account. Once settled, you'll be able to pay online and the request will move to Processing.</div>
            </div>
        </div>
        <?php elseif ($hasHeld): ?>
        <div class="block-banner">
            <div class="banner-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <div class="banner-title">Request pending clearance</div>
                <div class="banner-text">One or more of your requests are waiting for exit clearance sign-off before they can be released. Please coordinate with the Registrar's Office.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="status-strip">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-file-lines"></i></div>
                <div><div class="s-value"><?= count($requests) ?></div><div class="s-label">Total Requests</div></div>
            </div>
            <div class="s-item">
                <div class="s-icon yellow"><i class="fa-solid fa-credit-card"></i></div>
                <div><div class="s-value"><?= $counts['Awaiting_Payment'] ?></div><div class="s-label">Awaiting Payment</div></div>
            </div>
            <div class="s-item">
                <div class="s-icon orange"><i class="fa-solid fa-gear"></i></div>
                <div><div class="s-value"><?= $counts['Processing'] + $counts['Ready'] ?></div><div class="s-label">In Processing</div></div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-truck-fast"></i></div>
                <div><div class="s-value"><?= $counts['Shipped'] + $counts['Claimed'] ?></div><div class="s-label">Delivered / Claimed</div></div>
            </div>
        </div>

        <!-- ── Catalog & pricing ───────────────────────────────── -->
        <div class="panel">
            <div class="panel-header">
                <div><h3><i class="fa-solid fa-tags"></i> Document Catalog &amp; Pricing</h3>
                    <p style="font-size:12.5px;color:#64748b;margin-top:2px;">Select a document to request. Fees are set by the Registrar's Office.</p></div>
            </div>
            <div class="catalog-grid">
                <?php foreach ($catalog as $c):
                    $ci = $catIcon[$c['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines']; ?>
                    <div class="catalog-card" data-id="<?= (int) $c['id'] ?>" onclick="pickFromCatalog(<?= (int) $c['id'] ?>)">
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
                        <?php if ((int) $c['triggers_exit_clearance'] === 1): ?>
                            <div class="cat-clear"><i class="fa-solid fa-shield-halved"></i> Requires exit clearance</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── My requests ─────────────────────────────────────── -->
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
                    <span class="chip blue"><i class="fa-solid fa-file-lines"></i> <?= count($requests) ?> request<?= count($requests) === 1 ? '' : 's' ?></span>
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
                        $needsClearance = $r['document_status'] === 'Pending_Clearance'
                            || (int) ($r['triggers_exit_clearance'] ?? 0) === 1
                            || in_array('Pending_Clearance', array_column($reqEvents, 'status'), true);
                        $isRejected = $r['document_status'] === 'Rejected';
                        $payable = $r['document_status'] === 'Awaiting_Payment'
                            && (float) $r['fee_amount'] > 0
                            && ($r['payment_method'] ?? 'Online') !== 'Cash_on_Delivery';
                        $isCod = ($r['payment_method'] ?? 'Online') === 'Cash_on_Delivery';
                        $deliveryFee = (float) ($r['delivery_fee'] ?? 0);
                        $digitalReady = $r['document_status'] === 'Ready' && $r['fulfillment_type'] === 'Digital' && !empty($r['pdf_path']);
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
                                <div style="font-size:13px;font-weight:700;color:#0f172a;">₱<?= number_format((float) ($r['fee_amount'] ?? 0), 2) ?></div>
                                <?php if ($deliveryFee > 0): ?>
                                    <div style="font-size:11px;color:#0f766e;"><i class="fa-solid fa-motorcycle"></i> +₱<?= number_format($deliveryFee, 2) ?> delivery</div>
                                <?php endif; ?>
                                <?php if ($isCod): ?>
                                    <div style="font-size:11px;color:#dc2626;"><i class="fa-solid fa-hand-holding-dollar"></i> Cash on delivery</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="chip <?= $r['request_type'] === 'Express' ? 'express' : 'regular' ?>">
                                    <i class="fa-solid fa-bolt"></i> <?= $r['request_type'] === 'Express' ? 'Express' : 'Regular' ?>
                                </span>
                            </td>
                            <td>
                                <span class="chip <?= strtolower((string) $r['fulfillment_type']) ?>">
                                    <i class="fa-solid <?= $r['fulfillment_type'] === 'Courier' ? 'fa-truck' : ($r['fulfillment_type'] === 'Digital' ? 'fa-download' : 'fa-store') ?>"></i> <?= htmlspecialchars($r['fulfillment_type']) ?>
                                </span>
                            </td>
                            <td><span class="pill <?= $pill[0] ?>"><i class="fa-solid <?= $pill[1] ?>"></i> <?= htmlspecialchars($label) ?></span></td>
                            <td style="font-size:12px;color:#64748b;"><?= date('M d, Y', strtotime($r['request_date'])) ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <?php if ($payable): ?>
                                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation();openPaymentModal(<?= (int) $r['id'] ?>, '<?= htmlspecialchars($r['request_id']) ?>', <?= (float) $r['fee_amount'] + $deliveryFee ?>, <?= $deliveryFee ?>);">
                                        <i class="fa-solid fa-credit-card"></i> Pay Online
                                    </button>
                                <?php elseif ($digitalReady): ?>
                                    <a class="btn btn-sm btn-download" href="<?= $APP_ROOT . htmlspecialchars($r['pdf_path']) ?>" download>
                                        <i class="fa-solid fa-download"></i> PDF
                                    </a>
                                <?php elseif ($isRejected): ?>
                                    <span class="pill rejected" title="See expanded view for reason"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;"><i class="fa-solid fa-chevron-down"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="doc-detail-row" id="detail-<?= (int) $r['id'] ?>">
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
                                        <?= renderStepper((string) $r['document_status'], $needsClearance) ?>
                                    <?php endif; ?>

                                    <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:14px;">
                                        <?php if ($r['fulfillment_type'] === 'Courier' && !empty($r['delivery_address'])): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-location-dot" style="color:#2563eb;"></i> <b>Deliver to:</b> <?= htmlspecialchars($r['delivery_address']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($isCod): ?>
                                            <div style="font-size:12.5px;color:#b45309;"><i class="fa-solid fa-hand-holding-dollar" style="color:#dc2626;"></i> <b>Cash on delivery</b> — pay <b>₱<?= number_format((float) ($r['fee_amount'] ?? 0) + $deliveryFee, 2) ?></b> to the courier when you receive the document.</div>
                                        <?php elseif ($deliveryFee > 0): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-motorcycle" style="color:#0f766e;"></i> <b>Delivery fee:</b> ₱<?= number_format($deliveryFee, 2) ?> <span style="color:#94a3b8;">(paid by you)</span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['purpose'])): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-note-sticky" style="color:#64748b;"></i> <b>Purpose:</b> <?= htmlspecialchars($r['purpose']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['quantity']) && (int) $r['quantity'] > 1): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-copy"></i> <b>Qty:</b> <?= (int) $r['quantity'] ?> page(s) / syllabus</div>
                                        <?php endif; ?>
                                        <?php if ($r['document_status'] === 'Shipped' && !empty($r['lalamove_order_ref'])): ?>
                                            <div style="font-size:12.5px;color:#475569;"><i class="fa-solid fa-truck-fast" style="color:#6d28d9;"></i> <b>Rider ref:</b> <?= htmlspecialchars($r['lalamove_order_ref']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($r['fulfillment_type'] === 'Digital' && $r['document_status'] === 'Ready'): ?>
                                            <div style="font-size:12.5px;color:#0f766e;"><i class="fa-solid fa-lock" style="color:#0f766e;"></i> <b>PDF password:</b> your birthdate (<?= htmlspecialchars($student['birth_date']) ?>)</div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($r['fulfillment_type'] === 'Courier' && in_array($r['document_status'], ['Shipped', 'Claimed'], true)): ?>
                                        <div class="delivery-card">
                                            <div class="dl-icon"><i class="fa-solid fa-motorcycle"></i></div>
                                            <div class="dl-line"><b>Mock Lalamove order:</b> <?= htmlspecialchars($r['lalamove_order_ref']) ?><br>
                                                Status: <b><?= $r['document_status'] === 'Claimed' ? 'DELIVERED' : 'ASSIGNING_RIDER' ?></b> ·
                                                Tracking: <span style="font-family:monospace;"><?= htmlspecialchars('http://mock-lala.com/track/' . $r['lalamove_order_ref']) ?></span></div>
                                        </div>
                                    <?php endif; ?>

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
                <div class="info-text">Showing <strong><?= count($requests) ?></strong> of <strong><?= count($requests) ?></strong> requests</div>
            </div>
        </div>

    </div>
</main>

<!-- New Request Modal -->
<div class="modal-overlay" id="requestModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header"><h2><i class="fas fa-file-circle-plus"></i> New Document Request</h2><button class="modal-close" onclick="closeRequestModal()"><i class="fas fa-times"></i></button></div>
        <form id="requestForm"><div class="modal-body">
            <div class="form-group">
                <label>Document <span class="required">*</span></label>
                <div class="catalog-picker" id="catalogPicker">
                    <?php foreach ($catalog as $c): ?>
                        <div class="catalog-option" data-id="<?= (int) $c['id'] ?>" data-fee="<?= (float) $c['base_fee'] ?>" data-fee-type="<?= htmlspecialchars($c['fee_type']) ?>" data-req="<?= htmlspecialchars($c['requirement'] ?? '') ?>" data-clear="<?= (int) $c['triggers_exit_clearance'] ?>" onclick="selectCatalogOption(this, <?= (int) $c['id'] ?>)">
                            <span class="co-name"><?= htmlspecialchars($c['name']) ?></span>
                            <span class="co-fee"><?= feeLabel($c) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="catalog_id" id="catalogId" value="">
            </div>

            <div class="form-group" id="qtyGroup" style="display:none;">
                <label>Quantity <span class="required">*</span></label>
                <input type="number" id="reqQty" class="form-control" min="1" max="20" value="1">
                <small style="color:#94a3b8;">Number of pages / subject syllabi requested.</small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label>Request Type <span class="required">*</span></label>
                    <select name="request_type" id="reqType" class="form-control">
                        <option value="Regular">Regular</option>
                        <option value="Express">Express</option>
                    </select>
                    <small style="color:#94a3b8;">Express is prioritized by the Registrar.</small>
                </div>
                <div class="form-group">
                    <label>Fulfillment <span class="required">*</span></label>
                    <select name="fulfillment_type" id="reqFulfillment" class="form-control">
                        <option value="Pickup">Pickup at Registrar</option>
                        <option value="Digital">Digital (encrypted PDF)</option>
                        <option value="Courier">Courier delivery</option>
                    </select>
                </div>
            </div>

            <div class="form-group" id="addressGroup" style="display:none;">
                <label>Delivery Address <span class="required">*</span></label>
                <textarea name="delivery_address" id="reqAddress" class="form-control" rows="2" placeholder="Complete dropoff address for the courier"></textarea>
                <small style="color:#94a3b8;">The courier delivery fee is quoted from this address and is <b>paid by you</b> — it is added to the total below.</small>
            </div>

            <div class="form-group">
                <label>Payment Method <span class="required">*</span></label>
                <select name="payment_method" id="reqPaymentMethod" class="form-control">
                    <option value="Online">Pay online (GCash / Maya)</option>
                    <option value="Cash_on_Delivery">Cash on delivery</option>
                </select>
                <small style="color:#94a3b8;">Cash on delivery — pay the document fee plus delivery fee to the courier when you receive the document.</small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label>Purpose <span class="required">*</span></label>
                    <input type="text" name="purpose" id="reqPurpose" class="form-control" required placeholder="e.g. Job application, Transfer, Scholarship">
                </div>
                <div class="form-group">
                    <label>Recipient</label>
                    <input type="text" name="recipient" id="reqRecipient" class="form-control" placeholder="e.g. Company / School name">
                </div>
            </div>

            <div class="form-group" id="reqFileGroup" style="display:none;">
                <label>Requirement File</label>
                <input type="file" name="requirement_file" id="reqFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <div class="req-hint" id="reqHint"></div>
            </div>

            <div class="fee-preview">
                <div>
                    <div class="fp-label">Total fee</div>
                    <div style="font-size:11px;color:#94a3b8;" id="feeNote">Select a document to see the fee.</div>
                    <div style="font-size:11px;color:#0f766e;display:none;" id="deliveryFeeNote"></div>
                </div>
                <div class="fp-amount" id="feePreview">—</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" onclick="closeRequestModal()">Cancel</button>
            <button type="submit" class="btn btn-primary" id="submitReqBtn"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </div></form>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal-content" style="max-width:460px;">
        <div class="modal-header"><h2><i class="fa-solid fa-credit-card"></i> Pay Online</h2><button class="modal-close" onclick="closePayModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div id="payLoading" style="text-align:center;padding:26px 0;"><i class="fa-solid fa-spinner fa-spin" style="font-size:22px;color:#2563eb;"></i><p style="color:#64748b;font-size:13px;margin-top:8px;">Contacting payment gateway…</p></div>
            <div id="payContent" style="display:none;">
                <div class="pay-gateway">
                    <div class="pay-brand"><i class="fa-solid fa-bolt"></i> Mock Payment Gateway · GCash / Maya</div>
                    <div class="pay-amount" id="payAmount">₱0.00</div>
                    <div class="pay-row"><span>Document fee</span><b id="payDocFee">—</b></div>
                    <div class="pay-row" id="payDeliveryRow" style="display:none;"><span>Delivery fee (paid by you)</span><b id="payDeliveryFee">—</b></div>
                    <div class="pay-row"><span>Transaction ID</span><b id="payTxn">—</b></div>
                    <div class="pay-row"><span>Request</span><b id="payReq">—</b></div>
                    <div class="pay-row"><span>Status</span><b style="color:#fde68a;">PENDING</b></div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="gateway-chip"><i class="fa-solid fa-link"></i> <span id="payUrl">—</span></span>
                </div>
                <p style="font-size:12.5px;color:#64748b;margin:12px 0 4px;">This is a school-project mock gateway. Press the button below to simulate the payment provider confirming your payment.</p>
                <div class="simulate-actions">
                    <button class="btn btn-primary" style="flex:1;" id="simulateSuccessBtn"><i class="fa-solid fa-circle-check"></i> Simulate Successful Payment</button>
                    <button class="btn btn-light" id="simulateFailBtn"><i class="fa-solid fa-xmark"></i> Fail</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CATALOG = <?= json_encode(array_map(function ($c) {
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'base_fee' => (float) $c['base_fee'],
            'fee_type' => $c['fee_type'], 'requirement' => $c['requirement'] ?? '',
            'triggers_clearance' => (int) $c['triggers_exit_clearance']];
}, $catalog)) ?>;
const STUDENT_ID = <?= (int) $student['id'] ?>;

// Deterministic delivery quote — must mirror server-side mockDeliveryQuote()
// (PHP crc32 over the address → 1.5–5.5 km → ₱50 base + ₱20/km).
function crc32(str) {
    let c = -1;
    for (let i = 0; i < str.length; i++) {
        c ^= str.charCodeAt(i);
        for (let b = 0; b < 8; b++) c = (c >>> 1) ^ (0xEDB88320 & -(c & 1));
    }
    return (c ^ -1) >>> 0;
}
function mockDeliveryFee(address) {
    if (!address || !address.trim()) return 0;
    const dist = 1.5 + ((crc32(address.trim()) % 41) / 10);
    return Math.round((50 + dist * 20) * 100) / 100;
}

// ── Request modal ─────────────────────────────────────────────
let selectedCatalogId = 0;
let currentTxn = null;

function openRequestModal(presetId) {
    document.getElementById('requestForm').reset();
    document.getElementById('requestModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.querySelectorAll('.catalog-option').forEach(o => o.classList.remove('sel'));
    if (presetId) selectCatalogOption(document.querySelector(`.catalog-option[data-id="${presetId}"]`), presetId);
    else { selectedCatalogId = 0; document.getElementById('catalogId').value = ''; updateFeePreview(); }
}
function closeRequestModal() {
    document.getElementById('requestModal').classList.remove('active');
    document.body.style.overflow = '';
}
function pickFromCatalog(id) {
    openRequestModal(id);
    document.getElementById('requestModal').scrollTop = 0;
}

function selectCatalogOption(el, id) {
    document.querySelectorAll('.catalog-option').forEach(o => o.classList.remove('sel'));
    if (el) el.classList.add('sel');
    selectedCatalogId = id;
    document.getElementById('catalogId').value = id;
    updateFeePreview();
}
function updateFeePreview() {
    const opt = CATALOG[selectedCatalogId];
    const qtyInput = document.getElementById('reqQty');
    const qtyGroup = document.getElementById('qtyGroup');
    if (!opt) {
        document.getElementById('feePreview').textContent = '—';
        document.getElementById('feeNote').textContent = 'Select a document to see the fee.';
        document.getElementById('deliveryFeeNote').style.display = 'none';
        qtyGroup.style.display = 'none';
        document.getElementById('reqFileGroup').style.display = 'none';
        return;
    }
    const perUnit = opt.fee_type === 'flat';
    qtyGroup.style.display = perUnit ? 'none' : 'block';
    if (perUnit) qtyInput.value = 1;
    const qty = Math.max(1, parseInt(qtyInput.value) || 1);
    const docFee = opt.base_fee * (perUnit ? 1 : qty);

    // Courier delivery fee — quoted live from the address, paid by the student.
    const isCourier = document.getElementById('reqFulfillment').value === 'Courier';
    const delivery = isCourier ? mockDeliveryFee(document.getElementById('reqAddress').value) : 0;
    const total = docFee + delivery;

    document.getElementById('feePreview').textContent = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    document.getElementById('feeNote').textContent = opt.fee_type === 'flat' ? opt.name + ' (one-time)' : opt.name + ' × ' + qty;

    const deliveryNote = document.getElementById('deliveryFeeNote');
    if (delivery > 0) {
        deliveryNote.textContent = 'Includes delivery fee ₱' + delivery.toLocaleString('en-PH', { minimumFractionDigits: 2 }) + ' — paid by you to the courier.';
        deliveryNote.style.display = 'block';
    } else {
        deliveryNote.style.display = 'none';
    }

    const fileGroup = document.getElementById('reqFileGroup');
    const hint = document.getElementById('reqHint');
    if (opt.requirement) {
        fileGroup.style.display = 'block';
        hint.textContent = 'Required: ' + opt.requirement;
        hint.classList.add('visible');
    } else {
        fileGroup.style.display = 'none';
        hint.classList.remove('visible');
    }
}
document.getElementById('reqQty').addEventListener('input', updateFeePreview);
document.getElementById('reqAddress').addEventListener('input', updateFeePreview);
document.getElementById('reqFulfillment').addEventListener('change', function () {
    document.getElementById('addressGroup').style.display = this.value === 'Courier' ? 'block' : 'none';
    updateFeePreview();
});

document.getElementById('requestModal').addEventListener('click', function (e) { if (e.target === this) closeRequestModal(); });
document.getElementById('payModal').addEventListener('click', function (e) { if (e.target === this) closePayModal(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeRequestModal(); closePayModal(); } });

document.getElementById('requestForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!selectedCatalogId) { showToast('Please select a document from the catalog.', 'error'); return; }
    const btn = document.getElementById('submitReqBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    try {
        const fd = new FormData(this);
        fd.set('quantity', document.getElementById('reqQty').value || '1');
        const res = await fetch('../api/student-documents.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) { showToast(d.message, d.data && d.data.document_status === 'Pending_Clearance' ? 'warning' : 'success'); setTimeout(() => location.reload(), 900); }
        else { showToast(d.message || 'Submission failed.', 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    }
});

// ── Payment modal ──────────────────────────────────────────────
function openPaymentModal(requestId, requestLabel, amount, deliveryFee) {
    deliveryFee = deliveryFee || 0;
    const docFee = amount - deliveryFee;
    const modal = document.getElementById('payModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('payLoading').style.display = 'block';
    document.getElementById('payContent').style.display = 'none';
    document.getElementById('payAmount').textContent = '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    document.getElementById('payDocFee').textContent = '₱' + docFee.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    const deliveryRow = document.getElementById('payDeliveryRow');
    if (deliveryFee > 0) {
        deliveryRow.style.display = '';
        document.getElementById('payDeliveryFee').textContent = '₱' + deliveryFee.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    } else {
        deliveryRow.style.display = 'none';
    }
    document.getElementById('payReq').textContent = requestLabel || ('#' + requestId);
    document.getElementById('simulateSuccessBtn').disabled = true;
    document.getElementById('simulateFailBtn').disabled = true;

    fetch('../api/mock/payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', request_id: requestId, student_id: STUDENT_ID })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            currentTxn = d.data.transaction_id;
            document.getElementById('payTxn').textContent = currentTxn;
            document.getElementById('payUrl').textContent = d.data.payment_url;
            document.getElementById('payLoading').style.display = 'none';
            document.getElementById('payContent').style.display = 'block';
            document.getElementById('simulateSuccessBtn').disabled = false;
            document.getElementById('simulateFailBtn').disabled = false;
        } else {
            closePayModal();
            showToast(d.message || 'Could not start payment.', 'error');
        }
    }).catch(() => { closePayModal(); showToast('Payment gateway unreachable.', 'error'); });
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('active');
    document.body.style.overflow = '';
}
function simulatePayment(status) {
    if (!currentTxn) return;
    const btn = status === 'COMPLETED' ? document.getElementById('simulateSuccessBtn') : document.getElementById('simulateFailBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    fetch('../api/mock/payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'webhook', transaction_id: currentTxn, status: status })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            showToast(d.message, status === 'COMPLETED' ? 'success' : 'info');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(d.message || 'Simulation failed.', 'error');
            btn.disabled = false; btn.innerHTML = status === 'COMPLETED' ? '<i class="fa-solid fa-circle-check"></i> Simulate Successful Payment' : '<i class="fa-solid fa-xmark"></i> Fail';
        }
    }).catch(() => { showToast('Network error.', 'error'); btn.disabled = false; });
}
document.getElementById('simulateSuccessBtn').addEventListener('click', () => simulatePayment('COMPLETED'));
document.getElementById('simulateFailBtn').addEventListener('click', () => simulatePayment('FAILED'));

// ── Row detail toggle ──────────────────────────────────────────
function toggleDetail(id) {
    const row = document.getElementById('detail-' + id);
    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}

// ── Search + status filter ─────────────────────────────────────
function applyFilters() {
    const q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    let visible = 0;
    const rows = document.querySelectorAll('table tbody tr[data-doc]');
    rows.forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const matchQ = !q || text.includes(q);
        const matchS = !st || tr.dataset.status === st;
        tr.style.display = (matchQ && matchS) ? '' : 'none';
        const detail = document.getElementById('detail-' + tr.dataset.doc);
        if (detail) detail.style.display = 'none'; // collapse hidden rows
        if (matchQ && matchS) visible++;
    });
    document.querySelector('.table-footer .info-text').innerHTML =
        'Showing <strong>' + visible + '</strong> of <strong>' + rows.length + '</strong> requests';
}
document.getElementById('docSearch').addEventListener('input', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
</script>

<?php include '../includes/footer.php'; ?>
