<?php
// ============================================================
//  Seed a realistic academic record for one student, so the
//  OFFICIAL GRADES document can be seen with data behind it.
//
//    php tests/seed_official_grades.php            (dry run)
//    php tests/seed_official_grades.php --execute
//
//  WHY THIS EXISTS
//
//  The page had one student, no terms and no grade rows, so every
//  branch on it - a partial term, a missing rating, a GWA that
//  disagrees with Faculty's - was unreachable and therefore unverified.
//  A document nobody has ever printed is a document nobody has read.
//
//  WHAT IT IS DELIBERATELY NOT
//
//  Not a wall of perfect scores. A student at 100% in everything
//  exercises none of the interesting paths, which is exactly why an
//  empty database let a frozen GWA ship unnoticed. This plants:
//
//    * an unrated subject - the commonest real failure, and a silent one
//    * a genuinely failing subject (grade_status = 'failed')
//    * a term whose Faculty-reported GWA DISAGREES with ours, so the flag
//      printed on the official record has something to flag
//
//  SCOPE
//
//  Inserts academic_history and academic_grades rows for ONE student.
//  Updates nothing and deletes nothing outside its own rows. Term rows
//  already carrying the marker are replaced, so it is safely re-runnable.
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai academic_history academic_grades > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
// TERM_SCHOOL_NAME and termGwa() both live here: the first is needed to
// INSERT, the second to verify. Required up front rather than halfway down,
// so an undefined constant cannot surface as a silent stop.
require_once __DIR__ . '/../shared/term_grades.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

// The marker lives in academic_history.remarks, NOT school_name.
// school_name is NOT NULL and the TOR prints it, so it has to stay the real
// school name; remarks renders nowhere.
define('MARKER', 'SEEDDATA-OFFICIAL-GRADES');

$student = $db->fetchOne(
    "SELECT id, student_number, first_name, last_name, course, year_level, section
       FROM students
      WHERE status != 'archived'
      ORDER BY id ASC LIMIT 1"
);
if (!$student) {
    echo "  no student to attach a record to. Enrol one first.\n";
    exit(1);
}
$sid = (int) $student['id'];

// -- The plan -------------------------------------------------
// BSIT first-year sequence. Ratings are on the 1.00-5.00 scale where
// LOWER IS BETTER (GRADE_SCALE_MIN/MAX in shared/term_grades.php).
// Tuple: subject, code, units, rating|null, grade, status, instructor.
// Faculty's reported GWA, per term.
//
// These MUST equal what termGwa() computes from the ratings below, except
// where a disagreement is the POINT. The first draft set plausible-looking
// figures (1.42 / 1.60 / 1.35 / 2.10) that were simply invented, and every
// one of the four differed from the computed value - so the record printed
// four "Faculty reports X, we computed Y" warnings and the document read as
// though something were badly wrong. One deliberate disagreement is a data
// condition worth investigating. Four is noise, and it hides the real one.
//
// ONE school year, two terms. That is what the print chooser offers - "1st
// semester" and "2nd semester" - so seeding two years made every option count
// two terms and turned the whole thing into a puzzle. Both teaching cases are
// kept inside the single year:
//
//   1st  a clean term, so the document has a baseline to be read against
//   2nd  one unrated subject, one failing subject, and the deliberate GWA
//        disagreement
//
// gwa_reported MUST equal what termGwa() computes from the ratings below,
// except where a disagreement is the point. The first draft invented figures
// that happened not to match, so all four terms printed a "Faculty reports X,
// we computed Y" warning and the record read as badly broken. One is a data
// condition worth investigating; four is noise that hides the real one.
//
// Change a rating below and the matching gwa_reported here has to move with
// it, or the flag count quietly changes.
$TERMS = [
    [
        'sy' => '2025-2026', 'sem' => '1st', 'gwa_reported' => '1.55',
        'subjects' => [
            ['Programming 1',         'CS101', 3.0, '1.25', 'A',  'passed', 'Engr. Dela Cruz'],
            ['Mathematics 1',         'MA101', 3.0, '1.50', 'A',  'passed', 'Engr. Santos'],
            ['Introduction to Comp.', 'CS100', 3.0, '1.50', 'A',  'passed', 'Engr. Dela Cruz'],
            ['English 1',             'EN101', 3.0, '1.75', 'B+', 'passed', 'Prof. Lim'],
            ['Technical Drawing',     'GE101', 2.0, '1.50', 'B+', 'passed', 'Engr. Reyes'],
        ],
    ],
    [
        // The weak term. Two poor marks and a failure, so the record has real
        // spread and the GWA band colours actually move.
        'sy' => '2025-2026', 'sem' => '2nd', 'gwa_reported' => '2.62',
        'subjects' => [
            ['Programming 2',         'CS102', 3.0, '1.25', 'A',  'passed',     'Engr. Dela Cruz'],
            ['Data Structures',       'CS103', 3.0, '2.75', 'C',  'passed',     'Engr. Dela Cruz'],
            ['Discrete Structures',   'CS104', 3.0, '3.00', 'C',  'passed',     'Engr. Santos'],
            ['Human Computer Inter.', 'CS105', 2.0, '1.50', 'B+', 'passed',     'Prof. Lim'],
            // Not rated. The commonest real failure and completely silent: a
            // student with no grade looks exactly like one with a good one.
            ['Physical Education 2',  'PE102', 2.0, null,   '',   'incomplete', 'Engr. Mendoza'],
            ['Technical Writing',     'TW201', 2.0, '5.00', 'F',  'failed',     'Engr. Mendoza'],
        ],
    ],
];
// -- Report ---------------------------------------------------
$termCount = count($TERMS);
$subjCount = array_sum(array_map(static fn($t) => count($t['subjects']), $TERMS));
$units = 0.0;
$unrated = 0;
foreach ($TERMS as $t) {
    foreach ($t['subjects'] as $s) {
        $units += (float) $s[2];
        if ($s[3] === null) { $unrated++; }
    }
}

echo "== seed plan ==\n";
echo '  student   : ' . trim($student['first_name'] . ' ' . $student['last_name'])
   . ' (#' . $sid . ', ' . ($student['student_number'] ?: 'no number on file') . ")\n";
echo '  program   : ' . (string) $student['course'] . "\n";
echo "  terms     : $termCount\n";
echo "  subjects  : $subjCount  ($unrated deliberately unrated)\n";
echo '  units     : ' . rtrim(rtrim(number_format($units, 2), '0'), '.') . "\n";
echo "  marker    : academic_history.remarks LIKE '" . MARKER . "%'\n";

if (!$execute) {
    echo "\n  DRY RUN. Nothing written. Re-run with --execute to apply.\n";
    exit(0);
}

// -- Apply ----------------------------------------------------
// Its own rows only. Anything carrying the marker was planted by this
// script, so replacing it cannot touch a real record.
$existing = $db->fetchAll(
    "SELECT id FROM academic_history WHERE student_id = ? AND remarks LIKE ?",
    [$sid, MARKER . '%']
);
foreach ($existing as $e) {
    // academic_grades cascades on this FK.
    $db->delete('academic_history', 'id = ?', [(int) $e['id']]);
}
echo "\n  replaced " . count($existing) . " previously seeded term(s)\n";

$insertedTerms = 0;
$insertedSubjects = 0;

foreach ($TERMS as $i => $term) {
    $termStart = $term['sy'] . ($term['sem'] === '1st' ? '-08' : '-01');

    $rid = (int) $db->insert('academic_history', [
        'student_id'  => $sid,
        'school_name' => TERM_SCHOOL_NAME,
        'school_year' => $term['sy'],
        'semester'    => $term['sem'],
        'grade_level' => null,
        'remarks'     => MARKER . ' ' . $term['sy'] . ' ' . $term['sem'],
        'created_at'  => date('Y-m-d H:i:s', strtotime($termStart . ' 12:00')),
    ]);
    $insertedTerms++;

    foreach ($term['subjects'] as $j => $s) {
        [$subject, $code, $u, $rating, $grade, $status, $instructor] = $s;
        $db->insert('academic_grades', [
            'academic_history_id'  => $rid,
            'subject'              => $subject,
            'subject_code'         => $code,
            'units'                => $u,
            'final_rating'         => $rating,
            'grade'                => $grade === '' ? null : $grade,
            'grade_status'         => $status === '' ? null : $status,
            'instructor'           => $instructor,
            // Provenance, so the columns this work added are not empty on
            // screen. source_ref is part of a UNIQUE key, so it has to be
            // distinct within a term or the insert is rejected outright.
            'source_system'        => 'faculty',
            'source_ref'           => $term['sy'] . '-' . $term['sem'] . '-' . $code,
            'received_at'          => date('Y-m-d H:i:s', strtotime($termStart . ' 09:00')),
            'term_status'          => 'closed',
            // Two of ten confirmed. The rest stay at 0, which is the honest
            // state: an instructor name not yet checked against a faculty
            // record is exactly what that flag is for.
            'instructor_confirmed' => ($i + $j) % 5 === 0 ? 1 : 0,
        ]);
        $insertedSubjects++;
    }

    // Faculty's reported figure. It must survive into the document as a value
    // to compare against ours, not as the answer.
    $db->update('academic_history',
        ['gwa_reported' => $term['gwa_reported']],
        'id = ?', [$rid]);
}

echo "  inserted $insertedTerms term(s), $insertedSubjects subject row(s)\n";
// -- Verify, using the same functions the document uses -------
// Recomputing here rather than asserting the plan is what makes this worth
// running: the printed figures come from termGwa() / careerGwa(), so the
// check must come from those too.
$career = [];
$allSubjects = [];
$flagged = 0;
foreach ($db->fetchAll(
    "SELECT id, gwa_reported FROM academic_history
      WHERE student_id = ? AND remarks LIKE ?
      ORDER BY COALESCE(school_year,''), COALESCE(semester,''), id",
    [$sid, MARKER . '%']
) as $h) {
    $subs = $db->fetchAll(
        "SELECT units, final_rating FROM academic_grades WHERE academic_history_id = ?",
        [(int) $h['id']]
    );
    $career[]    = ['subjects' => $subs];
    $allSubjects = array_merge($allSubjects, $subs);

    if ($h['gwa_reported'] === null || $h['gwa_reported'] === '') { continue; }
    $g = termGwa($subs);
    if ($g !== null && abs($g - (float) $h['gwa_reported']) > 0.005) { $flagged++; }
}

echo "\n== what the document will print ==\n";
foreach ($career as $k => $t) {
    $g = termGwa($t['subjects']);
    echo '  term ' . ($k + 1) . ' GWA   : '
       . ($g === null ? 'N/A' : number_format($g, 2)) . "\n";
}
$cg = careerGwa($career);
$cu = array_sum(array_map(
    static fn($s) => termRatingValid($s['final_rating']) ? (float) $s['units'] : 0.0,
    $allSubjects
));
echo '  cumulative GWA : ' . ($cg === null ? 'N/A' : number_format($cg, 2)) . "\n";
echo '  total units    : ' . number_format($cu, 2) . "\n";
echo '  gwa flags      : ' . $flagged . '  (want 1 - the deliberate disagreement)'
   . ($flagged === 1 ? '' : '   *** WRONG ***') . "\n";

echo "\n  Open registrar/academic-history.php, expand the student, press\n";
echo "  'Print official grades'. The crest prints at the top of the sheet.\n";
