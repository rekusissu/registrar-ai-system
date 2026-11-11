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
requireRole('staff');
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

// Student history includes unsectioned students; only the review board groups by section.
$roster = $db->fetchAll("
    SELECT s.id, s.student_number, s.first_name, s.last_name,
            s.course AS program, s.year_level, s.section
       FROM students s
      WHERE s.status != 'archived'
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
        'state'       => $stamped === count($members) && $stamped > 0 ? 'accepted' : $gate['state'],
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

// Student initials are shared by the directory and grade dialog.
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
                h.gwa_computed, h.gwa_reported, h.credits, h.accepted_at, (SELECT MIN(g.source_system) FROM academic_grades g WHERE g.academic_history_id=h.id) AS source_system, (SELECT MAX(g.received_at) FROM academic_grades g WHERE g.academic_history_id=h.id) AS received_at
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
            'accepted_at' => $t['accepted_at'],
            'source_system' => $t['source_system'],
            'received_at' => $t['received_at'],
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
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$page_title = 'Academic History';
$page_description = 'Record term grades, computed GWA, and check a term before closing it';
$body_page = 'academic';
$APP_ROOT = '../';
// Scoped stylesheet, loaded by includes/header.php. The page itself
// carries no inline CSS, which is what keeps it looking like the rest
// of the portal rather than like a separate application.
$extra_css = ['academic-history.css', 'academic-workspace.css', 'academic-review.css'];
// bcp-letterhead.js supplies BCPPrint.printDocument(), which
// printGradeTemplate() calls. It must load before the inline script below
// runs. footer.php cache-busts each by mtime.
$page_scripts = ['bcp-letterhead.js', 'academic-workspace.js', 'academic-review.js'];
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
<?php include __DIR__ . '/../includes/academic-workspace.php'; ?>
<?php include __DIR__ . '/../includes/academic-review.php'; ?>
<?php // The panel and the container close here, before the sheet, which
// lives outside the page flow so it is not announced until it is opened. ?>
</section>
</div>
</main>

<?php // Landscape history dialog; focus is managed by academic-workspace.js. ?>
<div class="aw-modal" id="recordSheet" hidden>
 <div class="aw-dialog" role="dialog" aria-modal="true" aria-labelledby="sheetTitle">
  <div class="aw-dialog-head"><div><h2 id="sheetTitle" tabindex="-1">Student academic history</h2><p>Semester grades and academic record</p></div><button class="aw-close" type="button" data-sheet-close aria-label="Close academic history">×</button></div>
  <div class="aw-identity" id="awIdentity"></div>
  <p class="aw-demo-note" id="awDemoNote" hidden>Demo academic record · For workflow preview only. Not valid for official use.</p>
  <div class="aw-grade-body"><nav class="aw-periods" id="awPeriods" aria-label="Academic periods"></nav><div class="aw-grades" id="awGrades"></div></div>
  <div class="aw-modal-footer" style="justify-content:flex-end"><div><button class="btn btn-light" id="awAllRecords" type="button">Print options</button><button class="btn btn-primary" id="awPreview" type="button">Preview semester record</button></div></div>
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
<?php include __DIR__ . '/../includes/academic-print-options.php'; ?>
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
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

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
// Replaced by the landscape history dialog in academic-workspace.js.
function openRecord() {}
function closeRecord() {}
// Audit dialog.
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
// Print chooser is implemented in academic-review.js.
function openPrintMenu() {}
function closePrintMenu() {}

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
    const selectedKey = String(scope || '').startsWith('selected:') ? String(scope).slice(9) : null;
    const wanted = selectedKey ? all.filter(t => t.school_year + '|' + t.semester === selectedKey) : (isPartial ? all.filter(t => rank(t.semester) === rank(scope)) : all);
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

    const printIsPreview = wanted.some(t => t.source_system === 'demo' || !t.accepted_at);
    if (printIsPreview && !wanted.some(t => t.source_system === 'demo')) body = '<p style="text-align:center;font-weight:bold">UNACCEPTED RECORD — PREVIEW ONLY</p>' + body;
    if (wanted.some(t => t.source_system === 'demo')) body = '<p style="text-align:center;font-weight:bold">DEMO RECORD — NOT VALID FOR OFFICIAL USE</p>' + body;

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
            + '<div><span>Total included units</span><b>'
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
        title: (printIsPreview ? 'ACADEMIC RECORD PREVIEW — ' : 'OFFICIAL GRADES — ') + (r.name || ''),
        css: BCPPrint.letterheadCss() + GRADE_TEMPLATE_CSS,
        body: BCPPrint.headerHtml({
            logoUrl: logo,
            title: printIsPreview ? 'ACADEMIC RECORD PREVIEW' : 'OFFICIAL GRADES'
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
