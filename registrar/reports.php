<?php
// ============================================================
//  REGISTRAR/REPORTS.PHP
//  Student reports — counts by course, year, status
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$totalStudents = $db->fetchColumn("SELECT COUNT(*) FROM students");
$activeStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'active'");
$byCourse = $db->fetchAll("SELECT course, COUNT(*) as cnt FROM students WHERE course IS NOT NULL AND course != '' GROUP BY course ORDER BY cnt DESC");
$byYear = $db->fetchAll("SELECT year_level, COUNT(*) as cnt FROM students WHERE year_level IS NOT NULL GROUP BY year_level ORDER BY year_level");
$byStatus = $db->fetchAll("SELECT status, COUNT(*) as cnt FROM students GROUP BY status ORDER BY cnt DESC");
$byGender = $db->fetchAll("SELECT gender, COUNT(*) as cnt FROM students WHERE gender IS NOT NULL GROUP BY gender");

$page_title = 'Reports';
$APP_ROOT = '../';
$ACTIVE_NAV = 'reports';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
:root { --sidebar-width:260px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; box-sizing:border-box; }
.header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid #e8eaef; gap:16px; flex-wrap:wrap; }
.header .title h1 { font-size:22px; font-weight:700; color:#0f172a; margin:0 0 2px; }
.header .title p { font-size:13px; color:#64748b; margin:0; }
.report-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; margin-bottom:24px; }
.report-card { background:white; border-radius:14px; border:1px solid #e2e8f0; padding:18px 20px; box-shadow:0 1px 3px rgba(15,23,42,0.04); }
.report-card h3 { font-size:13px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #f1f5f9; }
.summary-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
.summary-card { background:white; border-radius:14px; padding:18px 20px; border:1px solid #e2e8f0; text-align:center; }
.summary-card .num { font-size:28px; font-weight:700; color:#0f172a; }
.summary-card .lbl { font-size:12px; color:#64748b; margin-top:2px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
td { padding:8px 0; border-bottom:1px solid #f1f5f9; color:#1e293b; }
td:last-child { text-align:right; font-weight:600; }
.progress { height:6px; background:#f1f5f9; border-radius:999px; margin-top:6px; overflow:hidden; }
.progress .bar { height:100%; border-radius:999px; background:#2563eb; transition:width .5s; }
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px;width:100%;max-width:100%} .summary-row{grid-template-columns:repeat(2,1fr)} }
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Reports</h1><p>Student population summary</p></div><div class="header-actions"><button class="btn btn-primary" onclick="generateAIReport()"><i class="fas fa-brain"></i> Generate AI Report</button></div></header>
<div id="aiReportBox" style="display:none;margin-bottom:18px;background:linear-gradient(135deg,#eef4ff,#f5f3ff);border:1px solid #dbeafe;border-radius:12px;padding:16px 18px;"></div>

<div class="summary-row">
<div class="summary-card"><div class="num"><?= $totalStudents ?></div><div class="lbl">Total Students</div></div>
<div class="summary-card"><div class="num"><?= $activeStudents ?></div><div class="lbl">Active</div></div>
<div class="summary-card"><div class="num"><?= $totalStudents - $activeStudents ?></div><div class="lbl">Inactive / Others</div></div>
<div class="summary-card"><div class="num"><?= count($byCourse) ?></div><div class="lbl">Courses Offered</div></div>
</div>

<div class="report-grid">
<div class="report-card">
<h3><i class="fas fa-graduation-cap" style="color:#2563eb;margin-right:6px;"></i> By Course</h3>
<table><?php $max = $byCourse[0]['cnt'] ?? 1; foreach ($byCourse as $r): ?><tr><td><?= htmlspecialchars($r['course']) ?></td><td><?= $r['cnt'] ?></td></tr><tr><td colspan="2" style="padding:2px 0 10px;"><div class="progress"><div class="bar" style="width:<?= round($r['cnt']/$max*100) ?>%"></div></div></td></tr><?php endforeach; if (!$byCourse): ?><tr><td colspan="2" style="color:#94a3b8;">No data</td></tr><?php endif; ?></table>
</div>
<div class="report-card">
<h3><i class="fas fa-layer-group" style="color:#b45309;margin-right:6px;"></i> By Year Level</h3>
<table><?php $max2 = max(array_column($byYear, 'cnt') ?: [1]); foreach ($byYear as $r): ?><tr><td><?= $r['year_level'] ? $r['year_level'].' Year' : 'N/A' ?></td><td><?= $r['cnt'] ?></td></tr><tr><td colspan="2" style="padding:2px 0 10px;"><div class="progress"><div class="bar" style="width:<?= round($r['cnt']/$max2*100) ?>%;background:#b45309;"></div></div></td></tr><?php endforeach; ?></table>
</div>
<div class="report-card">
<h3><i class="fas fa-flag" style="color:#16a34a;margin-right:6px;"></i> By Status</h3>
<table><?php $max3 = max(array_column($byStatus, 'cnt') ?: [1]); foreach ($byStatus as $r): $colors = ['active'=>'#16a34a','probation'=>'#b45309','at-risk'=>'#dc2626','graduated'=>'#2563eb','loa'=>'#7c3aed','transferred'=>'#db2777','dropped'=>'#dc2626','archived'=>'#94a3b8']; ?><tr><td style="text-transform:capitalize;"><?= htmlspecialchars($r['status']) ?></td><td><?= $r['cnt'] ?></td></tr><tr><td colspan="2" style="padding:2px 0 10px;"><div class="progress"><div class="bar" style="width:<?= round($r['cnt']/$max3*100) ?>%;background:<?= $colors[$r['status']] ?? '#2563eb' ?>;"></div></div></td></tr><?php endforeach; ?></table>
</div>
<div class="report-card">
<h3><i class="fas fa-venus-mars" style="color:#7c3aed;margin-right:6px;"></i> By Gender</h3>
<table><?php $max4 = max(array_column($byGender, 'cnt') ?: [1]); foreach ($byGender as $r): ?><tr><td><?= htmlspecialchars($r['gender'] ?? 'N/A') ?></td><td><?= $r['cnt'] ?></td></tr><tr><td colspan="2" style="padding:2px 0 10px;"><div class="progress"><div class="bar" style="width:<?= round($r['cnt']/$max4*100) ?>%;background:#7c3aed;"></div></div></td></tr><?php endforeach; ?></table>
</div>
</div>
</main>

<script>
function generateAIReport() {
    const box = document.getElementById('aiReportBox');
    const btn = event.target.closest('.btn');
    box.style.display = 'block';
    box.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Report</span> <span style="color:#64748b;font-size:12px;margin-left:4px;"><i class="fas fa-spinner fa-spin"></i> Analyzing student population...</span>';
    if (btn) { btn.disabled = true; }
    fetch('../api/ai-tools.php?action=report', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
    .then(r => r.json())
    .then(d => {
        if (d.success && d.data && d.data.report) {
            box.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Report</span><p style="margin:8px 0 0;color:#1e40af;font-size:14px;line-height:1.5;">' + d.data.report + '</p>';
        } else {
            box.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Report</span><p style="margin:8px 0 0;color:#94a3b8;">Unable to generate report right now.</p>';
        }
    })
    .catch(() => { box.innerHTML = '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;"><i class="fas fa-brain"></i> AI Report</span><p style="margin:8px 0 0;color:#94a3b8;">Error generating report.</p>'; })
    .finally(() => { if (btn) { btn.disabled = false; } });
}
</script>
<?php include '../includes/footer.php'; ?>