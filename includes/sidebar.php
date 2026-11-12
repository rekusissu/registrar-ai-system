<?php
// ============================================================
//  SIDEBAR.PHP  (includes/)
//  Clean sidebar with collapse, no dropdowns
// ============================================================

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$USER_ROLE  = $_SESSION['role'] ?? '';

// Student profile info for sidebar mini-card (student role only)
if ($USER_ROLE === 'student') {
    $_sbFN       = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($_sbFN)) $_sbFN = $_SESSION['full_name'] ?? 'Student';
    $_sbInitial  = strtoupper(substr(trim($_sbFN), 0, 1));
    $_sbStudNo   = $_SESSION['student_number'] ?? '';
    $_sbPhoto    = $_SESSION['photo'] ?? '';
    $_sbPhotoUrl = $_sbPhoto ? $APP_ROOT . ltrim($_sbPhoto, './') : '';
}
?>
<!-- Sidebar Toggle Button -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle Sidebar">
    <i class="fa-solid fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <a href="<?= $APP_ROOT ?>dashboard.php" title="Go to Dashboard" class="sidebar-logo-link">
                <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
            </a>
            <button type="button" class="sidebar-notif" id="bellBtn" title="Notifications" aria-label="Notifications" aria-haspopup="dialog" aria-expanded="false" aria-controls="notifModal">
                <i class="fa-solid fa-bell"></i>
                <span class="sidebar-notif-badge" id="bellBadge" aria-hidden="true" style="display:none">0</span>
            </button>
        </div>
        <button class="sidebar-collapse-btn" id="sidebarCollapse" title="Collapse Sidebar">
            <i class="fa-solid fa-angles-left"></i>
        </button>
    </div>

    <div class="sidebar-nav">

    <?php if ($USER_ROLE === 'student'): ?>
        <!-- ══════════ STUDENT PORTAL MENU ══════════ -->
        <div class="sidebar-brand">
            <div class="brand-title">Navigation</div>
        </div>

        <a href="<?= $APP_ROOT ?>student/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-house-chimney"></i>
            <span class="sidebar-text">Dashboard</span>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Academics &amp; Records</div>
        </div>

        <a href="<?= $APP_ROOT ?>student/profile.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_profile' ? 'active' : '' ?>">
            <i class="fa-solid fa-id-card-clip"></i>
            <span class="sidebar-text">My Profile</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/grades.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_grades' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-simple"></i>
            <span class="sidebar-text">Grades &amp; History</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/academic-records.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_academic' ? 'active' : '' ?>">
            <i class="fa-solid fa-graduation-cap"></i>
            <span class="sidebar-text">Academic Records</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/documents.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_documents' ? 'active' : '' ?>">
            <i class="fa-solid fa-folder-closed"></i>
            <span class="sidebar-text">Document Requests</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/ids.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_ids' ? 'active' : '' ?>">
            <i class="fa-solid fa-id-badge"></i>
            <span class="sidebar-text">Digital ID Cards</span>
        </a>

        <a href="<?= $APP_ROOT ?>student/contacts.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_contacts' ? 'active' : '' ?>">
            <i class="fa-solid fa-people-roof"></i>
            <span class="sidebar-text">Emergency &amp; Contacts</span>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Services &amp; AI</div>
        </div>

        <a href="<?= $APP_ROOT ?>student/queue.php" class="sidebar-item <?= $ACTIVE_NAV === 'student_queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-ticket"></i>
            <span class="sidebar-text">Live Queue System</span>
        </a>

        <button type="button" class="sidebar-item ai-trigger-item" onclick="if(window.toggleStudentChat) window.toggleStudentChat();">
            <i class="fa-solid fa-wand-magic-sparkles" style="color: #60a5fa;"></i>
            <span class="sidebar-text">AI Registrar Assistant</span>
            <span class="sidebar-badge-ai">AI</span>
        </button>

        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Account</div>
        </div>

        <a href="<?= $APP_ROOT ?>settings.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="sidebar-text">Account Settings</span>
        </a>

    </div><!-- end sidebar-nav -->

    <div class="sidebar-footer">
        <a href="<?= $APP_ROOT ?>logout.php" class="sidebar-item logout-link" id="logoutBtn" data-logout-url="<?= $APP_ROOT ?>logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="sidebar-text">Logout</span>
        </a>
    </div>

    <?php else: ?>

        <!-- GROUP 1 — Main Navigation -->
        <div class="sidebar-brand">
            <div class="brand-title">Main</div>
        </div>

        <a href="<?= $APP_ROOT ?>dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high"></i>
            <span class="sidebar-text">Dashboard</span>
        </a>

        <div class="sidebar-divider"></div>

        <!-- GROUP 2 — Registrar / Academic -->
        <?php
        $isStaffOrSuper   = in_array($USER_ROLE, ['super_admin', 'registrar_admin', 'staff'], true);
        $isAcadOrDeptHead = in_array($USER_ROLE, ['acad_officer', 'department_head'], true);
        $isAnyStaff       = $isStaffOrSuper || $isAcadOrDeptHead;
        $isSuperAdmin     = $USER_ROLE === 'super_admin';
        ?>
        <?php if ($isAnyStaff): ?>
        <div class="sidebar-brand">
            <div class="brand-title"><?= $isSuperAdmin || $isStaffOrSuper ? 'Registrar' : 'Academic Records' ?></div>
        </div>

        <?php if ($isStaffOrSuper): ?>
        <a href="<?= $APP_ROOT ?>registrar/students.php" class="sidebar-item <?= $ACTIVE_NAV === 'students' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-graduate"></i>
            <span class="sidebar-text">Students</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/guardians.php" class="sidebar-item <?= $ACTIVE_NAV === 'guardians' ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span class="sidebar-text">Guardians &amp; Contacts</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/rfid-cards.php" class="sidebar-item <?= $ACTIVE_NAV === 'rfid' ? 'active' : '' ?>">
            <i class="fa-solid fa-credit-card"></i>
            <span class="sidebar-text">RFID Cards</span>
        </a>


        <a href="<?= $APP_ROOT ?>registrar/rfid-scan-logs.php" class="sidebar-item <?= $ACTIVE_NAV === 'scanlogs' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span class="sidebar-text">Scan Logs</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/documents.php" class="sidebar-item <?= $ACTIVE_NAV === 'documents' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-lines"></i>
            <span class="sidebar-text">Documents</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/queue.php" class="sidebar-item <?= $ACTIVE_NAV === 'queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-display"></i>
            <span class="sidebar-text">Queue</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/masterlist.php" class="sidebar-item <?= $ACTIVE_NAV === 'masterlist' ? 'active' : '' ?>">
            <i class="fa-solid fa-table-list"></i>
            <span class="sidebar-text">Masterlist</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/file-storage.php" class="sidebar-item <?= $ACTIVE_NAV === 'filestorage' ? 'active' : '' ?>">
            <i class="fa-solid fa-folder-open"></i>
            <span class="sidebar-text">File Storage</span>
        </a>
        <?php endif; ?>

        <?php if ($isAcadOrDeptHead): ?>
        <a href="<?= $APP_ROOT ?>registrar/academic-dashboard.php?view=students" class="sidebar-item <?= $ACTIVE_NAV === 'students' ? 'active' : '' ?>"><i class="fa-solid fa-user-graduate"></i><span class="sidebar-text">Students</span></a>
        <a href="<?= $APP_ROOT ?>registrar/academic-dashboard.php?view=masterlist" class="sidebar-item <?= $ACTIVE_NAV === 'masterlist' ? 'active' : '' ?>"><i class="fa-solid fa-table-list"></i><span class="sidebar-text">Masterlist</span></a>
        <?php endif; ?>
        <?php if ($isAnyStaff): ?>
        <a href="<?= $APP_ROOT ?>registrar/status-tracker.php" class="sidebar-item <?= $ACTIVE_NAV === 'tracker' ? 'active' : '' ?>">
            <i class="fa-solid fa-arrows-rotate"></i>
            <span class="sidebar-text">Status Tracker</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/academic-history.php" class="sidebar-item <?= $ACTIVE_NAV === 'academic' ? 'active' : '' ?>">
            <i class="fa-solid fa-school"></i>
            <span class="sidebar-text">Academic History</span>
        </a>
        <?php endif; ?>

        <div class="sidebar-divider"></div>
        <?php endif; ?>

        <!-- GROUP 3 — AI Tools -->
        <?php if ($isAnyStaff): ?>
        <div class="sidebar-brand">
            <div class="brand-title">AI Tools</div>
        </div>

        <a href="<?= $APP_ROOT ?><?= $isAcadOrDeptHead ? 'registrar/academic-dashboard.php?view=analytics' : 'ai/insights.php' ?>" class="sidebar-item <?= $ACTIVE_NAV === 'insights' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i>
            <span class="sidebar-text"><?= $isAcadOrDeptHead ? 'Academic Reports' : 'Intelligent Analytics and Reports' ?></span>
        </a>
        <?php endif; ?>

        <?php if ($isAnyStaff): ?>
        <div class="sidebar-divider"></div>

        <div class="sidebar-brand">
            <div class="brand-title">Account</div>
        </div>

        <?php if (in_array($USER_ROLE, ['super_admin','registrar_admin','staff','acad_officer','department_head'], true)): ?>
        <a href="<?= $APP_ROOT ?>registrar/export-history.php" class="sidebar-item <?= $ACTIVE_NAV === 'export-history' ? 'active' : '' ?>"><i class="fa-solid fa-file-shield"></i><span class="sidebar-text">Export History</span></a>
        <?php endif; ?>
        <a href="<?= $APP_ROOT ?>settings.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span class="sidebar-text">Account Settings</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($USER_ROLE, ['super_admin','registrar_admin'], true)): ?>
        <a href="<?= $APP_ROOT ?>registrar/program-assignments.php" class="sidebar-item"><i class="fa-solid fa-user-check"></i><span class="sidebar-text">Program Assignments</span></a>
        <?php if ($USER_ROLE === 'registrar_admin'): ?>
        <a href="<?= $APP_ROOT ?>registrar/audit-logs.php" class="sidebar-item"><i class="fa-solid fa-file-shield"></i><span class="sidebar-text">Registrar Activity</span></a>
        <a href="<?= $APP_ROOT ?>registrar/users.php" class="sidebar-item"><i class="fa-solid fa-user-gear"></i><span class="sidebar-text">Staff Accounts</span></a>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($isSuperAdmin): ?>
        <div class="sidebar-divider"></div>

        <!-- GROUP 4 — System (super admin only) -->
        <div class="sidebar-brand">
            <div class="brand-title">System</div>
        </div>

        <a href="<?= $APP_ROOT ?>registrar/users.php" class="sidebar-item <?= $ACTIVE_NAV === 'users' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-gear"></i>
            <span class="sidebar-text">Users</span>
        </a>
        <a href="<?= $APP_ROOT ?>registrar/smtp-status.php" class="sidebar-item <?= $ACTIVE_NAV === 'smtp' ? 'active' : '' ?>">
            <i class="fa-solid fa-envelope-circle-check"></i>
            <span class="sidebar-text">SMTP Status</span>
        </a>

        <a href="<?= $APP_ROOT ?>registrar/audit-logs.php" class="sidebar-item <?= $ACTIVE_NAV === 'audit' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-shield"></i>
            <span class="sidebar-text">Audit Logs</span>
        </a>
        <?php endif; ?>

    </div><!-- end sidebar-nav -->

    <div class="sidebar-footer">
        <a href="<?= $APP_ROOT ?>logout.php" class="sidebar-item logout-link" id="logoutBtn" data-logout-url="<?= $APP_ROOT ?>logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="sidebar-text">Logout</span>
        </a>
    </div>

    <?php endif; ?>
</aside>

<!-- Logout Confirmation Modal -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h3 class="logout-modal-title">Confirm Logout</h3>
        <p class="logout-modal-message">Are you sure you want to sign out of your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="logout-btn-cancel" id="logoutCancel">Cancel</button>
            <button type="button" class="logout-btn-confirm" id="logoutConfirm">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= $APP_ROOT ?>css/notifications.css?v=<?= filemtime(__DIR__.'/../css/notifications.css') ?>">
<div id="notifModal" class="notification-overlay" hidden>
<section class="notification-panel" role="dialog" aria-modal="true" aria-labelledby="notifHeading" tabindex="-1">
<header class="notification-header"><div><span class="notification-eyebrow">ACTIVITY CENTER</span><h2 id="notifHeading">Notifications <span id="notifCount">0</span></h2></div><button type="button" id="notifClose" aria-label="Close notifications">&#215;</button></header>
<p class="notification-description">Your records, requests, and account activity.</p>
<nav class="notification-toolbar" aria-label="Notification filters"><div><button type="button" data-notif-filter="all" aria-pressed="true">All</button><button type="button" data-notif-filter="unread" aria-pressed="false">Unread</button></div><button type="button" id="notifRefresh" aria-label="Refresh notifications"><i class="fa-solid fa-rotate" aria-hidden="true"></i></button></nav>
<p id="notifStatus" role="status"></p><div id="notifList" aria-label="Recent notifications"></div>
<footer class="notification-footer"><span>Showing latest 50 updates</span><button type="button" id="notifMarkRead">Mark all as read</button></footer>
</section></div>
<script src="<?= $APP_ROOT ?>js/notifications.js?v=<?= filemtime(__DIR__.'/../js/notifications.js') ?>" defer data-feed="<?= $APP_ROOT ?>api/<?= $USER_ROLE === 'student' ? 'student-notifications.php' : 'notifications.php' ?>"></script>

<!-- Styles -->
<style>
.logout-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(8px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.logout-modal-overlay.active { display: flex; }
.logout-modal {
    background: white;
    border-radius: 24px;
    padding: 36px 40px 32px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 64px rgba(0,0,0,0.15);
    animation: slideUp 0.3s ease;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.logout-modal-icon {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: #fee2e2;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 28px;
    color: #dc2626;
}
.logout-modal-title {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
}
.logout-modal-message {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 24px;
}
.logout-modal-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
.logout-modal-actions button {
    padding: 10px 28px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
}
.logout-btn-cancel {
    background: #f1f5f9;
    color: #64748b;
}
.logout-btn-cancel:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.logout-btn-confirm {
    background: #dc2626;
    color: white;
    display: flex;
    align-items: center;
    gap: 8px;
}
.logout-btn-confirm:hover {
    background: #b91c1c;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(220,38,38,0.3);
}
@media (max-width: 480px) {
    .logout-modal { padding: 24px 20px; }
    .logout-modal-actions { flex-direction: column; }
    .logout-modal-actions button { width: 100%; justify-content: center; }
}

/* ── Notification Items ── */
.notif-item {
    display: flex;
    gap: 12px;
    padding: 12px;
    border-radius: 10px;
    background: #f8fafc;
    margin-bottom: 8px;
    transition: background 0.2s;
}
.notif-item:hover { background: #f1f5f9; }
.notif-item.unread { border-left: 3px solid #2563eb; }
.notif-icon {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #dbeafe;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 14px;
}
.notif-content { flex: 1; }
.notif-title { font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 2px; }
.notif-message { font-size: 12px; color: #64748b; line-height: 1.4; }
.notif-time { font-size: 11px; color: #94a3b8; margin-top: 4px; }
</style>

<!-- Logout modal wiring moved to js/logout.js -->
