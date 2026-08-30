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
$body_page = 'dashboard';
$userName = $_SESSION['full_name'] ?? 'Admin User';
$userRole = $_SESSION['role'] ?? 'Registrar';
$extra_css = ['dashboard.css', 'queue.css'];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <!-- Premium Hero Section with Banner -->
        <div style="background: linear-gradient(135deg, #1a3a8c 0%, #2563eb 100%); background-image: url('./assets/images/bestlink%20banner.jpg'); background-size: cover; background-position: center; background-blend-mode: overlay; border-radius: 20px; padding: 42px 48px; margin-bottom: 32px; box-shadow: 0 20px 60px rgba(26, 58, 140, 0.35); overflow: hidden; position: relative;">
            <!-- Decorative gradient element -->
            <div style="position: absolute; right: -100px; top: -100px; width: 400px; height: 400px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%); z-index: 0;"></div>

            <!-- Content -->
            <div style="position: relative; z-index: 1;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.2px; color: #fff; font-weight: 800; margin-bottom: 6px; text-shadow: 0 4px 12px rgba(0,0,0,0.6), 0 2px 4px rgba(0,0,0,0.8);">Registrar Portal</div>
                <h1 style="font-size: 36px; font-weight: 900; letter-spacing: -1px; margin: 0 0 12px; line-height: 1.1; color: #fff; text-shadow: 0 6px 16px rgba(0,0,0,0.7), 0 3px 8px rgba(0,0,0,0.9);">Welcome back, <span style="color: #fbbf24; font-weight: 900; letter-spacing: -0.5px; font-size: 32px; text-shadow: 0 6px 16px rgba(0,0,0,0.7), 0 3px 8px rgba(0,0,0,0.9);"><?= htmlspecialchars($userName) ?></span></h1>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.5); color: #fff; border-radius: 20px; font-size: 11px; font-weight: 800; backdrop-filter: blur(12px); text-shadow: 0 2px 6px rgba(0,0,0,0.5); box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
                        <i class="fa-solid fa-shield-check" style="font-size: 14px;"></i> <?= htmlspecialchars($userRole) ?>
                    </span>
                    <span style="font-size: 12px; color: #fff; font-weight: 700; text-shadow: 0 2px 6px rgba(0,0,0,0.5);">
                        <i class="fa-solid fa-calendar-days" style="margin-right: 6px; font-size: 13px;"></i> <?= date('M d, Y') ?>
                    </span>
                </div>
            </div>
        </div>

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

        <!-- Advanced Analytics Section -->
        <div class="chart-grid dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-chart-line"></i> Enrollment Trend</div>
                        <div class="card-subtitle">Track monthly growth patterns</div>
                    </div>
                    <span class="card-badge">Last 6 Months</span>
                </div>
                <div class="card-body">
                    <canvas id="enrollmentChart" data-labels='<?= json_encode($months) ?>' data-data='<?= json_encode($monthCounts) ?>'></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-chart-pie"></i> Course Distribution</div>
                        <div class="card-subtitle">Top 5 courses by enrollment</div>
                    </div>
                    <span class="card-badge">Top 5</span>
                </div>
                <div class="card-body">
                    <canvas id="courseChart" data-labels='<?= json_encode($courseLabels) ?>' data-data='<?= json_encode($courseCounts) ?>'></canvas>
                </div>
            </div>
        </div>

        <!-- Premium Analytics Grid -->
        <div class="chart-grid-3 dashboard-section">
            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-users-check"></i> Student Status Overview</div>
                        <div class="card-subtitle">Real-time distribution</div>
                    </div>
                </div>
                <div class="status-distribution">
                    <?php
                    $total = max(array_sum($statusData), 1);
                    $colors = ['active' => 'green', 'at-risk' => 'red', 'probation' => 'yellow', 'graduated' => 'blue'];
                    $labels = ['active' => 'Active', 'at-risk' => 'At Risk', 'probation' => 'Probation', 'graduated' => 'Graduated'];
                    $icons = ['active' => 'fa-check-circle', 'at-risk' => 'fa-exclamation-triangle', 'probation' => 'fa-clock', 'graduated' => 'fa-graduation-cap'];
                    $iconColors = ['active' => '#16a34a', 'at-risk' => '#dc2626', 'probation' => '#b45309', 'graduated' => '#2563eb'];
                    ?>
                    <?php foreach ($statusData as $key => $value): ?>
                        <div class="status-row">
                            <div class="status-left">
                                <i class="fas <?= $icons[$key] ?? 'fa-circle' ?>" style="color: <?= $iconColors[$key] ?? '#64748b' ?>;"></i>
                                <div class="status-info">
                                    <div class="status-label"><?= $labels[$key] ?? ucfirst($key) ?></div>
                                    <div class="status-count"><?= number_format($value) ?> students</div>
                                </div>
                            </div>
                            <div class="status-right">
                                <div class="status-percent"><?= round(($value / $total) * 100) ?>%</div>
                                <div class="status-bar">
                                    <div class="status-fill <?= $colors[$key] ?? 'blue' ?>" style="width: <?= round(($value / $total) * 100) ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-credit-card"></i> RFID & Documents</div>
                        <div class="card-subtitle">System resources status</div>
                    </div>
                </div>
                <div class="resource-grid">
                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">RFID Cards</div>
                            <div class="resource-stat"><?= number_format($activeCards) ?>/<?= number_format($totalCards) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= round(($activeCards / max($totalCards, 1)) * 100) ?>%; background: linear-gradient(90deg, #2563eb, #60a5fa);"></div>
                        </div>
                        <div class="resource-footer"><?= round(($activeCards / max($totalCards, 1)) * 100) ?>% Active</div>
                    </div>

                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">Expired Cards</div>
                            <div class="resource-stat"><?= number_format($expiredCards) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= round(($expiredCards / max($totalCards, 1)) * 100) ?>%; background: linear-gradient(90deg, #b45309, #fbbf24);"></div>
                        </div>
                        <div class="resource-footer">Requires renewal</div>
                    </div>

                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">Pending Documents</div>
                            <div class="resource-stat"><?= number_format($pendingDocs) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= $pendingDocs > 0 ? min(100, $pendingDocs * 10) : 0 ?>%; background: linear-gradient(90deg, #7c3aed, #a78bfa);"></div>
                        </div>
                        <div class="resource-footer">In processing</div>
                    </div>

                    <div class="resource-item">
                        <div class="resource-header">
                            <div class="resource-label">Approved Documents</div>
                            <div class="resource-stat"><?= number_format($approvedDocs) ?></div>
                        </div>
                        <div class="resource-bar">
                            <div class="resource-fill" style="width: <?= $approvedDocs > 0 ? min(100, $approvedDocs * 5) : 0 ?>%; background: linear-gradient(90deg, #16a34a, #4ade80);"></div>
                        </div>
                        <div class="resource-footer">Ready for pickup</div>
                    </div>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="fas fa-gauge"></i> Key Performance Metrics</div>
                        <div class="card-subtitle">System health indicators</div>
                    </div>
                </div>
                <div class="metrics-container">
                    <div class="metric-box">
                        <div class="metric-top">
                            <span class="metric-name">Enrollment Rate</span>
                            <span class="metric-badge active">Active</span>
                        </div>
                        <div class="metric-value"><?= round(($activeStudents / max($totalStudents, 1)) * 100) ?>%</div>
                        <div class="metric-track">
                            <div class="metric-progress" style="width: <?= round(($activeStudents / max($totalStudents, 1)) * 100) ?>%; background: #2563eb;"></div>
                        </div>
                    </div>

                    <div class="metric-box">
                        <div class="metric-top">
                            <span class="metric-name">Graduation Rate</span>
                            <span class="metric-badge success">Complete</span>
                        </div>
                        <div class="metric-value"><?= round(($graduatedStudents / max($totalStudents, 1)) * 100) ?>%</div>
                        <div class="metric-track">
                            <div class="metric-progress" style="width: <?= round(($graduatedStudents / max($totalStudents, 1)) * 100) ?>%; background: #7c3aed;"></div>
                        </div>
                    </div>

                    <div class="metric-box">
                        <div class="metric-top">
                            <span class="metric-name">Card Active Rate</span>
                            <span class="metric-badge info">Operational</span>
                        </div>
                        <div class="metric-value"><?= round(($activeCards / max($totalCards, 1)) * 100) ?>%</div>
                        <div class="metric-track">
                            <div class="metric-progress" style="width: <?= round(($activeCards / max($totalCards, 1)) * 100) ?>%; background: #16a34a;"></div>
                        </div>
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

        <!-- Live Queue -->
        <div class="chart-card dashboard-section">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-display" style="color: #2563eb;"></i> Live Queue</div>
                <a href="registrar/queue.php" class="card-badge" style="text-decoration:none;cursor:pointer;">Open Console <i class="fas fa-arrow-right" style="font-size:11px;"></i></a>
            </div>
            <div class="live-queue-widget" id="liveQueueWidget">
                <div style="color:#94a3b8;font-size:14px;padding:12px 2px;">Loading…</div>
            </div>
        </div>

    </div>
</main>

<?php
$page_scripts = ['dashboard.js', 'queue.js'];
$use_chart = true;
include 'includes/footer.php';
?>