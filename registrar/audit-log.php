<?php
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$logs = $db->fetchAll("SELECT a.*, u.full_name, u.email FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 200");
$page_title = 'Audit Log';
$APP_ROOT = '../';
$ACTIVE_NAV = 'audit';
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
.action-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
.action-badge.insert,.action-badge.create,.action-badge.add{background:#dcfce7;color:#16a34a}
.action-badge.update,.action-badge.edit{background:#eef4ff;color:#2563eb}
.action-badge.delete,.action-badge.archive{background:#fee2e2;color:#dc2626}
@media(max-width:768px){ .dashboard-main{margin-left:0;padding:16px} }
</style>
<main class="dashboard-main">
<header class="header"><div class="title"><h1>Audit Log</h1><p>Complete system activity history</p></div>
<div class="header-actions"><span style="font-size:13px;color:#64748b;">Last 200 entries</span></div></header>
<table>
<thead><tr><th>Time</th><th>User</th><th>Action</th><th>Table</th><th>Details</th></tr></thead>
<tbody>
<?php if (empty($logs)): ?>
<tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">No audit logs found.</td></tr>
<?php else: foreach ($logs as $l):
$action = strtolower($l['action']);
$actionClass = 'update';
if (strpos($action,'insert')!==false||strpos($action,'create')!==false||strpos($action,'add')!==false) $actionClass='insert';
elseif (strpos($action,'update')!==false||strpos($action,'edit')!==false) $actionClass='update';
elseif (strpos($action,'delete')!==false||strpos($action,'archive')!==false) $actionClass='delete';
$new = json_decode($l['new_values'], true);
$detail = '';
if ($new) {
    if (isset($new['first_name'])) $detail = $new['first_name'].' '.($new['last_name']??'');
    elseif (isset($new['card_uid'])) $detail = 'UID: '.$new['card_uid'];
    elseif (isset($new['student_number'])) $detail = $new['student_number'];
}
?>
<tr>
<td style="font-size:12px;color:#64748b;"><?= date('M d, Y h:i A', strtotime($l['created_at'])) ?></td>
<td><?= htmlspecialchars($l['full_name'] ?? $l['email'] ?? 'System') ?></td>
<td><span class="action-badge <?= $actionClass ?>"><?= htmlspecialchars(ucfirst($l['action'])) ?></span></td>
<td><?= htmlspecialchars($l['table_name'] ?? '—') ?></td>
<td style="max-width:200px;white-space:normal;word-break:break-word;"><?= htmlspecialchars($detail ?: '—') ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</main>
<?php include '../includes/footer.php'; ?>