<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$records = $db->fetchAll("SELECT h.*, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.student_number, s.course, s.year_level FROM health_records h JOIN students s ON h.student_id = s.id ORDER BY s.last_name");
$page_title = 'Health Records';
$APP_ROOT = '../';
$ACTIVE_NAV = 'health';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
:root { --sidebar-width:260px; }
.dashboard-main { margin-left:var(--sidebar-width); padding:24px 32px; min-height:100vh; width:calc(100% - var(--sidebar-width)); max-width:calc(100% - var(--sidebar-width)); overflow-x:hidden; box-sizing:border-box; }
.header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid #e8eaef; gap:16px; flex-wrap:wrap; }
.header .title h1 { font-size:22px; font-weight:700; color:#0f172a; margin:0 0 2px; }
.header .title p { font-size:13px; color:#64748b; margin:0; }
table { width:100%; border-collapse:collapse; background:white; border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; }
th { text-align:left; padding:10px 14px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
td { padding:10px 14px; font-size:13px; color:#1e293b; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
tr:hover { background:#f8fafc; }
.badge-blood { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#fee2e2; color:#dc2626; }
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
.btn { display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;font-family:inherit; }
.btn-secondary { background:white; color:#475569; border-color:#e2e8f0; }
.btn-secondary:hover { background:#f8fafc; }
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Health Records</h1><p>Student health information</p></div>
<div class="header-actions"><span style="font-size:13px;color:#64748b;"><?= count($records) ?> records</span></div></header>
<table>
<thead><tr><th>Student</th><th>Blood</th><th>Height</th><th>Weight</th><th>Allergies</th><th>Conditions</th></tr></thead>
<tbody>
<?php if (empty($records)): ?>
<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No health records found.</td></tr>
<?php else: foreach ($records as $r): ?>
<tr>
<td><strong><?= htmlspecialchars($r['student_name']) ?></strong><br><span style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($r['student_number']) ?> · <?= htmlspecialchars($r['course'] ?? '') ?></span></td>
<td><span class="badge-blood"><?= htmlspecialchars($r['blood_type'] ?? '—') ?></span></td>
<td><?= $r['height'] ? $r['height'].' cm' : '—' ?></td>
<td><?= $r['weight'] ? $r['weight'].' kg' : '—' ?></td>
<td style="max-width:200px;white-space:normal;word-break:break-word;"><?= htmlspecialchars($r['allergies'] ?? 'None') ?></td>
<td style="max-width:200px;white-space:normal;word-break:break-word;"><?= htmlspecialchars($r['pre_existing_conditions'] ?? 'None') ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>
<?php include '../includes/footer.php'; ?>