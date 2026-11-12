<?php
require_once __DIR__.'/../shared/security_headers.php';require_once __DIR__.'/../shared/session_config.php';require_once __DIR__.'/../shared/report_export_security.php';requireLogin();
if(!reportExportRole()){http_response_code(403);exit('Access denied.');}
$db=Database::getInstance();$admin=in_array(getCurrentUserRole(),['super_admin','registrar_admin'],true);
$records=$db->fetchAll('SELECT actor_name,actor_role,report_name,format,resource,status,ip_address,created_at,initiated_at FROM report_exports '.($admin?'':'WHERE user_id=? ').'ORDER BY id DESC LIMIT 200',$admin?[]:[getCurrentUserId()]);
$page_title='Export History';$APP_ROOT='../';$ACTIVE_NAV='export-history';include __DIR__.'/../includes/header.php';include __DIR__.'/../includes/sidebar.php';
?>
<main class="dashboard-main"><div class="dashboard-container ax-page">
<header class="page-header"><div><h1>Export History</h1><p><?= $admin?'Trace report confirmations and export requests across registrar accounts.':'Review your report confirmations and export requests.' ?></p></div></header>
<div class="ax-metrics" id="exMetrics"></div>
<section class="ax-surface"><header class="ax-surface-head"><div><span class="ax-eyebrow">REPORT ACTIVITY</span><h2>Export journal</h2><p>Search the latest <?= count($records) ?> recorded events.</p></div><button type="button" class="btn btn-secondary" id="exHelp"><i class="fas fa-circle-info" aria-hidden="true"></i> Status guide</button></header>
<div class="ax-filterbar"><label class="ax-search"><span>Search activity</span><input id="exSearch" type="search" placeholder="Report or account name…"></label><label>Status<select id="exStatus"><option value="">All statuses</option><option value="authorized">Authorized</option><option value="initiated">Initiated</option><option value="denied">Denied</option></select></label><label>Format<select id="exFormat"><option value="">All formats</option><?php foreach(array_unique(array_column($records,'format')) as $format): ?><option><?= htmlspecialchars($format) ?></option><?php endforeach; ?></select></label><button type="button" class="btn btn-secondary" id="exReset">Reset</button></div>
<div class="ax-table-scroll"><table class="ax-table"><thead><tr><th>Report / format</th><th>Requested by</th><th>Event status</th><th>Date & time</th><th></th></tr></thead><tbody id="exRows"></tbody></table></div><footer class="ax-table-footer"><span id="exCount" aria-live="polite"></span><span>Most recent first · Up to 200 events</span></footer>
</section>
<aside class="ax-inline-note"><i class="fas fa-shield-halved" aria-hidden="true"></i><span>Export requests are recorded against the staff account. An initiated request does not confirm that a file was saved.</span></aside>
</div></main>
<dialog id="exDialog" class="ax-dialog"><header><div><span class="ax-eyebrow">EXPORT TRANSPARENCY</span><h2 id="exDialogTitle">Event details</h2></div><button type="button" id="exClose" aria-label="Close details">×</button></header><div id="exDetail"></div></dialog>
<script type="application/json" id="exData"><?= json_encode($records,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php include __DIR__.'/../includes/footer.php'; ?>
