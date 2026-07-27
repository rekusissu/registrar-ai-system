<?php
// ============================================================
//  AI/INSIGHTS.PHP
//  AI-powered insights dashboard
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Get statistics for insights
$totalStudents = $db->fetchColumn("SELECT COUNT(*) FROM students");
$activeStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'active'");
$atRiskStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'at-risk' OR status = 'probation'");
$graduatedStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'graduated'");

$totalCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
$activeCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active'");
$expiredCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'expired'");

$pendingDocuments = $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'");
$totalDocuments = $db->fetchColumn("SELECT COUNT(*) FROM document_requests");

// Get recent status changes
$recentStatusChanges = $db->fetchAll("
    SELECT 
        st.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name
    FROM status_tracker st
    LEFT JOIN students s ON st.student_id = s.id
    ORDER BY st.created_at DESC
    LIMIT 5
");

// Get courses distribution
$courses = $db->fetchAll("
    SELECT course, COUNT(*) as count 
    FROM students 
    WHERE course IS NOT NULL AND course != ''
    GROUP BY course 
    ORDER BY count DESC
    LIMIT 5
");

$page_title = 'AI Insights';
$APP_ROOT = '../';
$ACTIVE_NAV = 'insights';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1><i class="fas fa-brain" style="color: var(--primary-500);"></i> AI Insights</h1>
            <p>Intelligent analytics and predictions</p>
        </div>
    </header>

    <!-- Stats Overview -->
    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-user-graduate" style="color: var(--primary-500);"></i>
                <span class="card-label">Total Students</span>
            </div>
            <div class="card-value" style="font-size: 28px;"><?= $totalStudents ?></div>
            <div class="card-sub">
                <span class="badge badge-success"><?= $activeStudents ?> Active</span>
                <span class="badge badge-danger"><?= $atRiskStudents ?> At Risk</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-credit-card" style="color: var(--primary-500);"></i>
                <span class="card-label">RFID Cards</span>
            </div>
            <div class="card-value" style="font-size: 28px;"><?= $totalCards ?></div>
            <div class="card-sub">
                <span class="badge badge-success"><?= $activeCards ?> Active</span>
                <span class="badge badge-danger"><?= $expiredCards ?> Expired</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-file-lines" style="color: var(--primary-500);"></i>
                <span class="card-label">Documents</span>
            </div>
            <div class="card-value" style="font-size: 28px;"><?= $totalDocuments ?></div>
            <div class="card-sub">
                <span class="badge badge-warning"><?= $pendingDocuments ?> Pending</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-graduation-cap" style="color: var(--primary-500);"></i>
                <span class="card-label">Graduated</span>
            </div>
            <div class="card-value" style="font-size: 28px;"><?= $graduatedStudents ?></div>
            <div class="card-sub">
                <span class="badge badge-primary"><?= round(($graduatedStudents / max($totalStudents, 1)) * 100) ?>% of total</span>
            </div>
        </div>
    </div>

    <!-- AI Insights Cards -->
    <div class="grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
        <!-- Course Distribution -->
        <div class="card">
            <h3 class="card-title"><i class="fas fa-chart-pie" style="color: var(--primary-500);"></i> Course Distribution</h3>
            <p class="card-subtitle">Top 5 courses by student count</p>
            <div style="margin-top: 16px;">
                <?php if (empty($courses)): ?>
                    <p class="text-gray-400">No course data available</p>
                <?php else: ?>
                    <?php foreach ($courses as $course): ?>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                            <span style="width: 120px; font-size: 13px; color: #1e293b;"><?= htmlspecialchars($course['course']) ?></span>
                            <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 9999px; overflow: hidden;">
                                <div style="height: 100%; width: <?= ($course['count'] / max($courses[0]['count'], 1)) * 100 ?>%; background: linear-gradient(90deg, #2563eb, #60a5fa); border-radius: 9999px;"></div>
                            </div>
                            <span style="font-weight: 600; font-size: 14px; color: #0f172a; min-width: 40px;"><?= $course['count'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- AI Predictions -->
        <div class="card" style="background: linear-gradient(135deg, #eef4ff, #dbeafe); border-color: #bfdbfe;">
            <h3 class="card-title"><i class="fas fa-robot" style="color: var(--primary-500);"></i> AI Predictions</h3>
            <div style="margin-top: 12px;">
                <div style="padding: 12px; background: white; border-radius: 10px; margin-bottom: 10px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 500;">At-Risk Students</span>
                        <span style="font-size: 20px; font-weight: 700; color: #dc2626;"><?= $atRiskStudents ?></span>
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <i class="fas fa-exclamation-triangle" style="color: #dc2626;"></i>
                        <?= $atRiskStudents > 0 ? 'Needs immediate attention' : 'All students are on track' ?>
                    </p>
                </div>
                <div style="padding: 12px; background: white; border-radius: 10px; margin-bottom: 10px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 500;">Expiring Cards</span>
                        <span style="font-size: 20px; font-weight: 700; color: #b45309;"><?= $expiredCards ?></span>
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <i class="fas fa-clock" style="color: #b45309;"></i>
                        <?= $expiredCards > 0 ? 'Renewal needed' : 'All cards are active' ?>
                    </p>
                </div>
                <div style="padding: 12px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 500;">Pending Documents</span>
                        <span style="font-size: 20px; font-weight: 700; color: #2563eb;"><?= $pendingDocuments ?></span>
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <i class="fas fa-file-lines" style="color: #2563eb;"></i>
                        <?= $pendingDocuments > 0 ? 'Process pending requests' : 'All documents processed' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <h3 class="card-title"><i class="fas fa-clock-rotate-left" style="color: var(--primary-500);"></i> Recent Status Changes</h3>
        <p class="card-subtitle">Latest student status updates</p>
        <div style="margin-top: 12px; overflow-x: auto;">
            <?php if (empty($recentStatusChanges)): ?>
                <p class="text-gray-400">No recent status changes</p>
            <?php else: ?>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Student</th>
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Previous</th>
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Current</th>
                            <th style="padding: 8px 12px; text-align: left; color: #64748b; font-weight: 600;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentStatusChanges as $change): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 8px 12px; font-weight: 500;"><?= htmlspecialchars($change['student_name'] ?? 'Unknown') ?></td>
                                <td style="padding: 8px 12px;">
                                    <?php if ($change['previous_status']): ?>
                                        <span class="badge badge-neutral"><?= ucfirst($change['previous_status']) ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 8px 12px;">
                                    <span class="badge badge-<?= $change['current_status'] === 'active' ? 'success' : ($change['current_status'] === 'at-risk' ? 'danger' : 'warning') ?>">
                                        <?= ucfirst($change['current_status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 8px 12px; color: #64748b; font-size: 13px;">
                                    <?= date('M d, Y h:i A', strtotime($change['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- AI Recommendations -->
    <div class="card" style="margin-top: 20px; background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-color: #86efac;">
        <h3 class="card-title"><i class="fas fa-lightbulb" style="color: #16a34a;"></i> AI Recommendations</h3>
        <div style="margin-top: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <?php if ($atRiskStudents > 0): ?>
                <div style="padding: 12px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <p style="font-weight: 500; color: #dc2626;"><i class="fas fa-triangle-exclamation"></i> <?= $atRiskStudents ?> students at risk</p>
                    <p style="font-size: 13px; color: #64748b; margin-top: 4px;">Schedule interventions and counseling sessions.</p>
                </div>
            <?php endif; ?>
            <?php if ($expiredCards > 0): ?>
                <div style="padding: 12px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <p style="font-weight: 500; color: #b45309;"><i class="fas fa-clock"></i> <?= $expiredCards ?> expired cards</p>
                    <p style="font-size: 13px; color: #64748b; margin-top: 4px;">Renew RFID cards for affected students.</p>
                </div>
            <?php endif; ?>
            <?php if ($pendingDocuments > 0): ?>
                <div style="padding: 12px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <p style="font-weight: 500; color: #2563eb;"><i class="fas fa-file-lines"></i> <?= $pendingDocuments ?> pending documents</p>
                    <p style="font-size: 13px; color: #64748b; margin-top: 4px;">Process pending document requests.</p>
                </div>
            <?php endif; ?>
            <?php if ($activeStudents > 0 && $totalStudents > 0 && ($activeStudents / $totalStudents) < 0.5): ?>
                <div style="padding: 12px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <p style="font-weight: 500; color: #2563eb;"><i class="fas fa-user-graduate"></i> Low active rate</p>
                    <p style="font-size: 13px; color: #64748b; margin-top: 4px;">Review student retention strategies.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>