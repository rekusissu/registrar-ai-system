<?php
// ============================================================
//  STUDENT/GRADES.PHP
//  Student's own academic history — per term, subjects, grades.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Grades &amp; Academic History';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_grades';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

$terms = $db->fetchAll(
    "SELECT * FROM academic_history
     WHERE student_id = ?
     ORDER BY COALESCE(school_year, ''), COALESCE(semester, '')",
    [$student['id']]
);

// Fetch all grades for the student's terms in one pass
$gradeRows = [];
if ($terms) {
    $termIds = array_column($terms, 'id');
    $placeholders = implode(',', array_fill(0, count($termIds), '?'));
    $gradeRows = $db->fetchAll(
        "SELECT * FROM academic_grades WHERE academic_history_id IN ($placeholders) ORDER BY id ASC",
        $termIds
    );
}
$gradesByTerm = [];
foreach ($gradeRows as $g) {
    $gradesByTerm[$g['academic_history_id']][] = $g;
}

// Summary
$totalUnits = 0;
$gradeValues = [];
foreach ($gradeRows as $g) {
    $totalUnits += (float)($g['units'] ?? 0);
    $gv = is_numeric($g['grade'] ?? null) ? (float)$g['grade'] : null;
    if ($gv !== null && $gv <= 3.0 && $gv >= 1.0) $gradeValues[] = $gv;
}
$avgGrade = count($gradeValues) ? number_format(array_sum($gradeValues) / count($gradeValues), 2) : '—';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>Grades &amp; Academic History</h1><p>Your academic record as maintained by the Registrar.</p></div>
        </header>

        <?php if (empty($terms)): ?>
            <div class="panel">
                <div class="empty-state"><i class="fa-solid fa-book-open"></i><p>No academic history on file yet</p><span>Records will appear here once the Registrar publishes your grades.</span></div>
            </div>
        <?php else: ?>

        <div class="status-strip">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-layers"></i></div>
                <div>
                    <div class="s-value"><?= count($terms) ?></div>
                    <div class="s-label">Terms / Semesters</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-book"></i></div>
                <div>
                    <div class="s-value"><?= count($gradeRows) ?></div>
                    <div class="s-label">Subjects Recorded</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon purple"><i class="fa-solid fa-calculator"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars($avgGrade) ?></div>
                    <div class="s-label">Average Grade</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon yellow"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <div class="s-value"><?= number_format($totalUnits, 1) ?></div>
                    <div class="s-label">Total Units</div>
                </div>
            </div>
        </div>

        <?php foreach ($terms as $term): ?>
        <div class="panel" style="margin-bottom:20px;">
            <div class="panel-toolbar">
                <div class="panel-title">
                    <i class="fa-solid fa-school" style="color:#2563eb;"></i>
                    <?= htmlspecialchars($term['school_name'] ?? 'School') ?>
                </div>
                <div class="panel-actions">
                    <?php if ($term['school_year']): ?><span class="chip blue"><i class="fa-solid fa-calendar-days"></i> <?= htmlspecialchars($term['school_year']) ?></span><?php endif; ?>
                    <?php if ($term['semester']): ?><span class="chip gray"><?= htmlspecialchars($term['semester']) ?></span><?php endif; ?>
                    <?php if ($term['grade_level']): ?><span class="chip gray"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($term['grade_level']) ?></span><?php endif; ?>
                    <?php if ($term['gwa'] !== null): ?><span class="chip green"><i class="fa-solid fa-star"></i> GWA <?= htmlspecialchars($term['gwa']) ?></span><?php endif; ?>
                </div>
            </div>

            <?php if (empty($gradesByTerm[$term['id']] ?? [])): ?>
                <div class="empty-state"><i class="fa-solid fa-book-open"></i><p>No subject grades recorded for this term</p></div>
            <?php else: ?>
                <div class="table-responsive" style="overflow-x:auto;">
                    <table class="table">
                        <thead>
                            <tr><th>Subject</th><th>Units</th><th>Grade</th><th>Remarks</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradesByTerm[$term['id']] as $g): ?>
                            <tr>
                                <td><div style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($g['subject']) ?></div></td>
                                <td><?= htmlspecialchars((string)$g['units']) ?></td>
                                <td>
                                    <?php
                                    $gm = strtolower((string)($g['grade'] ?? ''));
                                    $pcls = in_array($gm, ['f', '5.0', '4.0', 'inc', 'ng']) ? 'inactive' : 'active';
                                    ?>
                                    <span class="pill <?= $pcls ?>"><?= htmlspecialchars($g['grade'] ?? '—') ?></span>
                                </td>
                                <td style="font-size:13px;color:#64748b;"><?= htmlspecialchars($g['remarks'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($term['credits']) || !empty($term['subjects_completed']) || !empty($term['remarks'])): ?>
            <div style="padding:12px 20px;background:#fafcfe;border-top:1px solid #e8edf4;font-size:13px;color:#64748b;">
                <?php if (!empty($term['credits'])): ?><span style="margin-right:16px;"><i class="fa-solid fa-coins" style="color:#b45309;"></i> Credits: <strong style="color:#1e293b;"><?= htmlspecialchars($term['credits']) ?></strong></span><?php endif; ?>
                <?php if (!empty($term['subjects_completed'])): ?><span><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Subjects completed: <strong style="color:#1e293b;"><?= htmlspecialchars($term['subjects_completed']) ?></strong></span><?php endif; ?>
                <?php if (!empty($term['remarks'])): ?><div style="margin-top:6px;"><i class="fa-solid fa-note-sticky" style="color:#7c3aed;"></i> <?= nl2br(htmlspecialchars($term['remarks'])) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    </div>
</main>

<?php include '../includes/footer.php'; ?>