<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$page_title = 'Visit Statistics';
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_stats';

$today = date('Y-m-d');
$thisWeek = date('Y-m-d', strtotime('monday this week'));
$thisMonth = date('Y-m-01');

$stats = [
    'today'     => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) = ?", [$today]),
    'thisWeek'  => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) BETWEEN ? AND ?", [$thisWeek, $today]),
    'thisMonth' => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) BETWEEN ? AND ?", [$thisMonth, $today]),
    'total'     => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits"),
    'pending'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE record_status = 'Pending'"),
];

$reportStartDate = date('Y-m-d', strtotime('-29 days'));
$topReasons = $db->fetchAll("SELECT reason_for_visit, COUNT(*) AS cnt FROM health_visits WHERE reason_for_visit IS NOT NULL AND reason_for_visit != '' AND DATE(COALESCE(date_time, visit_date)) BETWEEN ? AND ? GROUP BY reason_for_visit ORDER BY cnt DESC LIMIT 8", [$reportStartDate, $today]);
$dailyCounts = [];
$dailyRows = $db->fetchAll("SELECT DATE(COALESCE(date_time, visit_date)) AS dt, COUNT(*) AS cnt FROM health_visits WHERE COALESCE(date_time, visit_date) IS NOT NULL AND DATE(COALESCE(date_time, visit_date)) BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND CURDATE() GROUP BY dt");
foreach ($dailyRows as $dailyRow) {
    $dailyCounts[$dailyRow['dt']] = (int) $dailyRow['cnt'];
}
$dailyVisits = [];
for ($offset = 13; $offset >= 0; $offset--) {
    $chartDate = date('Y-m-d', strtotime("-{$offset} days"));
    $dailyVisits[] = ['dt' => $chartDate, 'cnt' => $dailyCounts[$chartDate] ?? 0];
}
$hourly = $db->fetchAll("SELECT HOUR(date_time) AS hr, COUNT(*) AS cnt FROM health_visits WHERE date_time IS NOT NULL AND DATE(date_time) BETWEEN ? AND ? GROUP BY hr ORDER BY hr ASC", [$reportStartDate, $today]);
$recentVisits = $db->fetchAll("SELECT hv.*, s.first_name, s.last_name, s.student_number FROM health_visits hv LEFT JOIN students s ON hv.student_id = s.id ORDER BY COALESCE(hv.date_time, hv.visit_date, hv.created_at) DESC, hv.id DESC LIMIT 15");
$chartData = json_encode(
    ['topReasons' => $topReasons, 'dailyVisits' => $dailyVisits, 'hourly' => $hourly, 'reportDate' => $today],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
.visit-report{max-width:1480px;margin:0 auto;color:#0f172a}.visit-report-header{position:relative;overflow:hidden;margin-bottom:22px;padding:24px 26px;background:#fff;border:1px solid #dbe8e6;border-radius:18px;box-shadow:0 8px 28px rgba(15,118,110,.06)}.visit-report-header::before{content:"";position:absolute;inset:0 auto 0 0;width:5px;background:#0f766e}.visit-report-header::after{content:"";position:absolute;right:-80px;top:-120px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(13,148,136,.12),transparent 68%)}.visit-report-header h1{position:relative;margin:0 0 5px;font-size:26px;line-height:1.2;letter-spacing:-.5px;color:#0f172a}.visit-report-header p{position:relative;margin:0;color:#64748b;font-size:13px}.report-date{position:relative;display:inline-flex;margin-top:15px;padding:7px 11px;border:1px solid #ccfbf1;border-radius:999px;background:#f0fdfa;color:#0f766e;font-size:11px;font-weight:700}
.stat-strip{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden}.stat-metric{position:relative;padding:20px 22px;border-right:1px solid #e2e8f0}.stat-metric:last-child{border-right:0}.stat-metric::after{content:"";position:absolute;left:22px;right:22px;bottom:0;height:3px;background:#ccfbf1}.stat-metric.today::after,.stat-metric.week::after{background:#0f766e}.stat-metric.pending::after{background:#b45309}.stat-metric .stat-label{font-size:11px;font-weight:700;letter-spacing:.07em;color:#64748b;text-transform:uppercase}.stat-metric .stat-value{margin-top:7px;font-size:30px;line-height:1;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums}.stat-metric .stat-context{margin-top:7px;font-size:11px;color:#94a3b8}.stat-metric.pending .stat-value{color:#b45309}
.report-section{margin-bottom:18px}.report-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.85fr);gap:18px;margin-bottom:18px}.report-panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 6px 22px rgba(15,23,42,.04);overflow:hidden}.panel-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px 15px;border-bottom:1px solid #e2e8f0}.panel-heading h2{margin:0 0 3px;font-size:14px;font-weight:750;color:#0f172a}.panel-heading p{margin:0;font-size:11px;color:#94a3b8}.panel-body{padding:18px 20px}.chart-note{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-size:10px;font-weight:700;color:#0f766e}.chart-note::before{content:"";width:7px;height:7px;border-radius:2px;background:#0f766e}
.bar-chart{position:relative;display:grid;grid-template-columns:repeat(14,minmax(24px,1fr));align-items:end;gap:7px;height:238px;padding:12px 4px 26px;border-bottom:1px solid #cbd5e1;background:repeating-linear-gradient(to top,transparent 0,transparent 55px,rgba(226,232,240,.8) 56px)}.bar-col{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-width:0}.bar-col .bar-val{font-size:10px;font-weight:750;color:#475569;margin-bottom:5px;font-variant-numeric:tabular-nums}.bar-col .bar{width:min(26px,72%);min-height:3px;background:linear-gradient(180deg,#14b8a6,#0f766e);border-radius:5px 5px 1px 1px;box-shadow:0 5px 12px rgba(15,118,110,.13)}.bar-col .bar-lbl{position:absolute;bottom:-22px;font-size:9px;color:#94a3b8;white-space:nowrap}.bar-col:nth-child(even) .bar-lbl{display:none}
.reason-list{display:grid;gap:14px}.hbar-row{display:grid;grid-template-columns:minmax(90px,140px) minmax(80px,1fr) 24px;align-items:center;gap:10px}.hbar-label{font-size:11px;color:#475569;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.hbar-track{height:9px;background:#edf2f7;border-radius:99px;overflow:hidden}.hbar-fill{height:100%;background:linear-gradient(90deg,#0f766e,#2dd4bf);border-radius:99px}.hbar-count{font-size:11px;font-weight:750;color:#334155;text-align:right;font-variant-numeric:tabular-nums}
.hour-grid{display:grid;grid-template-columns:repeat(12,minmax(42px,1fr));gap:7px;overflow-x:auto;padding-bottom:4px}.hour-cell{min-height:65px;padding:10px 6px;text-align:center;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}.hour-cell .h-num{display:block;font-size:9px;font-weight:650;color:#94a3b8}.hour-cell .h-count{display:block;margin-top:5px;font-size:16px;font-weight:800;color:#334155;font-variant-numeric:tabular-nums}.hour-cell.peak{background:#ccfbf1;border-color:#0f766e;box-shadow:inset 0 -3px 0 #0f766e}.hour-cell.peak .h-count{color:#0f766e}
.findings-list{display:grid;gap:0}.finding-row{display:grid;grid-template-columns:minmax(130px,.8fr) minmax(0,1.2fr);gap:14px;align-items:center;padding:13px 0;border-bottom:1px solid #f1f5f9}.finding-row:last-child{border-bottom:0;padding-bottom:0}.finding-row:first-child{padding-top:0}.finding-label{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8}.finding-value{font-size:13px;font-weight:750;color:#0f172a;text-align:right;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
.recent-wrap{overflow-x:auto}.recent-table{width:100%;border-collapse:collapse}.recent-table th,.recent-table td{padding:13px 18px;text-align:left;font-size:12px;border-bottom:1px solid #f1f5f9}.recent-table th{background:#f8fafc;color:#64748b;font-size:10px;font-weight:750;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}.recent-table td{color:#334155}.recent-table tr:last-child td{border-bottom:0}.recent-table tbody tr:hover td{background:#f0fdfa}.status-badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:750}.status-badge.pending{background:#fffbeb;color:#92400e}.status-badge.completed{background:#ecfdf5;color:#065f46}.status-badge.cancelled{background:#fef2f2;color:#991b1b}
.empty-msg{grid-column:1/-1;color:#94a3b8;font-size:12px;padding:30px;text-align:center}.back-link{display:inline-flex;align-items:center;gap:7px;margin-bottom:14px;color:#0f766e;text-decoration:none;font-size:12px;font-weight:700}.back-link:hover{text-decoration:underline}.back-link:focus-visible{outline:3px solid rgba(15,118,110,.2);outline-offset:3px;border-radius:4px}
@media(max-width:1150px){.stat-strip{grid-template-columns:repeat(3,1fr)}.stat-metric{border-bottom:1px solid #e2e8f0}.stat-metric:nth-child(3){border-right:0}.report-grid{grid-template-columns:1fr}}@media(max-width:700px){.visit-report-header{padding:20px}.stat-strip{grid-template-columns:1fr 1fr}.stat-metric{padding:17px}.stat-metric:nth-child(odd){border-right:1px solid #e2e8f0}.stat-metric:nth-child(even){border-right:0}.stat-metric:last-child{grid-column:1/-1}.report-grid{gap:14px}.bar-chart{gap:4px}.hour-grid{grid-template-columns:repeat(8,1fr)}.finding-row{grid-template-columns:1fr;gap:3px}.finding-value{text-align:left}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>

<div class="dashboard-main">
<div class="dashboard-container visit-report">
<a href="dashboard.php" class="back-link">Back to clinic dashboard</a>
<header class="visit-report-header">
    <h1>Visit statistics</h1>
    <p>Monitor clinic demand, recurring reasons, and peak service periods.</p>
    <span class="report-date">Reporting period: <?= htmlspecialchars(date('M d, Y'), ENT_QUOTES, 'UTF-8') ?></span>
</header>

<section class="stat-strip" aria-label="Visit totals">
    <div class="stat-metric today"><div class="stat-label">Today</div><div class="stat-value"><?= $stats['today'] ?></div><div class="stat-context">Visits received today</div></div>
    <div class="stat-metric week"><div class="stat-label">This week</div><div class="stat-value"><?= $stats['thisWeek'] ?></div><div class="stat-context">Monday through today</div></div>
    <div class="stat-metric month"><div class="stat-label">This month</div><div class="stat-value"><?= $stats['thisMonth'] ?></div><div class="stat-context">Month-to-date activity</div></div>
    <div class="stat-metric total"><div class="stat-label">All visits</div><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-context">Complete visit history</div></div>
    <div class="stat-metric pending"><div class="stat-label">Pending</div><div class="stat-value"><?= $stats['pending'] ?></div><div class="stat-context">Awaiting completion</div></div>
</section>

<div class="report-grid">
    <section class="report-panel">
        <div class="panel-heading"><div><h2>Daily visit volume</h2><p>Fourteen-day clinic activity</p></div><span class="chart-note">Visits per day</span></div>
        <div class="panel-body"><div id="daily-chart" class="bar-chart" role="img" aria-label="Daily clinic visits over the last fourteen days"></div></div>
    </section>
    <section class="report-panel">
        <div class="panel-heading"><div><h2>Common reasons</h2><p>Leading reasons over 30 days</p></div></div>
        <div class="panel-body"><div id="reasons-chart" class="reason-list" role="img" aria-label="Most common reasons for clinic visits"></div></div>
    </section>
</div>

<div class="report-grid">
    <section class="report-panel">
        <div class="panel-heading"><div><h2>Service-hour distribution</h2><p>Visit volume by hour over 30 days</p></div><span class="chart-note">Peak hour highlighted</span></div>
        <div class="panel-body"><div id="hourly-chart" class="hour-grid" role="img" aria-label="Clinic visits grouped by hour of day"></div></div>
    </section>
    <section class="report-panel">
        <div class="panel-heading"><div><h2>Key findings</h2><p>Summary of the current reporting period</p></div></div>
        <div class="panel-body"><div class="findings-list">
            <div class="finding-row"><span class="finding-label">Average per day</span><strong class="finding-value" id="avg-daily">Not available</strong></div>
            <div class="finding-row"><span class="finding-label">Peak day</span><strong class="finding-value" id="peak-day">Not available</strong></div>
            <div class="finding-row"><span class="finding-label">Peak hour</span><strong class="finding-value" id="peak-hour">Not available</strong></div>
            <div class="finding-row"><span class="finding-label">Common reason</span><strong class="finding-value" id="top-reason">Not available</strong></div>
        </div></div>
    </section>
</div>

<section class="report-panel">
    <div class="panel-heading"><div><h2>Recent visits</h2><p>Latest clinic activity and record status</p></div><span class="chart-note">15 most recent</span></div>
    <div class="recent-wrap">
    <table class="recent-table">
        <thead><tr><th>Date and time</th><th>Student</th><th>Student ID</th><th>Reason</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($recentVisits)): ?>
        <tr><td colspan="5" class="empty-msg">No visits have been recorded yet.</td></tr>
        <?php else: ?>
        <?php foreach ($recentVisits as $v): ?>
        <tr>
            <td><?php echo htmlspecialchars($v['date_time'] ?? $v['visit_date'] ?? 'Not available', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(($v['student_number'] ?? '') !== '' ? $v['student_number'] : ($v['student_id'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($v['reason_for_visit'] ?? 'Not recorded', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php $status = strtolower($v['record_status'] ?? 'pending'); $cls = 'pending'; if (strpos($status,'record')!==false) $cls = 'completed'; elseif (strpos($status,'cancel')!==false) $cls = 'cancelled'; ?><span class="status-badge <?= $cls ?>"><?php echo htmlspecialchars($v['record_status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody></table>
    </div>
</section>
</div>
</div>


<script>
var CHART_DATA = <?php echo $chartData; ?>;
(function(){
    'use strict';
    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function renderDaily(data) {
        var el = document.getElementById('daily-chart');
        var values = data || [];
        var total = values.reduce(function(sum, row) { return sum + Number(row.cnt || 0); }, 0);
        var max = Math.max.apply(null, values.map(function(row) { return Number(row.cnt) || 0; })) || 1;
        el.innerHTML = values.map(function(row) {
            var count = Number(row.cnt) || 0;
            var height = count ? Math.max(8, Math.round(count / max * 100)) : 2;
            var label = row.dt.substring(5).replace('-', '/');
            return '<div class="bar-col" title="' + esc(row.dt) + ': ' + count + ' visit' + (count === 1 ? '' : 's') + '">' +
                '<span class="bar-val">' + count + '</span><span class="bar" style="height:' + height + '%"></span><span class="bar-lbl">' + esc(label) + '</span></div>';
        }).join('');
        var peak = values.reduce(function(best, row) { return row.cnt > best.cnt ? row : best; }, values[0]);
        document.getElementById('avg-daily').textContent = (total / 14).toFixed(1);
        document.getElementById('peak-day').textContent = total ? peak.dt + ' (' + peak.cnt + ')' : 'No visits recorded';
    }

    function renderReasons(data) {
        var el = document.getElementById('reasons-chart');
        if (!data || !data.length) { el.innerHTML = '<div class="empty-msg">No reasons recorded in the last 30 days.</div>'; document.getElementById('top-reason').textContent = 'Not available'; return; }
        var max = Math.max.apply(null, data.map(function(row) { return Number(row.cnt) || 0; })) || 1;
        el.innerHTML = data.map(function(row) {
            var count = Number(row.cnt) || 0;
            var width = Math.max(2, Math.round(count / max * 100));
            return '<div class="hbar-row" title="' + esc(row.reason_for_visit) + '"><span class="hbar-label">' + esc(row.reason_for_visit) + '</span><span class="hbar-track"><span class="hbar-fill" style="width:' + width + '%"></span></span><span class="hbar-count">' + count + '</span></div>';
        }).join('');
        document.getElementById('top-reason').textContent = data[0].reason_for_visit + ' (' + Number(data[0].cnt) + ')';
    }

    function renderHourly(data) {
        var el = document.getElementById('hourly-chart');
        var counts = {};
        (data || []).forEach(function(row) { counts[Number(row.hr)] = Number(row.cnt) || 0; });
        var max = Math.max.apply(null, Object.keys(counts).map(function(hour) { return counts[hour]; })) || 0;
        el.innerHTML = Array.from({ length: 24 }, function(_, hour) {
            var count = counts[hour] || 0;
            var label = (hour < 10 ? '0' : '') + hour + ':00';
            return '<div class="hour-cell' + (count === max && count > 0 ? ' peak' : '') + '" title="' + label + ': ' + count + ' visit' + (count === 1 ? '' : 's') + '"><span class="h-num">' + label + '</span><span class="h-count">' + count + '</span></div>';
        }).join('');
        var peakHour = 0;
        Object.keys(counts).forEach(function(hour) { if (counts[hour] > counts[peakHour]) peakHour = Number(hour); });
        document.getElementById('peak-hour').textContent = max ? (peakHour < 10 ? '0' : '') + peakHour + ':00 (' + max + ')' : 'No visits recorded';
    }

    renderDaily(CHART_DATA.dailyVisits || []);
    renderReasons(CHART_DATA.topReasons || []);
    renderHourly(CHART_DATA.hourly || []);
})();
</script>

