<?php
// ============================================================
//  STUDENT/ANNOUNCEMENTS.PHP
//  Student-facing announcements list (read-only).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Announcements';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_announcements';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$announcements = $db->fetchAll(
    "SELECT a.*, u.full_name AS author_name
     FROM announcements a
     LEFT JOIN users u ON u.id = a.author_id
     WHERE a.is_published = 1
     ORDER BY a.created_at DESC"
);
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>Announcements</h1><p>Updates from the Registrar's Office.</p></div>
        </header>

        <?php if (empty($announcements)): ?>
            <div class="panel">
                <div class="empty-state"><i class="fa-solid fa-bullhorn"></i><p>No announcements yet</p><span>Updates from the Registrar will appear here.</span></div>
            </div>
        <?php else: ?>
            <div style="max-width:860px;">
            <?php foreach ($announcements as $a): ?>
                <div class="announcement-card">
                    <div class="a-head">
                        <h3 class="a-title"><?= htmlspecialchars($a['title']) ?></h3>
                        <span class="a-date"><i class="fa-solid fa-clock"></i> <?= date('M d, Y h:i A', strtotime($a['created_at'])) ?></span>
                    </div>
                    <?php if ($a['body'] !== null && trim($a['body']) !== ''): ?>
                    <div class="a-body"><?= nl2br(htmlspecialchars($a['body'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($a['author_name'])): ?>
                    <div class="a-by">
                        <span class="student-avatar blue" style="width:28px;height:28px;font-size:12px;"><?= strtoupper(substr(htmlspecialchars(trim($a['author_name'])), 0, 1)) ?></span>
                        Posted by <?= htmlspecialchars($a['author_name']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<?php include '../includes/footer.php'; ?>