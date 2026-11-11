<?php
require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') === 'student') { header('Location: student/dashboard.php'); exit; }
if (!in_array($_SESSION['role'] ?? '', ['super_admin','staff'], true)) { http_response_code(403); exit('Access denied.'); }
header('Cache-Control: no-store, private');
require_once __DIR__ . '/shared/dashboard_data.php';
$snapshot = null;
try { $snapshot = registrarDashboardData(); } catch (Throwable $e) { error_log('[dashboard] '.$e->getMessage()); }
$page_title = 'Dashboard';
$APP_ROOT = './'; $ACTIVE_NAV = 'dashboard'; $body_page = 'dashboard';
$extra_css = ['queue.css','dashboard-modern.css'];
$userName = $_SESSION['full_name'] ?? 'Registrar';
$firstName = explode(' ', trim($userName))[0];
$date = new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="dashboard-main" id="mainContent">
 <div class="overview">
  <header class="ov-topbar">
   <div><p class="ov-eyebrow">WORKSPACE / REGISTRAR</p><h1>Dashboard</h1></div>
  </header>
  <section class="ov-welcome" aria-labelledby="welcomeTitle">
   <div class="ov-welcome-copy"><span class="ov-small-label">OFFICE OF THE REGISTRAR</span><h2 id="welcomeTitle">Welcome back, <?= e($firstName) ?>.</h2><p>Student records, document requests, and daily operations.<br>Everything you need to keep things moving.</p></div>
   <img src="assets/dashboard/campus-photo.webp" width="1760" height="896" alt="" class="ov-campus" fetchpriority="low">
  </section>
  <div id="dashboardError" class="ov-error" role="alert" hidden></div>
  <div class="ov-content-grid">
   <div class="ov-primary">
    <section class="ov-card ov-trend"><div class="ov-card-head"><div><h2>Student registration activity</h2><p>New student records created each month</p></div><span class="ov-chip">Last 6 months</span></div><div class="ov-chart" id="registrationChart" aria-label="Monthly new student records"></div><div class="ov-chart-foot"><span><span class="ov-legend-dot"></span> New student records</span><strong><span id="trendTotal">—</span> in this period</strong></div></section>
    <section class="ov-card"><div class="ov-card-head"><div><h2>Recent document requests</h2><p>The latest submissions across student services</p></div><a href="registrar/documents.php" class="ov-text-link">View all <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div><div class="ov-table-wrap"><table class="ov-table"><caption class="ov-sr-only">Six most recent document requests</caption><thead><tr><th scope="col">Student</th><th scope="col">Document</th><th scope="col">Status</th><th scope="col">Filed</th></tr></thead><tbody id="recentRequests"><tr><td colspan="4" class="ov-empty">Loading requests…</td></tr></tbody></table></div></section>
    <section class="ov-card"><div class="ov-card-head"><div><h2>Student status</h2><p>Current status of every student record</p></div><a href="registrar/status-tracker.php" class="ov-text-link">Open tracker <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div><div id="studentStatuses" class="ov-statuses"></div></section>
   </div>
   <aside class="ov-secondary" aria-label="Daily operations">
    <section class="ov-card ov-quick"><div class="ov-card-head"><h2>Quick access</h2></div><div class="ov-quick-links"><a href="registrar/students.php"><span class="ov-quick-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span><span>Student directory<small>Find and manage records</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a><a href="registrar/masterlist.php"><span class="ov-quick-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><span>Masterlist<small>Browse academic records</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a><a href="registrar/documents.php"><span class="ov-quick-icon"><i class="fa-regular fa-file-lines" aria-hidden="true"></i></span><span>Document desk<small>Review and release requests</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a></div></section>
    <section class="ov-card ov-queue"><div class="ov-card-head"><div><h2><span class="ov-live-dot" aria-hidden="true"></span> Live queue</h2><p>Today's registrar counter</p></div><a href="registrar/queue.php" class="ov-text-link" aria-label="Open live queue console"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></div><div id="liveQueueWidget" class="live-queue-widget"><p class="ov-empty">Connecting to queue…</p></div><p id="queueWidgetStatus" class="ov-queue-status" role="status">Connecting…</p><a href="registrar/queue.php" class="ov-queue-link">Open queue console <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></section>
    <section class="ov-card"><div class="ov-card-head"><div><h2>Students by course</h2><p>Top 5 · all student records</p></div></div><div id="courseDistribution" class="ov-courses"></div></section>
   </aside>
  </div>
  <span id="syncStatus" class="ov-sr-only" role="status"></span><footer class="ov-footer"><span>Bestlink College of the Philippines</span><span>Database refresh every 30 seconds · Queue every 5 seconds</span></footer>
 </div>
</main>
<script type="application/json" id="dashboardSnapshot"><?= json_encode($snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<?php $page_scripts = ['dashboard-live.js','queue.js']; include __DIR__ . '/includes/footer.php'; ?>
