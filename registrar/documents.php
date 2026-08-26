<?php
// ============================================================
//  REGISTRAR/DOCUMENTS.PHP
//  Document Requests — v2 queue + metrics hub.
//  Queue actions (process / ready / reject / ship / claim),
//  exit-clearance matrix, and the four spec metrics (turnaround,
//  revenue, daily queue volume, fulfillment split).
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

// Exit-clearance rows, only for clearance-triggering requests.
$clearanceMap = [];
$clearanceReqIds = array_filter($requests, fn($r) => (int) ($r['triggers_exit_clearance'] ?? 0) === 1);
if ($clearanceReqIds) {
    $ids = array_map('intval', array_column($clearanceReqIds, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT * FROM exit_clearances WHERE request_id IN ($ph)
          ORDER BY FIELD(office, 'Alumni', 'Dean', 'Property')",
        $ids
    );
    foreach ($rows as $row) {
        $clearanceMap[(int) $row['request_id']][] = $row;
    }
}

// Status events, one pass grouped in PHP.
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
// 1. Turnaround time: avg hours between payment and ready.
$tatHours = $db->fetchColumn(
    "SELECT AVG(TIMESTAMPDIFF(HOUR, paid_at, ready_at))
       FROM document_requests
      WHERE ready_at IS NOT NULL AND paid_at IS NOT NULL"
);
$tatHours = $tatHours !== null ? round((float) $tatHours, 1) : null;

// 2. Revenue report, by document type, over a date range.
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$revenueRows = $db->fetchAll(
    "SELECT COALESCE(c.name, dr.document_type) AS doc_name,
            SUM(dr.fee_amount) AS revenue, COUNT(*) AS cnt
       FROM document_requests dr
       LEFT JOIN document_catalog c ON c.id = dr.catalog_id
      WHERE dr.document_status <> 'Rejected'
        AND COALESCE(dr.paid_at, dr.request_date) BETWEEN ? AND ?
      GROUP BY c.id, dr.document_type
      ORDER BY revenue DESC",
    [$from . ' 00:00:00', $to . ' 23:59:59']
);
$revenueTotal = array_sum(array_map(fn($r) => (float) $r['revenue'], $revenueRows));

// 3. Daily queue volume (last 7 days, Express vs Regular).
$volumeRows = $db->fetchAll(
    "SELECT DATE(request_date) AS d, request_type, COUNT(*) AS cnt
       FROM document_requests
      WHERE request_date >= ?
      GROUP BY DATE(request_date), request_type",
    [date('Y-m-d', strtotime('-6 days')) . ' 00:00:00']
);
$volByDay = [];
foreach ($volumeRows as $v) {
    $volByDay[$v['d']][$v['request_type']] = (int) $v['cnt'];
}
$days = [];
$expressSeries = [];
$regularSeries = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[] = date('M d', strtotime($d));
    $expressSeries[] = $volByDay[$d]['Express'] ?? 0;
    $regularSeries[] = $volByDay[$d]['Regular'] ?? 0;
}
$todayVol = $volByDay[date('Y-m-d')] ?? [];
$expressToday = $todayVol['Express'] ?? 0;
$regularToday = $todayVol['Regular'] ?? 0;

// 4. Fulfillment split.
$fulfillRows = $db->fetchAll(
    "SELECT fulfillment_type, COUNT(*) AS cnt FROM document_requests GROUP BY fulfillment_type"
);
$fulfillMap = ['Pickup' => 0, 'Digital' => 0, 'Courier' => 0];
$fulfillTotal = 0;
foreach ($fulfillRows as $f) {
    if (isset($fulfillMap[$f['fulfillment_type']])) {
        $fulfillMap[$f['fulfillment_type']] = (int) $f['cnt'];
        $fulfillTotal += (int) $f['cnt'];
    }
}

// ── Display vocabulary ──────────────────────────────────────────
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

$page_title = 'Document Requests';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';
$extra_css = ['documents.css'];
$use_chart = true;
$page_scripts = ['documents.js'];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>Document Requests</h1>
        <p>Queue, workflow actions, exit clearances, and performance metrics.</p>
    </div>
    <div class="header-actions">
        <a href="documents-add.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Request</a>
    </div>
</header>

<!-- ── Metrics: stat cards ────────────────────────────────────── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon purple"><i class="fa-solid fa-stopwatch"></i></div></div>
        <div class="stat-number"><?= $tatHours !== null ? $tatHours . '<span style="font-size:15px;"> hrs</span>' : '—' ?></div>
        <div class="stat-label">Avg Turnaround (Payment → Ready)</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon green"><i class="fa-solid fa-coins"></i></div></div>
        <div class="stat-number">&#8369;<?= number_format($revenueTotal, 2) ?></div>
        <div class="stat-label">Revenue · <?= date('M d', strtotime($from)) ?> – <?= date('M d', strtotime($to)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon blue"><i class="fa-solid fa-bolt"></i></div></div>
        <div class="stat-number"><?= $expressToday ?></div>
        <div class="stat-label">Express Requests Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><div class="stat-icon yellow"><i class="fa-solid fa-calendar-day"></i></div></div>
        <div class="stat-number"><?= $regularToday ?></div>
        <div class="stat-label">Regular Requests Today</div>
    </div>
</div>

<!-- ── Metrics: charts ────────────────────────────────────────── -->
<div class="metrics-grid" style="margin-top:16px;">

    <div class="panel metric-panel">
        <div class="panel-header">
            <div><h3><i class="fa-solid fa-chart-column"></i> Revenue by Document Type</h3></div>
        </div>
        <form method="get" class="range-filter" style="margin-top:10px;">
            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            <span style="color:#94a3b8;">to</span>
            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            <button class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filter</button>
        </form>
        <div class="metric-canvas">
            <canvas id="revenueChart"
                data-labels='<?= htmlspecialchars(json_encode(array_column($revenueRows, 'doc_name'))) ?>'
                data-data='<?= htmlspecialchars(json_encode(array_map(fn($r) => (float) $r['revenue'], $revenueRows))) ?>'
                data-total='<?= (float) $revenueTotal ?>'></canvas>
        </div>
    </div>

    <div class="panel metric-panel">
        <div class="panel-header">
            <div><h3><i class="fa-solid fa-chart-bar"></i> Daily Queue Volume</h3></div>
        </div>
        <div class="metric-canvas">
            <canvas id="volumeChart"
                data-days='<?= htmlspecialchars(json_encode($days)) ?>'
                data-express='<?= htmlspecialchars(json_encode($expressSeries)) ?>'
                data-regular='<?= htmlspecialchars(json_encode($regularSeries)) ?>'></canvas>
        </div>
    </div>

    <div class="panel metric-panel">
        <div class="panel-header">
            <div><h3><i class="fa-solid fa-chart-pie"></i> Fulfillment Split</h3></div>
        </div>
        <div class="metric-canvas">
            <canvas id="fulfillmentChart"
                data-labels='<?= htmlspecialchars(json_encode(['Pickup', 'Digital', 'Courier'])) ?>'
                data-data='<?= htmlspecialchars(json_encode([$fulfillMap['Pickup'], $fulfillMap['Digital'], $fulfillMap['Courier']])) ?>'
                data-total='<?= $fulfillTotal ?>'></canvas>
        </div>
    </div>
</div>

<!-- ── Queue table ────────────────────────────────────────────── -->
<div class="panel" style="margin-top:16px;">
    <div class="search-toolbar">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="docSearch" placeholder="Search by request ID, student, document, or purpose...">
        </div>
        <select id="statusFilter" class="form-control" style="width:auto;min-width:170px;">
            <option value="">All statuses</option>
            <?php foreach ($statusLabel as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select id="typeFilter" class="form-control" style="width:auto;min-width:120px;">
            <option value="">All types</option>
            <option value="Express">Express</option>
            <option value="Regular">Regular</option>
        </select>
        <select id="fulfillFilter" class="form-control" style="width:auto;min-width:130px;">
            <option value="">All fulfillment</option>
            <option value="Pickup">Pickup</option>
            <option value="Digital">Digital</option>
            <option value="Courier">Courier</option>
        </select>
        <div class="panel-actions" style="margin-left:auto;display:flex;gap:8px;">
            <span class="chip blue"><i class="fa-solid fa-file-lines"></i> <?= count($requests) ?> request<?= count($requests) === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Student</th><th>Request</th><th>Fee</th><th>Type</th><th>Fulfillment</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" class="empty-state"><i class="fas fa-file-lines"></i><p>No document requests found</p><span>Requests from the student portal appear here.</span></td></tr>
            <?php else: foreach ($requests as $r):
                $pill = $statusPill[$r['document_status']] ?? ['awaiting-payment', 'fa-clock'];
                $label = $statusLabel[$r['document_status']] ?? str_replace('_', ' ', $r['document_status']);
                $ci = $catIcon[$r['sku']] ?? ['linear-gradient(135deg,#64748b,#475569)', 'fa-file-lines'];
                $st = (string) $r['document_status'];
                $clearances = $clearanceMap[(int) $r['id']] ?? [];
                $hasClearance = count($clearances) > 0; // exit-clearance doc: rows are seeded at submit
                $allCleared = $hasClearance && count(array_filter($clearances, fn($c) => $c['status'] === 'CLEARED')) === 3;
                $reqEvents = $eventsByRequest[(int) $r['id']] ?? [];
                // Lock "Mark Ready" only while exit-clearance rows are pending. A request
                // that merely *passed through* Pending_Clearance (finance block) but does
                // not trigger exit clearance must not stay locked with nothing to clear.
                $needsClearance = $hasClearance && !$allCleared;
                $isDigital = $r['fulfillment_type'] === 'Digital';
            ?>
                <tr data-doc="<?= (int) $r['id'] ?>" data-status="<?= htmlspecialchars($st) ?>"
                    data-reqtype="<?= htmlspecialchars((string) $r['request_type']) ?>"
                    data-fulfill="<?= htmlspecialchars((string) $r['fulfillment_type']) ?>"
                    data-address="<?= htmlspecialchars((string) $r['delivery_address'], ENT_QUOTES) ?>"
                    data-delivery="<?= (float) ($r['delivery_fee'] ?? 0) ?>"
                    data-method="<?= htmlspecialchars((string) ($r['payment_method'] ?? 'Online'), ENT_QUOTES) ?>"
                    data-label="<?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type'])), ENT_QUOTES) ?>"
                    onclick="toggleDetail(<?= (int) $r['id'] ?>)">
                    <td><div class="student-info"><div class="student-avatar blue"><?= htmlspecialchars(strtoupper(substr($r['student_name'], 0, 1))) ?></div><div><div class="student-name"><?= htmlspecialchars($r['student_name']) ?></div><div class="student-sub"><?= htmlspecialchars($r['student_number']) ?></div></div></div></td>
                    <td>
                        <div class="student-info">
                            <div class="student-avatar" style="background:<?= $ci[0] ?>;"><i class="fa-solid <?= $ci[1] ?>"></i></div>
                            <div>
                                <div class="student-name"><?= htmlspecialchars($r['catalog_name'] ?? ucwords(str_replace('_', ' ', $r['document_type']))) ?></div>
                                <div class="student-sub"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($r['request_id'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="font-size:13px;font-weight:700;color:#0f172a;">&#8369;<?= number_format((float) ($r['fee_amount'] ?? 0), 2) ?></td>
                    <td><span class="chip <?= $r['request_type'] === 'Express' ? 'express' : 'regular' ?>"><i class="fa-solid fa-bolt"></i> <?= $r['request_type'] ?></span></td>
                    <td><span class="chip <?= strtolower((string) $r['fulfillment_type']) ?>"><i class="fa-solid <?= $r['fulfillment_type'] === 'Courier' ? 'fa-truck' : ($r['fulfillment_type'] === 'Digital' ? 'fa-download' : 'fa-store') ?>"></i> <?= htmlspecialchars($r['fulfillment_type']) ?></span></td>
                    <td><span class="pill <?= $pill[0] ?>"><i class="fa-solid <?= $pill[1] ?>"></i> <?= htmlspecialchars($label) ?></span></td>
                    <td><div class="row-actions" onclick="event.stopPropagation();">
                        <?php if (in_array($st, ['Awaiting_Payment', 'Pending_Clearance'], true)): ?>
                            <button class="btn btn-sm btn-secondary" onclick="processDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-gear"></i> Process</button>
                            <button class="btn btn-sm btn-danger" onclick="rejectDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
                        <?php elseif ($st === 'Processing'): ?>
                            <?php if ($needsClearance): ?>
                                <button class="btn btn-sm btn-secondary" disabled title="Exit clearance must be complete (Alumni / Dean / Property)"><i class="fa-solid fa-lock"></i> Mark Ready</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-success" onclick="readyDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-circle-check"></i> Mark Ready</button>
                            <?php endif; ?>
                            <?php if ($isDigital): ?><button class="btn btn-sm btn-primary" onclick="genPdf(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-file-pdf"></i> PDF</button><?php endif; ?>
                            <?php if ($isDigital && !empty($r['pdf_path'])): ?><a class="btn btn-sm btn-light" href="<?= $APP_ROOT . htmlspecialchars($r['pdf_path']) ?>" target="_blank" rel="noopener" title="Open the generated PDF (password = student birthdate YYYY-MM-DD)"><i class="fa-solid fa-eye"></i> View</a><?php endif; ?>
                            <button class="btn btn-sm btn-danger" onclick="rejectDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
                        <?php elseif ($st === 'Ready'): ?>
                            <?php if ($r['fulfillment_type'] === 'Courier'): ?>
                                <button class="btn btn-sm btn-primary" onclick="shipDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-truck"></i> Ship</button>
                            <?php endif; ?>
                            <?php if ($isDigital): ?><button class="btn btn-sm btn-primary" onclick="genPdf(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-file-pdf"></i> PDF</button><?php endif; ?>
                            <?php if ($isDigital && !empty($r['pdf_path'])): ?><a class="btn btn-sm btn-light" href="<?= $APP_ROOT . htmlspecialchars($r['pdf_path']) ?>" target="_blank" rel="noopener" title="Open the generated PDF (password = student birthdate YYYY-MM-DD)"><i class="fa-solid fa-eye"></i> View</a><?php endif; ?>
                            <button class="btn btn-sm btn-success" onclick="claimDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-box-check"></i> Claimed</button>
                        <?php elseif ($st === 'Shipped'): ?>
                            <button class="btn btn-sm btn-success" onclick="claimDoc(<?= (int) $r['id'] ?>)"><i class="fa-solid fa-box-check"></i> Claimed</button>
                        <?php else: ?>
                            <span style="font-size:12px;color:#94a3b8;"><i class="fa-solid fa-chevron-down"></i> View</span>
                        <?php endif; ?>
                    </div></td>
                </tr>
                <tr class="doc-detail-row" id="detail-<?= (int) $r['id'] ?>">
                    <td colspan="7" style="padding:0;">
                        <div class="doc-detail" style="padding:18px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <?php if ($st === 'Rejected'): ?>
                                <div class="block-banner" style="margin-bottom:12px;">
                                    <div class="banner-icon"><i class="fa-solid fa-xmark"></i></div>
                                    <div><div class="banner-title">Request rejected</div><div class="banner-text"><?= htmlspecialchars($r['rejection_reason'] ?? 'No reason provided.') ?></div></div>
                                </div>
                            <?php endif; ?>

                            <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12.5px;color:#475569;">
                                <span><i class="fa-solid fa-hashtag" style="color:#2563eb;"></i> <b><?= htmlspecialchars($r['request_id']) ?></b></span>
                                <span><i class="fa-solid fa-note-sticky"></i> <?= htmlspecialchars($r['purpose'] ?? '—') ?></span>
                                <?php if (!empty($r['recipient'])): ?><span><i class="fa-solid fa-building"></i> <?= htmlspecialchars($r['recipient']) ?></span><?php endif; ?>
                                <?php if (!empty($r['requirement_file_path'])): ?><span><i class="fa-solid fa-file-shield"></i> <a href="<?= $APP_ROOT . htmlspecialchars($r['requirement_file_path']) ?>" target="_blank" rel="noopener">Requirement file</a></span><?php endif; ?>
                                <?php if ($st === 'Shipped' && !empty($r['lalamove_order_ref'])): ?><span><i class="fa-solid fa-truck-fast" style="color:#6d28d9;"></i> Rider: <b><?= htmlspecialchars($r['lalamove_order_ref']) ?></b></span><?php endif; ?>
                                <?php if ($st === 'Claimed'): ?><span><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Claimed <?= $r['claimed_at'] ? date('M d, Y h:i A', strtotime($r['claimed_at'])) : '' ?></span><?php endif; ?>
                            </div>

                            <?php if ($hasClearance): ?>
                                <?php if ($clearances): ?>
                                    <div class="clearance-matrix">
                                        <div class="cm-head"><span><i class="fa-solid fa-shield-halved"></i> Exit Clearance — Approve is locked until all are CLEARED</span></div>
                                        <?php foreach ($clearances as $cl): $cleared = $cl['status'] === 'CLEARED'; ?>
                                            <div class="cm-row">
                                                <span class="cm-office"><i class="fa-solid fa-building-columns"></i> <?= htmlspecialchars($cl['office']) ?> Office</span>
                                                <span style="display:flex;align-items:center;gap:10px;">
                                                    <span class="pill <?= $cleared ? 'ready' : 'pending-clearance' ?>"><i class="fa-solid <?= $cleared ? 'fa-circle-check' : 'fa-clock' ?>"></i> <?= $cleared ? 'CLEARED' : 'PENDING' ?></span>
                                                    <span class="cm-meta"><?= $cleared && $cl['cleared_at'] ? 'by #' . (int) $cl['cleared_by'] . ' · ' . date('M d h:i A', strtotime($cl['cleared_at'])) : '' ?></span>
                                                    <button class="btn btn-sm <?= $cleared ? 'btn-light' : 'btn-success' ?>" onclick="setClearance(<?= (int) $r['id'] ?>, '<?= htmlspecialchars($cl['office']) ?>', <?= $cleared ? "'reset'" : "'clear'" ?>)">
                                                        <i class="fa-solid <?= $cleared ? 'fa-rotate-left' : 'fa-check' ?>"></i> <?= $cleared ? 'Reopen' : 'Mark CLEARED' ?>
                                                    </button>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($r['fulfillment_type'] === 'Courier' && in_array($st, ['Shipped', 'Claimed'], true) && !empty($r['delivery_address'])): ?>
                                <div class="delivery-card" style="margin-top:12px;">
                                    <div class="dl-icon"><i class="fa-solid fa-motorcycle"></i></div>
                                    <div class="dl-line"><b>Mock Lalamove order:</b> <?= htmlspecialchars($r['lalamove_order_ref']) ?><br>
                                        Dropoff: <b><?= htmlspecialchars($r['delivery_address']) ?></b> · Tracking: <span style="font-family:monospace;">http://mock-lala.com/track/<?= htmlspecialchars($r['lalamove_order_ref']) ?></span></div>
                                </div>
                            <?php endif; ?>

                            <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin:14px 0 8px;"><i class="fa-solid fa-timeline"></i> Status timeline</h4>
                            <ul class="timeline">
                                <?php if (empty($reqEvents)): ?>
                                    <li><div class="tl-dot" style="background:#94a3b8;border-color:#e2e8f0;"></div><div class="tl-status">Submitted</div><div class="tl-when"><?= date('M d, Y h:i A', strtotime($r['request_date'])) ?></div></li>
                                <?php else: foreach ($reqEvents as $ev): ?>
                                    <li><div class="tl-dot"></div><div class="tl-status"><?= htmlspecialchars($statusLabel[$ev['status']] ?? str_replace('_', ' ', $ev['status'])) ?></div><?php if (!empty($ev['note'])): ?><div class="tl-note"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?><div class="tl-when"><?= date('M d, Y h:i A', strtotime($ev['created_at'])) ?></div></li>
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
        <div class="info-text">Showing <strong id="showingCount"><?= count($requests) ?></strong> of <strong><?= count($requests) ?></strong> requests</div>
    </div>
</div>

</div>
</main>

<!-- Ship (mock Lalamove) modal -->
<div class="modal-overlay" id="shipModal">
    <div class="modal-content" style="max-width:480px;">
        <div class="modal-header"><h2><i class="fa-solid fa-truck"></i> Book Courier Delivery</h2><button class="modal-close" onclick="closeModal('shipModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div class="detail-row"><span class="lbl">Request</span><span class="val" id="shipRequest">—</span></div>
            <div class="detail-row"><span class="lbl">Dropoff</span><span class="val" id="shipAddress">—</span></div>
            <div class="detail-row" id="shipFeeRow" style="display:none;"><span class="lbl">Delivery fee (student)</span><span class="val" id="shipStoredFee" style="color:#0f766e;font-weight:700;">—</span></div>
            <div class="detail-row" id="shipMethodRow" style="display:none;"><span class="lbl">Payment</span><span class="val" id="shipMethod">—</span></div>
            <div id="shipQuote" style="display:none;margin-top:12px;">
                <div class="delivery-card">
                    <div class="dl-icon"><i class="fa-solid fa-receipt"></i></div>
                    <div class="dl-line"><b>Mock Lalamove quotation</b><br>
                        Distance: <b id="shipDistance">—</b> km · Fee: <b id="shipFee">—</b> · Quote: <b id="shipQuoteId">—</b></div>
                </div>
            </div>
            <div id="shipBooked" style="display:none;margin-top:12px;">
                <div class="delivery-card" style="border-color:#86efac;">
                    <div class="dl-icon"><i class="fa-solid fa-motorcycle"></i></div>
                    <div class="dl-line"><b id="shipDriver">—</b><br>
                        Order: <b id="shipOrderId">—</b> · <span id="shipPhone">—</span> · <span style="font-family:monospace;" id="shipTracking">—</span></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeModal('shipModal')">Close</button>
            <button class="btn btn-primary" id="shipQuoteBtn" onclick="getShipQuote()"><i class="fa-solid fa-calculator"></i> Get Quote</button>
            <button class="btn btn-success" id="shipBookBtn" style="display:none;" onclick="bookRider()"><i class="fa-solid fa-truck-fast"></i> Book Rider</button>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header"><h2><i class="fa-solid fa-ban"></i> Reject Request</h2><button class="modal-close" onclick="closeModal('rejectModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="rejectId">
            <div class="detail-row" style="margin-bottom:14px;"><span class="lbl">Request</span><span class="val" id="rejectLabel">—</span></div>
            <div class="form-group"><label>Rejection reason <span class="required">*</span></label><textarea id="rejectReason" class="form-control" rows="3" placeholder="e.g. Missing scanned ID / incomplete requirements"></textarea></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeModal('rejectModal')">Cancel</button>
            <button class="btn btn-danger" id="rejectSubmit" onclick="submitReject()"><i class="fa-solid fa-ban"></i> Reject</button>
        </div>
    </div>
</div>

<!-- Generic confirm-action modal (replaces native confirm()) -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header"><h2><i class="fa-solid fa-circle-question"></i> Confirm Action</h2><button class="modal-close" onclick="closeModal('confirmModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body">
            <div id="confirmMessage" style="font-size:13.5px;line-height:1.55;color:#334155;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeModal('confirmModal')">Cancel</button>
            <button class="btn btn-primary" id="confirmOkBtn" onclick="doConfirm()"></button>
        </div>
    </div>
</div>

<script>
let SHIP = { id: null, address: '', quote: null };
let CONFIRM_ACTION = null;

function toggleDetail(id) {
    const row = document.getElementById('detail-' + id);
    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }
function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
['shipModal', 'rejectModal', 'confirmModal'].forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener('click', e => { if (e.target === el) closeModal(id); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal('shipModal'); closeModal('rejectModal'); closeModal('confirmModal'); } });

// ── In-app confirmation (no native confirm()) ──────────────────
function rowLabel(id) {
    const row = document.querySelector('tr[data-doc="' + id + '"]');
    return row ? row.dataset.label : ('#' + id);
}
function confirmAction(message, okLabel, okClass, onConfirm) {
    document.getElementById('confirmMessage').textContent = message;
    const ok = document.getElementById('confirmOkBtn');
    ok.className = 'btn ' + (okClass || 'btn-primary');
    ok.innerHTML = okLabel;
    CONFIRM_ACTION = onConfirm;
    openModal('confirmModal');
}
function doConfirm() {
    const fn = CONFIRM_ACTION;
    CONFIRM_ACTION = null;
    closeModal('confirmModal');
    if (typeof fn === 'function') fn();
}

function post(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(r => r.json());
}
function putDoc(id, action, extra) {
    return fetch('../api/documents.php?id=' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action: action }, extra || {}))
    }).then(r => r.json());
}

// ── Simple transition actions (confirmed via in-app modal) ─────
function processDoc(id) {
    confirmAction('Start processing "' + rowLabel(id) + '"? The request moves to the Processing queue.',
        '<i class="fa-solid fa-play"></i> Start Processing', 'btn-primary', () => {
            putDoc(id, 'process').then(d => {
                if (d.success) { showToast('Request moved to Processing.', 'success'); location.reload(); }
                else showToast(d.message || 'Action failed.', 'error');
            }).catch(() => showToast('Network error.', 'error'));
        });
}
function readyDoc(id) {
    confirmAction('Mark "' + rowLabel(id) + '" as Ready for Release? This finalizes the document and stamps the Ready time.',
        '<i class="fa-solid fa-check"></i> Mark Ready', 'btn-success', () => {
            putDoc(id, 'ready').then(d => {
                if (d.success) { showToast('Request marked Ready.', 'success'); location.reload(); }
                else showToast(d.message || 'Action failed.', 'error');
            }).catch(() => showToast('Network error.', 'error'));
        });
}
function claimDoc(id) {
    confirmAction('Mark "' + rowLabel(id) + '" as Claimed? This completes the request.',
        '<i class="fa-solid fa-flag-checkered"></i> Mark Claimed', 'btn-success', () => {
            putDoc(id, 'claim').then(d => {
                if (d.success) { showToast('Request marked Claimed.', 'success'); location.reload(); }
                else showToast(d.message || 'Action failed.', 'error');
            }).catch(() => showToast('Network error.', 'error'));
        });
}
function rejectDoc(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectLabel').textContent = rowLabel(id);
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
}
function submitReject() {
    const id = document.getElementById('rejectId').value;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { showToast('Please enter a rejection reason.', 'error'); return; }
    const btn = document.getElementById('rejectSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting…';
    putDoc(id, 'reject', { rejection_reason: reason }).then(d => {
        if (d.success) { showToast('Request rejected.', 'success'); location.reload(); }
        else showToast(d.message || 'Action failed.', 'error');
    }).catch(() => showToast('Network error.', 'error'))
      .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-ban"></i> Reject'; });
}

// ── Exit clearance ─────────────────────────────────────────────
function setClearance(requestId, office, action) {
    post('../api/exit-clearances.php', { request_id: requestId, office: office, action: action })
        .then(d => {
            if (d.success) { showToast(d.message, action === 'clear' ? 'success' : 'info'); location.reload(); }
            else showToast(d.message || 'Could not update clearance.', 'error');
        }).catch(() => showToast('Network error.', 'error'));
}

// ── Digital PDF generation (api/generate-document-pdf.php) ─────
function genPdf(id) {
    const btn = event && event.target ? event.target.closest('button') : null;
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>…'; }
    post('../api/generate-document-pdf.php', { request_id: id })
        .then(d => {
            if (d.success) { showToast('Digital PDF generated (' + d.data.filename + ').', 'success'); location.reload(); }
            else showToast(d.message || 'PDF generation failed.', 'error');
        }).catch(() => showToast('Network error.', 'error'))
        .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = orig; } });
}

// ── Courier shipping (mock Lalamove) ───────────────────────────
function shipDoc(id) {
    const row = document.querySelector('tr[data-doc="' + id + '"]');
    SHIP.id = id;
    SHIP.address = row ? row.dataset.address || '' : '';
    SHIP.quote = null;
    document.getElementById('shipRequest').textContent = row ? row.dataset.label : ('#' + id);
    document.getElementById('shipAddress').textContent = SHIP.address || 'No address on file';

    // The delivery fee was quoted to the student at submission and is stored on
    // the request — the student is the one who pays it (COD or at booking).
    const storedFee = parseFloat(row && row.dataset.delivery || 0);
    const method = row ? row.dataset.method || 'Online' : 'Online';
    document.getElementById('shipStoredFee').textContent = storedFee > 0 ? '&#8369;' + storedFee.toFixed(2) + ' — paid by student' : '—';
    document.getElementById('shipFeeRow').style.display = storedFee > 0 ? '' : 'none';
    document.getElementById('shipMethod').textContent = method === 'Cash_on_Delivery' ? 'Cash on Delivery (collect on hand-off)' : 'Online payment (already paid / pay at booking)';
    document.getElementById('shipMethodRow').style.display = '';

    document.getElementById('shipQuote').style.display = 'none';
    document.getElementById('shipBooked').style.display = 'none';
    document.getElementById('shipQuoteBtn').style.display = '';
    document.getElementById('shipBookBtn').style.display = 'none';
    openModal('shipModal');
}
function getShipQuote() {
    if (!SHIP.address) { showToast('No delivery address on file for this request.', 'error'); return; }
    const btn = document.getElementById('shipQuoteBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>…';
    post('../api/mock/lalamove.php', { action: 'quotation', pickup: 'Bestlink College of the Philippines', dropoff: SHIP.address })
        .then(d => {
            if (d.success) {
                SHIP.quote = d.data;
                document.getElementById('shipDistance').textContent = d.data.distance_km;
                document.getElementById('shipFee').textContent = '&#8369;' + d.data.total_fee.toFixed(2);
                document.getElementById('shipQuoteId').textContent = d.data.quotation_id;
                document.getElementById('shipQuote').style.display = 'block';
                document.getElementById('shipBookBtn').style.display = '';
            } else showToast(d.message || 'Quote failed.', 'error');
        }).catch(() => showToast('Network error.', 'error'))
        .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-calculator"></i> Get Quote'; });
}
function bookRider() {
    if (!SHIP.quote) return;
    const btn = document.getElementById('shipBookBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Booking…';
    post('../api/mock/lalamove.php', Object.assign({ action: 'order', request_id: SHIP.id, dropoff: SHIP.address }, SHIP.quote))
        .then(d => {
            if (d.success) {
                document.getElementById('shipDriver').textContent = d.data.driver_name + ' (rider)';
                document.getElementById('shipOrderId').textContent = d.data.order_id;
                document.getElementById('shipPhone').textContent = d.data.driver_phone;
                document.getElementById('shipTracking').textContent = d.data.tracking_url;
                document.getElementById('shipBooked').style.display = 'block';
                document.getElementById('shipQuoteBtn').style.display = 'none';
                document.getElementById('shipBookBtn').style.display = 'none';
                showToast('Rider booked — request marked Shipped.', 'success');
                setTimeout(() => location.reload(), 1400);
            } else showToast(d.message || 'Booking failed.', 'error');
        }).catch(() => showToast('Network error.', 'error'))
        .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-truck-fast"></i> Book Rider'; } });
}

// ── Search + filters ───────────────────────────────────────────
function applyFilters() {
    const q = (document.getElementById('docSearch').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const rt = document.getElementById('typeFilter').value;
    const ff = document.getElementById('fulfillFilter').value;
    let visible = 0;
    document.querySelectorAll('table tbody tr[data-doc]').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const matchQ = !q || text.includes(q);
        const matchS = !st || tr.dataset.status === st;
        const matchR = !rt || tr.dataset.reqtype === rt;
        const matchF = !ff || tr.dataset.fulfill === ff;
        const show = matchQ && matchS && matchR && matchF;
        tr.style.display = show ? '' : 'none';
        const detail = document.getElementById('detail-' + tr.dataset.doc);
        if (detail) detail.style.display = 'none';
        if (show) visible++;
    });
    document.getElementById('showingCount').textContent = visible;
}
document.getElementById('docSearch').addEventListener('input', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('typeFilter').addEventListener('change', applyFilters);
document.getElementById('fulfillFilter').addEventListener('change', applyFilters);
</script>

<?php include '../includes/footer.php'; ?>
