<?php
// ============================================================
//  REGISTRAR/DOCUMENTS-ARCHIVE.PHP
//  Archived document requests
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

<main class="main">
    <header class="header">
        <div class="title">
            <h1>Document Archive</h1>
            <p>Completed and processed document requests</p>
        </div>
        <div class="header-actions">
            <a href="documents.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Requests
            </a>
        </div>
    </header>

    <div class="table-container">
        <div class="table-wrapper">
            <table>
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
                        <tr>
                            <td colspan="4" class="text-center py-12 text-gray-500">
                                <i class="fas fa-archive text-5xl block mb-3 text-gray-300"></i>
                                <p class="text-lg font-medium text-gray-400">No archived documents</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <div>
                                        <div class="font-medium"><?= htmlspecialchars($doc['student_name']) ?></div>
                                        <div class="text-gray-400 text-sm"><?= htmlspecialchars($doc['student_number']) ?></div>
                                    </div>
                                </td>
                                <td><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= $doc['status'] === 'approved' ? 'success' : ($doc['status'] === 'denied' ? 'danger' : 'success') ?>">
                                        <?= ucfirst($doc['status']) ?>
                                    </span>
                                </td>
                                <td><?= $doc['processed_date'] ? date('M d, Y', strtotime($doc['processed_date'])) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>