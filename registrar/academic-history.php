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

$roster = $db->fetchAll("
    SELECT s.id, s.student_number, s.first_name, s.last_name,
           s.course AS program, s.year_level, s.section
      FROM students s
     WHERE s.status != 'archived'
     ORDER BY s.last_name, s.first_name
");

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
// The career GWA below is still read from the database, because it is
// computed over every term the filtered students have and a sample
// roster has no history to compute it from. A screenshot therefore shows
// a real career figure above a sample roster. That is a property of the
// harness, not of the page, and it is why these images are for looking
// at layout and not at data.
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
}

$audit = termAudit($sy, $sem, $rosterForAudit);

// Career GWA across every term on file for the filtered roster, so the
// figure someone remembers can be checked against what is actually held.
$career      = null;
$careerUnits = 0.0;
if ($visible) {
    $in = implode(',', array_map(static fn($r) => (int) $r['id'], $visible));
    $careerTerms = [];
    foreach ($db->fetchAll("SELECT id FROM academic_history WHERE student_id IN ($in)") as $h) {
        $subjectsForTerm = [];
        foreach ($db->fetchAll("SELECT units, final_rating FROM academic_grades WHERE academic_history_id = ?", [(int) $h['id']]) as $gs) {
            $subjectsForTerm[] = ['units' => (float) ($gs['units'] ?? 0), 'final_rating' => $gs['final_rating']];
        }
        $careerTerms[] = ['subjects' => $subjectsForTerm];
    }
    $career = careerGwa($careerTerms);
    foreach ($careerTerms as $t) {
        foreach ($t['subjects'] as $s) {
            if (termRatingValid($s['final_rating'])) {
                $careerUnits += max(0.0, (float) $s['units']);
            }
        }
    }
}

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
    <div class="ah-metric is-career"<?= $career === null ? ' data-empty="true"' : '' ?>>
        <p class="ah-label">Career GWA</p>
        <p class="ah-value"><?= $career === null ? '&mdash;' : number_format($career, 2) ?></p>
        <p class="ah-note">
            <?= $career === null
                ? 'no ratings on file'
                : 'over ' . rtrim(rtrim(number_format($careerUnits, 0), '0'), '.') . ' units' ?>
        </p>
    </div>
</section>

<?php if ($termProblem !== ''): ?>
    <p class="ah-notice" role="status">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <?= $termProblem ?>
    </p>
<?php endif; ?>

<?php // The roster panel. The term and the find box live in its toolbar,
        // where the other registrar pages put their filters.
//
// The form carries NO submit button. It commits itself when a value is
// committed (change, or Enter), which removes a control without removing
// the ability to change term — two separate problems, one of which needed
// a control. ?>
<section class="panel">
    <form class="panel-toolbar" method="get" action="academic-history.php" id="termForm">
        <div class="panel-title">
            <i class="fa-solid fa-list-check" aria-hidden="true"></i>
            Roster
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
                    placeholder="Search by name, number, program…"
                    autocomplete="off"
                    spellcheck="false"
                    style="width:260px"
                >
            </div>
        </div>
    </form>

<?php if (!$visible): ?>
        <div class="table-responsive">
            <table class="table">
                <tbody>
                <tr><td>
                    <div class="ah-empty-state">
                        <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                        <?php if ($program !== '' || $section !== ''): ?>
                            <p>No students match these filters</p>
                            <span>The program and section together match nobody.</span>
                        <?php else: ?>
                            <p>No active students in this term</p>
                            <span>Archived students are left out of this page on purpose.</span>
                        <?php endif; ?>
                    </div>
                </td></tr>
                </tbody>
            </table>
        </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    <th scope="col" class="ah-col-opt">Program</th>
                    <th scope="col" class="ah-col-sec" data-num>Units</th>
                    <th scope="col" class="ah-col-sec" data-num>Subjects</th>
                    <th scope="col" data-num>Term GWA</th>
                    <th scope="col">Record</th>
                    <?php // The sr-only text goes INSIDE the cell. Putting the
                    // class on the <th> itself hides the header cell too and
                    // leaves a grey block over the column with no label. ?>
                    <th scope="col"><span class="ah-sr-only">Open</span></th>
                </tr>
            </thead>
<?php foreach ($rows as $r): ?>
                <?php
                // Lower is better on this scale, so the bands run the
                // opposite way round from a school average.
                $band = 'none';
                if ($r['gwa'] !== null) {
                    $band = $r['gwa'] < GWA_AT_RISK - 0.5 ? 'good'
                          : ($r['gwa'] < GWA_AT_RISK ? 'warn' : 'poor');
                }
                $stateLabel = [
                    'complete' => 'Complete',
                    'partial'  => $r['missing'] . ' missing',
                    'none'     => 'Not received',
                ][$r['state']];
                ?>
                <tr data-ah-row
                    data-student="<?= (int) $r['id'] ?>"
                    data-search="<?= htmlspecialchars(strtolower(implode(' ', [
                        $r['name'], $r['number'], $r['program'],
                        $r['level'], $r['section'],
                    ]))) ?>">

                    <td>
                        <div class="ah-id-cell">
                            <?php // The name is now plain text. It used to be a
                            // disclosure button that expanded the record in a
                            // row beneath the student; the record opens in a
                            // sheet instead, from the View button, so a name
                            // that looks clickable but is not would be a lie.
                            // It keeps the same weight and colour the button had,
                            // so the row still scans the same way. ?>
                            <span class="ah-name"><?= htmlspecialchars($r['name']) ?></span>
                            <span class="ah-num"><?= htmlspecialchars($r['number'] !== '' ? $r['number'] : 'No number on file') ?></span>
                        </div>
                    </td>

                    <td class="ah-muted ah-col-opt">
                        <?= htmlspecialchars(ahProgramShort($r['program']) ?: '—') ?>
                    </td>
                    <td data-num class="ah-fig ah-col-sec">
                        <?= $r['units'] > 0
                            ? rtrim(rtrim(number_format($r['units'], 2), '0'), '.')
                            : '—' ?>
                    </td>
                    <td data-num class="ah-fig ah-col-sec">
                        <?= count($r['subjects']) ?: '—' ?>
                    </td>
                    <td data-num>
                        <span class="ah-gwa" data-band="<?= $band ?>">
                            <?= $r['gwa'] === null ? '—' : number_format($r['gwa'], 2) ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-badge ah-state" data-state="<?= $r['state'] ?>">
                            <span class="status-dot"></span><?= $stateLabel ?>
                        </span>
                    </td>
                    <td class="ah-act">
                        <button class="ah-view" type="button" data-view="<?= (int) $r['id'] ?>"
                                aria-label="Open the term record for <?= htmlspecialchars($r['name']) ?>">
                            View
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ah-panel-foot">
        Showing <?= count($visible) ?> of <?= count($roster) ?>
        student<?= count($visible) === 1 ? '' : 's' ?> in
        <?= htmlspecialchars(termLabel($sy, $sem)) ?>.
    </div>

    <div class="ah-empty-state" data-ah-nomatch hidden>
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <p>No student matches that</p>
        <span></span>
    </div>
<?php endif; ?>
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
// change. Searching the roster in place is faster for the way this screen is
// actually used: a registrar is looking for one student in a term they are
// already on, not browsing a taxonomy.
//
// Words are OR'd rather than AND'd, so "bsit a" finds every BSIT student in
// section A. AND-ing is what people expect from a file dialog and never what
// they expect from a name box, where "mendoza" alone must work.
(function () {
    const input = document.getElementById('rosterSearch');
    const nomatch = document.querySelector('[data-ah-nomatch]');
    const table = document.querySelector('.table');
    if (!input || !table) return;

    const rows = Array.from(table.querySelectorAll('tr[data-ah-row]'));
    const total = rows.length;

    function apply() {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;

        rows.forEach(row => {
            // The haystack is precomputed server-side, so this stays off the
            // row's own text - which would break the moment a cell contained
            // markup.
            const hay = row.dataset.search || '';
            const hit = terms.length === 0 || terms.some(t => hay.includes(t));
            row.hidden = !hit;

            // Nothing to hide alongside the row any more. This used to also
            // hide the expanded record under it, so a filtered-out student
            // could not leave an orphaned record showing. The record is in a
            // sheet now and the sheet follows whoever is open, not whoever is
            // visible, so there is no pairing left to maintain.

            if (hit) shown++;
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
    '.gt-ident { width:100%; border-collapse:collapse; margin-bottom:6px; }',
    '.gt-ident td { padding:3px 6px; font-size:10pt; vertical-align:top; }',
    '.gt-k { font-weight:700; width:22%; white-space:nowrap; }',
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
