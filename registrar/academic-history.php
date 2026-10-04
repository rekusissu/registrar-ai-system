<?php
// ============================================================
// ============================================================
//  REGISTRAR/ACADEMIC-HISTORY.PHP
//  Read-only view of term records, and the printable grade template.
//
//  BOUNDARY CHANGE 2026-10-02. Grades are owned by Faculty Management #296;
//  this office reads them. The grading editor is gone: addGradeRow(),
//  removeGradeRow(), readGrid(), saveGrades(), the Add-subject and Remove
//  controls, and the whole save-academic/delete-academic API path. See
//  DEPARTMENTS.md.
//
//  History, kept because the shape of the mistake is the reason the current
//  boundary matters. The page once imported previous schools, which was
//  wrong twice over: academic_history is consumed as TERMS by the TOR, Form
//  137 and the student grade views, and the save endpoint behind it could not
//  record a semester subject, a final rating, or a computed GWA at all — so
//  the page described records nobody was making. Then it became a grading
//  workspace, which put a second, competing owner on a record that only one
//  office can legitimately own. Two owners is how a GWA ends up typed twice
//  and disagreeing.
//
//  What remains here is the Registrar's actual job: show the terms Faculty
//  has sent, per student, with provenance and last-sync detail, and produce
//  the printable grade record.
//
//  The GWA printed is OUR computation (shared/term_grades.php over the
//  recorded ratings), not Faculty's reported figure. Both are stored, side
//  by side, and a disagreement is printed rather than silently resolved.
// ============================================================
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
if (empty($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
requireRole('registrar');
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';
// For the programme catalogue below. Masterlist loads this same file at the
// same point for the same reason - it is where getOfferedCourses() lives, and
// the catalogue has to be the SAME catalogue the course filters use, or this
// board would list programs the rest of the app does not offer.
require_once __DIR__ . '/../shared/functions.php';
$db = Database::getInstance();

// The session-bound CSRF token, taken from the guard itself.
//
// Reading $_SESSION['csrf_token'] directly would be wrong: the token is
// created lazily by csrfToken() on first call, so a session that has never
// posted anything has no value there and the page would ship an empty
// token, and every save would be refused with a 419. Asking the guard
// gets the real one and creates it if it is missing.
require_once __DIR__ . '/../shared/csrf_guard.php';
$csrfToken = csrfToken();

// ── Which term? ──────────────────────────────────────────────
// The term is chosen first and everything below is scoped to it, because
// a term is the unit of work here. Grading one is not a per-student
// action repeated 200 times; it is a single pass over a list.
$realYears = array_map(
    static fn($r) => (string) $r['school_year'],
    $db->fetchAll("
        SELECT DISTINCT school_year FROM academic_history
         WHERE school_year IS NOT NULL AND school_year <> ''
         ORDER BY school_year DESC
    ")
);

// Which years the box may offer, and which term it OPENS on, are two
// different questions, and conflating them is why the page used to greet you
// with an empty roster.
//
// The coming years are offered so a term can be started before it exists in
// the table. That is correct. But they were then pushed into the same list and
// rsort'd, so the synthetic 2026-2028 outranked every year that actually has
// grades, and $sy = $years[0] opened the page on a term guaranteed to be
// empty - the student listed, the row expandable, and "No grades received"
// behind it. Sorted together, offering a year quietly became defaulting to it.
//
// So: offer both, default to the newest year that HAS records. A database with
// no history at all still falls through to the synthetic list, so a fresh
// install opens on the current year rather than on nothing.
$years = $realYears;
$thisYear = (int) date('Y');
foreach ([$thisYear . '-' . $thisYear, $thisYear . '-' . ($thisYear + 1), $thisYear . '-' . ($thisYear + 2)] as $cand) {
    if (!in_array($cand, $years, true)) {
        $years[] = $cand;
    }
}
rsort($years);
rsort($realYears);

// ── Which term ──────────────────────────────────────────────────
// The term is typed, not chosen from a list. A registrar works in
// terms, and "2026-2028 1st" is how they say one out loud; making
// them open a dropdown to find the string they already know was the
// friction.
//
// Typing it means it can be wrong, so the parser is written to be
// forgiving about the shape and strict about the result. "2026-2028",
// "1st", "2026-2028 1st", "1st 2026-2028" and "2026-2028, 1st" all
// land on the same term, because a registrar should not have to
// remember which order the box was in.
//
// An unrecognised year is not silently swapped for the newest one.
// That would show a populated roster for a term nobody asked for,
// which is the one failure a grading screen must not have: the
// registrar would enter real grades against the wrong term. It falls
// back to the newest year and says so.
$SEMESTERS = ['1st', '2nd', 'Summer'];

$termQuery = isset($_GET['term']) ? trim((string) $_GET['term']) : '';
$sy  = '';
$sem = '1st';
$termProblem = '';

if ($termQuery !== '') {
    // Pull the year out by pattern, the semester out by its own name.
    if (preg_match('/(\d{4})\s*[-\x{2013}\x{2014}\/]\s*(\d{2,4})/u', $termQuery, $m)) {
        $sy = $m[1] . '-' . $m[2];
    }
    foreach ($SEMESTERS as $candidate) {
        // Case-insensitive so "1ST" and "summer" resolve, and matched
        // on a boundary so "1st" cannot be found inside a longer word.
        if (preg_match('/(?<![a-z0-9])' . strtolower($candidate) . '(?![a-z0-9])/i', $termQuery)) {
            $sem = $candidate;
            break;
        }
    }
    if ($sy !== '' && !in_array($sy, $years, true)) {
        $termProblem = 'There are no records for ' . htmlspecialchars($sy)
            . ' yet. Showing the most recent term instead.';
        $sy = '';
    }
    if ($sy === '' && $termProblem === '') {
        $termProblem = 'Could not read a school year from "' . htmlspecialchars($termQuery)
            . '". Try a year like 2026-2028, optionally with 1st, 2nd or Summer.';
    }
}

// Open on the newest year that actually has records, not merely the newest
// year on offer. See the note where $realYears is built.
if ($sy === '' && $realYears) {
    $sy = $realYears[0];
} elseif ($sy === '' && $years) {
    $sy = $years[0];
}

// The canonical string, so the box always shows a term the page can
// actually resolve rather than echoing back a typo.
$termCanonical = $sy . ' ' . $sem;

/**
 * Short form of a program for the roster's narrow column.
 *
 * The stored value is the full degree name, which runs to ~55 characters
 * and pushes the figures off the side of a laptop screen. Where the name
 * carries its own abbreviation in brackets — "…INFORMATION TECHNOLOGY
 * (BSIT)" — that is what a registrar actually says at the counter, so it
 * is shown. A name without one keeps its full text rather than being cut
 * down to an initialism nobody recognises.
 *
 * @return string
 */
function ahProgramShort(string $program): string
{
    $program = trim($program);
    if ($program === '') {
        return '';
    }
    if (preg_match('/\(([A-Za-z0-9]{2,10})\)\s*$/', $program, $m)) {
        return strtoupper($m[1]);
    }
    return $program;
}


// ── Roster filters ───────────────────────────────────────────
// Grouped by program and year level, with section as an optional filter.
// Section is a within-cohort detail; defaulting to it would split the
// pass into fragments that have to be stitched back together before the
// term can be called complete.
$program = isset($_GET['program']) ? trim((string) $_GET['program']) : '';
$section = isset($_GET['section']) ? trim((string) $_GET['section']) : '';

// ONLY STUDENTS WHO ARE IN A SECTION.
//
// The board is built from sections. A student the enrolment office has not yet
// placed has no section, so there is nothing to accept for them, and every card
// on this page - in view, complete, awaiting, GWA - is a claim ABOUT A SECTION.
// Counting an unsectioned student made "1 in view" and "1 awaiting grades" read
// as work outstanding on a section that does not exist.
//
// They are still real students and still belong on the Masterlist, which is
// where someone goes to PLACE them. Keeping them off this page is not hiding
// them.
//
// Both shapes of "no section" are excluded, because the column is free text
// typed by a clerk and "0" is the legacy encoding of the same absence: an empty
// string, whitespace, and the string "0" all mean unplaced.
$roster = $db->fetchAll("
    SELECT s.id, s.student_number, s.first_name, s.last_name,
            s.course AS program, s.year_level, s.section
       FROM students s
      WHERE s.status != 'archived'
        AND s.section IS NOT NULL
        AND TRIM(s.section) <> ''
        AND TRIM(s.section) <> '0'
   ORDER BY s.last_name, s.first_name"
);

$programs = [];
$sections = [];
foreach ($roster as $r) {
    $p = trim((string) ($r['program'] ?? ''));
    if ($p !== '') { $programs[$p] = true; }
    $sec = trim((string) ($r['section'] ?? ''));
    if ($sec !== '') { $sections[$sec] = true; }
}
ksort($programs);
ksort($sections);

$visible = [];
foreach ($roster as $r) {
    if ($program !== '' && trim((string) ($r['program'] ?? '')) !== $program) { continue; }
    if ($section !== '' && trim((string) ($r['section'] ?? '')) !== $section) { continue; }
    $visible[] = $r;
}


// ── What has been recorded for this term? ────────────────────
// Joined in one query rather than per student. The page shows a grid for
// every visible student, and fetching subjects one at a time is the
// difference between one round trip and two hundred.
$termRecords = [];
$termGrades  = [];
if ($sy !== '' && $visible) {
    $in = implode(',', array_map(static fn($r) => (int) $r['id'], $visible));
    foreach ($db->fetchAll("
        SELECT ah.* FROM academic_history ah
         WHERE ah.student_id IN ($in) AND ah.school_year = ? AND ah.semester = ?
    ", [$sy, $sem]) as $r) {
        $termRecords[(int) $r['student_id']] = $r;
    }
    if ($termRecords) {
        $rin = implode(',', array_map(static fn($r) => (int) $r['id'], $termRecords));
        foreach ($db->fetchAll("
            SELECT * FROM academic_grades WHERE academic_history_id IN ($rin) ORDER BY id ASC
        ") as $g) {
            $termGrades[(int) $g['academic_history_id']][] = $g;
        }
    }
}

// ── Per-student picture, and the audit over it ───────────────
$rosterForAudit = [];
$rows = [];
$termComplete = 0;
$termMissing  = 0;
$termUnits    = 0.0;

foreach ($visible as $r) {
    $sid  = (int) $r['id'];
    $rec  = $termRecords[$sid] ?? null;
    $rid  = $rec ? (int) $rec['id'] : 0;

    $subjects = [];
    foreach (($rid ? ($termGrades[$rid] ?? []) : []) as $g) {
        $subjects[] = [
            'subject'      => (string) $g['subject'],
            'subject_code' => (string) ($g['subject_code'] ?? ''),
            'units'        => (float) ($g['units'] ?? 0),
            'final_rating' => $g['final_rating'],
            'grade'        => (string) ($g['grade'] ?? ''),
            'remarks'      => (string) ($g['remarks'] ?? ''),
            'grade_status' => (string) ($g['grade_status'] ?? ''),
        ];
    }

    $gwa   = termGwa($subjects);
    $units = 0.0;
    $missingCount = 0;
    foreach ($subjects as $sub) {
        $units += max(0.0, (float) $sub['units']);
        if ($sub['final_rating'] === null || $sub['final_rating'] === '') {
            $missingCount++;
        }
    }
    $termUnits += $units;

    // Three states, not two. "Not started" and "started but incomplete"
    // are different problems and call for different responses: the first
    // needs a subject list built, the second needs a grade sheet chased.
    if (!$subjects) {
        $state = 'none';
    } elseif ($missingCount > 0) {
        $state = 'partial';
    } else {
        $state = 'complete';
    }
    $state === 'complete' ? $termComplete++ : $termMissing++;

    $rows[] = [
        'id'       => $sid,
        'number'   => (string) $r['student_number'],
        'name'     => trim($r['first_name'] . ' ' . $r['last_name']),
        'program'  => (string) ($r['program'] ?? ''),
        'level'    => (string) ($r['year_level'] ?? ''),
        'section'  => (string) ($r['section'] ?? ''),
        'record'   => $rid,
        'gwa'      => $gwa,
        'stored'   => $rec['gwa'] ?? null,
        'units'    => round($units, 2),
        'state'    => $state,
        'missing'  => $missingCount,
        'subjects' => $subjects,
    ];

    $rosterForAudit[] = [
        'name'     => trim($r['first_name'] . ' ' . $r['last_name']),
        'number'   => (string) $r['student_number'],
        'gwa'      => $rec['gwa'] ?? null,
        'subjects' => $subjects,
    ];
}

// The screenshot harness stands a sample roster in for the live one.
//
// The live database holds a single student, and one row cannot show a
// broken grid, a ledger with gaps in it, or a term that is half graded
// - which is the whole reason the harness builds a sample at all. The
// branch fires only when tests/ah_shot.php defines the constant; with
// nothing defined this is the database result, unchanged.
//
// These images are for looking at layout, not at data.
if (defined('AH_SHOT_ROWS')) {
    $rows           = json_decode(AH_SHOT_ROWS, true);
    $termComplete   = 0;
    $termMissing    = 0;
    $termUnits      = 0.0;
    $rosterForAudit = [];
    foreach ($rows as $r) {
        $r['state'] === 'complete' ? $termComplete++ : $termMissing++;
        $termUnits += (float) $r['units'];
        $rosterForAudit[] = [
            'name'     => $r['name'],
            'number'   => $r['number'],
            'gwa'      => $r['stored'],
            'subjects' => $r['subjects'],
        ];
    }
    $visible = array_map(static fn($r) => ['id' => $r['id']], $rows);

    // The acceptance board is built from $visible below, and the sample rows
    // carry their section in $r['section'] rather than on the roster query.
    // Without this the screenshot would show an empty board above a full
    // roster, which is the one combination that cannot happen in production
    // and would send whoever looked at it chasing a bug that is not there.
    $sectionsByCode = [];
    foreach ($rows as $r) {
        $code = trim((string) ($r['section'] ?? ''));
        if ($code === '') {
            continue;
        }
        $sectionsByCode[$code][] = [
            'name'     => $r['name'],
            'number'   => $r['number'],
            'gwa'      => $r['stored'],
            'subjects' => $r['subjects'],
        ];
    }
}

$audit = termAudit($sy, $sem, $rosterForAudit);

// ── Per-section acceptance gates ────────────────────────────────
//
// One section is the unit a registrar actually accepts. The gate itself is
// sectionAcceptance() over that section's slice of the roster ALREADY BUILT
// for the audit above - the same rows, the same termAudit(), so the Accept
// button and the "Check this term" dialog cannot give opposite answers
// about the same student.
//
// Acceptance stamps academic_history.accepted_at, which lives on the term
// row, so "is this section done" is answered by one grouped query rather
// than by re-deriving anything from the roster.
if (!isset($sectionsByCode)) {
    $sectionsByCode = [];
    foreach ($visible as $v) {
        $code = trim((string) ($v['section'] ?? ''));
        if ($code === '') {
            continue;
        }
        // program + NUL + code. The separator cannot appear in either half, and
        // the key never reaches the browser except as this folder path, so
        // there is nothing to escape.
        $key = trim((string) ($v['program'] ?? '')) . "\0" . $code;
        if (!isset($sectionsByCode[$key])) {
            $sectionsByCode[$key] = [];
        }
        $sectionsByCode[$key][] = [
            'section' => $code,
            'program' => trim((string) ($v['program'] ?? '')),
            // The roster query selects s.year_level UNALIASED, so the key here is
            // year_level and not level. Reading 'level' returned null for every
            // student, which put the whole board under one "Unassigned Year"
            // folder - the tree rendered, and was silently lying about where
            // these sections sat.
            'level'   => trim((string) ($v['year_level'] ?? '')),
            'name'     => trim($v['first_name'] . ' ' . $v['last_name']),
            'number'   => (string) $v['student_number'],
            'gwa'      => $termRecords[(int) $v['id']]['gwa'] ?? null,
            'subjects' => (function ($sid) use ($termRecords, $termGrades) {
                $rid = (int) ($termRecords[$sid]['id'] ?? 0);
                $out = [];
                foreach ($rid ? ($termGrades[$rid] ?? []) : [] as $g) {
                    $out[] = [
                        'units'        => (float) ($g['units'] ?? 0),
                        'final_rating' => $g['final_rating'],
                        'grade_status' => (string) ($g['grade_status'] ?? ''),
                    ];
                }
                return $out;
            })((int) $v['id']),
        ];
    }
}

$acceptedSections = [];
if ($sectionsByCode && $sy !== '') {
    // Grouped by program as well as section. Grouping by section alone summed
    // BSIT's and BSCS's acceptances into one number, which is the same merge
    // one level up, and it would have reported a section fully accepted while
    // half of it was not.
    foreach ($db->fetchAll(
        "SELECT s.course, s.section, COUNT(*) AS stamped
           FROM academic_history ah
           JOIN students s ON s.id = ah.student_id
          WHERE ah.school_year = ? AND ah.semester = ? AND ah.accepted_at IS NOT NULL
          GROUP BY s.course, s.section",
        [$sy, $sem]
    ) as $a) {
        $acceptedSections[trim((string) $a['course']) . "\0" . trim((string) $a['section'])] = (int) $a['stamped'];
    }
}

// ── The board tree: program → year → section ─────────────────
//
// A SECTION CODE IS NOT AN IDENTITY. It is scoped by program + year level +
// term (DEPARTMENTS.md), so BSCS 11001 and BSIT 11001 are two different
// sections that happen to share five characters. Keying the board on the bare
// code merged them: one row, four students, two cohorts, and one Accept click
// that would have accepted both.
//
// So the key is program + code throughout, and the TREE is what makes that
// visible rather than a hidden detail of a query: a folder per program, a
// folder per year inside it, and the sections inside that. Two folders can
// hold the same label at the same depth without colliding, which is exactly
// the case the nesting was needed for.
//
// The gate itself is still per SECTION. Program and year folders are
// navigation, not decisions - they carry counts and roll up their children's
// standing, and they have no Accept button, because accepting a program is not
// a thing this office does.
$sectionGates = [];
foreach ($sectionsByCode as $key => $members) {
    $code    = $members[0]['section'];
    $gate    = sectionAcceptance($sy, $sem, $members);
    $stamped = $acceptedSections[$key] ?? 0;

    // The students of this section, as the ledger rows the sheet and the
    // printer already read. They live INSIDE the section row rather than in
    // a roster of their own, so there is one table on the page and one place
    // to look. The row is the section because the section is what gets
    // accepted; the students are what it is accepted over.
    //
    // Matched on program AND code. Matching on the code alone pulled BSCS's
    // 11001 students into BSIT's 11001 row.
    $students = [];
    foreach ($rows as $r) {
        if (trim((string) ($r['section'] ?? '')) !== $code
            || trim((string) ($r['program'] ?? '')) !== $members[0]['program']) {
            continue;
        }
        $students[] = $r;
    }
    usort($students, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $sectionGates[$key] = [
        'key'         => $key,
        'code'        => $code,
        'program'     => $members[0]['program'],
        'year'        => (int) $members[0]['level'],
        'label'       => ahProgramShort($members[0]['program']),
        'roster'      => count($members),
        'stamped'     => $stamped,
        'can_accept'  => $gate['can_accept'],
        'state'       => $stamped > 0 ? 'accepted' : $gate['state'],
        'summary'     => $gate['summary'],
        'blocking'    => $gate['blocking'],
        'advisory'    => $gate['advisory'],
        'students'    => $students,
        // Students with no term row at all. Named, because "4 of 5" does not
        // tell a registrar who to chase, and a roster student with no record
        // is the one case the grade checker will never report.
        'no_term_row' => array_values(array_map(
            static fn($m) => $m['name'],
            array_filter($members, static fn($m) => !$m['subjects'])
        )),
    ];
}

// ── The folders ───────────────────────────────────────────────
//
// program → year → section, three levels, built by nesting rather than by a
// tree walk at render time. Every level is a ROLL-UP of the level below it, so
// a folder's figures are its children's own and cannot disagree with them.
//
// The roll-up standing is the one worth stating. A folder is 'accepted' only
// when every section inside it is accepted, and 'waiting' as soon as one is.
// The weaker state wins: a program whose Year 1 is done and Year 2 is not is
// not done, and printing it green because most of it is green would be the
// board reassuring the wrong person.

// THE CATALOGUE IS THE SHAPE; THE DATA FILLS IT IN.
//
// A folder that only appears once it holds something is a folder the reader
// cannot navigate past. It reports the absence by being absent, so "where is
// BSCpE?" and "did BSCpE have no sections this term?" print the same empty
// screen - and the second question is the one a registrar actually asks while
// chasing a faculty member for a missing grade.
//
// So every OFFERED program gets a folder, every program gets all four year
// levels, and only the SECTION level is conditional on there being sections.
// The distinction is not arbitrary: a program that exists and a section that
// does not are different facts, and only one of them is worth a folder.
//
// getOfferedCourses() is the catalogue the rest of the portal already uses for
// the course filters, so the programs listed here are the programs the rest of
// the app believes in. It is a static list - sixteen programs, one query-free
// call - so the board does not need a table it does not have.
$boardPrograms = array_keys(getOfferedCourses());
ksort($boardPrograms);

// The catalogue names programs in full ("BACHELOR OF SCIENCE IN INFORMATION
// TECHNOLOGY (BSIT)") but a clerk types the acronym into the Masterlist
// ("BSIT"). Both are the same program, and the board now lists BOTH - two BSIT
// folders, one of which always looks empty, which is worse than the original
// problem.
//
// So the acronym is folded into the catalogue entry it belongs to. The section
// KEYS keep the value the database holds; only the FOLDER is unified, because
// a section key is what the acceptance API resolves and changing it would
// invalidate every recorded acceptance.
//
// The first catalogue entry to claim an acronym wins. No two entries here
// share one, so the collision case is a data problem in the catalogue rather
// than something to resolve silently at render time.
$boardByAcronym = [];
foreach ($boardPrograms as $offered) {
    $acronym = ahProgramShort($offered);
    if ($acronym !== $offered && !isset($boardByAcronym[$acronym])) {
        $boardByAcronym[$acronym] = $offered;
    }
}

$boardTree = [];
foreach ($boardPrograms as $offered) {
    $boardTree[$offered] = ['name' => $offered, 'label' => ahProgramShort($offered), 'years' => []];

    // All four year levels, always. Each starts with no sections at all, so a
    // year with nothing in it reports zero sections rather than being absent.
    for ($y = 1; $y <= 4; $y++) {
        $boardTree[$offered]['years']['Year ' . $y] = ['name' => 'Year ' . $y, 'sections' => []];
    }
}

// THEN THE SECTIONS, into the folders above.
//
// NOT $program. The page already has a $program - the roster FILTER from
// ?program= - and it is read further down at "In view / All programs". A
// loop over $program and an unset($program) at the end destroyed the filter,
// so the summary strip rendered "All programs" on every load and logged a
// warning. The loop uses its own names and the reference is broken with [].
foreach ($sectionGates as $gate) {
    $progRaw = $gate['program'] !== '' ? $gate['program'] : 'Unassigned Program';

    // Fold an acronym into the catalogue folder that owns it, so the board
    // shows one BSIT rather than a catalogue BSIT beside a data BSIT.
    $progKey = $boardByAcronym[$progRaw] ?? $progRaw;

    // Year levels are named 'Year 1'..'Year 4' above. A section filed under a
    // year outside that range - a fifth year, or a year left blank by an old
    // import - still has to land somewhere visible, so it gets its own folder
    // rather than being folded into a year it does not belong to.
    $yearKey = ($gate['year'] >= 1 && $gate['year'] <= 4)
        ? 'Year ' . $gate['year']
        : ($gate['year'] > 0 ? 'Year ' . $gate['year'] : 'Unassigned Year');

    // A program that is NOT in the catalogue - and not an acronym of one -
    // still gets a folder, or its sections would be invisible on this page
    // while appearing everywhere else. Renaming a course does not delete its
    // students.
    if (!isset($boardTree[$progKey])) {
        $boardTree[$progKey] = ['name' => $progKey, 'label' => ahProgramShort($progKey), 'years' => []];
        for ($y = 1; $y <= 4; $y++) {
            $boardTree[$progKey]['years']['Year ' . $y] = ['name' => 'Year ' . $y, 'sections' => []];
        }
    }
    if (!isset($boardTree[$progKey]['years'][$yearKey])) {
        $boardTree[$progKey]['years'][$yearKey] = ['name' => $yearKey, 'sections' => []];
    }
    $boardTree[$progKey]['years'][$yearKey]['sections'][] = $gate;
}

/**
 * One folder's counts and standing, from its sections.
 *
 * @param  array $sections Section gates.
 * @return array
 */
function ah_folder_rollup(array $sections): array
{
    // A folder with nothing in it is NOT waiting. Nothing is outstanding when
    // nothing was ever due, and printing "Waitlist" against an empty folder is
    // how a registrar ends up chasing a section that does not exist.
    if (!$sections) {
        return [
            'sections' => 0, 'roster' => 0, 'stamped' => 0,
            'state'    => 'none', 'waiting' => 0,
        ];
    }

    $roster  = array_sum(array_column($sections, 'roster'));
    $stamped = array_sum(array_column($sections, 'stamped'));
    $waiting = array_filter($sections, static fn($s) => $s['state'] !== 'accepted');

    // 'ready' on a folder would invite someone to look for an Accept button
    // that does not exist, so a folder that is not fully accepted says
    // 'waiting' whatever the mix. Only the SECTION carries 'ready'.
    $state = $stamped > 0 && !$waiting ? 'accepted' : 'waiting';
    if (!array_filter($sections, static fn($s) => $s['roster'] > 0)) {
        $state = 'empty';
    }

    return [
        'sections' => count($sections),
        'roster'   => $roster,
        'stamped'  => $stamped,
        'state'    => $state,
        // The count of sections still blocking, which is the number a
        // registrar can act on from a closed folder.
        'waiting'  => $waiting ? count($waiting) : 0,
    ];
}

ksort($boardTree);
// Broken with [] rather than unset($name): PHP's foreach-by-reference leaves
// the loop variable pointing at the last element, and a plain unset() of a
// name the page already uses elsewhere is how the $program filter above was
// destroyed once already.
foreach ($boardTree as &$treeProgram) {
    ksort($treeProgram['years']);
    $allSections = [];
    foreach ($treeProgram['years'] as &$treeYear) {
        // Sections sorted by CODE. They arrived in the roster's ORDER BY, which
        // is by student name, so a year read as 21001, 11001, 11002 - the tree
        // looked shuffled and the codes are the only order a reader can predict.
        usort($treeYear['sections'], static fn($a, $b) => strcmp($a['code'], $b['code']));
        $treeYear['roll'] = ah_folder_rollup($treeYear['sections']);
        $allSections = array_merge($allSections, $treeYear['sections']);
    }
    unset($treeYear);
    $treeProgram['roll'] = ah_folder_rollup($allSections);
}
unset($treeProgram);

// ── Where the reader is standing ──────────────────────────────────
//
// The board is a BROWSE, not a spreadsheet. Masterlist learned this the hard
// way (commit f124bfa: eight programs times four years is hundreds of rows
// nobody scrolls), so this page takes the same shape rather than inventing a
// second one: a path bar and a grid of folder tiles, and the acceptance table
// only at the folder you actually opened.
//
// THE LOCATION IS THE URL. ?at=BSIT/Year%201 is a real link, so the folder can
// be bookmarked, pasted into a message to a department, and reached with Back -
// the same three things the Masterlist browser was built to give.
//
// Segments are rawurlencoded individually and joined with '/', so a program
// name containing a slash cannot invent a level. A path naming a folder that
// is not there falls back to the root AND says so, rather than rendering an
// empty grid under a heading that promises a list.
// THE ROOT, BUILT TWICE.
//
// Once for the real path, once more if the path named a folder that is not
// there. It is the same five lines both times, and the second copy is exactly
// where $program would get clobbered with an array - the page's $program is the
// roster FILTER, and losing it is how "In view" once fatalled on
// htmlspecialchars(). So it lives in one function, called twice.
$boardProgramFolders = static function (array $tree): array {
    $out = [];
    foreach ($tree as $treeKey => $treeProgram) {
        $out[] = [
            'name'  => $treeProgram['name'],
            'label' => $treeProgram['label'],
            'roll'  => $treeProgram['roll'],
            'path'  => rawurlencode($treeProgram['name']),
            'years' => count($treeProgram['years']),
        ];
    }
    return $out;
};

$boardAt = trim((string) ($_GET['at'] ?? ''));
$boardSegments = $boardAt === '' ? [] : array_map(
    'urldecode',
    array_filter(explode('/', $boardAt), static fn($s) => $s !== '')
);

$boardEmptyRoot = ['level' => 'root', 'name' => '', 'children' => [], 'sections' => [], 'roll' => null];
$boardNode = $boardEmptyRoot;
$boardMissing = false;

if (!$boardSegments) {
    $boardNode['children'] = $boardProgramFolders($boardTree);
} elseif (!isset($boardTree[$boardSegments[0]])) {
    $boardMissing = true;
} elseif (count($boardSegments) === 1) {
    // Standing in a program: its year folders are the children.
    //
    // $boardProgram, NOT $program. The page already has a $program in scope -
    // the program FILTER above this - and reusing the name here clobbered it
    // with an array, which then reached the summary strip's "In view" note and
    // fatalled on htmlspecialchars(). Local names start with the thing they
    // belong to.
    $boardProgram = $boardTree[$boardSegments[0]];
    $boardNode['level'] = 'program';
    $boardNode['name']  = $boardProgram['name'];
    $boardNode['roll']  = $boardProgram['roll'];
    foreach ($boardProgram['years'] as $yKey => $year) {
        $boardNode['children'][] = [
            'name'  => $year['name'],
            'label' => $year['name'],
            'roll'  => $year['roll'],
            'path'  => rawurlencode($boardProgram['name']) . '/' . rawurlencode($year['name']),
        ];
    }
} elseif (count($boardSegments) === 2 && isset($boardTree[$boardSegments[0]]['years'][$boardSegments[1]])) {
    // Standing in a year: this is where the decision is made.
    $boardProgram = $boardTree[$boardSegments[0]];
    $year         = $boardProgram['years'][$boardSegments[1]];
    $boardNode['level']         = 'year';
    $boardNode['name']          = $year['name'];
    $boardNode['program']       = $boardProgram['name'];
    $boardNode['program_label'] = $boardProgram['label'];
    $boardNode['roll']          = $year['roll'];
    $boardNode['sections']      = $year['sections'];
} else {
    $boardMissing = true;
}

if ($boardMissing) {
    $boardNode = $boardEmptyRoot;
    $boardNode['children'] = $boardProgramFolders($boardTree);
}

// The path bar. Ancestors are links; the last crumb is where you already are,
// so it is plain text with a "here" treatment - a link to the page you are on
// is a dead control that looks alive.
$boardCrumbs = [];
if ($boardNode['level'] !== 'root') {
    $boardCrumbs[] = ['label' => ahProgramShort($boardSegments[0]), 'path' => rawurlencode($boardSegments[0])];
    if ($boardNode['level'] === 'year') {
        $boardCrumbs[] = ['label' => $boardSegments[1], 'path' => rawurlencode($boardSegments[0]) . '/' . rawurlencode($boardSegments[1])];
    }
}

// The term rides on every link. Dropping it would drop the reader back to the
// newest term, which is the one place this page can lose someone's work.
$boardQuery = function (string $path) use ($termCanonical): string {
    return 'academic-history.php?term=' . rawurlencode($termCanonical)
         . ($path === '' ? '' : '&amp;at=' . $path);
};

// ── The two ways back ────────────────────────────────────────────
//
// The crumbs already offer both, but a path bar is small print at the top of a
// long panel and its ancestors are abbreviated. A registrar who has just
// accepted a section and wants the NEXT year does not want to read a breadcrumb
// to work out where they are; they want an arrow that says "up".
//
// So: UP goes one level, and ALL PROGRAMS jumps straight to the root. Two
// controls rather than one, because they are not the same trip - one is a step,
// the other is a reset, and collapsing them would make the reset unreachable
// without clicking through every level in between.
//
// At the root neither is rendered. A back button at the top of a drive, with
// nowhere above it, is a control that can only fail.
$boardUp      = null;   // one level up: program -> root, year -> program
$boardToRoot  = null;   // straight to the program list
if ($boardNode['level'] === 'program') {
    $boardUp = ['label' => 'All programs', 'path' => ''];
} elseif ($boardNode['level'] === 'year') {
    // Back up to this program's other years.
    $boardUp = [
        'label' => 'All ' . ahProgramShort($boardSegments[0]) . ' years',
        'path'  => rawurlencode($boardSegments[0]),
    ];
    $boardToRoot = ['label' => 'All programs', 'path' => ''];
}
// The stale-link case renders the root, so it gets the root's controls - none.

// (The roster-wide Career GWA metric card used to sit here. It ran one query
// per history row across the whole filtered roster on every page load, for a
// screen-only figure. The per-student figure is still computed, in the payload
// below, because the printed Certificate of Grades carries it.)

// The expected load, used only to raise a question and never to block.
$typicalUnits = null;
$termMeanGwa  = null;
if ($sy !== '') {
    $q = $db->fetchColumn("
        SELECT AVG(credits) FROM academic_history
         WHERE school_year = ? AND semester = ? AND credits IS NOT NULL AND credits > 0
    ", [$sy, $sem]);
    $typicalUnits = $q ? (float) $q : null;

    $m = $db->fetchColumn("
        SELECT AVG(gwa) FROM academic_history
         WHERE school_year = ? AND semester = ? AND gwa IS NOT NULL
    ", [$sy, $sem]);
    $termMeanGwa = $m ? round((float) $m, 2) : null;
}

/**
 * Two-letter initials from a name, for the student tile.
 *
 * The other registrar pages each carry their own copy of this, so this is
 * not shared with them today. It is defined once here rather than inlined
 * at both use sites on this page, which is the part that matters: the
 * dialog tile and the roster cannot disagree.
 */
function ah_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '?';
    }
    $sub = fn($s) => function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
    $up  = fn($s) => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $init = $up($sub($parts[0]));
    if (count($parts) > 1) {
        $init .= $up($sub(end($parts)));
    }
    return $init;
}

// ── Career records for the printable template ──────────────────
//
// Batched deliberately. The payload maps over every roster row, and a
// per-student query inside that map would be three round trips per student
// — 60 queries for a 20-student page, all of it to fill a document only
// one person at a time will ever print. Two queries serve the whole page,
// indexed by student id.
$careerTerms    = [];
$careerSubjects = [];
$careerGwas     = [];
$careerUnitsByStudent = [];

if ($rows) {
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    foreach ($db->fetchAll(
        "SELECT h.student_id, h.school_year, h.semester, h.gwa,
                h.gwa_computed, h.gwa_reported, h.credits
           FROM academic_history h
          WHERE h.student_id IN ($in)
          ORDER BY COALESCE(h.school_year, ''), COALESCE(h.semester, ''), h.id",
        $ids
    ) as $t) {
        $sid = (int) $t['student_id'];
        $careerTerms[$sid][] = [
            'school_year'  => $t['school_year'],
            'semester'     => $t['semester'],
            'gwa_stored'   => $t['gwa'],
            'gwa_computed' => $t['gwa_computed'],
            'gwa_reported' => $t['gwa_reported'],
            'credits'      => $t['credits'],
        ];
    }

    // Keyed "SY|SEM" so the template can pair each heading with its rows.
    foreach ($db->fetchAll(
        "SELECT h.student_id, h.school_year, h.semester,
                g.subject, g.subject_code, g.units, g.final_rating,
                g.grade, g.grade_status, g.instructor
           FROM academic_grades g
           JOIN academic_history h ON h.id = g.academic_history_id
          WHERE h.student_id IN ($in)
          ORDER BY COALESCE(h.school_year, ''), COALESCE(h.semester, ''), g.id",
        $ids
    ) as $g) {
        $sid   = (int) $g['student_id'];
        $syKey = (string) ($g['school_year'] ?? '') . '|'
               . (string) ($g['semester'] ?? '');
        $careerSubjects[$sid][$syKey][] = [
            'subject'      => (string) $g['subject'],
            'subject_code' => (string) ($g['subject_code'] ?? ''),
            'units'        => $g['units'],
            'final_rating' => $g['final_rating'],
            'grade'        => (string) ($g['grade'] ?? ''),
            'grade_status' => (string) ($g['grade_status'] ?? ''),
            'instructor'   => (string) ($g['instructor'] ?? ''),
        ];
    }

    // The printed GWA is COMPUTED here, from the ratings on file, rather than
    // read from academic_history.gwa_computed.
    //
    // That column is never written by the application — it is only ever set by
    // the one-time migration backfill, which copied the old hand-typed `gwa`
    // into it. So printing it means printing a frozen copy of a number somebody
    // typed, while the roster column beside it shows termGwa() computed live.
    // The two can disagree, and on an OFFICIAL record the printed figure is
    // the one that leaves the office. termGwa() is the single implementation
    // the status rules and the audit already read, so the document and the
    // screen now cannot tell different stories.
    foreach ($careerTerms as $sid => $terms) {
        foreach ($terms as $i => $t) {
            $key = (string) ($t['school_year'] ?? '') . '|'
                 . (string) ($t['semester'] ?? '');
            $careerTerms[$sid][$i]['gwa_live'] = termGwa($careerSubjects[$sid][$key] ?? []);
            $careerTerms[$sid][$i]['units_live'] = 0.0;
            foreach ($careerSubjects[$sid][$key] ?? [] as $s) {
                if (termRatingValid($s['final_rating'])) {
                    $careerTerms[$sid][$i]['units_live'] += max(0.0, (float) $s['units']);
                }
            }
        }
        // Career GWA: one weighted pass over every rated subject on file, so
        // the total units and the figure printed beside it come from the same
        // sum. Previously this took the last term's stored `gwa` — the same
        // frozen value described above.
        // Read the units back off $careerTerms, NOT off $terms. foreach is
        // by value, so $terms is the array as it was BEFORE the loop above
        // added units_live - reading it here silently summed an empty column
        // and printed "total units earned: 0" beside a real GWA.
        $careerGwas[$sid] = careerGwa(array_map(
            static fn($t) => ['subjects' => $careerSubjects[$sid]
                [(string) ($t['school_year'] ?? '') . '|'
                 . (string) ($t['semester'] ?? '')] ?? []],
            $terms
        ));
        $careerUnitsByStudent[$sid] = array_sum(
            array_column($careerTerms[$sid], 'units_live')
        );
    }
}

$payload = json_encode([
    'sy'       => $sy,
    'sem'      => $sem,
    // Kept in the payload even though the page no longer writes. The CSRF
    // token is still needed by anything on the page that posts, and the
    // print action is the one remaining thing a registrar may trigger.
    'csrf'     => $csrfToken,
    // Relative, and RELATIVE TO THE PAGE. printDocument() writes into an
    // about:blank iframe where a relative src resolves against nothing, so the
    // JS resolves this against window.location.href before handing it over -
    // the same fix js/insights.js already makes for the AI Insight report.
    //
    // The leading ../ is load-bearing and is measured from registrar/ where
    // this page lives, not from the repository root. Dropping it points at
    // registrar/assets/images/... , which does not exist, and the letterhead
    // prints with an empty box where the crest should be.
'logoPath' => '../assets/images/BCP_LOGO.png',
    'school'   => 'College of Computer Studies',
    'printedOn'=> date('F j, Y'),
    // Initials are computed here rather than in JS so the dialog tile and
    // the roster use one helper, the same one the other registrar pages
    // call, instead of two implementations of "first and last letter".
    'initials' => array_map(
        static fn($r) => ah_initials($r['name']),
        array_combine(
            array_column($rows, 'id'),
            $rows
        )
    ),
    'rows' => array_map(static fn($r) => [
        'id'       => $r['id'],
        'name'     => $r['name'],
        'number'   => $r['number'],
        'program'  => $r['program'],
        'level'    => $r['level'],
        'section'  => $r['section'],
        'record'   => $r['record'],
        'subjects' => $r['subjects'],
        // The read-only view shows the same figures the roster column
        // shows, so they travel in the payload rather than being
        // recomputed in the browser. A modal that disagreed with the
        // table beside it would be worse than no modal.
        'gwa'      => $r['gwa'],
        'units'    => $r['units'],
        'state'    => $r['state'],
        'missing'  => $r['missing'],
        // The student's whole record, not just the term on screen. The
        // printable template is a career document: it lists every term
        // Faculty has sent, with a cumulative GWA. Printing only the term
        // in view would make it useless as the transcript-shaped artefact
        // it is meant to be.
        'career'   => $careerTerms[(int) $r['id']] ?? [],
        'careerGwa' => $careerGwas[(int) $r['id']] ?? null,
        // Total units across the whole record, from the same rated subjects
        // the career GWA was computed from. A Certificate of Grades that
        // prints a cumulative figure without the units behind it is asking
        // the reader to trust it.
        'careerUnits' => round((float) ($careerUnitsByStudent[(int) $r['id']] ?? 0), 2),
        'careerSubjects' => $careerSubjects[(int) $r['id']] ?? [],
    ], $rows),
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$page_title = 'Academic History';
$page_description = 'Record term grades, computed GWA, and check a term before closing it';
$body_page = 'academic';
$APP_ROOT = '../';
// Scoped stylesheet, loaded by includes/header.php. The page itself
// carries no inline CSS, which is what keeps it looking like the rest
// of the portal rather than like a separate application.
$extra_css = ['academic-history.css'];
// bcp-letterhead.js supplies BCPPrint.printDocument(), which
// printGradeTemplate() calls. It must load before the inline script below
// runs. footer.php cache-busts each by mtime.
$page_scripts = ['bcp-letterhead.js'];
$ACTIVE_NAV = 'academic';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
<div class="dashboard-container">

<?php
// ── Head ──────────────────────────────────────────────────────
// The house header: kicker, title, one plain sentence, and the actions on
// the right. The rating scale rides along as a chip rather than a second
// paragraph, because it is reference information and the description is
// the page's subject.
//
// The header also states ownership. That is the fact a registrar most needs
// confirmed here: grades are maintained by Faculty, this office reads them
// and issues the printed record.
?>
<header class="ah-head">
    <div>
        <div class="ah-kicker">
            <i class="fa-solid fa-clipboard-list"></i> Term records
        </div>
        <h1>Academic History</h1>
        <p>
            Term grades maintained by Faculty Management. The Registrar reads them and
            issues the printed record. Select a student to open their term.
        </p>
    </div>
    <div class="header-actions">
        <span class="ah-scale-chip">
            <i class="fa-solid fa-arrow-down-wide-short"></i> 1.00 best, 5.00 worst
        </span>
        <button class="btn btn-light" type="button" data-audit>
            <i class="fa-solid fa-stethoscope"></i> Check this term
        </button>
    </div>
</header>

<?php
// "Awaiting grades" is the actionable figure, so it is the one metric whose
// value carries a tone. A mean is shown only once there is something to
// average: the average of nothing is not 0.00, it is absent, and the metric
// says so rather than reporting a number.
?>
<section class="ah-strip" aria-label="Term summary">
    <div class="ah-metric is-view">
        <p class="ah-label">In view</p>
        <p class="ah-value"><?= count($visible) ?></p>
        <p class="ah-note"><?= $program !== '' ? htmlspecialchars($program) : 'All programs' ?></p>
    </div>
    <div class="ah-metric is-done"<?= $termComplete === 0 ? ' data-empty="true"' : '' ?>>
        <p class="ah-label">Complete</p>
        <p class="ah-value"><?= $termComplete ?></p>
        <p class="ah-note"><?= $termComplete === 0 ? 'none graded yet' : 'every subject rated' ?></p>
    </div>
    <div class="ah-metric is-wait"<?= $termMissing === 0 ? ' data-empty="true"' : '' ?>>
        <p class="ah-label">Awaiting grades</p>
        <p class="ah-value"><?= $termMissing ?></p>
        <p class="ah-note"><?= $termMissing > 0 ? 'not started or incomplete' : 'nothing outstanding' ?></p>
    </div>
    <div class="ah-metric is-mean"<?= $termMeanGwa === null ? ' data-empty="true"' : '' ?>>
        <p class="ah-label">Mean term GWA</p>
        <p class="ah-value"><?= $termMeanGwa === null ? '&mdash;' : number_format($termMeanGwa, 2) ?></p>
        <p class="ah-note"><?= $termMeanGwa === null ? 'nothing recorded' : 'across recorded terms' ?></p>
    </div>
</section>

<?php if ($termProblem !== ''): ?>
    <p class="ah-notice" role="status">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <?= $termProblem ?>
    </p>
<?php endif; ?>

<?php // The ACCEPTANCE BOARD. This is the whole page.
        //
        // There was a roster table here before, one row per student, with the
        // sections nowhere on it. It is gone, and its students now hang off the
        // section rows below. Two reasons, and the second is the real one:
        //
        //   1. A section is what gets accepted, so a page that listed students
        //      made the user count students to work out sections.
        //   2. The same students in two tables is the same facts in two places,
        //      free to disagree - and the count above would have had to be
        //      reconciled against the list under it.
        //
        // So: one table, section rows, students nested. Opening a section is
        // how you see who is in it.
        //
        // The gate on each row is computed server-side by sectionAcceptance()
        // over the same roster the audit uses. Nothing here decides anything -
        // api/grade-acceptance.php recomputes the identical gate before it
        // stamps anything, so a stale page cannot accept a section that has
        // since lost a grade.
        //
        // The term box and the find box are HERE rather than in a toolbar of
        // their own, because there is no second panel to own them any more.
        // The form carries no submit button; it commits on change or Enter.
        ?>
        <section class="panel" aria-labelledby="boardTitle">
            <form class="panel-toolbar" method="get" action="academic-history.php" id="termForm">
                <div class="panel-title" id="boardTitle">
                    <i class="fa-solid fa-stamp" aria-hidden="true"></i>
                    Sections
                </div>

                <div class="header-actions">
                    <div class="form-group" style="margin:0">
                        <label class="ah-sr-only" for="fltTerm">Term</label>
                        <input
                            class="form-control"
                            type="text"
                            id="fltTerm"
                            name="term"
                            value="<?= htmlspecialchars($termCanonical) ?>"
                            placeholder="2026-2028 1st"
                            autocomplete="off"
                            spellcheck="false"
                            style="width:190px"
                            aria-describedby="termHelp"
                        >
                    </div>
                    <span class="ah-sr-only" id="termHelp">
                        School year and semester, in any order. For example 2026-2028 1st.
                    </span>

                    <div class="form-group" style="margin:0;position:relative">
                        <label class="ah-sr-only" for="rosterSearch">Find in this term</label>
                        <input
                            class="form-control"
                            type="search"
                            id="rosterSearch"
                            placeholder="Search students…"
                            autocomplete="off"
                            spellcheck="false"
                            style="width:230px"
                        >
                    </div>
                </div>
            </form>

            <p class="ahb-why ahb-caption">
                <?= htmlspecialchars(termLabel($sy, $sem)) ?> &middot;
                a section accepts once every student has a grade for every
                subject on their record
            </p>

<?php // Where you are. The root crumb is always present, so there is
            // always one click back to the top - the thing a drive always gives
            // you and a tree does not.

            // The LAST crumb is where the reader already is, so it renders as
            // plain text. A link to the page you are on is a dead control that
            // looks alive.
            ?>
            <nav class="ahb-crumbs" aria-label="Folder path">
                <a class="ahb-crumb ahb-crumb-root" href="<?= $boardQuery('') ?>">
                    <i class="fas fa-stamp" aria-hidden="true"></i> Sections
                </a>
                <?php foreach ($boardCrumbs as $i => $crumb):
                    $isLast = ($i === array_key_last($boardCrumbs)); ?>
                    <i class="fas fa-chevron-right ahb-crumb-sep" aria-hidden="true"></i>
                    <?php if ($isLast): ?>
                        <span class="ahb-crumb ahb-crumb-here"><?= htmlspecialchars($crumb['label']) ?></span>
                    <?php else: ?>
                        <a class="ahb-crumb" href="<?= $boardQuery($crumb['path']) ?>">
                            <?= htmlspecialchars($crumb['label']) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <?php if ($boardMissing): ?>
                <div class="ahb-missing">
                    <i class="fas fa-folder-open" aria-hidden="true"></i>
                    <p><strong>That folder is not here.</strong></p>
                    <p>It may have been renamed, or the link may be out of date. Everything on file is listed below.</p>
                </div>
            <?php endif; ?>

            <div class="ahb-head">
                <div>
                    <div class="ahb-title">
                        <i class="fas <?= $boardNode['level'] === 'year' ? 'fa-list-check'
                                       : ($boardNode['level'] === 'root' ? 'fa-stamp' : 'fa-folder-open') ?>"
                           aria-hidden="true"></i>
                        <?= $boardNode['level'] === 'root'
                            ? 'Programs'
                            : ($boardNode['level'] === 'program'
                                ? htmlspecialchars($boardNode['name'])
                                : htmlspecialchars($boardNode['name'])) ?>
                    </div>
                    <div class="ahb-sub">
                        <?php if ($boardNode['level'] === 'year'): ?>
                            <?= count($boardNode['sections']) ?> section<?= count($boardNode['sections']) === 1 ? '' : 's' ?>
                            &middot; <?= (int) $boardNode['roll']['roster'] ?> student<?= (int) $boardNode['roll']['roster'] === 1 ? '' : 's' ?>
                            &middot; in <?= htmlspecialchars(termLabel($sy, $sem)) ?>
                        <?php elseif ($boardNode['level'] === 'root'): ?>
                            <?= count($boardNode['children']) ?> program folder<?= count($boardNode['children']) === 1 ? '' : 's' ?>
                            &middot; <?= htmlspecialchars(termLabel($sy, $sem)) ?>
                        <?php else: ?>
                            <?= count($boardNode['children']) ?> year folder<?= count($boardNode['children']) === 1 ? '' : 's' ?>
                            &middot; <?= (int) $boardNode['roll']['roster'] ?> student<?= (int) $boardNode['roll']['roster'] === 1 ? '' : 's' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php // The term's standing, at every level. At the root this is
                // the ONE thing worth knowing, and it is the reason a
                // registrar opens this page: how much of the term is signed
                // off, and how much is waiting on faculty.

                // The standing and the two ways back share this side of the
                // head, so they are one group. The chip is the fact; the buttons
                // are the way out of this folder. ?>
                <div class="ahb-head-aside">
                    <?php if ($boardNode['roll'] !== null): ?>
                        <?php // Three states, three words. A folder with nothing in
                        // it says so: "Waitlist" against an empty folder would
                        // send someone hunting for a missing section that was
                        // never opened. ?>
                        <span class="ahb-chip ahb-chip-lg" data-state="<?= $boardNode['roll']['state'] ?>">
                            <span class="status-dot" aria-hidden="true"></span><?php
                            echo $boardNode['roll']['state'] === 'accepted' ? 'All accepted'
                               : ($boardNode['roll']['state'] === 'none'
                                   ? 'No sections yet'
                                   : 'Waitlist');
                        ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($boardUp !== null): ?>
                        <nav class="ahb-back" aria-label="Go back">
                            <?php // One step up. Labelled with the destination
                            // rather than a bare "Back", because "back" on a
                            // page whose ancestors are abbreviated tells you how
                            // to reverse, not where you will land. ?>
                            <a class="ahb-back-btn" href="<?= $boardQuery($boardUp['path']) ?>"
                               rel="up">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                <?= htmlspecialchars($boardUp['label']) ?>
                            </a>
                            <?php if ($boardToRoot !== null): ?>
                                <?php // The reset. Only offered where there is more
                                // than one level between here and the top - in a
                                // program folder, "All programs" IS the way up, so
                                // a second identical button would be a duplicate. ?>
                                <a class="ahb-back-btn is-root" href="<?= $boardQuery($boardToRoot['path']) ?>">
                                    <i class="fas fa-hard-drive" aria-hidden="true"></i>
                                    <?= htmlspecialchars($boardToRoot['label']) ?>
                                </a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
<?php // THE CATALOGUE ALWAYS HAS FOLDERS, so there is no whole-page empty
            // state here any more. The old guard was `if (!$sectionGates)` - it
            // existed because the board used to be built from sections alone and
            // had nothing at all to show without them. Now the programs and years
            // are there regardless, and hiding them would put back exactly the
            // confusion this board was rebuilt to remove: a blank screen that
            // could equally mean "no data", "wrong term", or "nothing to see
            // here".
            //
            // "No sections yet" is still said - but at the level it belongs to,
            // on the empty folder that says so, with a tile per program above
            // it.
            //
            // The folder tiles. Grid, not rows: this is a drive the reader moves
            // through, and the point of it is to pick ONE program rather than
            // scan for BSIT in the middle of everything.
            //
            // THE ONE PLACE THIS DIVERGES FROM MASTERLIST. Its tile meta reads
            // "3 students - 2 folders inside", because on the Masterlist the
            // count IS the fact. Here the fact is STANDING, so the meta leads
            // with it - "1 ready - 2 waiting" - and the counts follow. A
            // registrar opening this page wants to know what is blocked, and
            // making them open a folder to find out puts the answer one click
            // further away than it needs to be.
            if ($boardNode['level'] !== 'year' && $boardNode['children']): ?>
                <div class="ahb-folders">
                    <?php foreach ($boardNode['children'] as $child):
                        $cRoll = $child['roll'];
                        $ready = $cRoll['waiting'] === 0 ? $cRoll['sections'] : 0;
                        // "None yet" on a folder with nothing in it. NOT zero
                        // students and NOT a waitlist: the difference between
                        // "this year has no sections yet" and "this year's
                        // sections are incomplete" is the whole question a
                        // registrar opens this board to answer.
                        $cEmpty = $cRoll['sections'] === 0;
                    ?>
                        <a class="ahb-tile<?= $cEmpty ? ' is-empty' : '' ?>"
                           href="<?= $boardQuery($child['path']) ?>"
                           title="<?= htmlspecialchars($child['name']) ?>">
                            <i class="fas fa-folder ahb-tile-icon" aria-hidden="true"></i>
                            <span class="ahb-tile-body">
                                <span class="ahb-tile-name"><?= htmlspecialchars($child['label']) ?></span>
                                <span class="ahb-tile-meta">
                                    <?php if ($cEmpty): ?>
                                        No sections yet
                                        <?php if (isset($child['years'])): ?>
                                            &middot; <?= $child['years'] ?> year<?= $child['years'] === 1 ? '' : 's' ?> inside
                                        <?php endif; ?>
                                    <?php elseif ($cRoll['waiting'] === 0): ?>
                                        <?= $cRoll['sections'] ?> section<?= $cRoll['sections'] === 1 ? '' : 's' ?>, all accepted
                                    <?php else: ?>
                                        <?= $ready ?> ready &middot; <?= $cRoll['waiting'] ?> waiting
                                    <?php endif; ?>
                                    <?php if (!$cEmpty): ?>
                                        &middot; <?= (int) $cRoll['roster'] ?> student<?= (int) $cRoll['roster'] === 1 ? '' : 's' ?>
                                    <?php endif; ?>
                                    <?php if (!$cEmpty && isset($child['years'])): ?>
                                        &middot; <?= $child['years'] ?> year<?= $child['years'] === 1 ? '' : 's' ?> inside
                                    <?php endif; ?>
                                </span>
                                <?php // The meter is on the TILE here, not only on
                                // the rows. It is how a program with four
                                // sections looks half-done from across the room.
                                //
                                // Hidden on an empty folder rather than drawn at
                                // 0%: a bar with nothing in it reads as a
                                // progress bar that failed, not as "no data". ?>
                                <?php if (!$cEmpty):
                                    $tilePct = (int) $cRoll['roster'] > 0
                                        ? (int) round(100 * $cRoll['stamped'] / $cRoll['roster']) : 0; ?>
                                    <span class="ahb-meter<?= $tilePct >= 100 ? ' is-full' : '' ?>"
                                          role="img"
                                          aria-label="<?= (int) $cRoll['stamped'] ?> of <?= (int) $cRoll['roster'] ?> accepted">
                                        <i style="width:<?= $tilePct ?>%"></i>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <i class="fas fa-chevron-right ahb-tile-go" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($boardNode['level'] === 'year'): ?>
            <?php // The year exists whether or not it has sections, so this
            // branch can legitimately hold nothing. It says which year, and
            // what would appear here - an empty folder that rendered a bare
            // table header looked like a broken query rather than a fact. ?>
            <?php if (!$boardNode['sections']): ?>
                <div class="ah-empty-state">
                    <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                    <p>No sections in <?= htmlspecialchars($boardNode['name']) ?> yet</p>
                    <span>
                        Sections are assigned from the Masterlist. When one is assigned to
                        <?= htmlspecialchars(ahProgramShort($boardNode['program'])) ?>
                        <?= htmlspecialchars($boardNode['name']) ?>, it appears here to be accepted.
                    </span>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="ahb">
                    <thead>
                        <tr>
                            <th scope="col">Section</th>
                            <th scope="col">Students</th>
                            <th scope="col">Accepted</th>
                            <th scope="col">Standing</th>
                            <th scope="col"><span class="ah-sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($boardNode['sections'] as $g):
                        $pct = $g['roster'] > 0 ? (int) round(100 * $g['stamped'] / $g['roster']) : 0;
                        $standing = [
                            'accepted' => 'Accepted',
                            'ready'    => 'Ready to accept',
                            'waiting'  => 'Waitlist',
                            'empty'    => 'Nothing received',
                        ][$g['state']];
                        // The waitlist reason, in the registrar's words. A bare
                        // count ("3 issues") would send someone to the audit
                        // dialog to find out what they actually are.
                        $why = $g['state'] === 'waiting'
                            ? sprintf('%d to resolve: %s', count($g['blocking']),
                                       htmlspecialchars((string) ($g['blocking'][0]['title'] ?? '')))
                            : ($g['state'] === 'empty'
                                ? 'No subjects recorded'
                                : htmlspecialchars($g['summary']));
                    ?>
                        <tr class="ahb-leaf" data-gate="<?= $g['state'] ?>"
                            data-section="<?= htmlspecialchars($g['key']) ?>"
                            data-program="<?= htmlspecialchars($g['program']) ?>"
                            data-code="<?= htmlspecialchars($g['code']) ?>">
                            <td class="ahb-name">
                                <button class="ahb-caret" type="button"
                                        data-caret="<?= htmlspecialchars($g['key']) ?>"
                                        aria-expanded="false"
                                        aria-controls="<?= htmlspecialchars(md5($g['key'])) ?>"
                                        title="Show this section's students">
                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                    <span class="ah-sr-only">Show students in <?= htmlspecialchars($g['code']) ?></span>
                                </button>
                                <i class="fas fa-folder ahb-folder-icon" aria-hidden="true"></i>
                                <span class="ahb-code"><?= htmlspecialchars($g['code']) ?></span>
                                <span class="ahb-sub"><?= $g['roster'] ?> student<?= $g['roster'] === 1 ? '' : 's' ?></span>
                            </td>
                            <td class="ahb-count"><?= $g['roster'] ?></td>
                            <td class="ahb-count">
                                <span class="ahb-num"><?= $g['stamped'] ?> / <?= $g['roster'] ?></span>
                                <span class="ahb-meter<?= $pct >= 100 ? ' is-full' : '' ?>" role="img"
                                      aria-label="<?= $g['stamped'] ?> of <?= $g['roster'] ?> accepted">
                                    <i style="width:<?= $pct ?>%"></i>
                                </span>
                            </td>
                            <td>
                                <span class="ahb-chip" data-state="<?= $g['state'] ?>">
                                    <span class="status-dot" aria-hidden="true"></span><?= $standing ?>
                                </span>
                                <span class="ahb-why"><?= $why ?></span>
                            </td>
<td>
                                <div class="ahb-actions">
                                    <button class="ahb-btn ahb-btn-ai" type="button"
                                            data-ai="<?= htmlspecialchars($g['key']) ?>"
                                            <?= $g['state'] === 'waiting' ? '' : 'hidden' ?>>
                                        <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                                        Draft a chaser
                                    </button>
                                    <?php if ($g['state'] === 'accepted'): ?>
                                        <button class="ahb-btn ahb-btn-reopen" type="button"
                                                data-reopen="<?= htmlspecialchars($g['key']) ?>"
                                                data-program="<?= htmlspecialchars($g['program']) ?>">
                                            Reopen
                                        </button>
                                    <?php else: ?>
                                        <?php // Disabled, not hidden, and titled with the
                                        // reason. See .ahb-btn in the stylesheet. ?>
                                        <button class="ahb-btn ahb-btn-accept" type="button"
                                                data-accept="<?= htmlspecialchars($g['key']) ?>"
                                                data-program="<?= htmlspecialchars($g['program']) ?>"
                                                <?= $g['can_accept'] ? '' : 'disabled' ?>
                                                title="<?= $g['can_accept']
                                                    ? 'Accept this section for ' . htmlspecialchars(termLabel($sy, $sem))
                                                    : 'Not ready: ' . htmlspecialchars($g['summary']) ?>">
                                            Accept section
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
<?php // The section's students. This is where the standalone roster
                        // went: one table on screen, and opening a section is how
                        // you see who is in it.
                        //
                        // data-search carries the section code and program too, so
                        // typing a code finds the students under it.
                        ?>
                        <tr class="ahb-group" id="<?= htmlspecialchars(md5($g['key'])) ?>"
                            data-parent="<?= htmlspecialchars($g['key']) ?>" hidden>
                            <td colspan="5">
                                <table class="ahb-students">
                                    <thead>
                                        <tr>
                                            <th scope="col">Student</th>
                                            <th scope="col" class="ah-col-sec" data-num>Units</th>
                                            <th scope="col" class="ah-col-sec" data-num>Subjects</th>
                                            <th scope="col" data-num>Term GWA</th>
                                            <th scope="col">Record</th>
                                            <th scope="col"><span class="ah-sr-only">Open</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($g['students'] as $sr):
                                        $sBand = 'none';
                                        if ($sr['gwa'] !== null) {
                                            $sBand = $sr['gwa'] < GWA_AT_RISK - 0.5 ? 'good'
                                                   : ($sr['gwa'] < GWA_AT_RISK ? 'warn' : 'poor');
                                        }
                                        $sLabel = [
                                            'complete' => 'Complete',
                                            'partial'  => $sr['missing'] . ' missing',
                                            'none'     => 'Not received',
                                        ][$sr['state']];
                                    ?>
                                        <tr data-ah-row data-student="<?= (int) $sr['id'] ?>"
                                            data-search="<?= htmlspecialchars(strtolower($sr['name'] . ' ' . $sr['number'] . ' ' . $g['code'] . ' ' . $g['program'])) ?>">
                                            <td>
                                                <span class="ahb-sname"><?= htmlspecialchars($sr['name']) ?></span>
                                                <span class="ahb-sub"><?= htmlspecialchars($sr['number'] ?: 'No number on file') ?></span>
                                            </td>
                                            <td class="ahb-count"><?= $sr['units'] > 0 ? $sr['units'] : '&mdash;' ?></td>
                                            <td class="ahb-count"><?= count($sr['subjects']) ?: '&mdash;' ?></td>
                                            <td class="ahb-count">
                                                <span class="ah-gwa" data-band="<?= $sBand ?>">
                                                    <?= $sr['gwa'] === null ? '&mdash;' : number_format($sr['gwa'], 2) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge ah-state" data-state="<?= $sr['state'] ?>">
                                                    <span class="status-dot"></span><?= $sLabel ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="ah-view" type="button" data-view="<?= (int) $sr['id'] ?>"
                                                        aria-label="Open the term record for <?= htmlspecialchars($sr['name']) ?>">
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php // Where the assistant's note lands. Populated per section on
            // demand; empty until a registrar asks for one. ?>
            <div class="ahb-ai-out" id="ahbAiOut" hidden>
                <h4>For the College of Computer Studies</h4>
                <p id="ahbAiNote"></p>
                <pre id="ahbAiMsg"></pre>
            </div>

            <?php // The no-match state. It lives here rather than beside the
            // search box because the sections it did not match are still on
            // screen and still decidable: a search that hid the board would
            // take the acceptance decision away with it. ?>
            <div class="ah-empty-state" data-ah-nomatch hidden>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <p>No student matches that</p>
                <span></span>
            </div>
        </section>

<?php // The panel and the container close here, before the sheet, which
// lives outside the page flow so it is not announced until it is opened. ?>
</div>
</main>

<?php // The record SHEET.
//
// A drawer down the right edge, not a centered dialog and not a row that
// expands underneath.
//
// A centered dialog would cover the term, and reading one student's record
// against the rest of the term is the reason this page works. An expanded row
// was the previous answer, and it was wrong in a way that only shows with a
// real roster: opening a record pushes every student below it down, so on a
// hundred-row term the list jumps and the registrar loses their place in it.
//
// The sheet refuses both. It does not reflow the list behind it, and it leaves
// the roster visible AND clickable on the left. There is no scrim and no dim:
// a backdrop over the roster would hide the very context the sheet exists to
// preserve, and it would swallow the clicks that make the sheet worth having.
// View the next student without closing this one; the record just swaps.
//
// Two consequences of that decision, both deliberate:
//   - aria-modal is NOT set. This is not a modal. The roster behind it stays
//     live, and telling assistive technology otherwise would be the
//     accessibility version of the same lie.
//   - There is no click-outside-to-close, because there is no click-outside.
//     The close button, Escape and opening another student are all the ways out.
?>
<div class="ah-sheet" id="recordSheet" role="complementary"
     aria-labelledby="sheetTitle" hidden>
    <div class="ah-sheet-panel" role="dialog" aria-modal="false" aria-labelledby="sheetTitle">
        <div class="ah-sheet-head">
            <div class="ah-sheet-who">
                <div class="ah-sheet-kicker">Term record</div>
                <h2 id="sheetTitle" tabindex="-1">&mdash;</h2>
                <p class="ah-sheet-sub" id="sheetSub">&mdash;</p>
            </div>
            <button class="ah-sheet-close" type="button" data-sheet-close aria-label="Close the record">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <div class="ah-sheet-body" id="sheetBody"></div>
    </div>
</div>

<?php
// The pre-close audit. Read-only: it reports what is missing or
// inconsistent and stops there. It assigns no grade, changes no status,
// and writes nothing. The GWA-at-3.00 finding is a question about the
// status rules, not a decision about this student.
?>
<div class="modal-overlay ah-dialog" id="auditModal" role="dialog" aria-modal="true" aria-labelledby="auditModalTitle">
    <div class="modal-content">
        <div class="ah-dialog-head">
            <div>
                <div class="ah-dialog-kicker">Before you close</div>
                <h3 id="auditModalTitle" tabindex="-1">Check this term</h3>
            </div>
            <button class="modal-close" type="button" onclick="closeAudit()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="ah-dialog-body" id="auditBody"></div>
    </div>
</div>

<?php
// The print chooser. A dialog rather than a second and third button on every
// row, because the panel would carry two actions describing overlapping
// documents and the reader would have to work out which is which before
// pressing either.
//
// Reuses the audit dialog's own classes and its openDialog/closeDialog, so
// there is one dialog pattern on this page rather than two - but it is a
// NARROWER dialog. The audit's content is a list of findings that needs the
// width; three short options do not, and a full-width box with three lines
// in it reads as an empty page that failed to load.
//
// It names the student. The dialog floats over a roster of however many rows,
// and "Print which part of the record?" answers nothing about WHICH record -
// a registrar working down a list needs that confirmed before they press
// anything.
?>
<div class="modal-overlay ah-dialog" id="printModal" role="dialog" aria-modal="true" aria-labelledby="printModalTitle">
    <div class="modal-content ah-print-modal">
        <div class="ah-dialog-head">
            <div>
                <div class="ah-dialog-kicker">Official grades</div>
                <h3 id="printModalTitle" tabindex="-1">Print grades for</h3>
                <div class="ah-print-who" id="printWho">&mdash;</div>
            </div>
            <button class="modal-close" type="button" onclick="closePrintMenu()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="ah-dialog-body">
            <p class="ah-print-lead">Each semester is one sheet.</p>
            <div class="ah-print-choices">
                <button class="ah-print-choice" type="button" data-print-scope="1st">
                    <span class="ah-sheet-glyph" data-sheets="1" aria-hidden="true"><i></i><i></i></span>
                    <span class="ah-choice-text">
                        <b>1st semester</b>
                        <small id="printCount1st">&mdash;</small>
                    </span>
                </button>
                <button class="ah-print-choice" type="button" data-print-scope="2nd">
                    <span class="ah-sheet-glyph" data-sheets="1" aria-hidden="true"><i></i><i></i></span>
                    <span class="ah-choice-text">
                        <b>2nd semester</b>
                        <small id="printCount2nd">&mdash;</small>
                    </span>
                </button>
                <button class="ah-print-choice" type="button" data-print-scope="all">
                    <span class="ah-sheet-glyph" data-sheets="2" aria-hidden="true"><i></i><i></i></span>
                    <span class="ah-choice-text">
                        <b>All semesters</b>
                        <small id="printCountAll">&mdash;</small>
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
<script>
'use strict';
// Server-computed figures. termGwa() in shared/term_grades.php is the
// reference. Nothing here recomputes a GWA for display: the page shows the
// number the server stored, so the ledger cannot disagree with the
// printable record it produces.
const AH = <?= $payload ?>;

// The audit is computed server-side and rendered here. Findings arrive as
// data, not as prose written in JS, so the drawer and the validation
// cannot drift apart.
const AH_AUDIT = <?= json_encode([
    'blocking' => $audit['blocking'],
    'advisory' => $audit['advisory'],
    'summary'  => $audit['summary'],
    'stats'    => $audit['stats'],
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function rowById(id) {
    return AH.rows.find(r => r.id === id) || null;
}

/* Rating banding. The same numbers term_js_check.js pins against the
   server's, because a rating that changes colour but not meaning is worse
   than one that never changes. GWA_AT_RISK is 3.00 and the good band stops
   half a point under it. */
function ratingBand(v) {
    if (v === null || v === undefined || v === '') return 'none';
    const n = Number(v);
    if (!isFinite(n)) return 'none';
    return n < 2.5 ? 'good' : (n < 3 ? 'warn' : 'poor');
}

const na = v => (v === null || v === undefined || v === '') ? 'N/A' : v;

// ── The record ────────────────────────────────────────────────
// Which View button opened the sheet, so closing can put focus back on it.
// A script-level variable rather than a property on the sheet element: the
// sheet is part of the server-rendered page, and this is page state.
let lastViewButton = null;
// Built once per student, on first open, then cached on the element. It is
// static data — nothing on this page changes a grade — so rebuilding it on
// every toggle would be pure waste.
function recordHtml(r) {
    if (!r.subjects.length) {
        return '<div class="ah-record">'
            + '<p class="ah-empty-state" style="padding:1.5rem 0;border:0">'
            + '<strong>No grades received from Faculty for this term.</strong></p></div>';
    }

    const rows = r.subjects.map(s => {
        const rated = s.final_rating !== null && s.final_rating !== undefined
            && s.final_rating !== '';
        return '<tr>'
            + '<td>' + esc(na(s.subject)) + '</td>'
            + '<td class="ah-fig ah-col-code">' + esc(na(s.subject_code)) + '</td>'
            + '<td data-num class="ah-fig">' + esc(na(s.units)) + '</td>'
            + '<td data-num class="ah-fig">'
            + (rated ? Number(s.final_rating).toFixed(2) : '<span class="ah-muted">not rated</span>')
            + '</td>'
            + '<td>' + esc(na(s.grade_status)) + '</td>'
            + '</tr>';
    }).join('');

    return ''
        + '<div class="ah-record">'
        + '<div class="ah-record-head">'
        + '<p class="ah-record-title"><i class="fa-solid fa-book-open"></i>'
    + esc(AH.sem) + ' Semester &middot; ' + esc(AH.sy) + '</p>'
        + '<span class="ah-record-meta">' + r.subjects.length + ' subject'
        + (r.subjects.length === 1 ? '' : 's') + '</span>'
        + '</div>'
        + '<table class="table"><thead><tr>'
        + '<th>Subject</th><th class="ah-col-code">Code</th><th data-num>Units</th>'
        + '<th data-num>Final rating</th><th>Result</th>'
        + '</tr></thead><tbody>' + rows + '</tbody></table>'
        + '<div class="ah-record-foot">'
        // The class is what stands the pairs side by side as figures. A bare
        // <dl> stacks each label above its value, which reads as a list of
        // orphaned words rather than two measurements.
        + '<dl class="ah-record-facts">'
        + '<div><dt>Term GWA</dt><dd>' + (r.gwa === null ? '—' : Number(r.gwa).toFixed(2)) + '</dd></div>'
        + '<div><dt>Units</dt><dd>' + (r.units > 0 ? r.units : '—') + '</dd></div>'
        + '</dl>'
        // Says what it prints. This panel lists ONE term, and the button under
        // it produced a document listing EVERY term - so the control was
        // labelled with the artefact's name but sat directly under a single
        // semester's rows, and reading the two together gave the impression
        // that it printed that term alone. The count is here rather than
        // hidden in the document so the scope is visible before the click.
        + '<p class="ah-print-scope">'
        + 'Prints the official grades. Choose one semester or the whole record.'
        + '</p>'
        + '<div class="ah-print-actions">'
        // One button, not two. The two actions describe overlapping documents -
        // a single term and the whole career - and side by side the reader has
        // to work out which is which before pressing either. The chooser names
        // them and counts what each will produce.
        + '<button class="ah-print-btn btn btn-light" type="button" '
        + 'data-print-menu="' + r.id + '">'
        + '<i class="fa-solid fa-print"></i> Print official grades</button>'
        + '</div>'
        + '</div>'
        + '</div>';
}

/* Opens the record in the sheet.

   The body of this function was previously the expand-in-place version, and it
   was lost in an edit that replaced the block above it rather than its own.
   Worth knowing because the symptom is subtle: the function parsed, so nothing
   failed loudly, and the page simply stopped opening records.

   Focus moves into the sheet so a keyboard user lands on the heading, and
   Escape closes it - both handled by the delegated listeners below rather than
   per-element, because the roster behind the sheet stays interactive and can
   replace the record at any time. */
function openRecord(studentId) {
    const r = rowById(studentId);
    const sheet = document.getElementById('recordSheet');
    if (!r || !sheet) return;

    const title = document.getElementById('sheetTitle');
    const sub = document.getElementById('sheetSub');
    const body = document.getElementById('sheetBody');

    if (title) title.textContent = r.name;
    if (sub) {
        // The full program name, not the short form the roster's narrow column
        // uses. The PHP helper that abbreviates it does not exist in this
        // script, and there is room for the real name in a 620px sheet anyway.
        //
        // year_level is stored as a bare integer, and a bare "1" here reads as
        // a stray digit next to a course name. It is a year of study, so it is
        // spelled as one. Anything unrecognised is dropped rather than shown:
        // the subtitle carries identity, and a wrong number is worse than none.
        const YEAR = ['', '1st year', '2nd year', '3rd year', '4th year', '5th year'];
        const lvl = YEAR[Number(r.level)];
        sub.textContent = [
            r.number || 'No number on file',
            r.program,
            [lvl || '', r.section].filter(Boolean).join(' · '),
        ].filter(Boolean).join('  ·  ');
    }
    if (body) body.innerHTML = recordHtml(r);

    sheet.hidden = false;
    // One orchestrated entrance, then stillness. The list behind does not
    // animate - it did not move, and implying otherwise would be noise.
    sheet.classList.add('is-open');
    if (title) title.focus();
}

function closeRecord() {
    const sheet = document.getElementById('recordSheet');
    if (!sheet || sheet.hidden) return;
    sheet.classList.remove('is-open');
    sheet.hidden = true;
    // Return focus to the View button that opened it. Without this a keyboard
    // user is dropped at the top of the document, which on a hundred-row term
    // is the one place they were not.
    const last = lastViewButton;
    if (last && document.contains(last)) last.focus();
}
// ── Term box: self-submitting ─────────────────────────────────
// The page has no submit button anywhere, so this form commits itself.
// Enter still works (it is a real form), and committing a term reloads the
// roster for it. Debounced because typing "2026-2028 1st" fires a change on
// every intermediate value, and reloading five times to render one term is
// worse than a short wait.
(function () {
    const form = document.getElementById('termForm');
    const input = document.getElementById('fltTerm');
    if (!form || !input) return;

    let timer = null;
    function commit() {
        clearTimeout(timer);
        timer = setTimeout(() => form.submit(), 700);
    }

    input.addEventListener('change', commit);
    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(timer);
        form.submit();
    });
})();

// ── Search within the term ────────────────────────────────────
// Program and section used to be dropdowns that reloaded the page on every
// change. Searching in place is faster for the way this screen is actually
// used: a registrar is looking for one student in a term they are already
// on, not browsing a taxonomy.
//
// Words are OR'd rather than AND'd, so "bsit a" finds every BSIT student in
// section A. AND-ing is what people expect from a file dialog and never what
// they expect from a name box, where "mendoza" alone must work.
//
// THE SEARCH BOX OPENS WHAT IT FINDS. The students live one level down, so a
// match inside a collapsed section is a match nobody can see, and a search has
// to open the sections holding hits - the folders above are links, so there is
// nothing further up to open.
//
// It does not hide the sections that did not match. They stay on screen and
// stay decidable; hiding the board would take the acceptance decision away.
(function () {
    const input = document.getElementById('rosterSearch');
    const nomatch = document.querySelector('[data-ah-nomatch]');
    const board = document.querySelector('.ahb');
    if (!input || !board) return;

    const groups = Array.from(board.querySelectorAll('tr.ahb-group'));
    const rows = groups.flatMap(g => Array.from(g.querySelectorAll('tr[data-ah-row]')));
    const total = rows.length;

    function apply() {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;

        groups.forEach(grp => {
            let hitInGroup = 0;
            grp.querySelectorAll('tr[data-ah-row]').forEach(row => {
                // The haystack is precomputed server-side, so this stays off the
                // row's own text - which would break the moment a cell contained
                // markup. It carries the section code and program too, so typing
                // either finds the students under it.
                const hay = row.dataset.search || '';
                const hit = terms.length === 0 || terms.some(t => hay.includes(t));
                row.hidden = !hit;
                if (hit) hitInGroup++;
            });
            shown += hitInGroup;

            // Open a group while a search is running only if it holds a hit;
            // with an empty box every group shuts again, so the search does not
            // leave sections expanded behind it.
            const open = terms.length > 0 && hitInGroup > 0;
            grp.hidden = !open;
            const caret = document.querySelector(
                '.ahb-caret[data-caret="' + CSS.escape(grp.dataset.parent) + '"]');
            if (caret) {
                caret.setAttribute('aria-expanded', open ? 'true' : 'false');
                caret.title = (open ? 'Hide' : 'Show') + " this section's students";
            }
        });

        if (nomatch) {
            nomatch.hidden = shown !== 0;
            const p = nomatch.querySelector('p');
            if (p && terms.length) {
                // Name what was looked for. "No results" alone leaves the
                // reader guessing whether the term is empty or the search is
                // wrong, and those need opposite responses.
                p.textContent = 'No student in this term matches "'
                    + input.value.trim() + '". Clear the search to see all '
                    + total + '.';
            }
        }
    }

    input.addEventListener('input', apply);
    input.addEventListener('search', apply);

    // Enter in the search box filters what is already on screen, so it hands
    // the caret to the first match rather than reloading the page.
    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = rows.find(r => !r.hidden);
        if (!first) return;
        // The View button, not the name. The name is text now, so this is the
        // first thing a keyboard user can actually act on in a matching row.
        const btn = first.querySelector('.ah-view');
        if (btn) btn.focus();
    });

    apply();
})();

// ── The one dialog: the term audit ────────────────────────────
// openDialog/closeDialog used to be called here but were defined nowhere in
// the project, so the audit silently did nothing when it was opened. They
// are defined now, locally, because there is exactly one dialog left and a
// shared abstraction for a single caller is indirection without benefit.
//
// The audit is not a per-student view — it is a check over the whole term —
// which is why it stays a dialog while the student record does not.
function openDialog(id, focusSelector) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active');
    el.setAttribute('aria-hidden', 'false');
    const focusTarget = focusSelector ? el.querySelector(focusSelector) : null;
    if (focusTarget) focusTarget.focus();
    else {
        const heading = el.querySelector('h3');
        if (heading) heading.focus();
    }
}

function closeDialog(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active');
    el.setAttribute('aria-hidden', 'true');
}

function findingHtml(f, sev) {
    const icon = sev === 'blocking' ? 'fa-circle-exclamation' : 'fa-circle-info';
    return '<div class="ah-finding" data-sev="' + sev + '">'
        + '<i class="fa-solid ' + icon + '"></i>'
        + '<div class="ah-finding-body">'
        + '<p class="ah-finding-title">' + esc(f.title) + '</p>'
        + '<p class="ah-finding-detail">' + esc(f.detail) + '</p>'
        + '<p class="ah-finding-action">' + esc(f.action) + '</p>'
        + '</div>'
        + '</div>';
}

// The print chooser, alongside the audit dialog's own.
//
// It counts what each option will produce rather than describing it in
// general terms. "Every 1st-semester term on file" tells the reader nothing
// about THIS student; "2 terms" does, and it also means an option that would
// print nothing is visibly empty instead of silently doing so.
function openPrintMenu(studentId) {
    const r = rowById(studentId);
    if (!r) return;

    const modal = document.getElementById('printModal');
    if (!modal) return;
    modal.dataset.studentId = String(studentId);

    // Names the student. The dialog floats over a roster that may be a
    // hundred rows, and without this it is impossible to tell whose record
    // is about to be printed - the press is final and the sheet leaves the
    // office.
    const who = document.getElementById('printWho');
    if (who) {
        const num = r.number ? ' · ' + r.number : '';
        who.textContent = (r.name || '') + num;
    }

    const terms = r.career || [];
    const count = sem => terms.filter(
        t => String(t.semester || '').toLowerCase().startsWith(sem)
    ).length;

    const say = (id, n, unit) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = n === 0
            ? 'No ' + unit + ' terms on file.'
            : n + ' term' + (n === 1 ? '' : 's') + ' on file.';
    };
    say('printCount1st', count('1st'), '1st-semester');
    say('printCount2nd', count('2nd'), '2nd-semester');

    const all = document.getElementById('printCountAll');
    if (all) {
        all.textContent = terms.length === 0
            ? 'No terms on file.'
            : terms.length + ' terms on file · one sheet per term.';
    }

    // The "all" glyph stacks one sheet per term, so the stack is the page count.
    // It is set from the data rather than hard-coded to two: a student in their
    // first year has one term on file, and a chooser showing them a stack of two
    // would be promising paper that does not exist.
    const allGlyph = modal.querySelector('[data-print-scope="all"] .ah-sheet-glyph');
    if (allGlyph) allGlyph.dataset.sheets = terms.length > 1 ? '2' : '1';

    // An option with nothing behind it is disabled rather than hidden: a
    // missing button reads as a bug, and a greyed one reads as the answer.
    modal.querySelectorAll('[data-print-scope]').forEach(btn => {
        const scope = btn.dataset.printScope;
        const n = scope === 'all' ? terms.length : count(scope);
        btn.disabled = n === 0;
    });

    openDialog('printModal', '[data-print-scope]:not([disabled])');
}

function closePrintMenu() {
    closeDialog('printModal');
}

function openAudit() {
    const body = document.getElementById('auditBody');
    const a = AH_AUDIT;

    // What the check is and is not, stated on the surface rather than in a
    // tooltip. Someone reading a term audit should not have to guess whether
    // this thing is allowed to act.
    let html =
        '<p class="ah-audit-note">'
        + '<i class="fa-solid fa-circle-info"></i>'
        + 'This reads the term and reports. It does not change a grade, close a '
        + 'term, or write anything.'
        + '</p>';

    const groups = [
        ['Blocking', a.blocking],
        ['Advisory', a.advisory],
    ];
    groups.forEach(function (g) {
        const list = g[1] || [];
        if (!list.length) return;
        html += '<h4 class="ah-audit-group">' + g[0] + '</h4>';
        list.forEach(f => { html += findingHtml(f, g[0] === 'Blocking' ? 'blocking' : 'advisory'); });
    });

    if (!a.blocking.length && !a.advisory.length) {
        html += '<p class="ah-audit-empty">Nothing to report. Every subject in this '
            + 'term has a final rating, and the stored figures agree with the '
            + 'recorded ones.</p>';
    }

    body.innerHTML = html;
    openDialog('auditModal', '#auditModalTitle');
}

function closeAudit() { closeDialog('auditModal'); }

// ── The acceptance board ───────────────────────────────────────
//
// Three actions, one fetch helper. The gate is never consulted in the
// browser: the row was rendered server-side from the same termAudit() the
// API uses, and a client-side recheck would only ever agree with a stale
// page. The API recomputes and refuses with 409 if the section has since
// stopped being acceptable, and that refusal is rendered as-is.
// The folder levels are LINKS now, not caret rows: the location is the URL, so
// Back, a copied link and a bookmark all behave and there is no open-state to
// persist. What is left to the caret is a SECTION's students - one row, one
// group - so this is deliberately much smaller than the tree walk it replaced.
const ahb = {
    busy: null,

    async call(action, section, program) {
        if (this.busy) return;
        this.busy = action + ':' + program + ':' + section;
        try {
            const res = await fetch('../api/grade-acceptance.php?action=' + action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': AH.csrf,
                },
                // program is REQUIRED, not a nicety. The section code alone does
                // not identify a section - BSIT 11001 and BSCS 11001 are two
                // sections - so an accept without it would stamp both cohorts.
                body: JSON.stringify({
                    action: action, sy: AH.sy, sem: AH.sem,
                    section: section, program: program,
                }),
            });
            return { ok: res.ok, data: await res.json() };
        } finally {
            this.busy = null;
        }
    },
};

// A section row re-rendered from the server's own answer. Reloading the page
// is deliberately NOT what happens here: a registrar accepting six sections
// would lose the term they had selected six times, and the board is the one
// page where staying put matters.
//
// The row is found by section AND program. Matching on the code alone picked
// whichever of the two 11001 rows came first in the DOM, so accepting BSCS's
// section would have repainted BSIT's.
function ahbRepaintRow(section, program, data) {
    const tr = document.querySelector(
        '.ahb tbody tr[data-section="' + CSS.escape(section) + '"]'
        + '[data-program="' + CSS.escape(program) + '"]');
    if (!tr) return;

    const state = data.state || 'waiting';
    tr.dataset.gate = state;

    const chip = tr.querySelector('.ahb-chip');
    if (chip) {
        chip.dataset.state = state;
        chip.innerHTML = '<span class="status-dot" aria-hidden="true"></span>'
            + ({ accepted: 'Accepted', ready: 'Ready to accept',
                 waiting: 'Waitlist', empty: 'Nothing received' }[state] || state);
    }

    const why = tr.querySelector('.ahb-why');
    if (why) {
        why.textContent = data.summary
            || (state === 'waiting'
                ? ((data.blocking || []).length + ' to resolve')
                : '');
    }

    const cells = tr.querySelectorAll('.ahb-count');
    const stamped = cells.length > 1 && cells[1];
    const roster  = cells.length > 0 && cells[0];
    if (stamped && roster && typeof data.roster === 'number') {
        // The figure lives in its own span so it can be replaced as text.
        // Patching childNodes[0] worked only while the markup kept its exact
        // whitespace, which is not a thing worth relying on.
        const num = stamped.querySelector('.ahb-num');
        if (num) num.textContent = data.stamped + ' / ' + data.roster;
        const meter = stamped.querySelector('.ahb-meter');
        const bar = meter ? meter.querySelector('i') : null;
        if (meter && bar) {
            const pct = data.roster > 0 ? Math.round(100 * data.stamped / data.roster) : 0;
            bar.style.width = pct + '%';
            meter.classList.toggle('is-full', pct >= 100);
            meter.setAttribute('aria-label', data.stamped + ' of ' + data.roster + ' accepted');
        }
    }

    // Swap Reopen for Accept rather than toggling: an accepted section's
    // Accept button would be a no-op that still stamps timestamps.
    const actions = tr.querySelector('.ahb-actions');
    if (!actions) return;
    const label = document.createElement('span');
    label.className = 'ah-sr-only';
    label.textContent = state === 'accepted'
        ? 'Accepted. Reopen to put this section back on the waitlist.'
        : 'Accept this section.';

    if (state === 'accepted') {
        actions.innerHTML = '';
        actions.appendChild(ahbButton('Reopen', 'ahb-btn-reopen', 'data-reopen', section, program));
        actions.appendChild(label);
    } else {
        actions.innerHTML = '';
        const btn = ahbButton('Accept section', 'ahb-btn-accept', 'data-accept', section, program);
        if (data.can_accept !== true) {
            btn.disabled = true;
            btn.title = 'Not ready: ' + (data.summary || 'grades are missing');
        }
        actions.appendChild(btn);
        actions.appendChild(label);
    }
}

function ahbButton(text, cls, attr, value, program) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'ahb-btn ' + cls;
    b.setAttribute(attr, value);
    b.dataset.program = program;
    b.textContent = text;
    return b;
}

// One sentence saying what happened, and where the gaps are. Never a bare
// "done": accepting 4 of 5 because one student has no record is the case a
// registrar most needs spelled out after the click.
function ahbSay(msg) {
    let el = document.getElementById('ahbSay');
    if (!el) {
        el = document.createElement('p');
        el.id = 'ahbSay';
        el.className = 'ah-notice';
        el.setAttribute('role', 'status');
        const board = document.querySelector('.ahb');
        if (board && board.parentNode) board.parentNode.insertBefore(el, board.nextSibling);
    }
    el.innerHTML = '<i class="fa-solid fa-circle-info" aria-hidden="true"></i> ' + esc(msg);
}

// Accept. The button is disabled while in flight rather than removed, so the
// row does not jump and a double-click cannot stamp the same section twice.
async function ahbAccept(section, program, btn) {
    if (btn.disabled) return;
    const label = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Accepting…';

    const r = await ahb.call('accept', section, program);
    btn.textContent = label;

    if (!r) return;                       // a call was already in flight
    if (!r.ok) {
        // 409 means the section stopped being acceptable between this page
        // rendering and the click - a grade was withdrawn, or a student was
        // added to the section. Repaint from the server's verdict rather than
        // showing a stale "ready" row that would let the click be repeated.
        ahbRepaintRow(section, program, r.data);
        ahbSay(r.data.message || 'This section could not be accepted.');
        return;
    }

    ahbRepaintRow(section, program, {
        state: 'accepted',
        can_accept: false,
        summary: r.data.summary,
        stamped: r.data.stamped,
        roster: r.data.roster,
        blocking: [],
    });

    const gaps = r.data.no_term_row || [];
    ahbSay(gaps.length
        ? r.data.summary + ' No record on file yet for ' + gaps.join(', ') + '.'
        : r.data.summary);
    ahbRollUp();
}

async function ahbReopen(section, program, btn) {
    btn.disabled = true;
    const r = await ahb.call('reopen', section, program);
    btn.disabled = false;
    if (!r) return;
    if (!r.ok) { ahbSay(r.data.message || 'That section could not be reopened.'); return; }

    ahbRepaintRow(section, program, {
        state: r.data.state,
        can_accept: r.data.can_accept,
        summary: r.data.summary,
        blocking: r.data.blocking || [],
    });
    ahbSay(r.data.summary);
    ahbRollUp();
}

// The head chip above the table changes when a section is accepted or
// reopened, so it is recomputed from the rows on screen rather than by patching
// the word - a chip that disagrees with the rows beneath it is worse than no
// chip at all.
//
// It is only the HEAD. The folder tiles are a level or two up in the URL now,
// and a registrar who accepted a section expects to see the parent folder's
// numbers move; the one thing they cannot expect is a page that quietly rewrites
// a folder they have not opened. Tiles are correct as of the last load, and the
// click into them re-reads the server.
function ahbRollUp() {
    const leaves = Array.from(document.querySelectorAll('.ahb tbody tr.ahb-leaf'));
    if (!leaves.length) return;

    const waiting = leaves.filter(tr => tr.dataset.gate !== 'accepted').length;
    const state = waiting === 0 ? 'accepted' : 'waiting';

    const chip = document.querySelector('.ahb-head .ahb-chip-lg');
    if (chip) {
        chip.dataset.state = state;
        chip.innerHTML = '<span class="status-dot" aria-hidden="true"></span>'
            + (state === 'accepted' ? 'All accepted' : waiting + ' waiting');
    }
}

// The assistant explains the findings; it never clears them.
//
// A failure here leaves the deterministic findings on the row untouched, which
// is the whole reason the gate does not live behind this button. The message
// says which happened, because an empty panel with no error reads as a bug.
async function ahbDraft(section, btn) {
    const out  = document.getElementById('ahbAiOut');
    const note = document.getElementById('ahbAiNote');
    const msg  = document.getElementById('ahbAiMsg');
    if (!out || !note || !msg) return;

    btn.disabled = true;
    out.hidden = false;
    out.dataset.busy = 'true';
    note.textContent = 'Reading section ' + section + '…';
    msg.textContent = '';
    out.scrollIntoView({ block: 'nearest' });

    try {
        const res = await fetch('../api/grade-chaser-ai.php?action=explain', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': AH.csrf,
            },
            body: JSON.stringify({ sy: AH.sy, sem: AH.sem, section: section }),
        });
        const d = await res.json();

        if (!d.success && !d.note) {
            note.textContent = d.message || 'The assistant is unavailable right now.';
            msg.textContent = (d.blocking || []).map(b => '• ' + b.title + ' — ' + b.detail).join('\n');
            return;
        }
        note.textContent = d.note || '';
        msg.textContent = d.message || '';
    } catch (err) {
        note.textContent = 'The assistant could not be reached. The findings on the row are the real check.';
        msg.textContent = '';
    } finally {
        out.dataset.busy = 'false';
        btn.disabled = false;
    }
}


// ── Wiring ────────────────────────────────────────────────────
// One delegated listener for the whole page. Rows are created and hidden
// constantly by the filter, so per-element listeners would have to be
// re-bound after every keystroke.
document.addEventListener('click', e => {
    // View on a roster row opens that student's record in the sheet.
    const view = e.target.closest('.ah-view');
    if (view) {
        lastViewButton = view;
        openRecord(parseInt(view.dataset.view, 10));
        return;
    }
    // The close button. Delegated because the roster behind the sheet stays
    // live and can swap the record at any time, so the ways in and the ways
    // out both belong in one place. There is no scrim to close on - the sheet
    // does not dim the roster, by design.
    const shut = e.target.closest('[data-sheet-close]');
    if (shut) {
        closeRecord();
        return;
    }
    // Opens the chooser. The student's id is remembered on the dialog rather
    // than read back off the button at choice time, because the button lives
    // inside a row that the search filter can hide - and a chooser that
    // silently loses its subject when a keystroke hides the row is worse than
    // one extra property on the element.
    const menu = e.target.closest('[data-print-menu]');
    if (menu) {
        openPrintMenu(parseInt(menu.dataset.printMenu, 10));
        return;
    }
    const choice = e.target.closest('[data-print-scope]');
    if (choice) {
        const modal = document.getElementById('printModal');
        const sid = modal ? parseInt(modal.dataset.studentId || '0', 10) : 0;
        if (sid) {
            printOfficial(sid, choice.dataset.printScope);
        }
        closePrintMenu();
        return;
    }
    const audit = e.target.closest('[data-audit]');
    if (audit) { openAudit(); return; }

    // ── Acceptance board ─────────────────────────────────────────
    // Delegated with the rest of the page so a repainted row - whose buttons
    // are built in JS - is wired without re-binding anything.
    const accept = e.target.closest('[data-accept]');
    if (accept) { ahbAccept(accept.dataset.accept, accept.dataset.program, accept); return; }

    const reopen = e.target.closest('[data-reopen]');
    if (reopen) { ahbReopen(reopen.dataset.reopen, reopen.dataset.program, reopen); return; }

    const draft = e.target.closest('[data-ai]');
    if (draft) { ahbDraft(draft.dataset.ai, draft); return; }

    // The caret opens one section's students. The folder levels above are real
    // links, so there is no subtree to hide here - one row, one group.
    const caret = e.target.closest('[data-caret]');
    if (caret) {
        const grp = document.querySelector(
            'tr.ahb-group[data-parent="' + CSS.escape(caret.dataset.caret) + '"]');
        if (grp) {
            const open = grp.hidden;
            grp.hidden = !open;
            caret.setAttribute('aria-expanded', open ? 'true' : 'false');
            caret.title = (open ? 'Hide' : 'Show') + " this section's students";
        }
        return;
    }

    const closer = e.target.closest('[data-close-dialog]');
    if (closer) { closeDialog(closer.dataset.closeDialog); }
});

// Escape closes whichever dialog is open, and only when one is.
//
// The sheet is listed last and checked last. It is not a dialog - the roster
// behind it is still live - so a real dialog that happened to be open must win
// the key, and the sheet only closes once nothing else claims Escape.
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const audit = document.getElementById('auditModal');
    if (audit && audit.classList.contains('active')) { closeAudit(); return; }
    const print = document.getElementById('printModal');
    if (print && print.classList.contains('active')) { closePrintMenu(); return; }
    const sheet = document.getElementById('recordSheet');
    if (sheet && !sheet.hidden) closeRecord();
});

// The printable OFFICIAL GRADES record.
//
// This is the artefact the Registrar still owns after grades moved to
// Faculty: a formal career record on the BCP letterhead, listing every
// subject on file for the student, across every term.
//
// GWA policy — the figure printed is the Registrar's own COMPUTATION,
// termGwa()/careerGwa() over the ratings actually held, calculated server
// side in the payload (gwa_live / careerGwa). It was printing
// academic_history.gwa_computed, which nothing in the application ever
// writes: only a one-time migration backfill ever set it, copying the old
// hand-typed `gwa`. So the old document printed a frozen copy of a number
// somebody typed, which could disagree with the roster column printed on the
// same screen. Faculty's own figure (gwa_reported) is still carried, and a
// disagreement between the two is stated on the record rather than silently
// resolved — a document that quietly picks one of two conflicting numbers is
// the failure mode those two columns exist to prevent.
function printGradeTemplate(studentId) {
    printOfficial(studentId, 'all');
}

// The three print scopes, and the one entry point for all of them.
//
// '1st' and '2nd' mean every term of that semester across EVERY year - the
// student's 1st-semester terms, not merely the one on screen. Grouping by
// semester rather than by school year is what makes "All semesters, one
// semester per page" come out as asked: a reader asking for the 1st semester
// wants that half of every year, so those terms make up page 1 and the
// 2nd-semester terms start page 2.
//
// The terms are RE-SORTED rather than trusted to arrive in order, because the
// pagination depends on same-semester terms being contiguous. The payload
// orders by school year and then semester, which interleaves them
// (2024-2025 1st, 2024-2025 2nd, 2025-2026 1st, ...) - so without this the
// "two page" request produces three page breaks instead of one.
function printOfficial(studentId, scope) {
    const r = rowById(studentId);
    if (!r) return;

    const SEM_ORDER = {
        '1st': 1, 'first': 1, '1': 1,
        '2nd': 2, 'second': 2, '2': 2,
        '3rd': 3, 'third': 3, '3': 3,
        'summer': 4, 'midterm': 4,
    };
    const rank = s => SEM_ORDER[String(s || '').toLowerCase()] ?? 9;

    // Semester is the PRIMARY sort key, school year the secondary one.
    //
    // Year-first is the obvious order to reach for and it is the wrong one
    // here: it produces 2024-2025 1st, 2024-2025 2nd, 2025-2026 1st,
    // 2025-2026 2nd - interleaved, which puts a page break between every
    // pair and turns "one semester per page" into four pages. Semester-first
    // groups them into 1st 2024-2025, 1st 2025-2026, then 2nd 2024-2025,
    // 2nd 2025-2026, so exactly one break falls between the two halves.
    const all = (r.career || []).slice().sort((a, b) => {
        const rs = rank(a.semester) - rank(b.semester);
        if (rs !== 0) return rs;
        const ya = String(a.school_year || ''), yb = String(b.school_year || '');
        return ya < yb ? -1 : (ya > yb ? 1 : 0);
    });

    const isPartial = scope !== 'all' && !!scope;
    const wanted = isPartial ? all.filter(t => rank(t.semester) === rank(scope)) : all;
    if (!wanted.length) { return; }

    const na = v => (v === null || v === undefined || v === '') ? 'N/A' : v;
    const multiSem = rank(wanted[0].semester) !== rank(wanted[wanted.length - 1].semester);

    // ── Identity block ──────────────────────────────────────────
    // Date issued belongs on the document rather than only in the footer,
    // because "issued" and "printed" are different claims: a record printed
    // from this system today may certify a term Faculty sent last year.
    let body = '<div class="doc-h2">Student</div>'
        + '<table class="gt-ident">'
        + '<tr><td class="gt-k">Student Number</td><td>' + esc(na(r.number)) + '</td>'
        + '<td class="gt-k">Program</td><td>' + esc(na(r.program)) + '</td></tr>'
        + '<tr><td class="gt-k">Name</td><td>' + esc(na(r.name)) + '</td>'
        + '<td class="gt-k">Year / Section</td><td>' + esc(na(r.level))
        + (r.section ? ' · ' + esc(r.section) : '') + '</td></tr>'
        + '<tr><td class="gt-k">Date Issued</td><td>' + esc(na(AH.printedOn)) + '</td>'
        + '<td class="gt-k">Terms on file</td><td>'
        + wanted.length + (isPartial ? ' of ' + all.length : '') + '</td></tr>'
        + '</table>';

    // ── Per-term grades ─────────────────────────────────────────
    const byTerm = r.careerSubjects || {};

    wanted.forEach((t, i) => {
        const key = (t.school_year || '') + '|' + (t.semester || '');
        const subs = byTerm[key] || [];

        // Page break when the semester changes, so "All semesters" lays out as
        // one page per semester. Inline rather than a class so editing the
        // print stylesheet cannot quietly drop it - this layout IS the ask.
        const brk = (i > 0 && rank(t.semester) !== rank(wanted[i - 1].semester))
            ? ' style="page-break-before:always"' : '';

        // The heading is what carries the page break, so it must exist before
        // anything else: it is the boundary the break attaches to, and a
        // document whose term headings went missing is unreadable.
        body += '<div class="gt-term-head"' + brk + '>'
            + esc(na(t.semester)) + ' Semester · ' + esc(na(t.school_year)) + '</div>';

        if (!subs.length) {
                body += '<p class="gt-empty">No grades recorded for this term.</p>';
            } else {
                body += '<table class="gt-grid">'
                    + '<thead><tr><th>Subject</th><th>Code</th><th class="gt-u">Units</th>'
                    // Final rating only - no letter column.
                    //
                    // The A / B+ / C / F letters were a SECOND grading scale
                    // sitting beside the numeric one on the same row, and they
                    // disagree: a subject can read "final rating 3.00, grade A".
                    // An official record showing both asserts two answers to
                    // one question. The numeric rating is the one termGwa()
                    // averages and the one the GWA line is computed from, so it
                    // is the one that prints. Result stays, because that is an
                    // outcome rather than a second scale - it is what tells a
                    // failed subject from an unrated one.
                    + '<th class="gt-n">Final Rating</th><th>Result</th>'
                    + '<th>Instructor</th></tr></thead><tbody>';
                subs.forEach(s => {
                    const rated = s.final_rating !== null && s.final_rating !== undefined
                        && s.final_rating !== '';
                    body += '<tr>'
                        + '<td>' + esc(na(s.subject)) + '</td>'
                        + '<td>' + esc(na(s.subject_code)) + '</td>'
                        + '<td class="gt-u">' + esc(na(s.units)) + '</td>'
                        + '<td class="gt-n">'
                        + (rated ? Number(s.final_rating).toFixed(2) : 'Not rated') + '</td>'
                        + '<td>' + esc(na(s.grade_status)) + '</td>'
                        + '<td>' + esc(na(s.instructor)) + '</td>'
                        + '</tr>';
                });
                body += '</tbody></table>';
            }
// Term summary: the COMPUTED figure is printed; a mismatch with
        // Faculty's own reported number is stated, never quietly dropped.
        // gwa_live is computed server-side from the ratings on file.
            const computed = t.gwa_live !== null && t.gwa_live !== undefined
                ? Number(t.gwa_live) : null;
            const reported = t.gwa_reported !== null && t.gwa_reported !== undefined
                ? Number(t.gwa_reported) : null;
            const disagree = computed !== null && reported !== null
                && Math.abs(computed - reported) > 0.005;

            const termUnits = Number(t.units_live || 0);
            body += '<div class="gt-term-sum">'
                + '<span>Term GWA</span><b>'
                + (computed === null ? 'N/A' : computed.toFixed(2)) + '</b>'
                + '<span>Units</span><b>'
                + (termUnits > 0 ? termUnits : 'N/A') + '</b>'
                + '</div>';
            if (disagree) {
                body += '<div class="gt-flag">Faculty reports a term GWA of '
                    + reported.toFixed(2) + ' for this term; the figure computed from '
                    + 'the recorded ratings is ' + computed.toFixed(2)
                    + '. Please confirm with Faculty before issuing this record.</div>';
            }
            if (i < wanted.length - 1) body += '<div class="gt-rule"></div>';
        });

    // Cumulative block, on the whole record ONLY. A single-semester sheet with
    // a career figure on it invites the reader to treat a term number as a
    // lifetime one, so the partial print stops at the term.
    if (isPartial) {
        body += '<div class="gt-flag">This sheet covers '
            + wanted.length + ' term' + (wanted.length === 1 ? '' : 's')
            + ' only. It is not the student\'s complete academic record.</div>';
    } else {
        // Both figures come from the same weighted pass over every rated
        // subject on file - careerGwa() and the unit total are computed
        // together in PHP - so the number printed can never sit next to a
        // total taken from a different set of rows.
        const careerUnits = Number(r.careerUnits || 0);
        body += '<div class="gt-career">'
            + '<div><span>Cumulative GWA</span><b>'
            + (r.careerGwa === null || r.careerGwa === undefined
                ? 'N/A' : Number(r.careerGwa).toFixed(2))
            + '</b></div>'
            + '<div><span>Total units earned</span><b>'
            + (careerUnits > 0 ? careerUnits : 'N/A')
            + '</b></div>'
            + '</div>';
    }

    body += '<div class="sig"><div class="box"><div class="line">Certified by:<br>Registrar</div></div>'
        + '<div class="box"><div class="line">Noted by:<br>School Head / President</div></div></div>'
        + '<div class="foot-note">Grade records are maintained by Faculty Management '
        + 'and issued here for reference. GWA shown is computed from the recorded '
        + 'ratings as held on the date issued.<br>Printed ' + esc(AH.printedOn || '') + '.</div>';

    // The crest must be an ABSOLUTE url. printDocument() writes into an
    // about:blank iframe, so a relative src resolves against nothing and the
    // letterhead prints with an empty box where the logo should be. This is
    // the same bug js/insights.js already fixed for the AI Insight report by
    // resolving against window.location.href — so the two consumers now do
    // it the same way rather than one being right by accident.
    const logo = AH.logoPath
        ? new URL(AH.logoPath, window.location.href).href
        : '';

    BCPPrint.printDocument({
        title: 'OFFICIAL GRADES — ' + (r.name || ''),
        css: BCPPrint.letterheadCss() + GRADE_TEMPLATE_CSS,
        body: BCPPrint.headerHtml({
            logoUrl: logo,
            title: 'OFFICIAL GRADES'
        }) + body
    });
}

// Layout for the template only. The letterhead itself comes from
// BCPPrint, so it cannot drift from the AI Insight report.
const GRADE_TEMPLATE_CSS = [
    '.doc-h2 { font-size:11pt; font-weight:700; letter-spacing:.5px;',
    '           margin:16px 0 6px; text-align:center; }',
    // The identity block is a 4-column table (label/value/label/value). Left
    // on auto layout, the program string in the last cell claims 308px of the
    // 700px page and starves the middle value column to 84px — "October 4,
    // 2026" and the student's name wrap onto two lines and jam against the
    // next label, which reads as "Terms on file1 of 2" with no gap. Fixed
    // layout plus explicit widths give both value columns the same 28%, so
    // every value sits on one line no matter how long the program name is.
    '.gt-ident { width:100%; border-collapse:collapse; margin-bottom:6px;',
    '             table-layout:fixed; }',
    '.gt-ident td { padding:3px 6px; font-size:10pt; vertical-align:top; }',
    '.gt-k { font-weight:700; width:22%; white-space:nowrap; }',
    '.gt-ident td:nth-child(2), .gt-ident td:nth-child(4) { width:28%; }',
    '.gt-term-head { font-size:10.5pt; font-weight:700; text-align:center;',
    '                margin:14px 0 5px; padding:3px 0;',
    '                border-top:1px solid #000; border-bottom:1px solid #000; }',
    '.gt-grid { width:100%; border-collapse:collapse; margin-bottom:4px;',
    '           font-size:9.5pt; page-break-inside:avoid; }',
    '.gt-grid th { border-bottom:1px solid #000; padding:3px 4px;',
    '              font-size:8.5pt; text-transform:uppercase; letter-spacing:.3px; }',
    '.gt-grid td { padding:3px 4px; border-bottom:1px dotted #999; }',
    '.gt-u, .gt-n { text-align:right; white-space:nowrap; }',
    '.gt-empty { font-size:10pt; font-style:italic; text-align:center; margin:4px 0; }',
    '.gt-term-sum { font-size:10pt; margin:4px 0 0; }',
    // Each label/value pair is a unit, separated by a wide gap. Run
    // together as "Term GWA 1.00 Credits 12.00" the reader cannot tell where
    // one figure ends and the next begins.
    '.gt-term-sum span, .gt-term-sum b { margin-right:4px; }',
    '.gt-term-sum b { margin-right:26px; }',
    '.gt-career span { margin-right:6px; }',
    // A disagreement between our figure and Faculty's is printed, never
    // resolved silently. A record that quietly picks one of two numbers is
    // worse than one that admits there are two.
    '.gt-flag { border:1px solid #000; padding:5px 7px; margin:6px 0;',
    '           font-size:9pt; line-height:1.4; }',
    '.gt-rule { border-top:1px solid #000; margin:10px 0 0; }',
    // Two rows now: cumulative GWA, then total units. Row gap so the pair reads
    // as two figures rather than one run-on line, and a hairline above the
    // block so it separates from the last term's rows.
'.gt-career { font-size:11pt; font-weight:700; text-align:center;',
    '             margin:16px 0 0; padding-top:8px; border-top:2px solid #000; }',
    '.gt-career > div { margin-bottom:3px; }'
].join('\n');
</script>

<?php include '../includes/footer.php'; ?>
