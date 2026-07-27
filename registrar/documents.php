<?php
// ============================================================
//  REGISTRAR/DOCUMENTS.PHP
//  Document requests management
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$documents = $db->fetchAll("
    SELECT 
        dr.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number
    FROM document_requests dr
    LEFT JOIN students s ON dr.student_id = s.id
    ORDER BY dr.id DESC
");

$page_title = 'Document Requests';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1>Document Requests</h1>
            <p>Manage student document requests</p>
        </div>
        <div class="header-actions">
            <a href="documents-add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Request
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
                        <th>Purpose</th>
                        <th>Request Date</th>
                        <th>Status</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-12 text-gray-500">
                                <i class="fas fa-file-lines text-5xl block mb-3 text-gray-300"></i>
                                <p class="text-lg font-medium text-gray-400">No document requests found</p>
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
                                <td><?= htmlspecialchars($doc['purpose'] ?? '—') ?></td>
                                <td><?= date('M d, Y', strtotime($doc['request_date'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= $doc['status'] === 'approved' ? 'success' : ($doc['status'] === 'denied' ? 'danger' : ($doc['status'] === 'completed' ? 'success' : 'warning')) ?>">
                                        <?= ucfirst($doc['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <button class="action-btn view" onclick="alert('View document <?= $doc['id'] ?>')" title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($doc['status'] === 'pending'): ?>
                                            <button class="action-btn edit" onclick="alert('Process document <?= $doc['id'] ?>')" title="Process">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="action-btn delete" onclick="alert('Delete document <?= $doc['id'] ?>')" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>