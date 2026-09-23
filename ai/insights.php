<?php
// ============================================================
//  AI/INSIGHTS.PHP
//  Intelligent Analytics and Reports
//
//  The core AI function of the registrar system:
//    structured dashboard → historical comparison → AI interpretation
//    → printable executive report.
//
//  Layout reuses the dashboard's own classes (css/dashboard.css is
//  loaded globally by includes/header.php) so the two pages stay
//  visually identical: .header/.title, .stats-grid/.stat-card,
//  .chart-grid/.chart-card.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/analytics.php';

// Registrar analytics is staff work — student and nurse accounts are
// bounced the same way requireRole() does it, just with the extra roles
// this page allows (admin, registrar, staff).
if (!in_array(getCurrentUserRole(), aiInsightRoles(), true)) {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$period      = aiInsightPeriodFromRequest();
$build       = aiInsightBuild($period);
$cards       = $build['cards'];
$charts      = $build['charts'];
$currentYear = (int) date('Y');

// Trend badge glyph per direction.
$trendIcon = ['up' => 'fa-arrow-up', 'down' => 'fa-arrow-down', 'flat' => 'fa-minus'];

$page_title = 'Intelligent Analytics and Reports';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'insights';
// Footer wiring: Chart.js CDN + this page's frontend logic.
$use_chart = true;
$page_scripts = ['insights.js'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
/* ── Reporting Period control (same pill language as the dashboard header) ── */
.period-control { display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:6px 10px; box-shadow:0 1px 3px rgba(15,23,42,0.04); }
.period-control label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#64748b; display:flex; align-items:center; gap:6px; white-space:nowrap; margin:0; }
.period-control select { border:1.5px solid #e2e8f0; border-radius:8px; padding:6px 10px; font-size:13px; font-family:inherit; color:#1e293b; background:#fff; outline:none; cursor:pointer; }
.period-control select:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.10); }
.period-control.is-busy { opacity:.6; pointer-events:none; }

/* ── Section divider ── */
.ai-section-title { display:flex; align-items:center; gap:14px; margin:2px 0 18px; }
.ai-section-title span { font-size:12px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:#475569; white-space:nowrap; }
.ai-section-title::before, .ai-section-title::after { content:''; height:1px; background:#e2e8f0; flex:1; }

/* ── Chart grid variants ── */
.chart-grid.pie-first { grid-template-columns:1fr 2fr; }
.stat-trend.flat { color:#64748b; background:#f1f5f9; }

/* ── RFID card: doughnut + activity trend stacked ── */
.ai-rfid-body { height:auto !important; display:flex; flex-direction:column; gap:16px; }
.ai-mini-chart { position:relative; height:150px; }
.ai-mini-label { font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; }
.ai-empty-note { font-size:12px; color:#94a3b8; text-align:center; padding:16px 8px; }

/* ── AI ANALYSIS REPORT ── */
.ai-report-card .card-body { height:auto; }
.ai-report-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.ai-report-empty { text-align:center; padding:44px 20px; color:#94a3b8; }
.ai-report-empty i { font-size:34px; color:#cbd5e1; display:block; margin-bottom:12px; }
.ai-report-empty p { margin:0 0 4px; font-weight:600; color:#64748b; font-size:14px; }
.ai-report-empty span { font-size:12px; }
.ai-report-loading { display:none; text-align:center; padding:44px 20px; }
.ai-report-loading .spinner { width:38px; height:38px; border:3px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:ai-spin .8s linear infinite; margin:0 auto 12px; }
@keyframes ai-spin { to { transform:rotate(360deg); } }
.ai-report-error { display:none; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:12px; padding:12px 16px; font-size:13px; margin-bottom:14px; }
.ai-report-meta { display:none; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; font-size:12px; color:#64748b; }
.ai-source-badge { font-size:11px; font-weight:600; padding:4px 10px; border-radius:7px; background:#ecfdf5; color:#047857; }
.ai-source-badge.is-fallback { background:#fef3c7; color:#b45309; }
.ai-report-output { font-size:14px; line-height:1.7; color:#1e293b; }
.ai-report-output .ai-report-title { font-size:15px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:#0f172a; margin:0 0 14px; display:flex; align-items:center; gap:9px; }
.ai-report-output .ai-report-title i { color:#7c3aed; }
.report-section { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:14px 18px 12px; margin-bottom:12px; }
.report-section-head { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
.report-section-num { width:24px; height:24px; flex:0 0 24px; border-radius:7px; background:#2563eb; color:#fff; font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.report-section-2 .report-section-num { background:#0d9488; }
.report-section-3 .report-section-num { background:#7c3aed; }
.report-section h2 { font-size:13px; font-weight:700; color:#0f172a; margin:0; text-transform:uppercase; letter-spacing:.3px; }
.report-section ul { margin:4px 0 2px; padding-left:18px; }
.report-section li { margin-bottom:6px; }
.report-section p { margin:6px 0 2px; }
.report-section strong { color:#0f172a; }
.ai-report-footer { display:none; font-size:11px; color:#94a3b8; text-align:center; padding:12px 12px 0; border-top:1px dashed #e2e8f0; margin-top:16px; }
.ai-report-footer strong { color:#64748b; }

/* ── Export dropdown (mirrors the masterlist export menu) ── */
.ai-export-wrap { position:relative; }
.ai-export-menu { display:none; position:absolute; top:100%; right:0; z-index:50; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.1); min-width:172px; padding:4px; margin-top:4px; }
.ai-export-menu a { display:block; padding:8px 12px; font-size:12px; font-weight:600; color:#1e293b; text-decoration:none; border-radius:6px; }
.ai-export-menu a:hover { background:#f1f5f9; }
.ai-export-menu.is-open { display:block; }

@media (max-width:1100px) { .chart-grid.pie-first { grid-template-columns:1fr; } }
</style>

<main class="dashboard-main">
    <div class="dashboard-container">

        <!-- ── Page header ─────────────────────────────────────── -->
        <header class="header">
            <div class="title">
                <h1><i class="fas fa-chart-line" style="color:#2563eb;margin-right:8px;"></i>Intelligent Analytics and Reports</h1>
                <p>AI-assisted interpretation of registrar data for the selected reporting period</p>
            </div>
            <div class="header-actions">
                <div class="period-control" id="periodControl">
                    <label for="reportMonth"><i class="fas fa-calendar-days"></i> Reporting Period</label>
                    <select id="reportMonth" aria-label="Reporting month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $period['month'] ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select id="reportYear" aria-label="Reporting year">
                        <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $period['year'] ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="generateBtn">
                    <i class="fas fa-wand-magic-sparkles"></i> Generate AI Insight
                </button>
            </div>
        </header>

        <!-- ── KPI cards ───────────────────────────────────────── -->
        <div class="stats-grid dashboard-section" id="statCards">
            <?php foreach ($cards as $card): ?>
                <div class="stat-card" data-card="<?= htmlspecialchars($card['key']) ?>">
                    <div class="stat-header">
                        <div class="stat-icon <?= htmlspecialchars($card['tone']) ?>">
                            <i class="fas <?= htmlspecialchars($card['icon']) ?>"></i>
                        </div>
                        <span class="stat-trend <?= htmlspecialchars($card['badge']['dir']) ?>" title="Compared with <?= htmlspecialchars($period['prev_label']) ?>">
                            <i class="fas <?= $trendIcon[$card['badge']['dir']] ?? 'fa-minus' ?>"></i>
                            <span class="stat-trend-text"><?= htmlspecialchars($card['badge']['text']) ?></span>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format((int) $card['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($card['label']) ?></div>
                    <div class="stat-footer">
                        <span class="dot <?= htmlspecialchars($card['footer']['dot']) ?>"></span>
                        <span class="stat-footer-text"><?= htmlspecialchars($card['footer']['text']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Student Population Overview ─────────────────────── -->
        <div class="ai-section-title"><span>Student Population Overview</span></div>

        <!-- 1 & 2 · Student status + program distribution -->
        <div class="chart-grid pie-first dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-chart-pie"></i> Student Status Distribution</div>
                        <div class="card-subtitle">Active, enrolled, alumni, graduate, dropped, transferred</div>
                    </div>
                    <span class="card-badge" id="statusBadge"><?= htmlspecialchars($period['label']) ?></span>
                </div>
                <div class="card-body"><canvas id="statusChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-th-large"></i> Student Program Distribution</div>
                        <div class="card-subtitle">Student count per program — tile size = share of enrollment</div>
                    </div>
                    <span class="card-badge">Top 8</span>
                </div>
                <div class="card-body"><canvas id="programChart"></canvas></div>
            </div>
        </div>

        <!-- 3 & 4 · Document transactions + RFID -->
        <div class="chart-grid dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-file-lines"></i> Document Transaction Overview</div>
                        <div class="card-subtitle">Requests by document type and workflow stage</div>
                    </div>
                    <span class="card-badge" id="docBadge"><?= htmlspecialchars($period['label']) ?></span>
                </div>
                <div class="card-body"><canvas id="docChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-id-card"></i> RFID Overview</div>
                        <div class="card-subtitle" id="rfidSubtitle">Card lifecycle and activity</div>
                    </div>
                    <span class="card-badge" id="rfidBadge">12 Months</span>
                </div>
                <div class="card-body ai-rfid-body">
                    <div>
                        <div class="ai-mini-label">Cards by status</div>
                        <div class="ai-mini-chart">
                            <canvas id="rfidChart"></canvas>
                            <div class="ai-empty-note" id="rfidEmptyNote" style="display:none;">No RFID cards issued yet.</div>
                        </div>
                    </div>
                    <div>
                        <div class="ai-mini-label" id="rfidTrendLabel">Activity — last 12 months</div>
                        <div class="ai-mini-chart"><canvas id="rfidTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── AI ANALYSIS REPORT ──────────────────────────────── -->
        <div class="chart-card ai-report-card dashboard-section">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fas fa-robot" style="color:#7c3aed;"></i> AI ANALYSIS REPORT</div>
                    <div class="card-subtitle">Generate an AI-assisted analysis of the registrar data.</div>
                </div>
                <div class="ai-report-actions">
                    <button type="button" class="btn btn-secondary" id="printBtn" style="display:none;">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <div class="ai-export-wrap">
                        <button type="button" class="btn btn-secondary" id="exportBtn" style="display:none;">
                            <i class="fas fa-download"></i> Export <i class="fas fa-caret-down" style="margin-left:4px;"></i>
                        </button>
                        <div class="ai-export-menu" id="exportMenu">
                            <a href="#" id="exportPdf"><i class="fas fa-file-pdf"></i> Export PDF</a>
                            <a href="#" id="exportCsv"><i class="fas fa-file-csv"></i> Export CSV</a>
                            <a href="#" id="exportTxt"><i class="fas fa-file-lines"></i> Export TXT</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="ai-report-error" id="reportError"></div>

                <div class="ai-report-meta" id="reportMeta">
                    <span class="ai-source-badge" id="reportSourceBadge">AI-generated</span>
                    <span id="reportMetaText"></span>
                </div>

                <div class="ai-report-empty" id="reportEmpty">
                    <i class="fas fa-chart-simple"></i>
                    <p>No analysis generated yet</p>
                    <span>Choose a reporting period, then select Generate AI Insight.</span>
                </div>

                <div class="ai-report-loading" id="reportLoading">
                    <div class="spinner"></div>
                    <p style="margin:0;font-size:13px;color:#64748b;" id="reportLoadingText">Analysing registrar data…</p>
                </div>

                <div class="ai-report-output" id="reportOutput" style="display:none;"></div>

                <div class="ai-report-footer" id="reportFooter">
                    Generated by: <strong>Registrar Information System</strong><br>
                    AI-generated information is provided for administrative reference.
                </div>
            </div>
        </div>

        <!-- Period + chart payload for js/insights.js (first paint is server-rendered) -->
        <script type="application/json" id="insightsData"><?= json_encode([
            'period'    => [
                'month'      => $period['month'],
                'year'       => $period['year'],
                'label'      => $period['label'],
                'prev_label' => $period['prev_label'],
            ],
            'cards'     => $cards,
            'charts'    => $charts,
            'endpoints' => [
                'data'   => '../api/ai-insights-data.php',
                'report' => '../api/ai-insights-report.php',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

    </div><!-- /.dashboard-container -->
</main>

<?php include '../includes/footer.php'; ?>
