<?php
// ============================================================
//  REGISTRAR/MASTERLIST.PHP
//  Masterlist generator
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$students = $db->fetchAll("SELECT * FROM students ORDER BY course, year_level, last_name");

$page_title = 'Masterlist';
$APP_ROOT = '../';
$ACTIVE_NAV = 'masterlist';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1>Masterlist</h1>
            <p>Generate student masterlist</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-primary" onclick="exportCSV()">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
        </div>
    </header>

    <div class="card">
        <div style="overflow-x: auto;" id="masterlistContent">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: #1a2d4a; color: white;">
                        <th style="padding: 10px 12px; text-align: left;">#</th>
                        <th style="padding: 10px 12px; text-align: left;">Student ID</th>
                        <th style="padding: 10px 12px; text-align: left;">Name</th>
                        <th style="padding: 10px 12px; text-align: left;">Course</th>
                        <th style="padding: 10px 12px; text-align: left;">Year</th>
                        <th style="padding: 10px 12px; text-align: left;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-12 text-gray-500">No students found</td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($students as $student): ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 8px 12px;"><?= $i++ ?></td>
                                <td style="padding: 8px 12px;"><?= htmlspecialchars($student['student_number']) ?></td>
                                <td style="padding: 8px 12px;"><?= htmlspecialchars($student['last_name']) ?>, <?= htmlspecialchars($student['first_name']) ?></td>
                                <td style="padding: 8px 12px;"><?= htmlspecialchars($student['course'] ?? 'N/A') ?></td>
                                <td style="padding: 8px 12px;"><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?></td>
                                <td style="padding: 8px 12px;">
                                    <span class="badge badge-<?= $student['status'] === 'active' ? 'success' : 'warning' ?>">
                                        <?= ucfirst($student['status'] ?? 'Active') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <div class="info-text">Total: <strong><?= count($students) ?></strong> students</div>
        </div>
    </div>
</main>

<script>
function exportCSV() {
    const rows = document.querySelectorAll('#masterlistContent table tbody tr');
    let csv = 'Student ID,Name,Course,Year,Status\n';
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length >= 5) {
            const studentId = cols[1]?.textContent.trim() || '';
            const name = cols[2]?.textContent.trim() || '';
            const course = cols[3]?.textContent.trim() || '';
            const year = cols[4]?.textContent.trim() || '';
            const status = cols[5]?.textContent.trim() || '';
            csv += `"${studentId}","${name}","${course}","${year}","${status}"\n`;
        }
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'masterlist.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>