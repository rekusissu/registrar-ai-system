<?php
// ============================================================
//  REGISTRAR/RFID-SCAN-LOGS.PHP
//  RFID scan logs history — fully inline (CSS + JS)
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

$logs = $db->fetchAll("
    SELECT
        l.*,
        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
        s.student_number,
        s.course
    FROM rfid_scan_logs l
    LEFT JOIN students s ON l.student_id = s.id
    ORDER BY l.scanned_at DESC
    LIMIT 200
");

$page_title = 'Scan Logs';
$APP_ROOT = '../';
$ACTIVE_NAV = 'scanlogs';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
/* ============================================================
   RFID SCAN LOGS — INLINE STYLES
   ============================================================ */
:root { --sidebar-width: 260px; }
.dashboard-main {
    margin-left: var(--sidebar-width);
    padding: 24px 32px;
    min-height: 100vh;
    width: calc(100% - var(--sidebar-width));
    max-width: calc(100% - var(--sidebar-width));
    overflow-x: hidden;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease, max-width 0.3s ease;
}

/* ── Page header ── */
.header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 22px; padding-bottom: 16px;
    border-bottom: 1px solid #e8eaef;
    gap: 16px; flex-wrap: wrap;
}
.header .title h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin: 0 0 2px; }
.header .title p  { font-size: 13px; color: #64748b; margin: 0; }
.header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1.5px solid transparent;
    text-decoration: none;
    transition: all 0.2s ease;
    font-family: inherit;
    line-height: 1;
}
.btn-secondary { background: white; color: #475569; border-color: #e2e8f0; }
.btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }

/* ── Filter row ── */
.rfid-filters {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.rfid-filters input,
.rfid-filters select {
    padding: 9px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13px;
    font-family: inherit;
    outline: none;
    background: white;
    color: #1e293b;
    transition: all 0.2s ease;
    min-width: 160px;
}
.rfid-filters input { flex: 1; min-width: 240px; }
.rfid-filters input:focus,
.rfid-filters select:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.10); }

/* ── Table ── */
.rfid-table-wrapper {
    background: white;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}
.rfid-table-wrapper table { width: 100%; border-collapse: collapse; min-width: 900px; }
.rfid-table-wrapper th {
    text-align: left;
    padding: 11px 16px;
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.5px;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    position: sticky; top: 0; z-index: 2;
    white-space: nowrap;
}
.rfid-table-wrapper td {
    padding: 11px 16px;
    font-size: 14px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    white-space: nowrap;
}
.rfid-table-wrapper tbody tr:hover { background: #f8fafc; }
.rfid-table-wrapper tbody tr:last-child td { border-bottom: none; }

.student-name  { font-weight: 600; color: #0f172a; font-size: 14px; line-height: 1.2; }
.student-detail { font-size: 12px; color: #94a3b8; margin-top: 2px; }

/* ── Status badges ── */
.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    background: #f1f5f9; color: #475569;
    line-height: 1.2; white-space: nowrap;
}
.status-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block; flex-shrink: 0;
}
.status-badge.success,
.status-badge.active    { background: #dcfce7; color: #16a34a; }
.status-badge.denied,
.status-badge.expired,
.status-badge.inactive  { background: #fee2e2; color: #dc2626; }
.status-badge.warning,
.status-badge.lost     { background: #fef3c7; color: #b45309; }

/* ── Event pills ── */
.event-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    background: #eff6ff; color: #2563eb;
    line-height: 1.2;
}
.event-pill::before {
    content: '';
    width: 5px; height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.event-pill.exit  { background: #f1f5f9; color: #475569; }
.event-pill.library    { background: #f3e8ff; color: #a855f7; }
.event-pill.cafeteria  { background: #fef3c7; color: #b45309; }
.event-pill.other      { background: #f1f5f9; color: #475569; }
.event-pill.queue_join    { background: #e0e7ff; color: #4f46e5; }
.event-pill.queue_call    { background: #fef3c7; color: #b45309; }
.event-pill.queue_serving { background: #dbeafe; color: #2563eb; }
.event-pill.queue_completed { background: #dcfce7; color: #16a34a; }
.event-pill.queue_no_show { background: #fee2e2; color: #dc2626; }
.event-pill.queue_cancelled { background: #f1f5f9; color: #475569; }

/* ── Card UID pill (same as cards page) ── */
.card-uid-display {
    display: inline-flex; align-items: center; gap: 8px;
    font-family: 'Courier New', monospace;
    font-size: 13px; font-weight: 600;
    color: #0f172a;
    background: #f1f5f9; padding: 4px 12px; border-radius: 8px;
}

/* ── Pulse on newest row ── */
@keyframes rfid-pulse {
    0%   { background: #eef4ff; }
    50%  { background: #dbeafe; }
    100% { background: transparent; }
}
.rfid-pulse { animation: rfid-pulse 1.6s ease-out 1; }

/* ── Table footer ── */
.table-footer {
    padding: 12px 18px;
    background: #fafcfd;
    border-top: 1px solid #f1f5f9;
}
.table-footer .info-text { font-size: 13px; color: #64748b; }
.table-footer .info-text strong { color: #0f172a; }

/* ── Empty state ── */
.rfid-empty {
    text-align: center;
    padding: 48px 16px;
    color: #94a3b8;
}
.rfid-empty i { font-size: 44px; color: #cbd5e1; display: block; margin-bottom: 10px; }
.rfid-empty p { font-size: 15px; font-weight: 600; color: #64748b; margin: 0; }
.rfid-empty span { font-size: 13px; display: block; margin-top: 4px; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .dashboard-main {
        margin-left: 0;
        width: 100%; max-width: 100%;
        padding: 18px 14px;
    }
    .rfid-filters input { min-width: 100%; }
    .rfid-table-wrapper table { min-width: 700px; }
}
@media (max-width: 480px) {
    .header { flex-direction: column; align-items: flex-start; gap: 12px; }
    .header-actions { width: 100%; }
}
</style>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Scan Logs</h1>
            <p>Record of all RFID card taps</p>
        </div>
        <div class="header-actions">
            <a href="rfid-cards.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Cards
            </a>
            <a href="rfid-test.php" class="btn btn-secondary">
                <i class="fas fa-credit-card"></i> Test Scanner
            </a>
        </div>
    </header>

    <!-- Filters -->
    <div class="rfid-filters">
        <input type="text" id="logsSearch" placeholder="Search by UID, student, location..." />
        <select id="logsStatusFilter">
            <option value="">All statuses</option>
            <option value="success">Success</option>
            <option value="denied">Denied</option>
            <option value="warning">Warning</option>
            <option value="active">Active</option>
            <option value="expired">Expired</option>
            <option value="lost">Lost</option>
            <option value="inactive">Inactive</option>
        </select>
        <select id="logsEventFilter">
            <option value="">All events</option>
            <option value="entry">Entry</option>
            <option value="exit">Exit</option>
            <option value="library">Library</option>
            <option value="cafeteria">Cafeteria</option>
            <option value="other">Other</option>
            <option value="queue_join">Queue Join</option>
            <option value="queue_call">Queue Called</option>
            <option value="queue_serving">Queue Serving</option>
            <option value="queue_completed">Queue Completed</option>
            <option value="queue_no_show">Queue No-Show</option>
            <option value="queue_cancelled">Queue Cancelled</option>
        </select>
    </div>

    <!-- Table -->
    <div class="rfid-table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Card UID</th>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Location</th>
                    <th>Event</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="logsTableBody">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="rfid-empty">
                                <i class="fas fa-credit-card"></i>
                                <p>No scan logs yet</p>
                                <span>Tap a card on the Test Scanner to see logs appear here</span>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $log): ?>
                        <tr class="log-row<?= $i === 0 ? ' rfid-pulse' : '' ?>"
                            data-status="<?= htmlspecialchars($log['status']) ?>"
                            data-event="<?= htmlspecialchars($log['event_type'] ?? 'entry') ?>"
                            data-search="<?= htmlspecialchars(strtolower(($log['card_uid'] ?? '') . ' ' . ($log['student_name'] ?? '') . ' ' . ($log['location'] ?? '') . ' ' . ($log['student_number'] ?? ''))) ?>">
                            <td style="color:#64748b;font-size:13px;">
                                <?= date('M d, Y h:i:s A', strtotime($log['scanned_at'])) ?>
                            </td>
                            <td>
                                <span class="card-uid-display"><?= htmlspecialchars($log['card_uid']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($log['student_name'])): ?>
                                    <div class="student-name"><?= htmlspecialchars($log['student_name']) ?></div>
                                    <div class="student-detail"><?= htmlspecialchars($log['student_number'] ?? '') ?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;color:#64748b;"><?= htmlspecialchars($log['course'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($log['location'] ?? 'Main Gate') ?></td>
                            <td>
                                <span class="event-pill <?= htmlspecialchars($log['event_type'] ?? 'entry') ?>">
                                    <?= htmlspecialchars($log['event_type'] ?? 'entry') ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= htmlspecialchars($log['status']) ?>">
                                    <span class="status-dot"></span>
                                    <?= ucfirst(htmlspecialchars($log['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <div class="info-text">
            Showing <strong id="logsVisibleCount"><?= count($logs) ?></strong>
            of <strong id="logsTotalCount"><?= count($logs) ?></strong> logs
            <?php if (count($logs) >= 200): ?>
                <span style="color:#94a3b8;">(latest 200)</span>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
// ============================================================
// RFID SCAN LOGS — INLINE JS (client-side filters)
// ============================================================
(function () {
    'use strict';
    var search = document.getElementById('logsSearch');
    var statusSel = document.getElementById('logsStatusFilter');
    var eventSel = document.getElementById('logsEventFilter');
    var body = document.getElementById('logsTableBody');
    var visibleCounter = document.getElementById('logsVisibleCount');

    if (!body) return;

    function apply() {
        var q = (search.value || '').trim().toLowerCase();
        var s = statusSel.value;
        var e = eventSel.value;
        var rows = body.querySelectorAll('tr.log-row');
        var visible = 0;
        rows.forEach(function (row) {
            var matchStatus = !s || row.getAttribute('data-status') === s;
            var matchEvent = !e || row.getAttribute('data-event') === e;
            var haystack = row.getAttribute('data-search') || '';
            var matchSearch = !q || haystack.indexOf(q) !== -1;
            var ok = matchStatus && matchEvent && matchSearch;
            row.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });
        if (visibleCounter) visibleCounter.textContent = visible;
    }

    if (search) search.addEventListener('input', apply);
    if (statusSel) statusSel.addEventListener('change', apply);
    if (eventSel) eventSel.addEventListener('change', apply);
})();
</script>

<?php include '../includes/footer.php'; ?>
