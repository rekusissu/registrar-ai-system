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

// Human labels for event_type. The raw value (e.g. "queue_join") is still
// what the filter compares against, so the data-* attributes are unchanged.
$eventLabel = [
    'entry'           => 'Entry',
    'exit'            => 'Exit',
    'library'         => 'Library',
    'cafeteria'       => 'Cafeteria',
    'clinic'          => 'Clinic',
    'other'           => 'Other',
    'queue_join'      => 'Queue join',
    'queue_call'      => 'Queue called',
    'queue_serving'   => 'Queue serving',
    'queue_completed' => 'Queue completed',
    'queue_no_show'   => 'Queue no-show',
    'queue_cancelled' => 'Queue cancelled',
];

// Courses are stored in full, with the acronym in trailing parentheses, e.g.
// "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)". The table only has
// room for the acronym; the full name stays in the title attribute and in the
// row's data-search value.
function slCourseAcronym(?string $course): string {
    $course = trim((string) $course);
    if ($course === '') return '—';
    // Casing is left as-is: the catalog spells some as BSCpE, and upper-casing
    // would render that as BSCPE, which matches no other screen in the app.
    // The character class requires no spaces, so a value like
    // "COURSE (SEE ADVISOR)" is left alone rather than truncated to "SEE ADVISOR".
    if (preg_match('/\(([A-Za-z0-9.\-]{2,10})\)\s*$/', $course, $m)) return $m[1];
    return $course;
}

$page_title = 'Scan Logs';
$page_description = 'History of RFID card taps across campus';
$body_page = 'scanlogs';
$APP_ROOT = '../';
$ACTIVE_NAV = 'scanlogs';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
/* Scan logs ? registrar-blue system, matching Status Tracker / Queue / Documents. */
body[data-page="scanlogs"]{background:#f5f7fb;color:#0f172a}
body[data-page="scanlogs"] .dashboard-main{padding:24px clamp(18px,2.5vw,38px) 48px;background:linear-gradient(180deg,#eef4ff 0,#f8faff 300px,#f8faff 100%);min-height:auto}

/* -- Header -- */
.sl-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:0 0 16px;padding:25px 27px;border:1px solid #c7d7fe;border-radius:19px;background:linear-gradient(120deg,#eff6ff,#fff 68%);box-shadow:0 10px 30px rgba(37,99,235,.08)}
.sl-kicker{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#1d4ed8;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.sl-head h1{margin:0 0 5px;font-size:28px;line-height:1.1;letter-spacing:-.03em;color:#172554}
.sl-head p{max-width:620px;margin:0;font-size:12.5px;line-height:1.5;color:#64748b}
.sl-head .header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
/* box-sizing matters here: no global `* { box-sizing }` reset exists, and the
   UA sheet gives border-box to <button> but not to <a>. "Back to Cards" is an
   anchor, so min-height would otherwise resolve against the content box and
   render the button roughly 18px taller than intended. */
.sl-head .header-actions .btn{box-sizing:border-box;min-height:36px;font-size:12px}

/* -- Panel -- */
.sl-panel{border:1px solid #dbeafe;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.045);overflow:hidden}

/* -- Filter toolbar -- */
.sl-filters{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:13px 18px;border-bottom:1px solid #e5e7eb;background:#fff}
.sl-search{position:relative;flex:1 1 280px;min-width:210px}
.sl-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#64748b;font-size:13px;pointer-events:none}
.sl-search input{width:100%;height:38px;box-sizing:border-box;padding:0 12px 0 36px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif}
.sl-search input::placeholder{color:#94a3b8}
.sl-search input:focus{outline:0;border-color:#2563eb;background:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
.sl-filters select{height:38px;padding:0 10px;border:1px solid #cbd5e1;border-radius:9px;background:#f8faff;color:#1e293b;font:13px Inter,sans-serif;cursor:pointer}
.sl-filters select:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.1)}

/* -- Table -- */
.sl-table-scroll{max-height:66vh;overflow:auto}
.sl-panel table{width:100%;border-collapse:collapse;min-width:820px}
.sl-panel thead th{position:sticky;top:0;z-index:2;padding:11px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;text-align:left;white-space:nowrap}
.sl-panel tbody td{padding:10px 16px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;vertical-align:middle;white-space:nowrap}
.sl-panel tbody tr:last-child td{border-bottom:0}
.sl-panel tbody tr.log-row:hover{background:#eff6ff}

/* Time reads as a stamp: date above, clock below in mono. */
.sl-when{line-height:1.25}
.sl-when .d{display:block;font-size:12px;color:#475569}
.sl-when .t{display:block;margin-top:1px;font:600 12.5px/1.25 JetBrains Mono,ui-monospace,monospace;color:#94a3b8;font-variant-numeric:tabular-nums}

.sl-uid{display:inline-flex;align-items:center;gap:7px;padding:4px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;font:600 12.5px/1 JetBrains Mono,ui-monospace,monospace;color:#0f172a;letter-spacing:-.01em}
.sl-uid::before{content:"";width:5px;height:5px;border-radius:50%;background:#2563eb;flex:0 0 5px}

.sl-name{font-size:13px;font-weight:600;color:#0f172a;line-height:1.2}
.sl-sub{margin-top:1px;font-size:11.5px;color:#94a3b8}
.sl-muted{color:#94a3b8}
.sl-course{display:inline-block;padding:3px 9px;border:1px solid #dbeafe;border-radius:7px;background:#eff6ff;font:700 11.5px/1.3 Inter,sans-serif;letter-spacing:.02em;color:#1d4ed8}
.sl-loc{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#334155}

/* -- Status badges -- */
.sl-status{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700;background:#f1f5f9;color:#475569;line-height:1.2;white-space:nowrap}
.sl-status::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 6px}
.sl-status.success,.sl-status.active{background:#dcfce7;color:#15803d}
.sl-status.denied,.sl-status.expired,.sl-status.inactive{background:#fee2e2;color:#dc2626}
.sl-status.warning,.sl-status.lost{background:#fef3c7;color:#b45309}

/* -- Event pills -- */
.sl-event{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700;background:#eff6ff;color:#1d4ed8;line-height:1.2;white-space:nowrap}
.sl-event::before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor;flex:0 0 5px}
.sl-event.exit{background:#f1f5f9;color:#475569}
.sl-event.library{background:#f3e8ff;color:#7e22ce}
.sl-event.cafeteria{background:#fef3c7;color:#b45309}
.sl-event.clinic{background:#ccfbf1;color:#0f766e}
.sl-event.other{background:#f1f5f9;color:#475569}
.sl-event.queue_join{background:#e0e7ff;color:#4338ca}
.sl-event.queue_call{background:#fef3c7;color:#b45309}
.sl-event.queue_serving{background:#dbeafe;color:#1d4ed8}
.sl-event.queue_completed{background:#dcfce7;color:#15803d}
.sl-event.queue_no_show{background:#fee2e2;color:#dc2626}
.sl-event.queue_cancelled{background:#f1f5f9;color:#475569}

/* -- Panel footer -- */
.sl-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 18px;background:#f8faff;border-top:1px solid #e2e8f0}
.sl-foot .info{font-size:12.5px;color:#64748b}
.sl-foot .info strong{color:#0f172a;font-variant-numeric:tabular-nums}
.sl-foot .note{font-size:11.5px;color:#94a3b8}

/* -- Empty states -- */
.sl-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;min-height:240px;padding:38px 20px;text-align:center}
.sl-empty i{font-size:34px;color:#cbd5e1}
.sl-empty p{margin:0;font-size:14px;font-weight:600;color:#64748b}
.sl-empty span{font-size:12.5px;color:#94a3b8;max-width:38ch}

/* -- Newest-row pulse -- */
@keyframes sl-pulse{0%{background:#dbeafe}100%{background:transparent}}
.sl-pulse{animation:sl-pulse 1.6s ease-out 1}

/* -- Responsive -- */
@media(max-width:900px){.sl-head{flex-direction:column;align-items:flex-start}.sl-head .header-actions{width:100%;justify-content:flex-start}.sl-table-scroll{max-height:none}}
@media(max-width:600px){.sl-head{padding:21px 18px}.sl-head h1{font-size:25px}.sl-head .header-actions{flex-direction:column;align-items:stretch}.sl-head .btn{justify-content:center}.sl-search,.sl-filters select{width:100%}}
@media(prefers-reduced-motion:reduce){.sl-pulse{animation:none}}
</style>

<main class="dashboard-main">
    <header class="sl-head">
        <div>
            <div class="sl-kicker"><i class="fas fa-wave-square"></i> Access record</div>
            <h1>Scan Logs</h1>
            <p>Every RFID tap recorded at a reader, newest first. The latest 200 are kept here.</p>
        </div>
        <div class="header-actions">
            <a href="rfid-cards.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Cards</a>
        </div>
    </header>

    <div class="sl-panel">

        <!-- Filters -->
        <div class="sl-filters">
            <div class="sl-search">
                <i class="fas fa-search"></i>
                <input type="text" id="logsSearch" placeholder="Search UID, student, or location" aria-label="Search scan logs">
            </div>
            <select id="logsStatusFilter" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="success">Success</option>
                <option value="denied">Denied</option>
                <option value="warning">Warning</option>
                <option value="active">Active</option>
                <option value="expired">Expired</option>
                <option value="lost">Lost</option>
                <option value="inactive">Inactive</option>
            </select>
            <select id="logsEventFilter" aria-label="Filter by event">
                <option value="">All events</option>
                <?php foreach ($eventLabel as $val => $lbl): ?>
                    <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Table -->
        <div class="sl-table-scroll">
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
                            <div class="sl-empty">
                                <i class="fas fa-credit-card"></i>
                                <p>No scan logs yet</p>
                                <span>Tap a card on the Test Scanner to see logs appear here.</span>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $log):
                        $ev = $log['event_type'] ?? 'entry';
                        $evTxt = $eventLabel[$ev] ?? ucfirst(str_replace('_', ' ', (string) $ev));
                        $ts = strtotime($log['scanned_at']); ?>
                        <tr class="log-row<?= $i === 0 ? ' sl-pulse' : '' ?>"
                            data-status="<?= htmlspecialchars($log['status']) ?>"
                            data-event="<?= htmlspecialchars($ev) ?>"
                            data-search="<?= htmlspecialchars(strtolower(($log['card_uid'] ?? '') . ' ' . ($log['student_name'] ?? '') . ' ' . ($log['location'] ?? '') . ' ' . ($log['student_number'] ?? ''))) ?>">
                            <td>
                                <div class="sl-when">
                                    <span class="d"><?= date('M d, Y', $ts) ?></span>
                                    <span class="t"><?= date('h:i:s A', $ts) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="sl-uid"><?= htmlspecialchars($log['card_uid']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($log['student_name'])): ?>
                                    <div class="sl-name"><?= htmlspecialchars($log['student_name']) ?></div>
                                    <div class="sl-sub"><?= htmlspecialchars($log['student_number'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="sl-muted">Unknown card</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $acronym = slCourseAcronym($log['course'] ?? ''); ?>
                                <span class="sl-course" title="<?= htmlspecialchars($log['course'] ?? '') ?>"><?= htmlspecialchars($acronym) ?></span>
                            </td>
                            <td><span class="sl-loc"><i class="fas fa-location-dot" style="font-size:11px;color:#94a3b8;"></i><?= htmlspecialchars($log['location'] ?? 'Main Gate') ?></span></td>
                            <td>
                                <span class="sl-event <?= htmlspecialchars($ev) ?>"><?= htmlspecialchars($evTxt) ?></span>
                            </td>
                            <td>
                                <span class="sl-status <?= htmlspecialchars($log['status']) ?>"><?= ucfirst(htmlspecialchars($log['status'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr id="logsNoMatch" style="display:none;"><td colspan="7"><div class="sl-empty"><i class="fas fa-magnifying-glass"></i><p>No scans match your filters</p><span>Try a different UID, student, location, or clear the filters.</span></div></td></tr>
            </tbody>
        </table>
        </div>

        <div class="sl-foot">
            <div class="info">
                Showing <strong id="logsVisibleCount"><?= count($logs) ?></strong>
                of <strong id="logsTotalCount"><?= count($logs) ?></strong> logs
            </div>
            <?php if (count($logs) >= 200): ?>
                <div class="note">Only the latest 200 scans are loaded.</div>
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
        // Say so plainly when nothing matched, instead of leaving a blank table.
        var noMatch = document.getElementById('logsNoMatch');
        if (noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
    }

    if (search) search.addEventListener('input', apply);
    if (statusSel) statusSel.addEventListener('change', apply);
    if (eventSel) eventSel.addEventListener('change', apply);
    apply();
})();
</script>

<?php include '../includes/footer.php'; ?>
