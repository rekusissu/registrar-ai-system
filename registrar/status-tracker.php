<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Student status tracker - overview cards, per-student status
//  changer, and full status-change history (view only).
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
$db = Database::getInstance();

$statuses = ['active', 'enrolled', 'probation', 'at-risk', 'loa', 'graduated', 'transferred', 'dropped'];

$filterStatus = trim($_GET['status'] ?? '');
$where = "s.status != 'archived'";
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $statuses, true)) {
    $where .= " AND s.status = ?";
    $params[] = $filterStatus;
}

$studentsList = $db->fetchAll("
    SELECT s.id, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name,
           s.course, s.year_level, s.section, s.status,
           (SELECT MAX(created_at) FROM status_tracker st WHERE st.student_id = s.id) AS last_status_at
    FROM students s
    WHERE $where
    ORDER BY s.last_name, s.first_name
", $params);

// Overview card counts.
$counts = ['active' => 0, 'enrolled' => 0, 'inactive' => 0, 'dropped' => 0, 'graduated' => 0, 'alumni' => 0];
foreach ($studentsList as $st) {
    $sx = $st['status'];
    if ($sx === 'active') $counts['active']++;
    elseif ($sx === 'enrolled') $counts['enrolled']++;
    elseif ($sx === 'dropped') $counts['dropped']++;
    elseif ($sx === 'graduated') { $counts['graduated']++; $counts['alumni']++; }
    elseif (in_array($sx, ['loa', 'transferred'], true)) $counts['inactive']++;
}
$alumniEver = (int) $db->fetchColumn("SELECT COUNT(DISTINCT student_id) FROM status_tracker WHERE current_status = 'graduated'");
$counts['alumni'] = max($counts['alumni'], $alumniEver);

// Per-student history for the view modal.
$historyData = [];
if ($studentsList) {
    $ids = array_map(fn($s) => (int) $s['id'], $studentsList);
    $in = implode(',', $ids);
    $hist = $db->fetchAll("
        SELECT st.*, u.full_name AS changed_by_name
        FROM status_tracker st
        LEFT JOIN users u ON u.id = st.changed_by
        WHERE st.student_id IN ($in)
        ORDER BY st.created_at DESC
    ");
    foreach ($hist as $h) {
        $historyData[(int) $h['student_id']][] = [
            'prev'   => (string) ($h['previous_status'] ?? ''),
            'status' => (string) $h['current_status'],
            'reason' => (string) ($h['reason'] ?? ''),
            'by'     => (string) ($h['changed_by_name'] ?? ''),
            'at'     => (string) $h['created_at'],
        ];
    }
}

$page_title = 'Student Status Tracker';
$APP_ROOT = '../';
$ACTIVE_NAV = 'tracker';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
    <div class="dashboard-container">
        <header class="header">
            <div class="title">
                <h1>Student Status Tracker</h1>
                <p>Full life-cycle history of every status change</p>
            </div>
        </header>

        <!-- Overview cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
            <div class="stat-card"><div class="stat-top"><div class="stat-icon blue"><i class="fas fa-user-check"></i></div></div><div class="stat-number"><?= $counts['active'] ?></div><div class="stat-label">Active</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon green"><i class="fas fa-book-open-reader"></i></div></div><div class="stat-number"><?= $counts['enrolled'] ?></div><div class="stat-label">Enrolled</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon yellow"><i class="fas fa-pause"></i></div></div><div class="stat-number"><?= $counts['inactive'] ?></div><div class="stat-label">Inactive</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon red"><i class="fas fa-user-slash"></i></div></div><div class="stat-number"><?= $counts['dropped'] ?></div><div class="stat-label">Dropped</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon purple"><i class="fas fa-graduation-cap"></i></div></div><div class="stat-number"><?= $counts['graduated'] ?></div><div class="stat-label">Graduated</div></div>
            <div class="stat-card"><div class="stat-top"><div class="stat-icon teal"><i class="fas fa-user-graduate"></i></div></div><div class="stat-number"><?= $counts['alumni'] ?></div><div class="stat-label">Alumni</div></div>
        </div>

        <!-- Filter bar + table -->
        <div class="panel" style="margin-top:24px;padding:0;overflow:hidden;">
            <div class="panel-toolbar" style="background:#fafcfe;margin:0;padding:14px 20px;">
                <div class="panel-title" style="margin:0;"><i class="fas fa-table-list" style="color:#2563eb;"></i> Students</div>
                <div class="panel-actions">
                    <select id="statusFilter" class="form-control" style="width:auto;height:36px;" onchange="applyFilter()">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Student ID</th><th>Student Name</th><th>Program &amp; Year Level</th><th>Date Updated</th><th>Status</th><th style="text-align:center;">Actions</th></tr></thead>
                <tbody>
                <?php if (empty($studentsList)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No students match this status.</td></tr>
                <?php else: foreach ($studentsList as $st):
                    $sClass = in_array($st['status'], $statuses, true) ? $st['status'] : 'active';
                    $updated = $st['last_status_at'] ? date('M d, Y', strtotime($st['last_status_at'])) : '-';
                ?>
                    <tr data-sid="<?= (int) $st['id'] ?>">
                        <td style="font-family:'JetBrains Mono',monospace;font-size:12.5px;"><?= htmlspecialchars($st['student_number']) ?></td>
                        <td><strong><?= htmlspecialchars($st['student_name']) ?></strong></td>
                        <td style="font-size:12.5px;color:#475569;"><?= htmlspecialchars($st['course']) ?><br><span style="color:#94a3b8;font-size:11.5px;">Year <?= (int) $st['year_level'] ?><?= $st['section'] ? ' - ' . htmlspecialchars($st['section']) : '' ?></span></td>
                        <td style="font-size:12.5px;color:#64748b;"><?= $updated ?></td>
                        <td><span class="pill <?= $sClass ?>"><span class="status-dot <?= $sClass ?>"></span><?= ucfirst($st['status']) ?></span></td>
                        <td style="text-align:center;">
                            <div style="display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap;">
                                <select class="form-control" style="width:auto;height:30px;font-size:12px;padding:0 8px;" onchange="changeStatus(<?= (int) $st['id'] ?>, this.value)">
                                    <?php foreach ($statuses as $opt): ?>
                                        <option value="<?= $opt ?>" <?= $opt === $st['status'] ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="action-btn view" title="View status history" onclick="openHistory(<?= (int) $st['id'] ?>)"><i class="fas fa-eye"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</main>

<!-- History Modal -->
<div class="modal-overlay" id="historyModal"><div class="modal-content" style="max-width:560px;">
    <div class="modal-header">
        <h3><i class="fas fa-clock-rotate-left" style="color:#2563eb;"></i> Status History</h3>
        <button class="modal-close" onclick="closeHistory()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div id="historyBody"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeHistory()">Close</button>
    </div>
</div></div>

<script>
const HISTORY = <?= json_encode($historyData ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const STUDENT_NAMES = <?= json_encode(array_combine(array_map(fn($s) => (int) $s['id'], $studentsList), array_map(fn($s) => $s['student_name'], $studentsList)) ?: [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
function closeHistory() { const m = document.getElementById('historyModal'); if (m) { m.classList.remove('active'); document.body.style.overflow = ''; } }
document.getElementById('historyModal').addEventListener('click', function(e){ if (e.target === this) closeHistory(); });

function applyFilter() {
    const v = document.getElementById('statusFilter').value;
    const params = new URLSearchParams(window.location.search);
    if (v) params.set('status', v); else params.delete('status');
    window.location.href = 'status-tracker.php?' + params.toString();
}

async function changeStatus(id, status) {
    if (!confirm('Change this student status to ' + status + '?')) { location.reload(); return; }
    try {
        const res = await fetch('../api/students.php?action=bulk-status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: [id], status: status })
        });
        const d = await res.json();
        showToast(d.success ? 'Status updated.' : (d.message || 'Failed.'), d.success ? 'success' : 'error');
        if (d.success) setTimeout(() => window.location.reload(), 700);
    } catch (err) { showToast('Network error.', 'error'); location.reload(); }
}

function openHistory(id) {
    const body = document.getElementById('historyBody');
    const name = STUDENT_NAMES[id] || 'Student';
    const list = HISTORY[id] || [];
    if (!list.length) {
        body.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:24px;">No status changes recorded for ' + esc(name) + '.</div>';
    } else {
        body.innerHTML = '<div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:10px;">' + esc(name) + '</div>'
            + list.map(h => {
                const when = h.at ? new Date(String(h.at).replace(' ', 'T')).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
                const prev = h.prev ? '<span class="pill">' + esc(h.prev) + '</span> <i class="fas fa-arrow-right" style="font-size:10px;color:#94a3b8;margin:0 6px;"></i> ' : '';
                return '<div style="border:1px solid #f1f5f9;border-radius:10px;padding:10px 12px;margin-bottom:8px;">'
                    + '<div>' + prev + '<span class="pill ' + h.status + '">' + esc(h.status) + '</span></div>'
                    + (h.reason ? '<div style="font-size:12px;color:#475569;margin-top:5px;"><i class="fas fa-comment"></i> ' + esc(h.reason) + '</div>' : '')
                    + '<div style="font-size:11px;color:#94a3b8;margin-top:5px;">' + when + (h.by ? ' - by ' + esc(h.by) : '') + '</div>'
                    + '</div>';
            }).join('');
    }
    document.getElementById('historyModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
</script>

<?php include '../includes/footer.php'; ?>