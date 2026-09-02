<?php
// ============================================================
//  REGISTRAR/QUEUE.PHP
//  Queue serving console (registrar/admin). Live view of the
//  waiting line, now-serving ticket, and history. Actions:
//  Call Next, Complete, Skip (absent / failed to comply).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

$page_title = 'Queue';
$APP_ROOT = '../';
$ACTIVE_NAV = 'queue';
$body_page = 'console';
$extra_css = ['queue.css'];
$page_scripts = ['queue.js'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>Queue</h1>
        <p>Live queue serving console</p>
    </div>
    <div class="header-actions">
        <a href="../queue/monitor.php" target="_blank" class="btn btn-secondary"><i class="fas fa-tv"></i> Open Monitor</a>
        <a href="../queue/kiosk.php" target="_blank" class="btn btn-secondary"><i class="fas fa-credit-card"></i> Open Kiosk</a>
    </div>
</header>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-users"></i></div></div><div class="stat-number" id="stat-waiting">0</div><div class="stat-label">Waiting</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-bullhorn"></i></div></div><div class="stat-number" id="stat-serving">0</div><div class="stat-label">Now Serving</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-check-double"></i></div></div><div class="stat-number" id="stat-completed">0</div><div class="stat-label">Completed Today</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-icon red"><i class="fas fa-user-slash"></i></div></div><div class="stat-number" id="stat-no_show">0</div><div class="stat-label">No-Shows</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px;align-items:start;margin-bottom:20px;">

    <!-- NOW SERVING -->
    <div class="panel" id="nowServingBody">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fas fa-bullhorn"></i> Now Serving</div>
        </div>

        <div id="nsEmpty" class="empty-state" style="padding:34px 20px;">
            <i class="fas fa-circle-notch"></i>
            <p>No one is being served</p>
            <span>Call the next student in line</span>
            <button class="btn btn-primary" id="btnCallNext" style="margin-top:16px;"><i class="fas fa-forward"></i> Call Next</button>
        </div>

        <div id="nsContent" style="display:none;text-align:center;padding:14px 0;">
            <div style="font-size:72px;font-weight:900;background:linear-gradient(135deg,#2563eb,#7c3aed);-webkit-background-clip:text;background-clip:text;color:transparent;line-height:1;" id="nsNumber">000</div>
            <div style="font-size:24px;font-weight:700;margin:8px 0 2px;" id="nsName">—</div>
            <div style="color:#64748b;font-size:13px;">ID: <span id="nsNumber2">—</span> &middot; <span id="nsCourse">—</span></div>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Called <span id="nsElapsed">—</span> ago</div>
            <div class="action-group" style="justify-content:center;margin-top:18px;gap:10px;">
                <button class="btn btn-success" id="nsComplete"><i class="fas fa-check"></i> Complete</button>
                <button class="btn btn-danger" id="nsSkip"><i class="fas fa-forward"></i> Skip</button>
            </div>
        </div>
    </div>

    <!-- WAITING LIST -->
    <div class="panel">
        <div class="search-toolbar">
            <div class="panel-title" style="padding:0;"><i class="fas fa-list-ol"></i> Waiting Line</div>
        </div>
        <div class="table-responsive" style="overflow-x:auto;">
        <table class="table">
            <thead><tr>
                <th>#</th><th>Number</th><th>Student</th><th>ID No.</th><th>Course</th><th>Joined</th><th style="text-align:center;">Action</th>
            </tr></thead>
            <tbody id="waitingBody">
                <tr class="empty-state-row"><td colspan="7" class="empty-state"><i class="fas fa-people-group"></i><p>No students waiting</p><span>Tickets appear here when students tap at the kiosk</span></td></tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- HISTORY -->
<div class="panel">
    <div class="panel-toolbar">
        <div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Today's History</div>
    </div>
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead><tr><th>Number</th><th>Student</th><th>Status</th><th>Served At</th></tr></thead>
        <tbody id="completedBody">
            <tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p>Nothing served yet today</p></td></tr>
        </tbody>
    </table>
    </div>
</div>

</div>
</main>

<!-- Skip Confirm Modal -->
<div class="modal-overlay" id="skipModal">
    <div class="modal-content" style="max-width:420px;"><div class="modal-header"><h2><i class="fas fa-forward"></i> Skip Ticket</h2><button class="modal-close" onclick="window.queueCloseSkip()"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
        <p style="font-size:14px;color:#64748b;margin:0 0 14px;" id="skipMsg">Mark <strong id="skipName">—</strong> as no-show and call the next ticket?</p>
        <p style="font-size:12px;color:#94a3b8;margin:0;">Use this when the student is not present or failed to comply within 5 minutes.</p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" id="skipCancel">Cancel</button>
        <button class="btn btn-danger" id="skipConfirm"><i class="fas fa-forward"></i> Skip &amp; Next</button>
    </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
