<?php
// ============================================================
//  AI/INSIGHTS.PHP
//  Intelligent Analytics and Reports Dashboard
//  Styled to match registrar system design language
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();
$currentMonth = (int) date('n');
$currentYear  = (int) date('Y');

// ─── STAT CARDS ─────────────────────────────────────────────
$totalStudents  = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
$activeStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status IN ('active','enrolled')");
$totalCards     = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
$activeCards    = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active'");
$expiredCards   = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'expired'");
$totalDocuments = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests");
$pendingDocs    = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'");
$queueToday     = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = CURDATE()");
$queueTotal     = (int) $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets");

// ─── CHART DATASETS ─────────────────────────────────────────
$statusData     = $db->fetchAll("SELECT status, COUNT(*) AS count FROM students GROUP BY status ORDER BY count DESC");
$programData    = $db->fetchAll("SELECT course, COUNT(*) AS count FROM students WHERE course IS NOT NULL AND course != '' GROUP BY course ORDER BY count DESC LIMIT 8");
$docData        = $db->fetchAll("SELECT document_type, COUNT(*) AS count FROM document_requests WHERE document_type IS NOT NULL AND document_type != '' GROUP BY document_type ORDER BY count DESC");
$rfidStatusData = $db->fetchAll("SELECT status, COUNT(*) AS count FROM rfid_cards GROUP BY status ORDER BY count DESC");

$docLabels = [
    'form137'     => 'Form 137',
    'form138'     => 'Form 138',
    'good_moral'  => 'Good Moral',
    'transcript'  => 'Transcript',
    'certificate' => 'Certificate',
    'clearance'   => 'Clearance',
    'withdrawal'  => 'Withdrawal Form',
    'cor'         => 'COR',
    'cog'         => 'COG',
    'tor'         => 'TOR',
    'diploma'     => 'Diploma',
];

$statusMeta = [
    'active'      => ['label' => 'Active',      'color' => '#16a34a'],
    'enrolled'    => ['label' => 'Enrolled',    'color' => '#2563eb'],
    'probation'   => ['label' => 'Probation',   'color' => '#b45309'],
    'at-risk'     => ['label' => 'At Risk',     'color' => '#dc2626'],
    'graduated'   => ['label' => 'Graduated',   'color' => '#7c3aed'],
    'alumni'      => ['label' => 'Alumni',      'color' => '#0891b2'],
    'transferred' => ['label' => 'Transferred', 'color' => '#db2777'],
    'dropped'     => ['label' => 'Dropped',     'color' => '#64748b'],
    'loa'         => ['label' => 'LOA',         'color' => '#ea580c'],
];

$rfidMeta = [
    'active'   => ['label' => 'Active',   'color' => '#16a34a'],
    'inactive' => ['label' => 'Inactive', 'color' => '#94a3b8'],
    'lost'     => ['label' => 'Lost',     'color' => '#dc2626'],
    'expired'  => ['label' => 'Expired',  'color' => '#b45309'],
];

$statusLabels = []; $statusValues = []; $statusColors = [];
foreach ($statusData as $row) {
    $key = $row['status'];
    $statusLabels[] = $statusMeta[$key]['label'] ?? ucfirst($key);
    $statusValues[] = (int) $row['count'];
    $statusColors[] = $statusMeta[$key]['color'] ?? '#64748b';
}

$programLabels = array_column($programData, 'course');
$programValues = array_map('intval', array_column($programData, 'count'));
$programColors = ['#2563eb', '#16a34a', '#7c3aed', '#b45309', '#0891b2', '#db2777', '#ea580c', '#64748b'];

$docLabelsArr = []; $docValues = [];
foreach ($docData as $row) {
    $docLabelsArr[] = $docLabels[$row['document_type']] ?? ucfirst($row['document_type']);
    $docValues[]    = (int) $row['count'];
}
$docColors = ['#2563eb', '#16a34a', '#b45309', '#7c3aed', '#0891b2', '#db2777', '#ea580c', '#64748b'];

$rfidLabels = []; $rfidValues = []; $rfidColors = [];
foreach ($rfidStatusData as $row) {
    $key = $row['status'];
    $rfidLabels[] = $rfidMeta[$key]['label'] ?? ucfirst($key);
    $rfidValues[] = (int) $row['count'];
    $rfidColors[] = $rfidMeta[$key]['color'] ?? '#64748b';
}

$chartData = [
    'status'  => ['labels' => $statusLabels,  'values' => $statusValues,  'colors' => $statusColors],
    'program' => ['labels' => $programLabels, 'values' => $programValues, 'colors' => $programColors],
    'doc'     => ['labels' => $docLabelsArr,  'values' => $docValues,     'colors' => $docColors],
    'rfid'    => ['labels' => $rfidLabels,    'values' => $rfidValues,    'colors' => $rfidColors],
];

$page_title = 'Intelligent Analytics';
$APP_ROOT = '../';
$ACTIVE_NAV = 'insights';

include '../includes/header.php';
include '../includes/sidebar.php';
?><style>
:root { --sidebar-width:260px; --sidebar-collapsed-width:72px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; transition:margin-left .3s,width .3s,max-width .3s; }
.sidebar.collapsed~.dashboard-main,body.sidebar-collapsed .dashboard-main { margin-left:var(--sidebar-collapsed-width); width:calc(100% - var(--sidebar-collapsed-width)); max-width:calc(100% - var(--sidebar-collapsed-width)); }

/* Page header */
.page-header { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:24px; }
.page-header h1 { font-size:22px; font-weight:700; color:#0f172a; margin:0 0 4px; letter-spacing:-0.3px; }
.page-header .page-subtitle { font-size:13px; color:#64748b; margin:0; }
.header-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

/* Filter controls */
.filter-group { display:flex; gap:8px; align-items:center; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:6px 10px; box-shadow:0 1px 3px rgba(15,23,42,0.04); }
.filter-group label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#64748b; display:flex; align-items:center; gap:6px; }
.filter-group select { border:1.5px solid #e2e8f0; border-radius:8px; padding:6px 10px; font-size:13px; font-family:inherit; color:#1e293b; background:white; outline:none; cursor:pointer; }
.filter-group select:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.10); }

/* AI report */
.ai-report-card .card-body { height:auto; }
.ai-report-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.ai-report-output { min-height:200px; font-size:14px; line-height:1.7; color:#1e293b; }
.report-section { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px 12px; margin-bottom:12px; box-shadow:0 1px 2px rgba(15,23,42,0.03); }
.report-section-head { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
.report-section-num { width:24px; height:24px; flex:0 0 24px; border-radius:7px; background:#2563eb; color:#fff; font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.report-section-2 .report-section-num { background:#0d9488; } .report-section-3 .report-section-num { background:#7c3aed; } .report-section-4 .report-section-num { background:#b45309; } .report-section-5 .report-section-num { background:#dc2626; }
.report-section h2 { font-size:13px; font-weight:700; color:#0f172a; margin:0; text-transform:uppercase; letter-spacing:.3px; } .report-section ul { margin:4px 0 2px; padding-left:18px; } .report-section li { margin-bottom:6px; } .report-section li::marker { color:#94a3b8; } .report-section p { margin:6px 0 2px; } .report-section strong { color:#0f172a; } .ai-report-disclaimer { font-size:11px; color:#94a3b8; text-align:center; padding:10px 12px 0; border-top:1px dashed #e2e8f0; margin-top:14px; }
.ai-report-empty { color:#94a3b8; text-align:center; padding:48px 20px; }
.ai-report-empty i { font-size:34px; margin-bottom:10px; color:#cbd5e1; }
.ai-report-loading { display:none; text-align:center; padding:48px 20px; }
.ai-report-loading .spinner { width:38px; height:38px; border:3px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:spin .8s linear infinite; margin:0 auto 14px; }
@keyframes spin { to { transform:rotate(360deg); } }
.ai-report-error { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:12px 16px; font-size:13px; margin-bottom:12px; display:none; }

@media (max-width:1100px){ .chart-grid-3{grid-template-columns:1fr 1fr} }
@media (max-width:768px){ .dashboard-main{padding:16px} .chart-grid,.chart-grid-3{grid-template-columns:1fr} .page-header{flex-direction:column;align-items:flex-start} }
</style>

<main class="dashboard-main">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-chart-line" style="color:#2563eb;margin-right:8px;"></i>Intelligent Analytics</h1>
            <p class="page-subtitle">AI-powered registrar insights, trends, and operational patterns</p>
        </div>
        <div class="header-actions">
            <div class="filter-group">
                <label for="reportMonth"><i class="fas fa-calendar"></i> Period</label>
                <select id="reportMonth">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $currentMonth ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
                <select id="reportYear">
                    <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="button" class="btn btn-primary" id="generateBtn">
                <i class="fas fa-wand-magic-sparkles"></i> Generate Report
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $activeStudents ?>/<?= $totalStudents ?></span>
            </div>
            <div class="stat-value"><?= number_format($totalStudents) ?></div>
            <div class="stat-label">Total Students</div>
            <div class="stat-footer"><span class="dot blue"></span> <?= $totalStudents > 0 ? round($activeStudents / $totalStudents * 100) : 0 ?>% active/enrolled</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon green"><i class="fas fa-id-card"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $activeCards ?>/<?= $totalCards ?></span>
            </div>
            <div class="stat-value"><?= number_format($totalCards) ?></div>
            <div class="stat-label">RFID Cards</div>
            <div class="stat-footer"><span class="dot green"></span> <?= $expiredCards ?> expired</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon yellow"><i class="fas fa-file-lines"></i></div>
                <span class="stat-trend down"><i class="fas fa-clock"></i> <?= $pendingDocs ?> pending</span>
            </div>
            <div class="stat-value"><?= number_format($totalDocuments) ?></div>
            <div class="stat-label">Document Requests</div>
            <div class="stat-footer"><span class="dot yellow"></span> Total transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon purple"><i class="fas fa-ticket"></i></div>
                <span class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $queueTotal ?> all-time</span>
            </div>
            <div class="stat-value"><?= number_format($queueToday) ?></div>
            <div class="stat-label">Queue Today</div>
            <div class="stat-footer"><span class="dot purple"></span> Tickets issued</div>
        </div>
    </div>    <!-- Charts Row 1 -->
    <div class="chart-grid-3 dashboard-section">
        <div class="chart-card">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-chart-pie"></i> Student Status</div>
                    <div class="card-subtitle">Population distribution by status</div>
                </div>
                <span class="card-badge">Live</span>
            </div>
            <div class="card-body"><canvas id="statusChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-chart-column"></i> Program Distribution</div>
                    <div class="card-subtitle">Top programs by enrollment</div>
                </div>
                <span class="card-badge">Top 8</span>
            </div>
            <div class="card-body"><canvas id="programChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-id-card"></i> RFID Card Status</div>
                    <div class="card-subtitle">Card lifecycle overview</div>
                </div>
                <span class="card-badge">Live</span>
            </div>
            <div class="card-body"><canvas id="rfidChart"></canvas></div>
        </div>
    </div>

    <!-- Document Chart -->
    <div class="chart-card dashboard-section">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fas fa-file-lines"></i> Document Requests by Type</div>
                <div class="card-subtitle">Transaction volume per document</div>
            </div>
            <span class="card-badge">All-time</span>
        </div>
        <div class="card-body"><canvas id="docChart"></canvas></div>
    </div>

    <!-- AI Insight Report -->
    <div class="chart-card ai-report-card dashboard-section">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fas fa-robot" style="color:#7c3aed;"></i> AI Insight Report</div>
                <div class="card-subtitle">Generated analysis for the selected period</div>
            </div>
            <div class="ai-report-actions">
                <button type="button" class="btn btn-secondary" id="printBtn" style="display:none;"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="btn btn-secondary" id="exportBtn" style="display:none;"><i class="fas fa-download"></i> Export</button>
            </div>
        </div>
        <div class="card-body">
            <div class="ai-report-error" id="reportError"></div>
            <div class="ai-report-empty" id="reportEmpty">
                <i class="fas fa-chart-simple"></i>
                <p style="margin:0 0 4px;font-weight:600;color:#64748b;">No report generated yet</p>
                <span>Select a period and click Generate Report to see AI insights.</span>
            </div>
            <div class="ai-report-loading" id="reportLoading">
                <div class="spinner"></div>
                <p style="margin:0;font-size:13px;color:#64748b;">Analyzing registrar data…</p>
            </div>
            <div class="ai-report-output" id="reportOutput" style="display:none;"></div>
            <div class="ai-report-disclaimer" id="reportDisclaimer" style="display:none;">AI-generated content is for administrative reference. Generated by <strong>Registrar Information System</strong>.</div>
        </div>
    </div>

    <script type="application/json" id="insightsData"><?= json_encode($chartData) ?></script>
</main>

<?php
$use_chart = true;
$page_scripts = ['insights.js'];
include '../includes/footer.php';