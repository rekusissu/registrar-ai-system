<?php
// ============================================================
//  STUDENT/_GUARD.PHP
//  Shared bootstrap for every student-portal page.
//  Requires a logged-in student (or admin), resolves the
//  caller's linked students.id, and exposes $student row.
//
//  Pages include this AFTER session config:
//    require_once __DIR__ . '/../shared/session_config.php';
//    require_once __DIR__ . '/../shared/database.php';
//    require_once __DIR__ . '/_guard.php';
//
//  This guard also renders the shared header + sidebar so every
//  student page inherits the exact same UI, page loader, and logo
//  as the rest of the system — no per-page include needed.
// ============================================================

requireStudent();

// Load shared helpers (getStudentStatusLabel, logActivity, etc.)
require_once __DIR__ . '/../shared/functions.php';

$db = Database::getInstance();

// The student's own record — resolved server-side from the linked
// users.student_id. Never trust any client-supplied id.
$student = null;
$studentId = getCurrentStudentId();
if ($studentId) {
    $student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]);
}

if (!$student) {
    // No linked student record — render a friendly notice instead of a fatal.
    $page_title = $page_title ?? 'Student Portal';
    $APP_ROOT = $APP_ROOT ?? '../';
    $ACTIVE_NAV = $ACTIVE_NAV ?? 'student_dashboard';
    include '../includes/header.php';
    include '../includes/sidebar.php';
    ?>
    <main class="dashboard-main">
        <div class="dashboard-container">
            <header class="header">
                <div class="title"><h1>No Student Record Linked</h1>
                    <p>Your account is not yet linked to a student record.</p>
                </div>
            </header>
            <div class="panel" style="padding:40px;text-align:center;">
                <i class="fa-solid fa-user-slash" style="font-size:48px;color:#94a3b8;"></i>
                <p style="margin-top:16px;color:#64748b;">Please contact the Registrar's Office to link your account to your student record.</p>
            </div>
        </div>
    </main>
    <?php
    include '../includes/footer.php';
    exit;
}

// ── Happy path: render the shared UI shell (logo, loader, CSS,
//    sidebar) exactly like the rest of the system. ─────────────
include '../includes/header.php';
include '../includes/sidebar.php';
