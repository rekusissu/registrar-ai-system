<?php
// ============================================================
//  REGISTRAR/DOCUMENTS-ARCHIVE.PHP
//  Archived document requests (gen-2)
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$documents = $db->fetchAll("
    SELECT
        dr.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number
    FROM document_requests dr
    LEFT JOIN students s ON dr.student_id = s.id
    WHERE dr.status IN ('approved', 'completed', 'released', 'denied')
    ORDER BY dr.id DESC
");

$page_title = 'Archive';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main">
<div class="dashboard-container">

<header class="header">
    <div class="title">
        <h1>Document Archive</h1>
        <p>Completed and processed document requests</p>
    </div>
    <div class="header-actions">
        <a href="documents.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Requests</a>
    </div>
</header>

<div class="panel">
    <div class="table-responsive" style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Student</th>
                <th>Document Type</th>
                <th>Status</th>
                <th>Processed Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($documents)): ?>
                <tr><td colspan="4" class="empty-state"><i class="fas fa-archive"></i><p>No archived documents</p><span>Processed requests will appear here</span></td></tr>
            <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td><div class="student-info"><div class="student-avatar gray"><?= htmlspecialchars(strtoupper(substr($doc['student_name'], 0, 1))) ?></div><div><div class="student-name"><?= htmlspecialchars($doc['student_name']) ?></div><div class="student-sub"><?= htmlspecialchars($doc['student_number']) ?></div></div></div></td>
                        <td><span class="chip blue"><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></span></td>
                        <td><span class="pill <?= htmlspecialchars($doc['status']) ?>"><?= ucfirst($doc['status']) ?></span></td>
                        <td style="font-size:12px;color:#64748b;"><?= $doc['processed_date'] ? date('M d, Y', strtotime($doc['processed_date'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong><?= count($documents) ?></strong> archived request<?= count($documents) === 1 ? '' : 's' ?></div>
    </div>
</div>

</div>
</main>

<?php include '../includes/footer.php'; ?>
