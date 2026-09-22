<?php
// ============================================================
//  REGISTRAR/DOCUMENTS.PHP
//  Document Requests — v2 queue + metrics hub (no Digital).
//  Pickup only. Express available from registrar onsite.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();

// ── Queue ───────────────────────────────────────────────────────
$requests = $db->fetchAll(
    "SELECT dr.*, c.name AS catalog_name, c.sku, c.fee_type, c.base_fee,
            c.requirement, c.triggers_exit_clearance,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name,
            s.student_number
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
       LEFT JOIN students s ON dr.student_id = s.id
      ORDER BY dr.id DESC"
);

// Status events grouped by request.
$eventsByRequest = [];
if ($requests) {
    $ids = array_map('intval', array_column($requests, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $events = $db->fetchAll(
        "SELECT * FROM document_request_events WHERE request_id IN ($ph) ORDER BY id ASC",
        $ids
    );
    foreach ($events as $ev) {
        $eventsByRequest[(int) $ev['request_id']][] = $ev;
    }
}

// ── Metrics ─────────────────────────────────────────────────────
$tatHours = $db->fetchColumn(
    "SELECT AVG(TIMESTAMPDIFF(HOUR, paid_at, ready_at))
       FROM document_requests WHERE ready_at IS NOT NULL AND paid_at IS NOT NULL"
);
$tatHours = $tatHours !== null ? round((float) $tatHours, 1) : null;

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$revenueRows = $db->fetchAll(
    "SELECT COALESCE(c.name, dr.document_type) AS doc_name,
            SUM(dr.fee_amount) AS revenue, COUNT(*) AS cnt
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
      WHERE dr.document_status <> 'Rejected'
        AND COALESCE(dr.paid_at, dr.request_date) BETWEEN ? AND ?
      GROUP BY c.id, dr.document_type ORDER BY revenue DESC",
    [$from . ' 00:00:00', $to . ' 23:59:59']
);
$revenueTotal = array_sum(array_map(fn($r) => (float) $r['revenue'], $revenueRows));

$volumeRows = $db->fetchAll(
    "SELECT DATE(request_date) AS d, request_type, COUNT(*) AS cnt
       FROM document_requests WHERE request_date >= ?
      GROUP BY DATE(request_date), request_type",
    [date('Y-m-d', strtotime('-6 days')) . ' 00:00:00']
);
$volByDay = [];
foreach ($volumeRows as $v) $volByDay[$v['d']][$v['request_type']] = (int) $v['cnt'];
$days = $expressSeries = $regularSeries = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[] = date('M d', strtotime($d));
    $expressSeries[] = $volByDay[$d]['Express'] ?? 0;
    $regularSeries[] = $volByDay[$d]['Regular'] ?? 0;
}

$regularCount = $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE request_type = 'Regular'") ?: 0;
$expressCount = $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE request_type = 'Express'") ?: 0;

$catalog   = $db->fetchAll("SELECT * FROM document_catalog WHERE is_active = 1 ORDER BY id");
$students  = $db->fetchAll("SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name FROM students WHERE status='active' ORDER BY name");

$statusPill = [
    'Pending_Clearance' => ['pending-clearance','fa-triangle-exclamation'],
    'Awaiting_Payment'  => ['awaiting-payment','fa-credit-card'],
    'Processing'        => ['processing','fa-gear'],
    'Ready'             => ['ready','fa-circle-check'],
    'Shipped'           => ['shipped','fa-truck-fast'],
    'Claimed'           => ['claimed','fa-box-check'],
    'Rejected'          => ['rejected','fa-xmark'],
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
    'DOC-TOR'     => ['linear-gradient(135deg,#2563eb,#1d4ed8)','fa-file-invoice'],
    'DOC-COE'     => ['linear-gradient(135deg,#16a34a,#15803d)','fa-certificate'],
    'DOC-GM'      => ['linear-gradient(135deg,#0d9488,#0f766e)','fa-handshake-angle'],
    'DOC-DIPLOMA' => ['linear-gradient(135deg,#7c3aed,#6d28d9)','fa-graduation-cap'],
    'DOC-CTC'     => ['linear-gradient(135deg,#4f46e5,#4338ca)','fa-copy'],
    'DOC-HD'      => ['linear-gradient(135deg,#ea580c,#c2410c)','fa-sign-out-alt'],
    'DOC-CD'      => ['linear-gradient(135deg,#db2777,#be185d)','fa-book-open'],
];

$page_title = 'Document Requests';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';
$extra_css = ['documents.css'];
$use_chart = true;
?>
﻿

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>Document Requests</h1>
        <p>Queue, workflow actions, and performance metrics.</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="openNewRequest()"><i class="fas fa-plus"></i> New Request</button>
    </div>
</header>

<!-- ── Stats Cards ──────────────────────────────────────────── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon blue"><i class="fa-solid fa-clock"></i></div></div>
        <div class="stat-number"><?= $tatHours !== null ? htmlspecialchars($tatHours) . '<span style="font-size:15px;"> hrs</span>' : '—' ?></div>
        <div class="stat-label">Avg Turnaround</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon green"><i class="fa-solid fa-coins"></i></div></div>
        <div class="stat-number">&#8369;<?= number_format($revenueTotal, 2) ?></div>
        <div class="stat-label">Monthly Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon yellow"><i class="fa-solid fa-file-lines"></i></div></div>
        <div class="stat-number"><?= (int) $regularCount ?></div>
        <div class="stat-label">Regular Requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon purple"><i class="fa-solid fa-bolt"></i></div></div>
        <div class="stat-number"><?= (int) $expressCount ?></div>
        <div class="stat-label">Express Requests</div>
    </div>
</div>

<!-- ── Charts ───────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px;">
    <div class="panel">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fa-solid fa-chart-bar"></i> Revenue by Document Type</div>
            <div class="range-filter">
                <input type="date" id="revFrom" class="form-control" value="<?= htmlspecialchars($from) ?>" style="height:34px;width:140px;">
                <span style="color:#94a3b8;">–</span>
                <input type="date" id="revTo" class="form-control" value="<?= htmlspecialchars($to) ?>" style="height:34px;width:140px;">
                <button class="btn btn-sm btn-secondary" onclick="applyRevFilter()"><i class="fa-solid fa-filter"></i></button>
            </div>
        </div>
        <div style="padding:16px;">
            <div class="metric-canvas">
                <canvas id="revenueChart"
                    data-labels='<?= htmlspecialchars(json_encode(array_column($revenueRows, 'doc_name'))) ?>'
                    data-data='<?= htmlspecialchars(json_encode(array_map(fn($r) => (float) $r['revenue'], $revenueRows))) ?>'
                    data-total="<?= (float) $revenueTotal ?>"></canvas>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fa-solid fa-chart-line"></i> Daily Queue Volume (Last 7 Days)</div>
        </div>
        <div style="padding:16px;">
            <div class="metric-canvas">
                <canvas id="volumeChart"
                    data-labels='<?= htmlspecialchars(json_encode($days)) ?>'
                    data-express='<?= htmlspecialchars(json_encode($expressSeries)) ?>'
                    data-regular='<?= htmlspecialchars(json_encode($regularSeries)) ?>'></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ── Queue Table ──────────────────────────────────────────── -->
<div class="panel" style="margin-top:24px;">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fa-solid fa-list"></i> Request Queue</div>
    </div>
    <div style="padding:16px 20px;">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px;">
            <div style="flex:1;min-width:200px;">
                <div class="form-group" style="margin:0;">
                    <input type="text" id="docSearch" class="form-control" placeholder="Search student, document, ID…" style="height:36px;">
                </div>
            </div>
            <select id="typeFilter" class="form-control" style="height:36px;width:160px;" onchange="applyFilters()">
                <option value="">All Types</option>
                <option value="Regular">Regular</option>
                <option value="Express">Express</option>
            </select>
            <select id="statusFilter" class="form-control" style="height:36px;width:180px;" onchange="applyFilters()">
                <option value="">All Statuses</option>
                <option value="Pending_Clearance">Pending Clearance</option>
                <option value="Awaiting_Payment">Awaiting Payment</option>
                <option value="Processing">Processing</option>
                <option value="Ready">Ready for Release</option>
                <option value="Shipped">Shipped</option>
                <option value="Claimed">Claimed</option>
                <option value="Rejected">Rejected</option>
            </select>
            <span style="font-size:12px;color:#94a3b8;">Showing <strong id="showingCount"><?= count($requests) ?></strong> of <?= count($requests) ?></span>
        </div>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Student</th><th>Request</th><th>Fee</th><th>Type</th><th>Fulfillment</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" class="empty-state"><i class="fas fa-file-lines"></i><p>No document requests found</p><span>Requests appear here once submitted.</span></td></tr>
            <?php else: foreach ($requests as $r):
                $pill = $statusPill[$r['document_status']] ?? ['awaiting-payment','fa-clock'];
                $label = $statusLabel[$r['document_status']] ?? str_replace('_', ' ', $r['document_status']);
                $ci = $catIcon[$r['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)','fa-file-lines'];
                $st = (string) $r['document_status'];
                $reqEvents = $eventsByRequest[(int) $r['id']] ?? [];
                $isPaid = !empty($r['paid_at']);
            ?>
                <tr data-doc="<?= (int) $r['id'] ?>" data-status="<?= htmlspecialchars($st) ?>"
                    data-reqtype="<?= htmlspecialchars((string) $r['request_type']) ?>"
                    data-paid="<?= $isPaid ? '1' : '0' ?>"
                    data-label="<?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type'])), ENT_QUOTES) ?>"
                    onclick="toggleDetail(<?= (int) $r['id'] ?>)">
                    <td><div class="student-info"><div class="student-avatar blue"><?= htmlspecialchars(strtoupper(substr($r['student_name'], 0, 1))) ?></div><div><div class="student-name"><?= htmlspecialchars($r['student_name']) ?></div><div class="student-sub"><?= htmlspecialchars($r['student_number']) ?></div></div></div></td>
                    <td><div class="student-info"><div class="student-avatar" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div><div><div class="student-name"><?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type']))) ?></div><div class="student-sub"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($r['request_id'] ?? '') ?></div></div></div></td>
                    <td style="font-size:13px;font-weight:700;color:#0f172a;">&#8369;<?= number_format((float) ($r['fee_amount'] ?? 0), 2) ?></td>
                    <td><span class="chip <?= $r['request_type'] === 'Express' ? 'express' : 'regular' ?>"><i class="fa-solid fa-bolt"></i> <?= htmlspecialchars($r['request_type']) ?></span></td>
                    <td><span class="chip pickup"><i class="fa-solid fa-store"></i> Pickup</span></td>
                    <td><span class="pill <?= $pill[0] ?>"><i class="fa-solid <?= $pill[1] ?>"></i> <?= htmlspecialchars($label) ?></span></td>
                    <td><div class="row-actions" onclick="event.stopPropagation();">
                        <?php if (in_array($st, ['Awaiting_Payment','Pending_Clearance'], true)): ?>
                            <?php if ($isPaid): ?>
                                <button class="btn btn-sm btn-secondary" onclick="processDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-gear"></i> Process</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger" onclick="rejectDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
                        <?php elseif ($st === 'Processing'): ?>
                            <button class="btn btn-sm btn-success" onclick="approveRelease(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-circle-check"></i> Approve &amp; Release</button>
                            <button class="btn btn-sm btn-danger" onclick="rejectDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
                        <?php elseif ($st === 'Ready'): ?>
                            <button class="btn btn-sm btn-success" onclick="claimDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-box-check"></i> Claimed</button>
                        <?php elseif ($st === 'Shipped'): ?>
                            <button class="btn btn-sm btn-success" onclick="claimDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-box-check"></i> Claimed</button>
                        <?php else: ?>
                            <span style="font-size:12px;color:#94a3b8;"><i class="fa-solid fa-chevron-down"></i> View</span>
                        <?php endif; ?>
                    </div></td>
                </tr>

                <tr class="doc-detail-row" id="detail-<?= (int) $r['id'] ?>" style="display:none;">
                    <td colspan="7" style="padding:0;">
                        <div class="doc-detail" style="padding:18px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <?php if ($st === 'Rejected'): ?>
                                <div class="block-banner" style="margin-bottom:12px;">
                                    <div class="banner-icon"><i class="fa-solid fa-xmark"></i></div>
                                    <div><div class="banner-title">Request rejected</div><div class="banner-text"><?= htmlspecialchars($r['rejection_reason'] ?? 'No reason provided.') ?></div></div>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12.5px;color:#475569;">
                                <div><strong>Purpose:</strong> <?= htmlspecialchars($r['purpose'] ?: '—') ?></div>
                                <div><strong>Recipient:</strong> <?= htmlspecialchars($r['recipient'] ?: '—') ?></div>
                                <div><strong>Qty:</strong> <?= (int) ($r['quantity'] ?? 1) ?></div>
                                <div><strong>Payment:</strong> <?= htmlspecialchars($r['payment_method'] ?? 'Online') ?></div>
                                <div><strong>Requested:</strong> <?= date('M d, Y h:i A', strtotime($r['request_date'])) ?></div>
                                <?php if (!empty($r['paid_at'])): ?><div><strong>Paid:</strong> <?= date('M d, Y h:i A', strtotime($r['paid_at'])) ?></div><?php endif; ?>
                                <?php if (!empty($r['ready_at'])): ?><div><strong>Ready:</strong> <?= date('M d, Y h:i A', strtotime($r['ready_at'])) ?></div><?php endif; ?>
                                <?php if (!empty($r['release_date'])): ?><div><strong>Release Date:</strong> <?= htmlspecialchars($r['release_date']) ?></div><?php endif; ?>
                            </div>
                            <?php if (!empty($reqEvents)): ?>
                                <div style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px;">
                                    <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;"><i class="fa-solid fa-timeline"></i> Activity Log</div>
                                    <?php foreach ($reqEvents as $ev): ?>
                                        <div style="display:flex;gap:10px;margin-bottom:6px;font-size:12px;">
                                            <span style="color:#94a3b8;white-space:nowrap;"><?= date('M d, h:i A', strtotime($ev['created_at'])) ?></span>
                                            <span style="color:#1e293b;"><?= htmlspecialchars($ev['note'] ?? $ev['status']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
</main>

<!-- ═══ NEW REQUEST MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="newRequestModal">
    <div class="modal-content" style="max-width:560px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-file-circle-plus"></i> New Document Request</h3>
            <button class="modal-close" onclick="closeModal('newRequestModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="newRequestForm" onsubmit="submitNewRequest(event)">
                <div class="form-group">
                    <label>Student <span style="color:#dc2626;">*</span></label>
                    <select name="student_id" id="nrStudent" class="form-control" data-searchable required>
                        <option value="">Search or select a student…</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['student_number']) ?> — <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Document Type <span style="color:#dc2626;">*</span></label>
                    <select name="catalog_id" id="nrCatalog" class="form-control" required onchange="updateNrFee()">
                        <option value="">Select a document…</option>
                        <?php foreach ($catalog as $c):
                            $feeTxt = $c['fee_type'] === 'flat'
                                ? '&#8369;' . number_format((float) $c['base_fee'], 2)
                                : '&#8369;' . number_format((float) $c['base_fee'], 2) . ' per ' . str_replace('_', ' ', $c['fee_type']); ?>
                            <option value="<?= (int) $c['id'] ?>"
                                data-fee="<?= (float) $c['base_fee'] ?>"
                                data-fee-type="<?= htmlspecialchars($c['fee_type']) ?>"
                                data-req="<?= htmlspecialchars((string) $c['requirement'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($c['name']) ?> (<?= $feeTxt ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="nrHint" class="req-hint" style="margin-top:4px;"></div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Priority</label>
                        <select id="nrPriority" class="form-control" onchange="updateNrFee()">
                            <option value="Regular">Regular</option>
                            <option value="Express">Express (+&#8369;100)</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Fulfillment</label>
                        <input type="text" class="form-control" value="Pickup at Registrar" readonly style="background:#f1f5f9;">
                    </div>
                </div>
                <div class="form-group">
                    <label>Purpose <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="purpose" id="nrPurpose" class="form-control" placeholder="e.g., Employment requirement" required>
                </div>
                <div class="form-group">
                    <label>Recipient</label>
                    <input type="text" name="recipient" id="nrRecipient" class="form-control" placeholder="e.g., UP Manila Registrar">
                </div>
                <div class="fee-preview">
                    <span class="fp-label"><i class="fa-solid fa-coins"></i> Estimated Fee</span>
                    <span class="fp-amount" id="nrFeePreview">&#8369;0.00</span>
                </div>
                <div class="modal-footer" style="border-top:1px solid #e2e8f0;margin-top:16px;padding-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('newRequestModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="nrSubmitBtn"><i class="fa-solid fa-plus"></i> Add Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ APPROVE & RELEASE MODAL ═════════════════════════════════ -->
<div class="modal-overlay" id="approveReleaseModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-circle-check"></i> Approve &amp; Release</h3>
            <button class="modal-close" onclick="closeModal('approveReleaseModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="arId">
            <p style="font-size:13px;color:#475569;margin-bottom:12px;">Set the date of release for this document.</p>
            <div class="form-group">
                <label>Date of Release <span style="color:#dc2626;">*</span></label>
                <input type="date" id="arReleaseDate" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #e2e8f0;">
            <button class="btn btn-secondary" onclick="closeModal('approveReleaseModal')">Cancel</button>
            <button class="btn btn-success" id="arSubmitBtn" onclick="submitApproveRelease()"><i class="fa-solid fa-circle-check"></i> Approve &amp; Release</button>
        </div>
    </div>
</div>

<!-- ═══ REJECT MODAL ═════════════════════════════════════════════ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-ban"></i> Reject Request</h3>
            <button class="modal-close" onclick="closeModal('rejectModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="rejectId">
            <p style="font-size:13px;color:#475569;margin-bottom:12px;">Rejecting: <strong id="rejectLabel"></strong></p>
            <div class="form-group">
                <label>Rejection Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="rejectReason" class="form-control" rows="3" placeholder="Enter reason for rejection…"></textarea>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #e2e8f0;">
            <button class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
            <button class="btn btn-danger" id="rejectSubmit" onclick="submitReject()"><i class="fa-solid fa-ban"></i> Reject</button>
        </div>
    </div>
</div>

<script>
// ── Helpers ────────────────────────────────────────────────────
const PRIORITY_FEE = 100;

function openModal(id) { const m = document.getElementById(id); if (m) { m.classList.add('active'); document.body.style.overflow = 'hidden'; } }
function closeModal(id) { const m = document.getElementById(id); if (m) { m.classList.remove('active'); document.body.style.overflow = ''; } }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) { m.classList.remove('active'); document.body.style.overflow = ''; } });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => { m.classList.remove('active'); document.body.style.overflow = ''; });
});

function rowLabel(id) {
    const tr = document.querySelector('tr[data-doc="' + id + '"]');
    return tr ? (tr.dataset.label || 'Request #' + id) : 'Request #' + id;
}

async function putDoc(id, action, extra) {
    const body = Object.assign({ action: action }, extra || {});
    const res = await fetch('../api/documents.php?id=' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    return res.json();
}

// ── New Request Modal ──────────────────────────────────────────
function openNewRequest() {
    document.getElementById('newRequestForm').reset();
    document.getElementById('nrHint').textContent = '';
    document.getElementById('nrHint').classList.remove('visible');
    document.getElementById('nrFeePreview').textContent = '\u20B10.00';
    openModal('newRequestModal');
}

function updateNrFee() {
    const opt = document.getElementById('nrCatalog').selectedOptions[0];
    if (!opt || !opt.value) {
        document.getElementById('nrFeePreview').textContent = '\u20B10.00';
        document.getElementById('nrHint').classList.remove('visible');
        return;
    }
    const fee = parseFloat(opt.dataset.fee || '0');
    const feeType = opt.dataset.feeType;
    const isExpress = document.getElementById('nrPriority').value === 'Express';
    const total = (feeType === 'flat' ? fee : fee) + (isExpress ? PRIORITY_FEE : 0);
    document.getElementById('nrFeePreview').textContent = '\u20B1' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const req = opt.dataset.req;
    if (req) {
        document.getElementById('nrHint').textContent = 'Required: ' + req;
        document.getElementById('nrHint').classList.add('visible');
    } else {
        document.getElementById('nrHint').classList.remove('visible');
    }
}

async function submitNewRequest(e) {
    e.preventDefault();
    const btn = document.getElementById('nrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding\u2026';
    const data = {
        student_id: document.getElementById('nrStudent').value,
        catalog_id: document.getElementById('nrCatalog').value,
        request_type: document.getElementById('nrPriority').value,
        fulfillment_type: 'Pickup',
        purpose: document.getElementById('nrPurpose').value.trim(),
        recipient: document.getElementById('nrRecipient').value.trim()
    };
    if (!data.student_id || !data.catalog_id || !data.purpose) {
        showToast('Please fill all required fields.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Request';
        return;
    }
    try {
        const res = await fetch('../api/student-documents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            closeModal('newRequestModal');
            showToast(result.message || 'Document request created.', 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(result.message || 'Failed to create request.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Request';
    }
}

// ── Process ────────────────────────────────────────────────────
function processDoc(id) {
    const tr = document.querySelector('tr[data-doc="' + id + '"]');
    if (tr && tr.dataset.paid !== '1') {
        showToast('Payment not confirmed. Cannot process yet.', 'error');
        return;
    }
    if (!confirm('Start processing this request?')) return;
    putDoc(id, 'process').then(d => {
        if (d.success) { showToast('Request set to Processing.', 'success'); location.reload(); }
        else showToast(d.message || 'Action failed.', 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ── Approve & Release ─────────────────────────────────────────
function approveRelease(id) {
    document.getElementById('arId').value = id;
    document.getElementById('arReleaseDate').value = '';
    openModal('approveReleaseModal');
}

async function submitApproveRelease() {
    const id = document.getElementById('arId').value;
    const releaseDate = document.getElementById('arReleaseDate').value;
    if (!releaseDate) { showToast('Please select a release date.', 'error'); return; }
    const btn = document.getElementById('arSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Approving\u2026';
    try {
        const d = await putDoc(id, 'ready', { approval_reason: 'Approved by registrar', release_date: releaseDate });
        if (d.success) {
            closeModal('approveReleaseModal');
            showToast('Request approved and ready for release.', 'success');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(d.message || 'Action failed.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Approve &amp; Release';
    }
}

// ── Claim ──────────────────────────────────────────────────────
function claimDoc(id) {
    if (!confirm('Mark this request as claimed?')) return;
    putDoc(id, 'claim').then(d => {
        if (d.success) { showToast('Request marked Claimed.', 'success'); location.reload(); }
        else showToast(d.message || 'Action failed.', 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ── Reject ─────────────────────────────────────────────────────
function rejectDoc(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectLabel').textContent = rowLabel(id);
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
}

async function submitReject() {
    const id = document.getElementById('rejectId').value;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { showToast('Please enter a rejection reason.', 'error'); return; }
    const btn = document.getElementById('rejectSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting\u2026';
    try {
        const d = await putDoc(id, 'reject', { rejection_reason: reason });
        if (d.success) { showToast('Request rejected.', 'success'); location.reload(); }
        else showToast(d.message || 'Action failed.', 'error');
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-ban"></i> Reject';
    }
}

// ── Detail Row Toggle ──────────────────────────────────────────
function toggleDetail(id) {
    const row = document.getElementById('detail-' + id);
    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}

// ── Search + Filters ───────────────────────────────────────────
function applyFilters() {
    const q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const rt = document.getElementById('typeFilter').value;
    let visible = 0;
    document.querySelectorAll('table tbody tr[data-doc]').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const matchQ = !q || text.includes(q);
        const matchS = !st || tr.dataset.status === st;
        const matchR = !rt || tr.dataset.reqtype === rt;
        const show = matchQ && matchS && matchR;
        tr.style.display = show ? '' : 'none';
        const detail = document.getElementById('detail-' + tr.dataset.doc);
        if (detail) detail.style.display = 'none';
        if (show) visible++;
    });
    document.getElementById('showingCount').textContent = visible;
}
document.getElementById('docSearch').addEventListener('input', applyFilters);

// ── Revenue Date Filter ────────────────────────────────────────
function applyRevFilter() {
    const from = document.getElementById('revFrom').value;
    const to = document.getElementById('revTo').value;
    window.location.href = 'documents.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
}
</script>

<?php include '../includes/footer.php'; ?>
