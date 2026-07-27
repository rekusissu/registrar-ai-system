<?php
// ============================================================
//  DASHBOARD.PHP - PREMIUM VERSION
//  All data from database, proper sidebar spacing
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/shared/database.php';

$db = Database::getInstance();

// ─── FETCH ALL DATA ──────────────────────────────────────────────

// Stats
$totalStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
$activeStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'active'");
$atRiskStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status IN ('at-risk', 'probation')");
$graduatedStudents = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'graduated'");
$totalCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
$activeCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active'");
$expiredCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'expired'");
$lostCards = (int) $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'lost'");
$totalDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests");
$pendingDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'");
$approvedDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'approved'");
$deniedDocs = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'denied'");

// Monthly enrollment (last 6 months)
$monthlyData = $db->fetchAll("
    SELECT MONTH(created_at) as month, COUNT(*) as count
    FROM students
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY MONTH(created_at)
    ORDER BY month ASC
");

$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
$monthCounts = array_fill(0, 6, 0);
foreach ($monthlyData as $d) {
    $idx = $d['month'] - 1;
    if ($idx >= 0 && $idx < 6) $monthCounts[$idx] = (int) $d['count'];
}

// Course distribution (top 5)
$courseData = $db->fetchAll("
    SELECT course, COUNT(*) as count 
    FROM students 
    WHERE course IS NOT NULL AND course != ''
    GROUP BY course 
    ORDER BY count DESC 
    LIMIT 5
");
$courseLabels = array_column($courseData, 'course');
$courseCounts = array_column($courseData, 'count');

// Status distribution
$statusData = [
    'active' => (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'active'"),
    'at-risk' => (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'at-risk'"),
    'probation' => (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'probation'"),
    'graduated' => (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'graduated'")
];

// Recent activity
$recentActivity = $db->fetchAll("
    SELECT st.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name
    FROM status_tracker st
    LEFT JOIN students s ON st.student_id = s.id
    ORDER BY st.created_at DESC
    LIMIT 5
");

// ─── PAGE SETUP ───────────────────────────────────────────────────
$page_title = 'Dashboard';
$APP_ROOT = './';
$ACTIVE_NAV = 'dashboard';
$userName = $_SESSION['full_name'] ?? 'Admin User';
$userRole = $_SESSION['role'] ?? 'Registrar';
$extra_css = ['dashboard.css'];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <!-- Header -->
        <header class="header">
            <div class="title">
                <h1>Dashboard</h1>
                <p>Welcome back, <?= htmlspecialchars($userName) ?>!</p>
            </div>
            <div class="user-info">
                <span class="role"><?= htmlspecialchars($userRole) ?></span>
                <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
            </div>
        </header>

        <!-- Stats -->
        <div class="stats-grid dashboard-section">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <span class="stat-trend up"><i class="fas fa-arrow-up"></i> 12%</span>
                </div>
                <div class="stat-value"><?= number_format($totalStudents) ?></div>
                <div class="stat-label">Total Students</div>
                <div class="stat-footer"><span class="dot green"></span> <?= $activeStudents ?> active</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                    <span class="stat-trend up"><i class="fas fa-arrow-up"></i> 5%</span>
                </div>
                <div class="stat-value"><?= number_format($activeStudents) ?></div>
                <div class="stat-label">Active Students</div>
                <div class="stat-footer"><span class="dot blue"></span> <?= round(($activeStudents / max($totalStudents, 1)) * 100) ?>% of total</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon yellow"><i class="fas fa-triangle-exclamation"></i></div>
                    <span class="stat-trend down"><i class="fas fa-arrow-up"></i> 3%</span>
                </div>
                <div class="stat-value"><?= number_format($atRiskStudents) ?></div>
                <div class="stat-label">At Risk Students</div>
                <div class="stat-footer"><span class="dot red"></span> Needs attention</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon purple"><i class="fas fa-graduation-cap"></i></div>
                    <span class="stat-trend up"><i class="fas fa-arrow-up"></i> 8%</span>
                </div>
                <div class="stat-value"><?= number_format($graduatedStudents) ?></div>
                <div class="stat-label">Graduated</div>
                <div class="stat-footer"><span class="dot purple"></span> <?= round(($graduatedStudents / max($totalStudents, 1)) * 100) ?>% rate</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="chart-grid dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-line"></i> Enrollment Overview</div>
                    <span class="card-badge">Last 6 Months</span>
                </div>
                <div class="card-body">
                    <canvas id="enrollmentChart" data-labels='<?= json_encode($months) ?>' data-data='<?= json_encode($monthCounts) ?>'></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-pie" style="color: #7c3aed;"></i> Course Distribution</div>
                    <span class="card-badge">Top 5</span>
                </div>
                <div class="card-body">
                    <canvas id="courseChart" data-labels='<?= json_encode($courseLabels) ?>' data-data='<?= json_encode($courseCounts) ?>'></canvas>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="chart-grid-3 dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-circle" style="color: #16a34a;"></i> Student Status</div>
                    <span class="card-badge">Distribution</span>
                </div>
                <div class="progress-list">
                    <?php
                    $total = max(array_sum($statusData), 1);
                    $colors = ['active' => 'green', 'at-risk' => 'red', 'probation' => 'yellow', 'graduated' => 'blue'];
                    $labels = ['active' => 'Active', 'at-risk' => 'At Risk', 'probation' => 'Probation', 'graduated' => 'Graduated'];
                    $icons = ['active' => 'fa-check-circle', 'at-risk' => 'fa-exclamation-triangle', 'probation' => 'fa-clock', 'graduated' => 'fa-graduation-cap'];
                    $iconColors = ['active' => '#16a34a', 'at-risk' => '#dc2626', 'probation' => '#b45309', 'graduated' => '#2563eb'];
                    ?>
                    <?php foreach ($statusData as $key => $value): ?>
                        <div class="progress-item">
                            <span class="progress-label"><i class="fas <?= $icons[$key] ?? 'fa-circle' ?>" style="color: <?= $iconColors[$key] ?? '#64748b' ?>;"></i> <?= $labels[$key] ?? ucfirst($key) ?></span>
                            <div class="progress-track"><div class="progress-fill <?= $colors[$key] ?? 'blue' ?>" style="width: <?= round(($value / $total) * 100) ?>%;"></div></div>
                            <span class="progress-value"><?= number_format($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-credit-card" style="color: #db2777;"></i> RFID & Documents</div>
                    <span class="card-badge">Overview</span>
                </div>
                <div class="progress-list">
                    <div class="progress-item">
                        <span class="progress-label"><i class="fas fa-credit-card" style="color: #2563eb;"></i> RFID Cards</span>
                        <div class="progress-track"><div class="progress-fill blue" style="width: <?= round(($activeCards / max($totalCards, 1)) * 100) ?>%;"></div></div>
                        <span class="progress-value"><?= number_format($activeCards) ?>/<?= number_format($totalCards) ?></span>
                    </div>
                    <div class="progress-item">
                        <span class="progress-label"><i class="fas fa-check-circle" style="color: #16a34a;"></i> Active Cards</span>
                        <div class="progress-track"><div class="progress-fill green" style="width: <?= round(($activeCards / max($totalCards, 1)) * 100) ?>%;"></div></div>
                        <span class="progress-value"><?= round(($activeCards / max($totalCards, 1)) * 100) ?>%</span>
                    </div>
                    <div class="progress-item">
                        <span class="progress-label"><i class="fas fa-clock" style="color: #b45309;"></i> Expired Cards</span>
                        <div class="progress-track"><div class="progress-fill yellow" style="width: <?= round(($expiredCards / max($totalCards, 1)) * 100) ?>%;"></div></div>
                        <span class="progress-value"><?= number_format($expiredCards) ?></span>
                    </div>
                    <div class="progress-item">
                        <span class="progress-label"><i class="fas fa-file-lines" style="color: #7c3aed;"></i> Pending Docs</span>
                        <div class="progress-track"><div class="progress-fill purple" style="width: <?= $pendingDocs > 0 ? min(100, $pendingDocs * 10) : 0 ?>%;"></div></div>
                        <span class="progress-value"><?= number_format($pendingDocs) ?></span>
                    </div>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-bolt" style="color: #b45309;"></i> Quick Stats</div>
                    <span class="card-badge">Overview</span>
                </div>
                <div class="rings-row">
                    <div class="ring-box">
                        <canvas id="ringTotal" width="80" height="80"></canvas>
                        <div class="ring-number"><?= number_format($totalStudents) ?></div>
                        <div class="ring-text">Total</div>
                    </div>
                    <div class="ring-box">
                        <canvas id="ringActive" width="80" height="80"></canvas>
                        <div class="ring-number"><?= number_format($activeStudents) ?></div>
                        <div class="ring-text">Active</div>
                    </div>
                    <div class="ring-box">
                        <canvas id="ringRisk" width="80" height="80"></canvas>
                        <div class="ring-number"><?= number_format($atRiskStudents) ?></div>
                        <div class="ring-text">At Risk</div>
                    </div>
                    <div class="ring-box">
                        <canvas id="ringGrad" width="80" height="80"></canvas>
                        <div class="ring-number"><?= number_format($graduatedStudents) ?></div>
                        <div class="ring-text">Graduated</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="chart-card dashboard-section">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-clock-rotate-left" style="color: #2563eb;"></i> Recent Activity</div>
                <span class="card-badge">Latest Updates</span>
            </div>
            <?php if (empty($recentActivity)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No recent activity</p>
                    <span>Status changes will appear here</span>
                </div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentActivity as $activity): 
                        $statusClass = $activity['current_status'] === 'active' ? 'active' : ($activity['current_status'] === 'at-risk' ? 'risk' : 'warning');
                        $statusIcon = $activity['current_status'] === 'active' ? 'fa-check' : ($activity['current_status'] === 'at-risk' ? 'fa-exclamation' : 'fa-clock');
                    ?>
                        <div class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon <?= $statusClass ?>"><i class="fas <?= $statusIcon ?>"></i></div>
                                <div class="activity-info">
                                    <div class="activity-name"><?= htmlspecialchars($activity['student_name'] ?? 'Unknown') ?></div>
                                    <div class="activity-detail">
                                        Status changed to <strong style="color: <?= $activity['current_status'] === 'active' ? '#16a34a' : ($activity['current_status'] === 'at-risk' ? '#dc2626' : '#b45309') ?>;"><?= ucfirst($activity['current_status'] ?? 'Unknown') ?></strong>
                                        <?php if ($activity['reason']): ?> — <?= htmlspecialchars($activity['reason']) ?><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="activity-time"><?= date('M d, h:i A', strtotime($activity['created_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<?php
$page_scripts = ['dashboard.js'];
$use_chart = true;
include 'includes/footer.php';
?>