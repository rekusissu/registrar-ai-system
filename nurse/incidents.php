<?php
// ============================================================
//  NURSE/INCIDENTS.PHP
//  Clinic Incidents — Record and manage clinic incidents.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
requireRole('nurse');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$page_title = 'Clinic Incidents';
$APP_ROOT = '../';
$ACTIVE_NAV = 'nurse_incidents';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.inc-empty{text-align:center;padding:60px 20px;color:#94a3b8;font-size:14px}
.inc-empty i{font-size:48px;margin-bottom:16px;display:block;color:#cbd5e1}
</style>

<div class="dashboard-main">
    <div class="header">
        <div class="title">
            <h1><i class="fa-solid fa-triangle-exclamation"></i> Clinic Incidents</h1>
            <p>Track and manage clinic-related incidents.</p>
        </div>
    </div>

    <div class="panel" style="margin-top:24px;">
        <div class="panel-toolbar">
            <div class="panel-title"><i class="fa-solid fa-list"></i> Incident Log</div>
        </div>
        <div class="inc-empty">
            <i class="fa-solid fa-clipboard-check"></i>
            <p>No incidents recorded yet.</p>
            <p style="font-size:12px;">Incidents will appear here once recorded from the clinic dashboard.</p>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
