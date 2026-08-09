<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Subsystem 8 — Student Status Tracker.
//  Full life-cycle timeline of every status change, filterable
//  by status / student, with effective & end dates.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

// Options for status filter
$statuses = ['active','probation','at-risk','loa','graduated','transferred','dropped','archived'];

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql = "SELECT st.*, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.course,
               u.full_name AS changed_by_name
        FROM status_tracker st
        LEFT JOIN students s ON st.student_id = s.id
        LEFT JOIN users u ON st.changed_by = u.id";
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $statuses, true)) {
    $sql .= " WHERE st.current_status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY st.created_at DESC, st.id DESC";
$tracker = $db->fetchAll($sql, $params);

// Stats
$counts = [];
foreach ($statuses as $st) {
    $counts[$st] = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = ?", [$st]);
}
$totalChanges = count($tracker);

// Students with LOA (have end_date info) — highlight
$loaCount = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'loa'");

$page_title = 'Status Tracker';
$APP_ROOT = '../';
$ACTIVE_NAV = 'tracker';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<style>
.timeline { position: relative; padding-left: 24px; }
.timeline::before { content:''; position:absolute; left:8px; top:4px; bottom:4px; width:2px; background:#e2e8f0; }
.tl-item { position: relative; margin-bottom:14px; background:#fafcfd; border:1px solid #f1f5f9; border-radius:10px; padding:12px 16px; }
.tl-item::before { content:''; position:absolute; left:-22px; top:16px; width:12px; height:12px; border-radius:50%; background:#fff; border:3px solid #2563eb; }
.tl-item.active::before { border-color:#16a34a; } .tl-item.at-risk::before { border-color:#dc2626; }
.tl-item.probation::before { border-color:#b45309; } .tl-item.graduated::before { border-color:#2563eb; }
.tl-item.loa::before { border-color:#7c3aed; } .tl-item.transferred::before { border-color:#db2777; }
.tl-item.dropped::before { border-color:#dc2626; } .tl-item.archived::before { border-color:#94a3b8; }
</style>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Student Status Tracker</h1>
                <p>Full life-cycle history of every status change</p>
            </div>
            <div class="header-actions">
                <a href="students.php" class="btn btn-secondary"><i class="fas fa-user-graduate"></i> Students</a>
            </div>
        </header>

        <!-- Overview cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
            <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-rotate"></i></div></div><div class="stat-number"><?= (int)$db->fetchColumn("SELECT COUNT(*) FROM status_tracker") ?></div><div class="stat-label">Total Status Changes</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-user-check"></i></div></div><div class="stat-number"><?= $counts['active'] ?></div><div class="stat-label">Currently Active</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-book-open-reader"></i></div></div><div class="stat-number"><?= $loaCount ?></div><div class="stat-label">On Leave (LOA)</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-triangle-exclamation"></i></div></div><div class="stat-number"><?= $counts['at-risk'] + $counts['probation'] ?></div><div class="stat-label">At Risk / Probation</div></div>
        </div>

        <!-- Filter bar -->
        <div class="panel" style="margin-top:24px;">
            <div class="panel-toolbar" style="background:#fafcfe;">
                <div class="panel-title"><i class="fas fa-clock-rotate-left" style="color:#2563eb;"></i> Status Timeline</div>
                <div class="panel-actions">
                    <select id="statusFilter" class="form-control" style="width:auto;height:36px;" onchange="applyFilter()">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="padding:20px;">
                <?php if (empty($tracker)): ?>
                    <div class="empty-state"><i class="fas fa-clock-rotate-left"></i><p>No status changes recorded</p><span>Status changes will appear here</span></div>
                <?php else: ?>
                    <div class="timeline">
                    <?php foreach ($tracker as $t):
                        $sClass = in_array($t['current_status'], ['active','at-risk','probation','graduated','loa','transferred','dropped','archived'], true) ? $t['current_status'] : 'active';
                        $effDate = $t['effective_date'] ? date('M d, Y', strtotime($t['effective_date'])) : date('M d, Y h:i A', strtotime($t['created_at']));
                    ?>
                        <div class="tl-item <?= $sClass ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                                <div>
                                    <span class="pill <?= $sClass ?>"><span class="status-dot <?= $sClass ?>"></span><?= ucfirst($t['current_status']) ?></span>
                                    <span style="font-weight:600;font-size:14px;color:#0f172a;margin-left:8px;"><?= htmlspecialchars($t['student_name'] ?? 'Deleted student') ?></span>
                                    <?php if ($t['student_number']): ?><span style="color:#94a3b8;font-size:12px;margin-left:4px;"><?= htmlspecialchars($t['student_number']) ?></span><?php endif; ?>
                                </div>
                                <div style="font-size:12px;color:#64748b;">
                                    <i class="fas fa-calendar-day"></i> <?= $effDate ?>
                                    <?php if ($t['end_date']): ?> → <i class="fas fa-calendar-xmark"></i> <?= date('M d, Y', strtotime($t['end_date'])) ?><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($t['previous_status']): ?>
                                <div style="font-size:12px;color:#64748b;margin-top:4px;">
                                    <span class="pill <?= $t['previous_status'] ?>"><?= ucfirst($t['previous_status']) ?></span>
                                    <i class="fas fa-arrow-right" style="font-size:10px;color:#94a3b8;margin:0 6px;"></i>
                                    <span class="pill <?= $sClass ?>"><?= ucfirst($t['current_status']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($t['reason']): ?>
                                <div style="font-size:13px;color:#475569;margin-top:6px;"><i class="fas fa-comment"></i> <?= htmlspecialchars($t['reason']) ?></div>
                            <?php endif; ?>
                            <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                                <?= $t['changed_by_name'] ? 'By '.htmlspecialchars($t['changed_by_name']) : '' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
function applyFilter() {
    const v = document.getElementById('statusFilter').value;
    const params = new URLSearchParams(window.location.search);
    if (v) params.set('status', v); else params.delete('status');
    window.location.href = 'status-tracker.php?' + params.toString();
}
</script>

<?php include '../includes/footer.php'; ?>