<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Visit Statistics';
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_stats';

$today = date('Y-m-d');
$thisWeek = date('Y-m-d', strtotime('monday this week'));
$thisMonth = date('Y-m-01');

$stats = [
    'today'     => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) = ?", [$today]),
    'thisWeek'  => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) >= ?", [$thisWeek]),
    'thisMonth' => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) >= ?", [$thisMonth]),
    'total'     => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits"),
    'pending'   => (int) $db->fetchColumn("SELECT COUNT(*) FROM health_visits WHERE record_status = 'Pending'"),
];

$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
$topReasons = $db->fetchAll("SELECT reason_for_visit, COUNT(*) AS cnt FROM health_visits WHERE reason_for_visit IS NOT NULL AND reason_for_visit != '' AND DATE(COALESCE(date_time, visit_date)) >= ? GROUP BY reason_for_visit ORDER BY cnt DESC LIMIT 8", [$thirtyDaysAgo]);
$dailyVisits = $db->fetchAll("SELECT DATE(COALESCE(date_time, visit_date)) AS dt, COUNT(*) AS cnt FROM health_visits WHERE DATE(COALESCE(date_time, visit_date)) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY dt ORDER BY dt ASC");
$hourly = $db->fetchAll("SELECT HOUR(date_time) AS hr, COUNT(*) AS cnt FROM health_visits WHERE date_time IS NOT NULL AND DATE(date_time) >= ? GROUP BY hr ORDER BY hr ASC", [$thirtyDaysAgo]);
$recentVisits = $db->fetchAll("SELECT hv.*, s.first_name, s.last_name, s.student_id FROM health_visits hv LEFT JOIN students s ON hv.student_id = s.id ORDER BY COALESCE(hv.date_time, hv.visit_date) DESC LIMIT 15");
$chartData = json_encode(['topReasons'=>$topReasons, 'dailyVisits'=>$dailyVisits, 'hourly'=>$hourly]);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:#fff;border-radius:12px;padding:1.25rem;display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);border-left:4px solid #0d9488}
.stat-card .icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.stat-card.today .icon{background:#e0f2fe;color:#0284c7}
.stat-card.week .icon{background:#d1fae5;color:#059669}
.stat-card.month .icon{background:#fef3c7;color:#d97706}
.stat-card.total .icon{background:#ede9fe;color:#7c3aed}
.stat-card.pending .icon{background:#fee2e2;color:#dc2626}
.stat-card .stat-label{font-size:.8rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.stat-card .stat-value{font-size:1.5rem;font-weight:700;color:#1f2937}
.panels-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
@media(max-width:900px){.panels-grid{grid-template-columns:1fr}}
.panel{background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.panel-title{font-size:1rem;font-weight:600;color:#1f2937;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid #e5e7eb}
.bar-chart{display:flex;align-items:flex-end;gap:6px;height:180px;padding-top:10px}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%}
.bar-col .bar{width:100%;max-width:36px;background:#0d9488;border-radius:4px 4px 0 0;min-height:2px}
.bar-col .bar-val{font-size:.7rem;color:#374151;margin-bottom:3px;font-weight:600}
.bar-col .bar-lbl{font-size:.65rem;color:#6b7280;margin-top:4px;white-space:nowrap}
.hbar-row{display:flex;align-items:center;margin-bottom:.6rem;gap:.5rem}
.hbar-label{width:140px;font-size:.8rem;color:#374151;text-align:right;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hbar-track{flex:1;background:#f3f4f6;border-radius:6px;height:22px;overflow:hidden}
.hbar-fill{height:100%;background:#0d9488;border-radius:6px;display:flex;align-items:center;padding-left:8px;min-width:24px}
.hbar-fill span{font-size:.7rem;color:#fff;font-weight:600}
.hour-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:6px}
@media(max-width:600px){.hour-grid{grid-template-columns:repeat(6,1fr)}}
.hour-cell{text-align:center;padding:.5rem .25rem;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb}
.hour-cell .h-num{font-size:.7rem;color:#6b7280}
.hour-cell .h-count{font-size:1.1rem;font-weight:700;color:#1f2937}
.hour-cell.peak{background:#ccfbf1;border-color:#0d9488}
.hour-cell.peak .h-count{color:#0d9488}
.recent-table{width:100%;border-collapse:collapse}
.recent-table th,.recent-table td{padding:.65rem .75rem;text-align:left;font-size:.82rem;border-bottom:1px solid #f3f4f6}
.recent-table th{background:#f9fafb;color:#6b7280;font-weight:600;text-transform:uppercase;font-size:.7rem;letter-spacing:.04em}
.recent-table tr:hover td{background:#f0fdfa}
.status-badge{display:inline-block;padding:.15rem .55rem;border-radius:9999px;font-size:.72rem;font-weight:600}
.status-badge.pending{background:#fef3c7;color:#92400e}
.status-badge.completed{background:#d1fae5;color:#065f46}
.status-badge.cancelled{background:#fee2e2;color:#991b1b}
.back-link{display:inline-flex;align-items:center;gap:.4rem;color:#0d9488;text-decoration:none;font-weight:500;font-size:.9rem;margin-bottom:1rem}
.back-link:hover{text-decoration:underline}
.empty-msg{color:#9ca3af;font-style:italic;font-size:.85rem;padding:2rem;text-align:center}
</style>

<div class="dashboard-main">
<div class="dashboard-container">
<a href="dashboard.php" class="back-link">&#8592; Back to Dashboard</a>

<div class="stats-grid">
<div class="stat-card today"><div class="icon">&#128197;</div><div><div class="stat-label">Today</div><div class="stat-value"><?php echo $stats['today']; ?></div></div></div>
<div class="stat-card week"><div class="icon">&#128198;</div><div><div class="stat-label">This Week</div><div class="stat-value"><?php echo $stats['thisWeek']; ?></div></div></div>
<div class="stat-card month"><div class="icon">&#128202;</div><div><div class="stat-label">This Month</div><div class="stat-value"><?php echo $stats['thisMonth']; ?></div></div></div>
<div class="stat-card total"><div class="icon">&#128200;</div><div><div class="stat-label">Total Visits</div><div class="stat-value"><?php echo $stats['total']; ?></div></div></div>
<div class="stat-card pending"><div class="icon">&#8987;</div><div><div class="stat-label">Pending</div><div class="stat-value"><?php echo $stats['pending']; ?></div></div></div>
</div>

<div class="panels-grid">
<div class="panel"><div class="panel-title">Daily Visits &mdash; Last 14 Days</div><div id="daily-chart" class="bar-chart"></div></div>
<div class="panel"><div class="panel-title">Top Reasons for Visit &mdash; Last 30 Days</div><div id="reasons-chart"></div></div>
</div>

<div class="panels-grid">
<div class="panel"><div class="panel-title">Visits by Hour &mdash; Last 30 Days</div><div id="hourly-chart" class="hour-grid"></div></div>
<div class="panel"><div class="panel-title">Summary</div>
<div style="padding:1rem 0;font-size:.9rem;color:#374151;line-height:1.8">
<div>&#128197; <strong>Average per day (14d):</strong> <span id="avg-daily">-</span></div>
<div>&#128202; <strong>Peak day (14d):</strong> <span id="peak-day">-</span></div>
<div>&#127976; <strong>Peak hour (30d):</strong> <span id="peak-hour">-</span></div>
<div>&#128211; <strong>Most common reason:</strong> <span id="top-reason">-</span></div>
</div>
</div>
</div>

<div class="panel" style="margin-bottom:1.5rem">
<div class="panel-title">Recent Visits</div>
<div style="overflow-x:auto">
<table class="recent-table">
<thead><tr><th>Date &amp; Time</th><th>Student</th><th>ID</th><th>Reason</th><th>Status</th></tr></thead>
<tbody>
<?php if (empty($recentVisits)): ?>
<tr><td colspan="5" class="empty-msg">No visits recorded yet.</td></tr>
<?php else: ?>
<?php foreach ($recentVisits as $v): ?>
<tr>
<td><?php echo htmlspecialchars($v['date_time'] ?? $v['visit_date'] ?? 'N/A'); ?></td>
<td><?php echo htmlspecialchars(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')); ?></td>
<td><?php echo htmlspecialchars($v['student_id'] ?? $v['student_id_number'] ?? 'N/A'); ?></td>
<td><?php echo htmlspecialchars($v['reason_for_visit'] ?? '-'); ?></td>
<td><?php $status = strtolower($v['record_status'] ?? 'pending'); $cls = 'pending'; if (strpos($status,'complet')!==false) $cls='completed'; elseif (strpos($status,'cancel')!==false) $cls='cancelled'; ?><span class="status-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($v['record_status'] ?? 'Pending'); ?></span></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table>
</div>
</div>

</div>
</div>


<script>
var CHART_DATA = <?php echo $chartData; ?>;
(function(){
    'use strict';
    function renderDaily(d) {
        var el = document.getElementById('daily-chart');
        if (!d || !d.length) { el.innerHTML = '<div class="empty-msg">No data</div>'; return; }
        var mx = Math.max.apply(null, d.map(function(x){ return parseInt(x.cnt,10); }));
        if (mx === 0) mx = 1;
        var h = '';
        d.forEach(function(r) {
            var ht = Math.round((parseInt(r.cnt,10) / mx) * 100);
            var s = r.dt ? r.dt.substring(5) : '';
            h += '<div class="bar-col"><div class="bar-val">' + r.cnt + '</div><div class="bar" style="height:' + ht + '%"></div><div class="bar-lbl">' + s + '</div></div>';
        });
        el.innerHTML = h;
        var tot = d.reduce(function(a,b){ return a + parseInt(b.cnt,10); }, 0);
        var avg = (tot / d.length).toFixed(1);
        var pk = d.reduce(function(a,b){ return parseInt(b.cnt,10) > parseInt(a.cnt,10) ? b : a; });
        var ae = document.getElementById('avg-daily');
        var pe = document.getElementById('peak-day');
        if (ae) ae.textContent = avg;
        if (pe) pe.textContent = (pk.dt || '-') + ' (' + pk.cnt + ')';
    }

    function renderReasons(d) {
        var el = document.getElementById('reasons-chart');
        if (!d || !d.length) { el.innerHTML = '<div class="empty-msg">No data</div>'; return; }
        var mx = Math.max.apply(null, d.map(function(x){ return parseInt(x.cnt,10); }));
        if (mx === 0) mx = 1;
        var h = '';
        d.forEach(function(r) {
            var w = Math.round((parseInt(r.cnt,10) / mx) * 100);
            h += '<div class="hbar-row"><div class="hbar-label" title="' + (r.reason_for_visit||'') + '">' + (r.reason_for_visit||'') + '</div><div class="hbar-track"><div class="hbar-fill" style="width:' + w + '%"><span>' + r.cnt + '</span></div></div></div>';
        });
        el.innerHTML = h;
        var te = document.getElementById('top-reason');
        if (te && d.length) te.textContent = d[0].reason_for_visit + ' (' + d[0].cnt + ')';
    }

    function renderHourly(d) {
        var el = document.getElementById('hourly-chart');
        var mp = {};
        var mx = 0;
        var pk = 0;
        if (d) d.forEach(function(r) {
            var hr = parseInt(r.hr,10);
            var c = parseInt(r.cnt,10);
            mp[hr] = c;
            if (c > mx) { mx = c; pk = hr; }
        });
        var h = '';
        for (var i = 0; i < 24; i++) {
            var c = mp[i] || 0;
            var cls = (c === mx && c > 0) ? ' hour-cell peak' : ' hour-cell';
            var lb = i < 10 ? '0' + i : '' + i;
            h += '<div class="' + cls + '"><div class="h-num">' + lb + ':00</div><div class="h-count">' + c + '</div></div>';
        }
        el.innerHTML = h;
        var ph = document.getElementById('peak-hour');
        if (ph && mx > 0) ph.textContent = (pk < 10 ? '0' : '') + pk + ':00 (' + mx + ')';
    }

    renderDaily(CHART_DATA.dailyVisits || []);
    renderReasons(CHART_DATA.topReasons || []);
    renderHourly(CHART_DATA.hourly || []);
})();
</script>

