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
// The student picker must mirror the Student Management module, which lists
// every student row regardless of status. Filtering on `status = 'active'`
// hid anyone whose status is the column default ('enrolled'), plus
// probation / at-risk / loa, so the modal came up empty. Only soft-deleted
// rows are excluded so a request can't be filed for a removed student.
$students  = $db->fetchAll(
    "SELECT id, student_number, CONCAT(first_name,' ',last_name) AS name
       FROM students
      WHERE status IS NULL OR status NOT IN ('archived')
      ORDER BY name"
);

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
$page_description = 'Document request queue, workflow actions, and performance metrics';
$body_page = 'documents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';
$extra_css = ['documents.css'];
$use_chart = true;
?>
﻿

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<style>
/* Document requests — registrar-blue system, matching the Queue console. */
body[data-page="documents"]{background:#f5f7fb;color:#0f172a}
body[data-page="documents"] .dashboard-main{padding:24px clamp(18px,2.5vw,38px) 48px;background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%);min-height:auto}

/* ── Header ───────────────────────────────────────────── */
.dq-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08)}
.dq-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.dq-head h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}
.dq-head p{max-width:640px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}
.dq-head .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.dq-head .btn{min-height:36px;font-size:12px}

/* ── Metric strip ─────────────────────────────────────── */
.dq-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin:0 0 16px;background:#fff;border:1px solid #dbeafe;border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden}
.dq-metric{position:relative;padding:18px 20px;border-right:1px solid #e2e8f0}
.dq-metric:last-child{border-right:0}
.dq-metric::after{content:"";position:absolute;left:20px;right:20px;bottom:0;height:3px;background:#dbeafe}
.dq-metric.is-tat::after{background:#1d4ed8}
.dq-metric.is-rev::after{background:#16a34a}
.dq-metric.is-reg::after{background:#64748b}
.dq-metric.is-exp::after{background:#d97706}
.dq-metric .dq-label{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#64748b}
.dq-metric .dq-value{margin-top:6px;font-size:28px;font-weight:800;line-height:1.1;color:#0f172a;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.dq-metric .dq-value .dq-unit{font-size:15px;font-weight:700;color:#64748b}
.dq-metric.is-rev .dq-value{color:#15803d}

/* ── Panels ───────────────────────────────────────────── */
.dq-split{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:0 0 16px}
.dq-split>.panel{margin-bottom:0}
body[data-page="documents"] .panel{border:1px solid #dbeafe;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.045);margin-bottom:16px;overflow:hidden}
body[data-page="documents"] .panel-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:13px 18px;background:#f8faff;border-bottom:1px solid #e5e7eb}
body[data-page="documents"] .panel-title{display:flex;align-items:center;gap:8px;padding:0;font-size:13px;font-weight:700;letter-spacing:-.01em;color:#1e293b}
body[data-page="documents"] .panel-title i{color:#2563eb;font-size:12px}
body[data-page="documents"] .table-responsive{margin:0}
body[data-page="documents"] .table{margin:0;width:100%;border-collapse:collapse}
body[data-page="documents"] .table thead th{padding:11px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;text-align:left;white-space:nowrap}
body[data-page="documents"] .table tbody td{padding:11px 14px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;vertical-align:middle}
body[data-page="documents"] .table tbody tr[data-doc]{cursor:pointer}
body[data-page="documents"] .table tbody tr[data-doc]:hover{background:#eff6ff}
body[data-page="documents"] .student-avatar{width:32px;height:32px;font-size:12px}
body[data-page="documents"] .empty-state td{padding:0}
body[data-page="documents"] .dq-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;min-height:220px;padding:34px 20px;text-align:center}
body[data-page="documents"] .dq-empty i{font-size:34px;color:#cbd5e1}
body[data-page="documents"] .dq-empty p{margin:0;font-size:14px;font-weight:600;color:#64748b}
body[data-page="documents"] .dq-empty span{font-size:12.5px;color:#94a3b8}

/* Row actions stay quiet until the row is hovered. */
.row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.row-actions .btn{opacity:.55;transition:opacity .15s ease}
.row-actions .btn:focus-visible{opacity:1}

/* ── Filter toolbar ───────────────────────────────────── */
.dq-filters{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:13px 18px;border-bottom:1px solid #e5e7eb;background:#fff}
.dq-search{position:relative;flex:1 1 280px;min-width:200px}
.dq-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#64748b;font-size:13px;pointer-events:none}
.dq-search input{width:100%;height:38px;box-sizing:border-box;padding:0 12px 0 36px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif}
.dq-search input:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.dq-filters select{height:38px;padding:0 10px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif;cursor:pointer}
.dq-filters select:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.dq-count{font-size:12px;color:#64748b;white-space:nowrap}
.dq-count strong{color:#0f172a}

/* ── Revenue range picker ─────────────────────────────── */
.dq-range{display:flex;align-items:center;gap:7px}
.dq-range input[type="date"]{height:34px;width:150px;padding:0 9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;font:13px Inter,sans-serif;cursor:pointer}
.dq-range input[type="date"]:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.dq-range .dq-sep{color:#94a3b8;font-size:12px}
.dq-range .btn{min-height:34px;width:34px;padding:0;display:inline-flex;align-items:center;justify-content:center}

/* ── Expanded detail row ──────────────────────────────── */
.doc-detail{background:#f8faff}
.doc-detail .dq-fields{display:flex;flex-wrap:wrap;gap:8px 22px;font-size:12.5px;color:#475569}
.doc-detail .dq-fields strong{color:#0f172a;font-weight:700}
.doc-detail .dq-log{margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px}
.doc-detail .dq-log-head{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#64748b;margin-bottom:8px}
.doc-detail .dq-log-row{display:flex;gap:10px;margin-bottom:5px;font-size:12px}
.doc-detail .dq-log-row time{color:#94a3b8;white-space:nowrap;font-variant-numeric:tabular-nums}

/* ── New Request modal ───────────────────────────────── */
/* registrar.css makes .modal-content the scroll box (max-height:90vh,
   overflow-y:auto). Here the dialog becomes a fixed-height flex column
   instead: header and footer pinned, only the body scrolls. */
#newRequestModal{align-items:flex-start}
#newRequestModal .modal-content{display:flex;flex-direction:column;max-width:600px;max-height:calc(100vh - 40px);padding:0;border-radius:18px;border:1px solid #dbeafe;box-shadow:0 24px 60px rgba(15,23,42,.22);overflow:hidden}
#newRequestModal .modal-header{flex:0 0 auto;display:flex;align-items:center;gap:13px;padding:18px 22px;background:linear-gradient(120deg,#eff6ff,#fff 70%);border-bottom:1px solid #dbeafe;margin-bottom:0}
#newRequestModal .modal-header .nq-mark{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border-radius:11px;background:linear-gradient(140deg,#2563eb,#1d4ed8);color:#fff;font-size:15px;box-shadow:0 6px 16px rgba(37,99,235,.28)}
#newRequestModal .modal-header h3{margin:0;font-size:17px;font-weight:700;letter-spacing:-.02em;color:#172554}
#newRequestModal .modal-header p{margin:2px 0 0;font-size:12px;color:#64748b}
#newRequestModal .modal-header .modal-close{margin-left:auto;background:transparent;border:1px solid #dbeafe;color:#64748b;width:32px;height:32px;border-radius:9px;display:grid;place-items:center;cursor:pointer;transition:background .15s ease,color .15s ease}
#newRequestModal .modal-header .modal-close:hover{background:#e0e7ff;color:#1d4ed8}
#newRequestModal .modal-body{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:20px 22px;margin-bottom:0;background:#fff}

/* Section heads, so a long form stays scannable. */
.nq-section{margin-bottom:16px}
.nq-section-head{display:flex;align-items:center;gap:8px;margin-bottom:9px}
.nq-section-head span{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#1d4ed8}
.nq-section-head::after{content:"";flex:1;height:1px;background:#e2e8f0}
#newRequestModal .form-group{margin-bottom:0}
#newRequestModal .form-group>label{display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#475569}
#newRequestModal .form-group>label .nq-req{color:#dc2626;margin-left:2px}
#newRequestModal .form-control{width:100%;height:40px;box-sizing:border-box;padding:0 12px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif;transition:border-color .15s ease,box-shadow .15s ease}
#newRequestModal select.form-control{cursor:pointer;padding-right:30px}
#newRequestModal .form-control:focus{outline:0;border-color:#2563eb;background:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
#newRequestModal .form-control::placeholder{color:#94a3b8}
#newRequestModal .form-control[readonly]{background:#f1f5f9;color:#64748b;cursor:default}
#newRequestModal .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
#newRequestModal .req-hint{margin-top:8px}

/* The fee ticket — the one loud element. */
.nq-fee{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 4px;padding:16px 18px 15px;border:1px solid #bfdbfe;border-radius:14px;background:linear-gradient(140deg,#eff6ff,#fff 72%)}
.nq-fee::before{content:"";position:absolute;left:18px;right:18px;top:11px;height:2px;background:repeating-linear-gradient(90deg,#c7d7fe 0 7px,transparent 7px 14px)}
.nq-fee-cap{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#1d4ed8}
.nq-fee-note{margin-top:5px;font-size:12px;color:#64748b;max-width:34ch}
.nq-fee-amount{font:800 30px/1 JetBrains Mono,ui-monospace,monospace;letter-spacing:-.03em;color:#1d4ed8;font-variant-numeric:tabular-nums;white-space:nowrap}

#newRequestModal .modal-footer{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:14px 22px;background:#f8faff;border-top:1px solid #e2e8f0}
#newRequestModal .modal-footer .btn{min-height:40px;padding:0 20px;font-size:13px}
#newRequestModal .modal-footer .btn-primary{display:inline-flex;align-items:center;gap:7px}

@media(max-width:560px){#newRequestModal .modal-content{max-height:calc(100vh - 24px)}#newRequestModal .modal-body{padding:16px}#newRequestModal .form-row{grid-template-columns:1fr}#newRequestModal .modal-header{padding:15px 16px}#newRequestModal .modal-footer{padding:12px 16px}#newRequestModal .modal-footer .btn{flex:1 1 auto;justify-content:center}.nq-fee{flex-direction:column;align-items:flex-start;gap:8px}.nq-fee-amount{font-size:26px}}
@media(prefers-reduced-motion:reduce){#newRequestModal .modal-header .modal-close{transition:none}}

/* ── Responsive ───────────────────────────────────────── */
@media(max-width:1000px){.dq-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.dq-metric:nth-child(2){border-right:0}.dq-metric:nth-child(-n+2){border-bottom:1px solid #e2e8f0}.dq-metric::after{display:none}.dq-split{grid-template-columns:1fr}}
@media(max-width:900px){.dq-head{flex-direction:column;align-items:flex-start}.dq-head .header-actions{width:100%;justify-content:flex-start}}
@media(max-width:600px){.dq-head{padding:21px 18px}.dq-head h1{font-size:25px}.dq-head .header-actions{flex-direction:column;align-items:stretch}.dq-head .btn{justify-content:center}.dq-strip{grid-template-columns:1fr}.dq-metric{border-right:0;border-bottom:1px solid #e2e8f0}.dq-metric:last-child{border-bottom:0}.dq-search,.dq-filters select{width:100%}.dq-range{width:100%}.dq-range input[type="date"]{flex:1 1 0;width:auto}}
@media(prefers-reduced-motion:reduce){.row-actions .btn{transition:none}}
</style>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="dq-head">
    <div>
        <div class="dq-kicker"><i class="fa-solid fa-file-circle-check"></i> Registrar desk</div>
        <h1>Document Requests</h1>
        <p>Process requests, track release status, and see how the desk is performing.</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="openNewRequest()"><i class="fas fa-plus"></i> New Request</button>
    </div>
</header>

<!-- ── Stats Cards ──────────────────────────────────────────── -->
<div class="dq-strip">
    <div class="dq-metric is-tat">
        <div class="dq-label">Avg turnaround</div>
        <div class="dq-value"><?= $tatHours !== null ? htmlspecialchars($tatHours) . '<span class="dq-unit"> hrs</span>' : '&mdash;' ?></div>
    </div>
    <div class="dq-metric is-rev">
        <div class="dq-label">Revenue in range</div>
        <div class="dq-value">&#8369;<?= number_format($revenueTotal, 2) ?></div>
    </div>
    <div class="dq-metric is-reg">
        <div class="dq-label">Regular requests</div>
        <div class="dq-value"><?= (int) $regularCount ?></div>
    </div>
    <div class="dq-metric is-exp">
        <div class="dq-label">Express requests</div>
        <div class="dq-value"><?= (int) $expressCount ?></div>
    </div>
</div>

<!-- ── Charts ───────────────────────────────────────────────── -->
<div class="dq-split">
    <div class="panel">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fa-solid fa-chart-bar"></i> Revenue by Document Type</div>
            <div class="dq-range">
                <input type="date" id="revFrom" value="<?= htmlspecialchars($from) ?>" aria-label="Revenue from">
                <span class="dq-sep">&ndash;</span>
                <input type="date" id="revTo" value="<?= htmlspecialchars($to) ?>" aria-label="Revenue to">
                <button class="btn btn-sm btn-secondary" onclick="applyRevFilter()" title="Apply date range"><i class="fa-solid fa-filter"></i></button>
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
            <div class="panel-title"><i class="fa-solid fa-chart-line"></i> Daily Queue Volume, Last 7 Days</div>
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
<div class="panel">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fa-solid fa-list"></i> Request Queue</div>
    </div>
    <div class="dq-filters">
        <div class="dq-search">
            <i class="fas fa-search"></i>
            <input type="text" id="docSearch" placeholder="Search student, document, ID&hellip;" aria-label="Search requests">
        </div>
        <select id="typeFilter" onchange="applyFilters()" aria-label="Filter by request type">
            <option value="">All Types</option>
            <option value="Regular">Regular</option>
            <option value="Express">Express</option>
        </select>
        <select id="statusFilter" onchange="applyFilters()" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="Pending_Clearance">Pending Clearance</option>
            <option value="Awaiting_Payment">Awaiting Payment</option>
            <option value="Processing">Processing</option>
            <option value="Ready">Ready for Release</option>
            <option value="Shipped">Shipped</option>
            <option value="Claimed">Claimed</option>
            <option value="Rejected">Rejected</option>
        </select>
        <span class="dq-count">Showing <strong id="showingCount"><?= count($requests) ?></strong> of <?= count($requests) ?></span>
    </div>

    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr><th>Student</th><th>Request</th><th>Fee</th><th>Type</th><th>Fulfillment</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" class="empty-state"><div class="dq-empty"><i class="fas fa-file-lines"></i><p>No document requests found</p><span>Requests appear here once a student or the registrar submits one.</span></div></td></tr>
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
                        <div class="doc-detail" style="padding:18px 22px;border-bottom:1px solid #e2e8f0;">
                            <?php if ($st === 'Rejected'): ?>
                                <div class="block-banner" style="margin-bottom:12px;">
                                    <div class="banner-icon"><i class="fa-solid fa-xmark"></i></div>
                                    <div><div class="banner-title">Request rejected</div><div class="banner-text"><?= htmlspecialchars($r['rejection_reason'] ?? 'No reason provided.') ?></div></div>
                                </div>
                            <?php endif; ?>
                            <div class="dq-fields">
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
                                <div class="dq-log">
                                    <div class="dq-log-head">Activity Log</div>
                                    <?php foreach ($reqEvents as $ev): ?>
                                        <div class="dq-log-row">
                                            <time datetime="<?= htmlspecialchars(date('c', strtotime($ev['created_at']))) ?>"><?= date('M d, h:i A', strtotime($ev['created_at'])) ?></time>
                                            <span><?= htmlspecialchars($ev['note'] ?? $ev['status']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            <tr id="docNoMatch" style="display:none;"><td colspan="7" class="empty-state"><div class="dq-empty"><i class="fas fa-magnifying-glass"></i><p>No requests match your search</p><span>Try a different name, ID number, document, or clear the filters.</span></div></td></tr>
        </tbody>
    </table>
    </div>
</div>

</div>
</main>

<!-- ═══ NEW REQUEST MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="newRequestModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="nq-mark"><i class="fa-solid fa-file-circle-plus"></i></div>
            <div>
                <h3>New Document Request</h3>
                <p>Raise a request on behalf of a student.</p>
            </div>
            <button class="modal-close" onclick="closeModal('newRequestModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="newRequestForm" onsubmit="submitNewRequest(event)">

                <div class="nq-section">
                    <div class="nq-section-head"><span>Student</span></div>
                    <div class="form-group">
                        <select name="student_id" id="nrStudent" class="form-control" data-searchable required aria-label="Student">
                            <option value="">Search or select a student&hellip;</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['student_number']) ?> &mdash; <?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="nq-section">
                    <div class="nq-section-head"><span>Document</span></div>
                    <div class="form-group">
                        <select name="catalog_id" id="nrCatalog" class="form-control" required onchange="updateNrFee()" aria-label="Document type">
                            <option value="">Select a document&hellip;</option>
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
                        <div id="nrHint" class="req-hint"></div>
                    </div>
                </div>

                <div class="nq-section">
                    <div class="nq-section-head"><span>Handling</span></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nrPriority">Priority</label>
                            <select id="nrPriority" class="form-control" onchange="updateNrFee()">
                                <option value="Regular">Regular</option>
                                <option value="Express">Express (+&#8369;100)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Fulfillment</label>
                            <input type="text" class="form-control" value="Pickup at Registrar" readonly>
                        </div>
                    </div>
                </div>

                <div class="nq-section">
                    <div class="nq-section-head"><span>Details</span></div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="nrPurpose">Purpose<span class="nq-req">*</span></label>
                        <input type="text" name="purpose" id="nrPurpose" class="form-control" placeholder="Employment requirement" required>
                    </div>
                    <div class="form-group">
                        <label for="nrRecipient">Recipient</label>
                        <input type="text" name="recipient" id="nrRecipient" class="form-control" placeholder="UP Manila Registrar">
                    </div>
                </div>

                <div class="nq-fee">
                    <div>
                        <div class="nq-fee-cap">Estimated fee</div>
                        <div class="nq-fee-note">Updates as you change the document or priority.</div>
                    </div>
                    <div class="nq-fee-amount" id="nrFeePreview">&#8369;0.00</div>
                </div>

                <div class="modal-footer">
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
    // Say so plainly when nothing matched, instead of leaving a blank table.
    const noMatch = document.getElementById('docNoMatch');
    if (noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
    document.getElementById('showingCount').textContent = visible;
}
document.getElementById('docSearch').addEventListener('input', applyFilters);
applyFilters();

// ── Revenue Date Filter ────────────────────────────────────────
function applyRevFilter() {
    const from = document.getElementById('revFrom').value;
    const to = document.getElementById('revTo').value;
    window.location.href = 'documents.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
}
</script>

<?php include '../includes/footer.php'; ?>
