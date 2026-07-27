<?php
// ============================================================
//  REGISTRAR/RFID-SCAN-LOGS.PHP
//  RFID scan logs history
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

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
$ACTIVE_NAV = 'rfid';
$extra_css = ['rfid-cards.css'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
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
        </select>
        <select id="logsEventFilter">
            <option value="">All events</option>
            <option value="entry">Entry</option>
            <option value="exit">Exit</option>
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
                        <td colspan="7" class="text-center py-12 text-gray-500">
                            <i class="fas fa-credit-card text-5xl block mb-3 text-gray-300"></i>
                            <p class="text-lg font-medium text-gray-400">No scan logs yet</p>
                            <p class="text-sm text-gray-400">Tap a card on the Test Scanner to see logs appear here</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $log): ?>
                        <tr class="log-row<?= $i === 0 ? ' rfid-pulse' : '' ?>"
                            data-status="<?= htmlspecialchars($log['status']) ?>"
                            data-event="<?= htmlspecialchars($log['event_type'] ?? 'entry') ?>"
                            data-search="<?= htmlspecialchars(strtolower(json_encode([$log['card_uid'] ?? '', $log['student_name'] ?? '', $log['location'] ?? '']))) ?>">
                            <td class="text-gray-600 text-sm"><?= date('M d, Y h:i:s A', strtotime($log['scanned_at'])) ?></td>
                            <td><span class="font-mono text-sm font-medium"><?= htmlspecialchars($log['card_uid']) ?></span></td>
                            <td>
                                <?php if (!empty($log['student_name'])): ?>
                                    <div class="student-name"><?= htmlspecialchars($log['student_name']) ?></div>
                                    <div class="student-detail"><?= htmlspecialchars($log['student_number'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="text-gray-400">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm text-gray-600"><?= htmlspecialchars($log['course'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($log['location'] ?? 'Main Gate') ?></td>
                            <td><span class="event-pill <?= htmlspecialchars($log['event_type'] ?? 'entry') ?>"><?= htmlspecialchars($log['event_type'] ?? 'entry') ?></span></td>
                            <td>
                                <span class="status-badge <?= htmlspecialchars($log['status']) ?>">
                                    <span class="status-dot"></span> <?= ucfirst(htmlspecialchars($log['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <div class="info-text">Showing <strong id="logsVisibleCount"><?= count($logs) ?></strong> of <strong><?= count($logs) ?></strong> latest logs (max 200)</div>
    </div>
</main>

<script>
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