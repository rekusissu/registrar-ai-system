<?php
// ============================================================
//  REGISTRAR/STATUS-TRACKER.PHP
//  Student Status Tracker — AI-powered decision console.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('staff');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/status_evidence.php';
// The directory shows each student's face beside their name, read from Digital
// File Storage - the same photograph the Students page, the View modal and the
// counter desk use. The avatar here was coloured by STATUS_META and held two
// letters built with mb_substr(), so it never had a photograph to show: s.photo
// was selected by the query and then never used for anything.
require_once __DIR__ . '/../shared/stored_file.php';

// Who needs a decision today, found by the same contradiction rules the
// "Scan records" button runs — computed here so the first screen
// answers that question without a click, and so the count is not a
// client-side guess. A source that is missing is reported rather than
// counted as zero findings.
$queue = [];
try {
    $queue = statusCohortFindings();
} catch (Throwable $e) {
    error_log('[status-tracker] attention queue unavailable: ' . $e->getMessage());
    $queue = ['findings' => [], 'scanned' => 0, 'errors' => ['queue: ' . $e->getMessage()]];
}
$queueRows = $queue['findings'] ?? [];
$queueHigh  = count($queueRows);

$db = Database::getInstance();

// ── The five statuses ───────────────────────────────────────────
//
// This page used to declare its own list of eleven values, eight of which the
// column could actually store, and keep its own colour map beside it. The two
// drifted from the badge map in shared/functions.php and from the insights pie
// in shared/analytics.php, so the same status could be one colour in the
// tracker timeline and another in a student's row.
//
// The set and the presentation now both come from shared/functions.php. This
// page cannot offer a status the database would reject, and cannot colour one
// differently from everywhere else, because it no longer decides either.
//
//   enrolled · active · graduate · alumni · dropped
$STATUSES = studentStatuses();

$STATUS_META = [];
foreach ($STATUSES as $st) {
    $STATUS_META[$st] = studentStatusMeta($st);
}

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';
$pageNum      = max(1, (int) ($_GET['page'] ?? 1));

// ── Year level and program filters ─────────────────────────────
//
// Both are URL parameters beside status and q, on the same reasoning
// (see the directory note below): a client-side filter cannot survive
// server-side paging, and a URL makes the view shareable and the back
// button meaningful.
//
// Four facets, each one a SET rather than a single value, because the question
// this panel answers is "show me these students", and that is rarely one value
// of anything: a registrar chasing a section is looking at BSIT 2nd year
// first semester, and then BSIT 3rd year first semester too.
//
// They arrive as name[] from the checkboxes, comma-separated from a shared URL,
// and both are accepted so a filtered view can be pasted to a colleague.
//
// Every value is checked against what the roster actually holds before it
// reaches SQL. The bind is already safe; this is so a stale link filters to
// nothing rather than to a confusing subset.
$MAX_PROGRAM = (int) $db->fetchColumn("SELECT COALESCE(MAX(CHAR_LENGTH(course)), 0) FROM students");

$facetValues = static function (string $key, int $maxLen = 255) use ($MAX_PROGRAM): array {
    $raw = $_GET[$key] ?? [];
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        if (mb_strlen($v) > $maxLen) {
            continue;
        }
        $out[$v] = $v;          // key => value, so a repeat collapses
    }
    return array_values($out);
};

// Ordinal suffix for the year labels: "1st year", not "Year 1". The array is
// there because the set is fixed; "11th"-style generalisation is not needed.
$ORDINAL = [1 => 'st', 2 => 'nd', 3 => 'rd', 4 => 'th'];

$filterYear     = array_values(array_filter($facetValues('year'),      'ctype_digit'));
$filterProgram  = $facetValues('program', $MAX_PROGRAM);
$filterSemester = $facetValues('semester');
$filterSection  = $facetValues('section');

$counts = [];
foreach ($STATUSES as $s) {
    // Every status here is a value the column accepts, so the count is a plain
    // lookup. This line used to be guarded by in_array($s, $DB_STATUSES) against
    // a second list that had drifted from the first - the two-lists setup is
    // gone, so there is nothing left to guard against.
    $counts[$s] = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = ?", [$s]);
}

$totalStudents    = (int) $db->fetchColumn("SELECT COUNT(*) FROM students");
$monthStart       = date('Y-m-01 00:00:00');
$prevMonthStart   = date('Y-m-01 00:00:00', strtotime('-1 month'));
$changesThisMonth = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= ?", [$monthStart]);
$changesPrevMonth = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= ? AND created_at < ?", [$prevMonthStart, $monthStart]);
$changesLast7d    = (int) $db->fetchColumn("SELECT COUNT(*) FROM status_tracker WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
// "Attention needed" used to be at-risk + probation - two statuses that were
// advisory rather than enrolment states, and no longer exist. Attention is now
// the students who are neither currently enrolled nor finished: dropped, and
// anything the column holds that is not one of the five (which the data-quality
// page surfaces separately). Counting from the stored values rather than from
// named statuses means this cannot drift when the list changes.
$attentionNeeded = $counts['dropped'];

// ── Directory: server-side search, filter and paging ─────────────
//
// Search used to run in the browser against rows already in the DOM.
// That only worked because every student was rendered: it cannot be
// carried over to paging, where a name on page 4 would be invisible to
// a client-side filter on page 1 and the search would appear to return
// nothing. It is a URL parameter now, which also makes a filtered view
// shareable and the back button meaningful.
$PER_PAGE = 25;
$where = []; $params = [];
if ($filterStatus !== '' && in_array($filterStatus, $STATUSES, true)) {
    $where[] = 's.status = ?';
    $params[] = $filterStatus;
}
// Each facet becomes its own IN clause. Ticking values inside one facet
// widens the result (1st OR 2nd year); ticking across facets narrows it
// (BSIT AND 1st). That is the only combination people expect from a filter.
$facetClauses = [
    'year'      => [$filterYear,     's.year_level', true],
    'program'   => [$filterProgram,  's.course',     false],
    'semester'  => [$filterSemester, 's.semester',   false],
    'section'   => [$filterSection,  's.section',    false],
];
foreach ($facetClauses as [$vals, $col, $isInt]) {
    if (!$vals) {
        continue;
    }
    $ph = implode(',', array_fill(0, count($vals), '?'));
    $where[] = "$col IN ($ph)";
    $params   = array_merge($params, $isInt ? array_map('intval', $vals) : $vals);
}
if ($search !== '') {
    $where[] = '(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name," ",s.last_name) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$matchedTotal = (int) $db->fetchColumn("SELECT COUNT(*) FROM students s" . $whereSql, $params);
$totalPages   = max(1, (int) ceil($matchedTotal / $PER_PAGE));
$pageNum      = min($pageNum, $totalPages);
$offset       = ($pageNum - 1) * $PER_PAGE;

// ── Sorted by need, not by recency ───────────────────────────────
//
// This was ORDER BY last_change DESC, which put the most recently
// touched student first - very nearly the opposite of urgent, since a
// record nobody has touched in eight months outranked one flagged that
// morning. Now the flagged students sort first, and within each band
// the stalest is likeliest to be the one nobody got to.
//
// The flag is the same contradiction set the left rail shows, carried
// into SQL so paging stays correct. Sorting in PHP instead would order
// a page of results rather than the whole set, which silently reorders
// the last page.
//
// The no-flags case must produce valid SQL: a bare "0" as the first
// ORDER BY term is read by MySQL as a column position, not a literal,
// and fails with "Unknown column '0' in 'order clause'". The CASE
// wrapper is what makes it a constant rather than a reference.
$flaggedIds = array_map('intval', array_column($queueRows, 'student_id'));
if ($flaggedIds) {
    $flagSql = 'CASE WHEN s.id IN (' . implode(',', array_fill(0, count($flaggedIds), '?')) . ') THEN 0 ELSE 1 END';
    $orderParams = array_merge($flaggedIds, $params);
} else {
    $flagSql = 'CASE WHEN 1 = 1 THEN 0 ELSE 1 END';
    $orderParams = $params;
}
$lastTs = 'IFNULL(MAX(st.created_at), "1970-01-01 00:00:00")';

$sql = "SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name,
               s.course, s.year_level, s.status, s.photo,
               " . studentPhotoSelectSql() . " AS photo_path,
               MAX(st.created_at) AS last_change
        FROM students s
        LEFT JOIN status_tracker st ON st.student_id = s.id"
     . $whereSql . "
        GROUP BY s.id
        ORDER BY $flagSql ASC, $lastTs ASC, s.id DESC
        LIMIT $PER_PAGE OFFSET $offset";
$students = $db->fetchAll($sql, $orderParams);

// What each facet can offer, and how many students sit behind each value.
//
// Counts are the whole-roster count per value, not the count within the other
// three facets. A count that re-counts itself to zero as you tick is a filter
// that appears broken: tick "1st year" and every other year shows 0, which
// reads as "there are no 2nd years" rather than "no 2nd years match what you
// have ticked so far". The number answers "how big is this cohort", which is
// the question worth asking before ticking it.
$facetOptions = [];

$facetOptions['year'] = [];
foreach ([1, 2, 3, 4] as $y) {
    // Always offered, even at zero. A year with nobody in it must still be
    // tickable so a registrar can confirm it is empty rather than wonder
    // whether the option is missing.
    $facetOptions['year'][(string) $y] = [
        'label' => $y . $ORDINAL[$y] . ' year',
        'count' => (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM students WHERE year_level = ?", [$y]
        ),
    ];
}

$facetOptions['program'] = [];
foreach ($db->fetchAll(
    "SELECT course, COUNT(*) AS c FROM students
     WHERE course IS NOT NULL AND TRIM(course) <> ''
     GROUP BY course ORDER BY course"
) as $r) {
    $facetOptions['program'][(string) $r['course']] = [
        'label' => courseAcronym((string) $r['course']) ?: (string) $r['course'],
        'title' => (string) $r['course'],
        'count' => (int) $r['c'],
    ];
}

// Semester and section are free text in the enrolment form, so they are read
// from the data rather than from a list written here - which would drift the
// first time somebody typed "First Sem" instead of "1st".
foreach (['semester' => 'semester', 'section' => 'section'] as $key => $col) {
    $facetOptions[$key] = [];
    foreach ($db->fetchAll(
        "SELECT `$col` AS v, COUNT(*) AS c FROM students
         WHERE `$col` IS NOT NULL AND TRIM(`$col`) <> ''
         GROUP BY `$col` ORDER BY `$col`"
    ) as $r) {
        $facetOptions[$key][(string) $r['v']] = [
            'label' => (string) $r['v'],
            'count' => (int) $r['c'],
        ];
    }
}

// The distribution, and the filter, collapsed into one list in the rail.
// Recent Activity is gone: with a work queue above it and a case panel
// carrying the full history, a third timeline repeated the same rows in
// a column that pushed the directory off-screen.
$distData = [];
foreach ($STATUSES as $s) { $distData[$s] = $totalStudents > 0 ? round(($counts[$s] / $totalStudents) * 100, 1) : 0; }

// Flagged ids, for the marker in the table row. Set before the ORDER BY
// above consumes them, so it is read from the queue rather than re-run.
$queueRank = [];
foreach ($queueRows as $qr) {
    $queueRank[(int) $qr['student_id']] = $qr;
}

// Builds a directory URL that preserves every filter, not just two.
// Without this, changing one facet drops the other three, the search and the
// page number, so the view jumps back to the first page and loses the
// reader's place.
$dirUrl = static function (array $over = []) use ($filterStatus, $search, $pageNum, $facetValues): string {
    $p = ['status' => $filterStatus, 'q' => $search, 'page' => $pageNum];
    foreach (['year', 'program', 'semester', 'section'] as $k) {
        $p[$k] = $facetValues($k);
    }
    foreach ($over as $k => $v) {
        $p[$k] = ($v === null || $v === '') ? '' : $v;
    }
    // page=1 is the default; carrying it would leave ?page=1 on a link that
    // means "start again".
    if (isset($p['page']) && (int) $p['page'] <= 1) {
        unset($p['page']);
    }
    // Empty facets drop out so a shared link is four parameters, not eight.
    // The scalar arm matters as much as the array one: the override loop
    // above turns a null into '', and a '' left in the query reads as a
    // filter that is set to nothing rather than as no filter at all.
    $p = array_filter($p, static fn($v) => is_array($v) ? $v !== [] : ($v !== '' && $v !== null));
    return $p ? '?' . http_build_query($p) : '';
};

// Every ticked value across every facet. Drives the badge on the button and
// the "no students match" copy, which would otherwise claim nothing matches a
// status when the real cause is a section with nobody in it.
$activeFacets = [];
foreach (['year', 'program', 'semester', 'section'] as $k) {
    foreach ($facetValues($k) as $v) {
        $activeFacets[] = ['key' => $k, 'value' => $v];
    }
}

// True when anything at all is narrowing the directory. Drives the Clear link.
$hasAnyFilter = $search !== '' || $filterStatus !== '' || $activeFacets !== [];


$page_title = 'Status Tracker';
$page_description = 'Student status monitoring and activity tracker';
$body_page = 'status-tracker';
$APP_ROOT   = '../';
$ACTIVE_NAV = 'tracker';
$extra_css = ['status-tracker.css', 'status-workflow.css', 'status-desk-v2.css'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="dashboard-main status-desk-v2">
<header class="st-head"><div><div class="st-kicker"><i class="fas fa-user-check"></i> STUDENT STANDING</div><h1>Status Tracker</h1><p>One place to review student records and record approved status changes.</p></div><button type="button" class="sv-primary" id="btnAIMissed"><i class="fas fa-arrows-rotate"></i> Scan records</button></header>
<div class="sv-summary"><div><span class="sv-metric-icon blue"><i class="fas fa-users"></i></span><div><small>Students on record</small><strong><?= number_format($totalStudents) ?></strong></div></div><div><span class="sv-metric-icon amber"><i class="fas fa-flag"></i></span><div><small>Need review</small><strong><?= $queueHigh ?></strong></div></div><div><span class="sv-metric-icon green"><i class="fas fa-clock-rotate-left"></i></span><div><small>Changes this month</small><strong><?= $changesThisMonth ?></strong></div></div><div class="sv-summary-note"><i class="fas fa-shield-halved"></i><p>Every change has a reason and a recorded author.</p></div></div>
<?php if ($queueRows || !empty($queue['errors'])): ?><details class="sv-attention"><summary><span><i class="fas fa-circle-exclamation"></i> <?= $queueHigh ?> students to review</span><span>Open review queue <i class="fas fa-chevron-down"></i></span></summary><div class="sv-attention-list"><?php foreach ($queueRows as $q): ?><button type="button" onclick='openStudentModal(<?= (int)$q['student_id'] ?>,<?= htmlspecialchars(json_encode($q['student_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($q['student_number']),ENT_QUOTES) ?>)'><strong><?= htmlspecialchars($q['student_name']) ?></strong><span><?= htmlspecialchars(implode(' · ',array_column($q['issues'],'title'))) ?></span><i class="fas fa-arrow-right"></i></button><?php endforeach; ?><?php if(!empty($queue['errors'])): ?><p>Some source records could not be read. Review results may be incomplete.</p><?php endif; ?></div></details><?php endif; ?>
<section class="sv-roster"><div class="sv-roster-heading"><div><h2>Student directory</h2><p>Select a student to review their standing and history.</p></div><span><?= number_format($matchedTotal) ?> <?= $matchedTotal === 1 ? 'student' : 'students' ?></span></div>
<nav class="sv-status-tabs" aria-label="Filter student status"><a class="<?= $filterStatus === '' ? 'selected' : '' ?>" href="<?= htmlspecialchars($dirUrl(['status'=>null,'page'=>1])) ?>">All students <b><?= $totalStudents ?></b></a><?php foreach($STATUSES as $st): ?><a class="<?= $filterStatus === $st ? 'selected' : '' ?>" href="<?= htmlspecialchars($dirUrl(['status'=>$st,'page'=>1])) ?>"><i style="background:<?= $STATUS_META[$st]['color'] ?>"></i><?= htmlspecialchars($STATUS_META[$st]['label']) ?> <b><?= $counts[$st] ?></b></a><?php endforeach; ?></nav>
    <form class="st-dirbar" method="get" action="status-tracker.php">
      <?php if ($filterStatus !== ''): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <?php endif; ?>

      <div class="st-search">
        <i class="fas fa-search"></i>
        <input type="search" name="q" placeholder="Search name or student ID"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off">
      </div>

      <?php /* The filter. Four facets - year, program, semester, section - as
             tick lists in one panel.

             They were not always here. Year was a strip of four buttons above
             the search and program was its own single-select beside it, which
             is two controls for one question: "3rd year" only means anything
             relative to a program, and picking 3rd year on its own returns half
             of every program on the roster. One panel says the relationship
             instead of making it be inferred from two unrelated widgets.

             A <details> element, so the disclosure, the open state and the
             keyboard behaviour are the browser's rather than reimplemented.
             Plain checkboxes in a GET form: the panel posts on Apply, no JS
             required, and the four parameters are shareable as a URL.

             Every facet is optional and they combine with AND. Ticking values
             INSIDE one facet widens the result - 1st OR 2nd year - which is
             why these are boxes and not radios. */
      $facetMeta = [
          'year'     => 'Year',
          'program'  => 'Program',
          'semester' => 'Semester',
          'section'  => 'Section',
      ]; ?>
      <details class="st-facets" id="stFacets"<?= $activeFacets ? ' open' : '' ?>>
        <summary class="st-facets-btn">
          <i class="fas fa-sliders" aria-hidden="true"></i>
          <span>Filters</span>
          <?php if ($activeFacets): ?>
            <span class="st-facets-badge"><?= count($activeFacets) ?></span>
          <?php endif; ?>
          <i class="fas fa-chevron-down st-facets-chev" aria-hidden="true"></i>
        </summary>

        <div class="st-facets-panel">
          <?php foreach ($facetMeta as $fkey => $fcap):
              $opts  = $facetOptions[$fkey] ?? [];
              $picked = $facetValues($fkey);
          ?>
            <fieldset class="st-facet">
              <legend><?= htmlspecialchars($fcap) ?></legend>
              <?php if (!$opts): ?>
                <p class="st-facet-none">None recorded yet</p>
              <?php else: ?>
                <div class="st-facet-list">
                  <?php foreach ($opts as $oval => $o): ?>
                    <label class="st-facet-opt">
                      <input type="checkbox" name="<?= $fkey ?>[]"
                             value="<?= htmlspecialchars((string) $oval) ?>"
                             <?= in_array((string) $oval, $picked, true) ? 'checked' : '' ?>>
                      <span class="st-facet-lbl"
                            <?= isset($o['title']) && $o['title'] !== $o['label']
                               ? 'title="' . htmlspecialchars($o['title']) . '"' : '' ?>><?= htmlspecialchars($o['label']) ?></span>
                      <span class="st-facet-n"><?= number_format($o['count']) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </fieldset>
          <?php endforeach; ?>

          <div class="st-facets-actions">
            <button type="submit" class="st-facets-apply">Apply filters</button>
            <?php if ($activeFacets): ?>
              <a class="st-facets-reset" href="<?= htmlspecialchars($dirUrl([
                    'year' => null, 'program' => null, 'semester' => null,
                    'section' => null, 'page' => null])) ?>">Reset</a>
            <?php endif; ?>
          </div>
        </div>
      </details>

      <button type="submit" class="st-dirbar-go">Search</button>
      <?php if ($hasAnyFilter): ?>
        <a class="st-dirbar-clear" href="status-tracker.php">Clear</a>
      <?php endif; ?>
    </form>


<div class="sv-table-scroll"><table class="sv-table"><thead><tr><th>Student</th><th>Academic details</th><th>Current standing</th><th>Last recorded change</th><th></th></tr></thead><tbody>
<?php foreach($students as $s): $sid=(int)$s['id'];$meta=studentStatusMeta($s['status']);$flag=isset($queueRank[$sid]);$name=trim($s['first_name'].' '.$s['last_name']);$photo=studentPhotoUrl($s,'../'); ?>
<tr id="sv-student-<?= $sid ?>"><td><div class="sv-person"><span class="sv-person-avatar"><?php if($photo): ?><img src="<?= htmlspecialchars($photo) ?>" alt="" onerror="this.remove()"><?php endif; ?><span><?= htmlspecialchars(studentInitials($s['first_name'],$s['last_name'])) ?></span></span><div><strong><?= htmlspecialchars($name) ?></strong><small><?= htmlspecialchars($s['student_number']) ?></small></div></div></td><td><strong class="sv-program"><?= htmlspecialchars($s['course'] ?: 'Program not set') ?></strong><small class="sv-muted"><?= (int)$s['year_level'] > 0 ? 'Year '.(int)$s['year_level'] : 'Year not set' ?><?= !empty($s['section']) ? ' · '.htmlspecialchars($s['section']) : '' ?></small></td><td><span class="sv-standing" style="--standing:<?= $meta['color'] ?>;--standing-bg:<?= $meta['bg'] ?>"><i class="<?= $meta['icon'] ?>"></i><?= htmlspecialchars($meta['label'] ?: 'Unset') ?></span><?php if($flag): ?><small class="sv-review-needed"><i class="fas fa-flag"></i> Review needed</small><?php endif; ?></td><td><?php if($s['last_change']): ?><strong class="sv-date"><?= date('M j, Y',strtotime($s['last_change'])) ?></strong><small class="sv-muted">Recorded in history</small><?php else: ?><span class="sv-no-history">No changes recorded</span><?php endif; ?></td><td><button class="sv-review" type="button" onclick='openStudentModal(<?= $sid ?>,<?= htmlspecialchars(json_encode($name),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($s['student_number']),ENT_QUOTES) ?>)'>Review student <i class="fas fa-arrow-right"></i></button></td></tr>
<?php endforeach; ?>
<?php if(!$students): ?><tr><td colspan="5"><div class="sv-empty"><i class="fas fa-user-magnifying-glass"></i><strong>No students found</strong><p>Try a different search or clear the filters.</p><a href="status-tracker.php">Clear filters</a></div></td></tr><?php endif; ?>
</tbody></table></div><footer class="sv-pagination"><span><?= $matchedTotal ? ($offset+1).'–'.min($offset+$PER_PAGE,$matchedTotal).' of '.number_format($matchedTotal).' students' : 'No matching students' ?></span><div><?php if($pageNum>1): ?><a href="<?= htmlspecialchars($dirUrl(['page'=>$pageNum-1])) ?>">Previous</a><?php endif; ?><span>Page <?= $pageNum ?> of <?= $totalPages ?></span><?php if($pageNum<$totalPages): ?><a href="<?= htmlspecialchars($dirUrl(['page'=>$pageNum+1])) ?>">Next</a><?php endif; ?></div></footer></section>
<div class="st-drawer" id="stDrawer" role="dialog" aria-modal="true" aria-labelledby="stDrawerTitle" hidden>
  <div class="st-drawer-scrim" onclick="closeDrawer()"></div>
  <div class="st-drawer-panel">
    <div class="st-drawer-head">
      <h2 id="stDrawerTitle"><i class="fas fa-magnifying-glass"></i> <span id="stDrawerLabel">Check</span></h2>
      <button type="button" class="st-drawer-x" onclick="closeDrawer()" aria-label="Close results"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="st-drawer-body" id="stDrawerBody">
      <div class="st-loading"><i class="fas fa-spinner fa-spin"></i> Reading records&hellip;</div>
    </div>
  </div>
</div>
</main>

<!-- History Modal -->
<div class="st-modal-overlay sv-review-modal" id="studentModal" role="dialog" aria-modal="true" aria-labelledby="modalName">
  <div class="st-modal">
    <div class="st-modal-header">
      <div class="st-modal-header-info">
        <div id="modalAvatar" class="st-modal-header-avatar" style="background:var(--brand-500)"></div>
        <div><div class="st-modal-header-name" id="modalName"></div><div class="st-modal-header-num" id="modalNumber"></div></div>
      </div>
      <div class="st-modal-header-actions">
        <button class="st-btn-sm st-btn-apply" id="btnModalCase" onclick="assembleCase()"><i class="fas fa-folder-open"></i> Refresh review</button>
        <button class="st-modal-close" onclick="closeModal()" aria-label="Close student status history"><i class="fas fa-xmark"></i></button>
      </div>
    </div>
    <!--
      The findings and the evidence they were read from, side by side.
      The note is the only part a language model writes, and it is handed
      findings that have already fired. There is no Apply control in this
      panel on purpose: a status change is made in the form below it, by
      the person, with a reason attached.
    -->
    <div class="sv-modal-profile"><div><small>CURRENT STANDING</small><strong id="stCurrentStatus">—</strong></div><div><small>PROGRAM &amp; YEAR</small><strong id="svModalProgram">—</strong></div></div>
    <nav class="st-modal-tabs" aria-label="Student review"><button type="button" class="on" data-st-pane="review" onclick="setStatusPane('review')"><i class="fas fa-address-card"></i> Overview</button><button type="button" data-st-pane="history" onclick="setStatusPane('history')"><i class="fas fa-clock-rotate-left"></i> History</button><button type="button" data-st-pane="change" onclick="setStatusPane('change')"><i class="fas fa-pen-to-square"></i> Change status</button></nav>
    <div class="st-case" id="modalCase">
      <div class="st-case-head">
        <div class="st-modal-ai-lbl"><i class="fas fa-clipboard-check"></i> Record overview</div>
        <span class="st-case-src" id="modalCaseSrc"></span>
      </div>
      <div class="st-case-note" id="modalCaseNote">Assembling the record…</div>
      <div class="st-case-body">
        <div class="st-case-col">
          <h4>Concerns to review</h4>
          <div id="modalCaseFlags"><div class="st-modal-empty"><i class="fas fa-inbox"></i> Nothing flagged</div></div>
        </div>
        <div class="st-case-col">
          <h4>Supporting records</h4>
          <div id="modalCaseEvidence"><div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div></div>
        </div>
      </div>
    </div>
    <div class="st-modal-timeline">
      <div class="st-modal-timeline-h"><i class="fas fa-clock-rotate-left"></i> Status History</div>
      <div id="modalTimeline">
        <div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>
      </div>
    </div>

    <!--
      The one place on this page a status can change, and it is a form a
      person fills in rather than a button a panel offers.

      A reason is required, because this journal is the only record of
      why a student moved. The old Apply button sent none, so every
      transition applied from this page was logged with a null reason.

      The two date fields are what make a leave of absence end. The
      columns existed and nothing wrote them, so an LOA had no return
      date and could never be known to have expired. They only appear
      for the statuses that are time-boxed by nature; a graduation has
      no expiry, and asking for one would be asking the wrong question.
    -->
    <form class="st-change" id="stChangeForm" hidden onsubmit="return false;">
      <div class="st-change-head">
        <h4>Choose the new standing</h4>
        <span class="st-change-hint">This updates the student record immediately after confirmation.</span><div class="sv-status-guidance" id="svStatusGuidance">Select an approved status, then describe the supporting decision below.</div>
      </div>
      <div class="st-change-grid">
        <div class="st-field">
          <label for="chStatus">New status</label>
          <select id="chStatus" onchange="toggleWindowFields();showStandingDescription()">
            <?php foreach ($STATUSES as $s): ?>
              <option value="<?= $s ?>"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $s))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="st-field st-field-wide">
          <label for="chReason">Reason <span class="st-req">required</span></label>
          <textarea id="chReason" maxlength="255" rows="3" placeholder="e.g. Approved withdrawal received on November 10."></textarea>
        </div>
        <div class="st-field st-window" id="chEffectiveWrap" hidden>
          <label for="chEffective">Effective from</label>
          <input type="date" id="chEffective">
        </div>
        <div class="st-field st-window" id="chEndWrap" hidden>
          <label for="chEnd">Window ends <span class="st-req">for a timed leave</span></label>
          <input type="date" id="chEnd">
        </div>
      </div>
      <div class="st-change-foot">
        <span class="st-change-err" id="chError" role="alert"></span>
        <button type="button" class="st-btn-dismiss" onclick="setStatusPane('review')">Back to review</button>
        <button type="button" class="st-btn-apply" id="chSubmit" onclick="reviewStatusChange()">
          Review change
        </button>
      </div>
    </form>
    <div class="st-review-footer" id="stReviewFooter"><button type="button" class="st-btn-dismiss" onclick="closeModal()">Close</button><button type="button" class="st-btn-apply" onclick="setStatusPane('change')"><i class="fas fa-pen"></i> Change status</button></div>
  </div>
</div>
<div class="st-confirm-overlay" id="stConfirm" hidden role="dialog" aria-modal="true" aria-labelledby="stConfirmTitle"><div class="st-confirm-card"><div class="st-confirm-icon"><i class="fas fa-user-check"></i></div><h2 id="stConfirmTitle">Confirm status change</h2><p id="stConfirmStudent"></p><div class="st-transition"><strong id="stConfirmFrom"></strong><i class="fas fa-arrow-right"></i><strong id="stConfirmTo"></strong></div><div class="st-confirm-reason"><small>Reason for change</small><p id="stConfirmReason"></p></div><p class="st-confirm-note">Saves immediately and records your name in status history.</p><div class="st-confirm-actions"><button type="button" class="st-btn-dismiss" onclick="closeStatusConfirm()">Back</button><button type="button" class="st-btn-apply" id="stConfirmSave" onclick="saveStatusConfirmed()">Confirm &amp; save</button></div><p id="stConfirmError" role="alert"></p></div></div>

<script>window.STUDENT_STANDING = <?= json_encode(array_column($db->fetchAll("SELECT id, status FROM students"), 'status', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.STUDENT_PROFILE = <?= json_encode(array_column($db->fetchAll("SELECT id, course, year_level FROM students"),null,'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="../js/status-workflow.js?v=20261110-v2"></script>
<!-- Toast -->
<div class="st-toast" id="toast"></div>

<!-- The filter panel is a native <details>, so it opens, closes and takes the
     keyboard with no help at all. The only thing a browser will not do is
     close it when the pointer goes down somewhere else, which is why a person
     ends up clicking a filter open and then having to click the button again
     to shut it. Everything else is deliberately absent: no live filtering, no
     auto-submit on tick. Ticking several boxes and then committing is the
     point, and a filter that reloads on every click makes comparing two
     combinations impossible. -->
<script>
(function(){
    var box = document.getElementById('stFacets');
    if (!box) return;
    document.addEventListener('pointerdown', function (e) {
        if (box.open && !box.contains(e.target)) box.open = false;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && box.open) {
            box.open = false;
            box.querySelector('summary').focus();
        }
    });
})();
</script>

<script>
'use strict';
(function(){
const STATUS_META=<?= json_encode($STATUS_META) ?>;
const ALL_STATUSES=<?= json_encode($STATUSES) ?>;
const DB_STATUSES=<?= json_encode($STATUSES) ?>;
const SEARCH_DELAY=250;
let searchTimer=null;

/* --- Distribution Bar ---
   Removed. The rail's "By status" list now carries the same counts, and
   a proportional bar is a third rendering of one column of data. It also
   took a full band of height above the directory, which is the thing
   this layout exists to reclaim. */

/* --- Helpers --- */
function escapeHTML(str){const d=document.createElement('div');d.textContent=str;return d.innerHTML;}
function toast(msg,type){
const el=document.getElementById('toast');
if(!el)return;
el.textContent=msg;
el.className='st-toast '+(type||'')+' show';
setTimeout(()=>el.classList.remove('show'),3000);
}
window.toast=toast;

/* --- Review tools --- */
// The four-button bar is gone. "Scan records" is one link in the
// rail, because the rail already lists what it finds — the button
// explained the list rather than adding to it. The other three checks
// live behind the case panel's overflow menu.

/* --- Review drawer ---
   Results arrive in a slide-over. They used to render inline above the
   table, so the directory jumped down every time a check ran and the row
   you were reading was never in the same place twice. */
function openDrawer(label){
const d=document.getElementById('stDrawer');
if(!d)return;
d.hidden=false;
// Force a reflow so the opening transition runs on first paint.
void d.offsetWidth;
d.classList.add('open');
document.body.style.overflow='hidden';
const l=document.getElementById('stDrawerLabel');
if(l)l.textContent=label||'Check';
const x=document.querySelector('#stDrawer .st-drawer-x');
if(x)x.focus();
}
window.openDrawer=openDrawer;

function closeDrawer(){
const d=document.getElementById('stDrawer');
if(!d)return;
d.classList.remove('open');
document.body.style.overflow='';
// Wait out the transition before removing it from the tree, so the panel
// does not vanish mid-slide.
setTimeout(()=>{d.hidden=true;},200);
}
window.closeDrawer=closeDrawer;

document.addEventListener('keydown',e=>{
if(e.key==='Escape'){
const d=document.getElementById('stDrawer');
if(d&&!d.hidden){closeDrawer();return;}
}
});

function showAILoading(label){
openDrawer(label||'Check');
const body=document.getElementById('stDrawerBody');
if(body)body.innerHTML='<div class="st-loading"><i class="fas fa-spinner fa-spin"></i> Reading records…</div>';
}

function renderRecCards(recs){
if(!recs||!recs.length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No transitions suggested</div>';
let h='';recs.forEach((rec,i)=>{
const sv=rec.severity||'low';
const cls=sv==='high'?'sv-high':sv==='med'?'sv-med':'sv-low';
// The title read rec.type, a key no endpoint has ever returned, so every
// card fell back to the same generic heading and the panel said nothing
// about what it was suggesting. The transition itself is the useful part.
const to=rec.recommended_status?String(rec.recommended_status).replace(/-/g,' '):'review only';
const from=String(rec.current_status||'').replace(/-/g,' ');
h+='<div class="st-rec '+cls+'" id="rec-'+i+'"><div class="st-rec-info"><div class="st-rec-title">'+escapeHTML(from)+' <i class="fas fa-arrow-right" style="font-size:9px"></i> '+escapeHTML(to)+'</div>';
h+='<div class="st-rec-name">'+escapeHTML(rec.student_name)+' <span class="st-ai-src">'+escapeHTML(rec.student_number||'')+'</span></div>';
h+='<div class="st-rec-reason">'+escapeHTML(rec.reason)+'</div>';
h+='<div class="st-rec-acts"><button class="st-btn-apply" onclick="applyRec(\''+i+'\','+parseInt(rec.student_id)+',\''+escapeHTML(rec.recommended_status||'')+'\')">Review case</button>';
h+='<button class="st-btn-dismiss" onclick="dismissRec(\''+i+'\')">Dismiss</button></div></div></div>';
});return h;
}

/* --- What I missed ---
   One student per card, each contradiction underneath it, and a way in
   to the full evidence. Nothing here changes anything: the buttons open
   a read-only case, and any status change is made from the form inside
   it, by the person, with a reason. */
function renderMissedCards(data){
const d=(data&&data.data)||data||{};
const list=d.findings||[];
const count=list.length;
let h='';
if(d.partial){
h+='<div class="st-find-warn"><i class="fas fa-triangle-exclamation"></i> Some records could not be read, so this list is incomplete.</div>';
}
if(!count){
return{html:h+'<div class="st-find-clear"><i class="fas fa-circle-check"></i><b>No review concerns found</b><span>Every record on the roster agrees with itself. New findings land here as soon as one does not.</span></div>',count:0};
}
list.forEach((f,i)=>{
const meta=STATUS_META[f.current_status]||STATUS_META.inactive;
const parts=String(f.student_name||'?').trim().split(' ');
const initials=(parts[0]?parts[0][0]:'')+(parts.length>1?parts[parts.length-1][0]:'');
const issues=f.issues||[];
h+='<article class="st-find" id="missed-'+i+'">';
h+='<header class="st-find-head">';
h+='<span class="st-find-av" style="background:'+meta.bg+';color:'+meta.color+'">'+escapeHTML(initials.toUpperCase())+'</span>';
h+='<span class="st-find-who"><b>'+escapeHTML(f.student_name)+'</b><span>'+escapeHTML(f.student_number||'No ID')+'</span></span>';
h+='<span class="st-find-badge" style="background:'+meta.bg+';color:'+meta.color+'">'+escapeHTML(String(f.current_status||'unset').replace(/-/g,' '))+'</span>';
h+='</header>';
if(issues.length>1)h+='<p class="st-find-n">'+issues.length+' contradictions in this record</p>';
h+='<ul class="st-find-list">';
issues.forEach(iss=>{
h+='<li class="st-find-iss">';
h+='<span class="st-find-ico"><i class="fas fa-triangle-exclamation"></i></span>';
h+='<div class="st-find-issbody">';
h+='<b>'+escapeHTML(iss.title)+'</b>';
h+='<p>'+escapeHTML(iss.detail)+'</p>';
h+='<p class="st-find-q"><i class="fas fa-circle-question"></i><span>'+escapeHTML(iss.question)+'</span></p>';
h+='</div></li>';
});
h+='</ul>';
h+='<footer class="st-find-foot">';
h+='<button class="st-btn-apply" onclick="openFromCard(\'missed-'+i+'\','+parseInt(f.student_id)+')">Read the case</button>';
h+='<button class="st-btn-dismiss" onclick="dismissRec(\'missed-'+i+'\')">Dismiss</button>';
h+='</footer></article>';
});
return{html:h,count:count};
}

/* Open the case for a student named in a panel card, reusing the name and
   number already rendered there rather than re-fetching a list. */
function openFromCard(cardId,studentId){
const card=document.getElementById(cardId);
const nameEl=card?card.querySelector('.st-rec-title'):null;
const numEl=card?card.querySelector('.st-ai-src'):null;
let name=nameEl?nameEl.textContent:'';
if(nameEl){const clone=nameEl.cloneNode(true);const ic=clone.querySelector('i');if(ic)ic.remove();name=clone.textContent.trim();}
openStudentModal(studentId,name,numEl?numEl.textContent.trim():'');
}

function renderAnomCards(anoms){
if(!anoms||!anoms.length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No anomalies</div>';
let h='';anoms.forEach((a,i)=>{
const msg=a.label||a.message||JSON.stringify(a);
h+='<div class="st-rec sv-med" id="anom-'+i+'"><div class="st-rec-info"><div class="st-rec-title"><i class="fas fa-magnifying-glass-chart"></i> Anomaly</div>';
h+='<div class="st-rec-reason">'+escapeHTML(msg)+'</div></div></div>';
});return h;
}

function renderRiskCards(risks){
if(!risks||typeof risks!=='object'||!Object.keys(risks).length)return'<div style="text-align:center;padding:16px;color:var(--text-subtle);font-size:13px"><i class="fas fa-check-circle" style="color:#16a34a"></i> No risk data</div>';
let h='';Object.keys(risks).forEach(id=>{
const risk=risks[id];const level=risk.risk||'low';
const cls=level==='high'?'sv-high':level==='medium'?'sv-med':'sv-low';
h+='<div class="st-rec '+cls+'" id="risk-'+id+'"><div class="st-rec-info"><div class="st-rec-title"><i class="fas fa-shield-halved"></i> Student #'+escapeHTML(id)+'</div>';
h+='<div class="st-rec-name">'+escapeHTML(risk.name||'Student #'+id)+' <span class="st-ai-src">Risk: '+escapeHTML(level)+'</span></div>';
h+='<div class="st-rec-reason">'+escapeHTML(risk.reason||'No reason')+'</div></div></div>';
});return h;
}

async function fetchAIEndpoint(action,body){
let url='../api/ai-tools.php?action='+action;
const opts={method:'GET'};
if(body){opts.method='POST';opts.headers={'Content-Type':'application/json'};opts.body=JSON.stringify(body);}
const r=await fetch(url,opts);
if(!r.ok)throw new Error('API error');
return await r.json();
}

async function runAI(tab){
const labels={missed:'What I missed',anomalies:'Activity check',risks:'Attention',recommendations:'Suggested transitions',all:'All checks'};
const label=labels[tab]||'Check';
showAILoading(label);
const body=document.getElementById('stDrawerBody');
if(!body)return;
try{
if(tab==='all'){
const results=await Promise.allSettled([
fetchAIEndpoint('missed_checks'),
fetchAIEndpoint('status_recommendations'),
fetchAIEndpoint('status_anomalies'),
fetchAIEndpoint('student_risks',{student_ids:[]})
]);
let html='',count=0;
const sections=['What I missed','Suggested transitions','Activity check','Attention'];
results.forEach((res,i)=>{
let content='';let cnt=0;
if(res.status==='fulfilled'){
const d=res.value;
if(i===0){const r=renderMissedCards(d);content=r.html;cnt=r.count;}
else if(i===1){const r=d.data?.recommendations||d.recommendations||[];content=renderRecCards(r);cnt=r.length;}
else if(i===2){const a=d.data?.anomalies||d.anomalies||[];content=renderAnomCards(a);cnt=a.length;}
else if(i===3){const r=d.data?.risks||d.risks||{};content=renderRiskCards(r);cnt=Object.keys(r).length;}
}else{
content='<div class="st-panel-fail"><i class="fas fa-exclamation-circle"></i> This check did not return. The others are unaffected.</div>';
}
html+='<div class="st-ai-section"><div class="st-ai-section-hdr">'+sections[i]+' ('+cnt+')</div>'+content+'</div>';
count+=cnt;
});
if(count===0)html='<div class="st-empty"><i class="fas fa-circle-check"></i><strong>Nothing flagging</strong><span>These checks found nothing to act on.</span></div>';
body.innerHTML=html;
}else{
const epMap={missed:'missed_checks',anomalies:'status_anomalies',risks:'student_risks',recommendations:'status_recommendations'};
const postBody=(tab==='risks')?{student_ids:[]}:undefined;
const data=await fetchAIEndpoint(epMap[tab]||'missed_checks',postBody);
let html='';
if(tab==='missed'){html=renderMissedCards(data).html;}
else if(tab==='recommendations'){const r=data.data?.recommendations||data.recommendations||[];html=renderRecCards(r);}
else if(tab==='anomalies'){const a=data.data?.anomalies||data.anomalies||[];html=renderAnomCards(a);}
else if(tab==='risks'){const r=data.data?.risks||data.risks||{};html=renderRiskCards(r);}
body.innerHTML=html;
}
}catch(e){
body.innerHTML='<div class="st-panel-fail"><i class="fas fa-exclamation-circle"></i> Unable to run this check. The others are unaffected.</div>';
}
}
window.runAI=runAI;

/* --- Suggested transitions ---
   Opens the case panel rather than applying. The old button posted to
   bulk-status the moment it was clicked, with no confirmation and no
   reason, so the change landed and the journal recorded nothing about
   why. A suggestion is now a place to read, not a shortcut to commit. */
function applyRec(idx,studentId,status){
const card=document.getElementById('rec-'+idx);
const nameEl=card?card.querySelector('.st-rec-name'):null;
const numEl=card?card.querySelector('.st-ai-src'):null;
const label=(nameEl?nameEl.textContent:'').replace(numEl?numEl.textContent:'',' ').trim();
openStudentModal(studentId,label,numEl?numEl.textContent:'');
const sel=document.getElementById('chStatus');
if(sel){sel.value=status;toggleWindowFields();}
const reason=document.getElementById('chReason');
if(reason){reason.focus();}
toast('Review the case, then record the change with a reason.','info');
}
window.applyRec=applyRec;

function dismissRec(idx){
const card=document.getElementById('rec-'+idx);
if(card){card.style.opacity='0';setTimeout(()=>card.remove(),300);}
}
window.dismissRec=dismissRec;

/* --- Attention Dots Loader ---
   The endpoint this calls was named status_risks, and the API only ever
   defined student_risks — so every request fell through to "Unknown
   action". The reply was HTTP 200, so nothing threw; the JSON simply had
   no risks key, and the whole Risk column sat blank with no error
   anywhere. Both names now resolve, and an empty id list means "the
   whole roster" rather than a rejection. */
async function loadRisks(){
const dots=document.querySelectorAll('.st-rdot.loading');
if(!dots.length)return;
try{
const r=await fetch('../api/ai-tools.php?action=student_risks',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({student_ids:[]})});
if(!r.ok)throw new Error();
const data=await r.json();
if(!data.success)throw new Error(data.message||'failed');
const risks=(data.data&&data.data.risks)||data.risks||{};
dots.forEach(dot=>{
const id=dot.dataset.studentId;
if(risks[id]){const level=risks[id].risk||'low';
dot.className='st-rdot '+(level==='high'?'high':level==='medium'?'med':'low');
dot.title=risks[id].reason||level;
}else{dot.classList.remove('loading');dot.classList.add('unk');}
});
}catch(e){dots.forEach(dot=>{dot.classList.remove('loading');dot.classList.add('unk');});}
}

/* Search is server-side now.

   It used to hide rows already in the DOM, which only worked because
   every student was rendered. With paging that would silently fail: a
   name living on page 4 is not in the page-1 markup, so searching for
   it would return nothing and look like no student by that name exists.
   The input is in a GET form and submits, so the query reaches every
   row and the result is a shareable URL. */

/* --- Init --- */
const missedBtn=document.getElementById('btnAIMissed');
if(missedBtn)missedBtn.addEventListener('click',function(){runAI('missed');});

// toggleWindowFields() is called at the end of this script, next to the
// WINDOWED list it reads. It used to be called from the init block above,
// which runs before that const is initialised - a temporal dead zone
// ReferenceError on every page load, thrown before anything else in the init
// sequence could run.
loadRisks();

/* --- Student Modal --- */
window.openStudentModal=function(id,name,number){
const modal=document.getElementById('studentModal');
window._currentModalStudentId=id;
document.getElementById('modalName').textContent=name;
document.getElementById('modalNumber').textContent=number;
const av=document.getElementById('modalAvatar');
if(av){const parts=name.split(' ');const initials=(parts[0]?parts[0][0]:'')+(parts[1]?parts[1][0]:'');av.textContent=initials.toUpperCase();}
document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';
// Reset the case panel and the change form every time, so a previous
// student's evidence is never read as this one's.
const note=document.getElementById('modalCaseNote');
if(note)note.textContent='Reading supporting records…';
const flags=document.getElementById('modalCaseFlags');
if(flags)flags.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div>';
const ev=document.getElementById('modalCaseEvidence');
if(ev)ev.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div>';
const src=document.getElementById('modalCaseSrc');
if(src)src.textContent='';
const reason=document.getElementById('chReason');
if(reason)reason.value='';
const err=document.getElementById('chError');
if(err)err.textContent='';
modal.classList.add('show');
document.body.style.overflow='hidden';
fetchStudentHistory(id);
prepareStatusReview(id);
assembleCase();
};

/* --- Assemble a case ---
   Replaces the old "Generate AI profile". It called action=profile, which
   built its brief with sprintf('%s is currently listed as %s...') and
   returned source:'rules' — it never called a model at all, so a button
   labelled AI was showing a fill-in-the-blank. This panel renders real
   cross-module evidence and the questions that evidence raises. */
async function assembleCase(){
const id=window._currentModalStudentId||0;
if(!id)return;
const btn=document.getElementById('btnModalCase');
const noteEl=document.getElementById('modalCaseNote');
const flagEl=document.getElementById('modalCaseFlags');
const evEl=document.getElementById('modalCaseEvidence');
const srcEl=document.getElementById('modalCaseSrc');
if(btn){btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Reading';btn.disabled=true;}
if(noteEl)noteEl.textContent='Reading the record…';
if(flagEl)flagEl.innerHTML='<div class="st-modal-empty"><i class="fas fa-spinner fa-spin"></i> Working</div>';
if(evEl)evEl.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> Not read yet</div>';
try{
const r=await fetch('../api/ai-tools.php?action=case_brief',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})});
if(!r.ok)throw new Error();
const d=await r.json();
if(!d.success)throw new Error(d.message||'unavailable');
const dd=d.data||{};
if(noteEl)noteEl.textContent=dd.note||'No summary available.';
if(srcEl)srcEl.textContent=dd.source==='ai'?'phrasing by AI':'Record checks';
renderCaseFlags(dd.findings||[]);
renderCaseEvidence(dd.evidence||{},!!dd.partial);
}catch(e){
if(noteEl)noteEl.textContent='The case could not be assembled. A record may be missing on this server.';
if(flagEl)flagEl.innerHTML='<div class="st-modal-empty"><i class="fas fa-exclamation-circle"></i> Nothing read</div>';
}
if(btn){btn.innerHTML='<i class="fas fa-folder-open"></i> Refresh review';btn.disabled=false;}
}
window.assembleCase=assembleCase;

function renderCaseFlags(findings){
const el=document.getElementById('modalCaseFlags');
if(!el)return;
if(!findings.length){el.innerHTML='<div class="st-modal-empty"><i class="fas fa-circle-check"></i> No review concerns found</div>';return;}
let h='';
findings.forEach(f=>{
h+='<div class="st-case-flag w-'+escapeHTML(f.weight||'low')+'">';
h+='<div class="st-case-flag-t">'+escapeHTML(f.title)+'</div>';
h+='<div class="st-case-flag-d">'+escapeHTML(f.detail)+'</div>';
h+='<div class="st-case-flag-q"><i class="fas fa-circle-question"></i> '+escapeHTML(f.question)+'</div>';
h+='</div>';
});
el.innerHTML=h;
}

function renderCaseEvidence(ev,partial){
const el=document.getElementById('modalCaseEvidence');if(!el)return;
const disc=ev.discipline||{},docs=ev.documents||{};
const rows=[['fa-wallet','Outstanding balance','PHP '+Number(ev.balance||0).toLocaleString('en-PH',{minimumFractionDigits:2})],['fa-file-lines','Document requests',Number(docs.open||0)+' open · '+Number(docs.held||0)+' held'],['fa-scale-balanced','Disciplinary cases',(disc.pending||[]).length+' pending'],['fa-user-shield','Guardian contact',ev.has_guardian?'On file':'Not on file']];
if((ev.grades||[]).length)rows.push(['fa-graduation-cap','Latest GWA',Number(ev.grades[0].gwa).toFixed(2)]);
if(ev.last_scan)rows.push(['fa-id-card','Last card scan',String(ev.last_scan).slice(0,16).replace('T',' ')]);
el.innerHTML='<div class="sv-evidence-grid">'+rows.map(r=>'<div class="sv-evidence-item"><i class="fas '+r[0]+'"></i><div><small>'+escapeHTML(r[1])+'</small><strong>'+escapeHTML(r[2])+'</strong></div></div>').join('')+'</div>'+(partial?'<p class="sv-partial">Some sources could not be read. Verify missing information before deciding.</p>':'');
}

async function fetchStudentHistory(id){
try{
const r=await fetch('../api/status-history.php?student_id='+id);
if(!r.ok)throw new Error();
const d=await r.json();
const history=d.data||d.history||[];
const container=document.getElementById('modalTimeline');
if(history.length===0){container.innerHTML='<div class="st-modal-empty"><i class="fas fa-inbox"></i> No status history</div>';return;}
let html='';
history.forEach(h=>{
const meta=STATUS_META[h.current_status]||STATUS_META['inactive'];
const prevMeta=STATUS_META[h.previous_status]||{bg:'#eef2f7',color:'#64748b'};
html+='<div class="st-tl-i"><div class="st-tl-dot" style="background:'+meta.color+'"></div><div class="st-tl-chg">';
html+='<span class="st-badge" style="background:'+prevMeta.bg+';color:'+prevMeta.color+';padding:2px 6px;font-size:10px">'+(h.previous_status||'N/A')+'</span>';
html+=' <i class="fas fa-arrow-right" style="color:var(--text-subtle);font-size:10px"></i> ';
html+='<span class="st-badge" style="background:'+meta.bg+';color:'+meta.color+';padding:2px 6px;font-size:10px">'+h.current_status+'</span>';
html+='</div>';
if(h.reason)html+='<div class="st-tl-rsn">'+escapeHTML(h.reason)+'</div>';
html+='<div class="st-tl-time">'+escapeHTML(h.changed_by_name||'System')+' \u00b7 '+new Date(h.created_at).toLocaleString()+'</div></div>';
});
container.innerHTML=html;
}catch(e){document.getElementById('modalTimeline').innerHTML='<div class="st-modal-empty"><i class="fas fa-exclamation-circle"></i> Failed to load history</div>';}
}

window.closeModal=function(){
document.getElementById('studentModal').classList.remove('show');
document.body.style.overflow='';
};
document.getElementById('studentModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

/* --- Recording a status change ---
   The only write path on this page, and it is a form a person fills in.

   Two rules the old Apply button broke. It posted the new status the
   moment it was clicked, with no confirmation — one mis-click changed a
   student's record. And it sent no reason, so trackStatusChange() logged
   a null and the journal could show that someone changed and nothing
   about why. Both are fixed here: the reason is required client-side and
   rejected server-side, and the window fields are only offered for
//   statuses that are actually time-boxed. */

// The effective/return date fields. This used to list loa, probation and
// transferred - the three statuses that were time-boxed by nature, and all
// three of which are gone. None of the five current statuses is a window:
// enrolled, active, graduate, alumni and dropped are all indefinite states.
//
// The fields are therefore never offered, and toggleWindowFields() is kept as a
// no-op rather than deleted, because the markup that calls it on change is
// harmless and removing the call sites would be a larger change to a form
// layout than the status list itself warrants. If a windowed status is ever
// reintroduced, this is the list to add it to - and it must be a status in
// studentStatuses(), or the date would be written for a state that cannot exist.
const WINDOWED=[];

function toggleWindowFields(){
const sel=document.getElementById('chStatus');
const wrapped=sel?WINDOWED.indexOf(sel.value)!==-1:false;
const eff=document.getElementById('chEffectiveWrap');
const end=document.getElementById('chEndWrap');
if(eff)eff.hidden=!wrapped;
if(end)end.hidden=!wrapped;
}
window.toggleWindowFields=toggleWindowFields;

async function submitStatusChange(){
const id=window._currentModalStudentId||0;
const sel=document.getElementById('chStatus');
const reasonEl=document.getElementById('chReason');
const errEl=document.getElementById('chError');
const btn=document.getElementById('chSubmit');
const effEl=document.getElementById('chEffective');
const endEl=document.getElementById('chEnd');
const err=msg=>{if(errEl){errEl.textContent=msg;}};
err('');
if(!id){err('Open a student first.');return;}
const status=sel?sel.value:'';
const reason=reasonEl?reasonEl.value.trim():'';
if(!reason){
err('Give a reason — the status history is the only record of why this changed.');
if(reasonEl)reasonEl.focus();
return;
}
const payload={ids:[parseInt(id,10)],status:status,reason:reason};
if(WINDOWED.indexOf(status)!==-1){
const effVal=effEl?effEl.value.trim():'';
const endVal=endEl?endEl.value.trim():'';
if(!endVal){
err('A timed status needs an end date, or the window can never be seen to have closed.');
if(endEl)endEl.focus();
return;
}
payload.end_date=endVal;
if(effVal)payload.effective_date=effVal;
}
if(btn){btn.disabled=true;btn.textContent='Recording...';}
try{
const r=await fetch('../api/students.php?action=bulk-status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
const d=await r.json();
if(!d.success)throw new Error(d.message||'The change was not recorded.');
toast('Status recorded for '+(document.getElementById('modalName').textContent||'student'),'success');
setTimeout(()=>location.reload(),900);
}catch(e){
err(e.message||'The change was not recorded.');
if(btn){btn.disabled=false;btn.textContent='Review change';}
closeStatusConfirm();
}
}
window.submitStatusChange=submitStatusChange;

// Now that WINDOWED exists, run the field toggle the init block could not.
toggleWindowFields();

})();
</script>

<?php
// The shared footer. This page was ending here, which left the whole
// bottom of the app missing with it:
//
//   js/sidebar.js         the collapse button and the mobile drawer were
//                         rendered by includes/sidebar.php but had nothing
//                         listening to them, so they did nothing
//   js/session-warning.js the idle auto-logout, so a left-open session
//                         never timed itself out on this page alone
//   js/logout.js          the logout confirmation every other page asks
//                         for (the link still navigated; it just stopped
//                         asking first)
//   js/auth.js            the auth checks
//   </body></html>        the document was never closed
//
// Only this page and the two standalone ones - rfid-kiosk.php and
// smtp-debug.php, which have no sidebar and want no chrome - were
// missing it.
include '../includes/footer.php';
?>

