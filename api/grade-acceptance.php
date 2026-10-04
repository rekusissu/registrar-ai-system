<?php
// ============================================================
//  API/GRADE-ACCEPTANCE.PHP
//  Registrar accepts a section's term, or leaves it on the waitlist.
//
//  POST action=accept  -> accept one section's term
//  POST action=reopen  -> put an accepted term back on the waitlist
//  GET  action=gate    -> the acceptance verdict for one section
//
//  This endpoint writes ONE fact: that the Registrar accepted a term.
//  It never writes, edits or infers a grade. Grades belong to Faculty
//  Management #296 (DEPARTMENTS.md) and the registrar write paths for
//  them return 409 on purpose. The gate is recomputed from the live
//  roster on every call rather than trusted from the request, so a
//  section that lost a grade since the page was rendered cannot be
//  accepted by replaying an old verdict.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';
require_once __DIR__ . '/../shared/grade_acceptance.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in to accept a term.']);
    exit;
}

// Registrar only. Accepting a term is a signature on an official record;
// it is not an action the student portal or Faculty's view may take. Same
// check requireRole('registrar') makes on the page, admin included.
if (!in_array(getCurrentUserRole(), ['registrar', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only a registrar can accept a term.']);
    exit;
}

$db = Database::getInstance();

$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action  = $_GET['action'] ?? '';
$input   = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sy  = trim((string) ($_GET['sy'] ?? $input['sy'] ?? ''));
$sem = trim((string) ($_GET['sem'] ?? $input['sem'] ?? ''));

// NOT trimmed, because PHP's trim() strips NUL by default - its default
// character list starts " \t\n\r\0\x0B" - and the NUL is the separator in the
// board's key. Trimming first turned "BSIT\011001" into "BSIT011001", which
// matched no section at all, and the gate then reported every accept as "this
// section has no students". Two trims that each look harmless, one of which
// eats the delimiter.
$sectionRaw = (string) ($_GET['section'] ?? $input['section'] ?? '');
$program    = trim((string) ($_GET['program'] ?? $input['program'] ?? ''));

if (strpos($sectionRaw, "\0") !== false) {
    [$program, $sectionRaw] = explode("\0", $sectionRaw, 2);
}
$section = trim($sectionRaw);

if ($sy === '' || $sem === '') {
    echo json_encode(['success' => false, 'message' => 'Pick a school year and semester first.']);
    exit;
}
if ($section === '') {
    echo json_encode(['success' => false, 'message' => 'Pick a section first.']);
    exit;
}

$roster = grade_acceptance_roster($db, $sy, $sem, $section, $program);
$gate   = sectionAcceptance($sy, $sem, $roster);

// Every UPDATE below filters on program as well as section, for the same
// reason. A single missed filter here would stamp an accepted_at onto another
// program's term rows, and the damage would not show until someone read that
// program's record.
$scopeWhere = "student_id IN (SELECT id FROM students WHERE section = ?"
            . ($program !== '' ? ' AND course = ?' : '') . ')';
$scopeArgs  = [$sy, $sem, $section];
if ($program !== '') {
    $scopeArgs[] = $program;
}

// Acceptance lives on the term row, so accepting a SECTION stamps one row per
// student. Already-accepted rows are counted to answer "is this section done"
// without a second query shape per action.
$acceptedRows = $db->fetchAll(
    "SELECT accepted_at FROM academic_history
      WHERE school_year = ? AND semester = ? AND accepted_at IS NOT NULL
        AND $scopeWhere",
    $scopeArgs
);
$acceptedCount = count($acceptedRows);
$acceptedAt    = $acceptedRows ? (string) $acceptedRows[0]['accepted_at'] : null;

if ($action === 'gate' && $method === 'GET') {
    echo json_encode([
        'success'     => true,
        'section'     => $section,
        'can_accept'  => $gate['can_accept'],
        'state'       => $acceptedCount > 0 ? 'accepted' : $gate['state'],
        'summary'     => $gate['summary'],
        'blocking'    => $gate['blocking'],
        'advisory'    => $gate['advisory'],
        'stats'       => $gate['stats'],
        'accepted_at' => $acceptedAt,
    ]);
    exit;
}

if ($method !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Use GET for the gate or POST to accept.']);
    exit;
}

if ($action === 'reopen') {
    // Reopening is allowed unconditionally. The reason that blocked an
    // acceptance may already be resolved, and refusing to reopen a now-clean
    // term would leave a registrar unable to undo a mis-click - which is the
    // one situation where being stuck is worst.
    $n = $db->query(
        "UPDATE academic_history SET accepted_at = NULL, accepted_by = NULL
          WHERE school_year = ? AND semester = ? AND $scopeWhere",
        $scopeArgs
    )->rowCount();

    echo json_encode([
        'success'    => true,
        'state'      => $gate['state'],
        'summary'    => $n > 0
            ? 'Put back on the waitlist.'
            : 'That section was not accepted, so nothing changed.',
        'can_accept' => $gate['can_accept'],
        'blocking'   => $gate['blocking'],
        'advisory'   => $gate['advisory'],
    ]);
    exit;
}

if ($action !== 'accept') {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Accept ────────────────────────────────────────────────────
// The gate is recomputed above, not read from the request body. A client
// that posts can_accept=true is not evidence of anything; the roster is.
if (!$gate['can_accept']) {
    http_response_code(409);
    echo json_encode([
        'success'  => false,
        'message'  => $gate['state'] === 'empty'
            ? 'This section has no students, so there is nothing to accept.'
            : 'This section is not ready. ' . $gate['summary'],
        'state'    => $gate['state'],
        'blocking' => $gate['blocking'],
        'advisory' => $gate['advisory'],
    ]);
    exit;
}

// Only students who actually have a term row for this term get stamped. A
// roster student with no academic_history row has no record to accept, and
// creating one here would forge a term Faculty never sent - so they are
// counted and reported rather than silently included or silently dropped.
$stamped = 0;
$noTerm  = [];
foreach ($roster as $r) {
    if (!$r['record_id']) {
        $noTerm[] = $r['name'];
        continue;
    }
    $db->query(
        "UPDATE academic_history SET accepted_at = NOW(), accepted_by = ?
          WHERE id = ? AND accepted_at IS NULL",
        [(int) $_SESSION['user_id'], $r['record_id']]
    );
    if ($db->fetchColumn(
        "SELECT accepted_at FROM academic_history WHERE id = ?",
        [$r['record_id']]
    ) !== null) {
        $stamped++;
    }
}

echo json_encode([
    'success'     => true,
    'state'       => 'accepted',
    'accepted_at' => date('Y-m-d H:i:s'),
    'summary'     => 'Accepted ' . $stamped . ' of ' . count($roster)
                  . ' students in ' . $section . '.',
    'stamped'     => $stamped,
    'roster'      => count($roster),
    // Named, not counted. "3 of 5" does not say who to chase.
    'no_term_row' => $noTerm,
]);

