<?php
// ============================================================
//  Seed sections, so the ACCEPTANCE BOARD has something to accept.
//
//    php tests/seed_section_acceptance.php            (dry run)
//    php tests/seed_section_acceptance.php --execute
//
//  WHY THIS EXISTS
//
//  The board is the page, and on a fresh install it renders its empty state.
//  That is the CORRECT reading — sections are assigned from the Masterlist,
//  and no one had — but it means every branch on the board is unreachable:
//  ready, waitlist, accepted, empty. A board nobody has ever accepted
//  anything on is a board nobody has read.
//
//  WHAT IT IS DELIBERATELY NOT
//
//  Not four sections of perfect grades. It plants one section in EVERY state
//  the board can be in, because a board showing only green proves nothing:
//
//    * BSIT 1 / 11001  READY      fully graded - Accept is enabled
//    * BSIT 1 / 11002  WAITLIST   one unrated subject blocks the whole section
//    * BSIT 2 / 12001  WAITLIST   one student with NO record at all
//    * BSIT 2 / 12002  EMPTY      graded, nothing entered yet
//    * BSCS 1 / 11001  READY      a second program, same code as BSIT 11001
//
//  The last line is the point of the lot. A section code is scoped by
//  PROGRAM + year + term (DEPARTMENTS.md), so BSCS 11001 and BSIT 11001 are
//  two different sections that happen to share five characters. A board
//  grouping on the bare code would merge them into one row of four students
//  and accept two cohorts with one click.
//
//  SCOPE
//
//  Inserts students carrying this script's markers, plus their academic_history
//  and academic_grades rows. Updates nothing outside its own rows. Anything
//  carrying a marker is replaced on re-run, so it is safely repeatable.
//  Nothing is archived or deleted - an earlier draft of this script deleted
//  its own students, and students have guardians, documents and audit rows
//  pointing at them, so a delete was the wrong verb for a re-run.
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai students academic_history academic_grades > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/term_grades.php';
require_once __DIR__ . '/../shared/section_code.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

// TWO markers, because one account in this system has no email at all
// (tests/document_pickup_email.php) and an email-only filter leaves it
// behind forever. Same reasoning as tests/clear_e2e_residue.php.
define('MARKER_EMAIL', '@seed.sections.test');
define('MARKER_ADDR',  'SEEDDATA-SECTIONS');

$SY   = '2026-2027';
$SEM  = '1st';

// The BSIT first-year sequence. Ratings are on the 1.00-5.00 scale where LOWER
// IS BETTER (GRADE_SCALE_MIN/MAX in shared/term_grades.php).
// Tuple: subject, code, units, rating|null, status, instructor.
$SUBJECTS_1 = [
    ['Programming 1',          'IT101', 3.0, 1.50, 'passed', 'Engr. Dela Cruz'],
    ['Introduction to Comp.',  'IT102', 3.0, 1.75, 'passed', 'Engr. Dela Cruz'],
    ['Mathematics 1',          'MA101', 3.0, 2.00, 'passed', 'Engr. Santos'],
    ['Human Computer Inter.',  'HC101', 2.0, 1.50, 'passed', 'Prof. Lim'],
];
$SUBJECTS_2 = [
    ['Programming 2',          'IT201', 3.0, null,   'incomplete', 'Engr. Dela Cruz'],
    ['Data Structures',        'IT202', 3.0, null,   'incomplete', 'Engr. Mendoza'],
    ['Discrete Structures',    'MA201', 3.0, null,   'incomplete', 'Engr. Santos'],
];

// The plan. Every section is named by what it should make the board DO, so a
// reader can check the board against this list without reading the code.
//
// year is what the section code's first digit carries, and it is what the
// board nests on, so it must agree with the code or the tree lies.
$PLAN = [
    [
        'program' => 'BSIT', 'year' => 1, 'section' => '11001', 'state' => 'ready',
        'note'    => 'fully graded - Accept is enabled',
        'people'  => [
            ['Roldan Tiu',      '2026-1001', 'Male',   1.50],
            ['Angela Mendoza',  '2026-1002', 'Female', 1.75],
            ['Joshua Alvarez',  '2026-1003', 'Male',   2.25],
        ],
        'subjects' => $SUBJECTS_1,
    ],
    [
        'program' => 'BSIT', 'year' => 1, 'section' => '11002', 'state' => 'waitlist',
        'note'    => 'one unrated subject blocks the whole section',
        'people'  => [
            ['Camille Santos',   '2026-1004', 'Female', null],
            ['Diego Ramos',      '2026-1005', 'Male',   1.50],
            ['Nicole Buenaventura','2026-1006','Female', 1.75],
        ],
        // All four rated for two students; the third has the last two blank.
        // One blank rating is enough to block the section - that is the rule.
        'subjects' => $SUBJECTS_1,
        'blanks'   => [2 => [2, 3]],
    ],
    [
        'program' => 'BSIT', 'year' => 2, 'section' => '21001', 'state' => 'waitlist',
        'note'    => 'one student has no record at all',
        'people'  => [
            ['Marco Villanueva', '2026-2001', 'Male',   2.00],
            ['Kristine Ago',     '2026-2002', 'Female', 1.50],
            ['Paolo Reyes',      '2026-2003', 'Male',   null],
        ],
        'subjects' => [
            ['Programming 2',         'IT201', 3.0, 1.75, 'passed', 'Engr. Dela Cruz'],
            ['Data Structures',       'IT202', 3.0, 2.00, 'passed', 'Engr. Mendoza'],
            ['Discrete Structures',   'MA201', 3.0, 2.50, 'passed', 'Engr. Santos'],
            ['Technical Writing',     'TW201', 2.0, 1.50, 'passed', 'Prof. Lim'],
        ],
        // Student 3 is enrolled but no term row exists for them at all - the
        // case termAudit CANNOT see, and the one the board must still name.
        'no_record' => [2],
    ],
    [
        'program' => 'BSIT', 'year' => 2, 'section' => '21002', 'state' => 'empty',
        'note'    => 'graded, but nothing entered from Faculty yet',
        'people'  => [
            ['Bea Fernandez',    '2026-2004', 'Female', null],
            ['Carlo Domingo',    '2026-2005', 'Male',   null],
        ],
        'subjects' => $SUBJECTS_2,
        // 'empty' means the subject LIST was never sent, so this section writes
        // a term row and no subject rows at all. termAudit reports that as
        // no_subjects - "nothing entered" - which is a different instruction to
        // a registrar than "a rating is missing". See the write loop below.
    ],
    [
        // SAME section code as BSIT 11001 above. This is the row that catches
        // a board grouping on the bare code instead of program + code.
        'program' => 'BSCS', 'year' => 1, 'section' => '11001', 'state' => 'ready',
        'note'    => 'a second program sharing the code 11001',
        'people'  => [
            ['Sofia Navarro',    '2026-3001', 'Female', 1.25],
            ['Miguel Ortigas',   '2026-3002', 'Male',   1.50],
        ],
        'subjects' => $SUBJECTS_1,
    ],
];

// -- Report ---------------------------------------------------
$students = 0;
$people    = array_sum(array_map(static fn($s) => count($s['people']), $PLAN));
echo "== seed plan ==\n";
echo "  term        : $SY $SEM\n";
echo "  sections    : " . count($PLAN) . "\n";
echo "  students    : $people\n";
foreach ($PLAN as $s) {
    // The code is built by shared/section_code.php rather than typed here, so
    // this script cannot invent a code the Masterlist would not produce.
    $built = sectionCodeFromParts($s['year'], $SEM, (int) substr($s['section'], -3));
    $flag  = $built === $s['section'] ? '' : "  <-- MISMATCH, expected $built";
    echo "    {$s['program']} yr{$s['year']}  {$s['section']}  "
       . str_pad($s['state'], 9) . count($s['people']) . " student(s)"
       . "  {$s['note']}$flag\n";
    $students += count($s['people']);
}

if (!$execute) {
    echo "\n  DRY RUN. Nothing written. Re-run with --execute to apply.\n";
    exit(0);
}

// -- Clear this script's own rows ------------------------------
// academic_history cascades from students, and academic_grades cascades from
// academic_history, so removing the students removes the whole record. Going
// through students rather than deleting the term rows directly is what keeps
// the cascade honest.
$stale = $db->fetchAll(
    "SELECT id FROM students WHERE email LIKE ? OR address LIKE ?",
    [MARKER_EMAIL, MARKER_ADDR . '%']
);
foreach ($stale as $s) {
    $db->delete('students', 'id = ?', [(int) $s['id']]);
}
echo "\n  removed " . count($stale) . " previously seeded student(s)\n";

// -- Apply ----------------------------------------------------
//
// gwa_reported is COMPUTED from the ratings this script writes, not typed into
// the plan. An earlier draft carried a figure per student, and every one of
// them disagreed with what termGwa() computed from its own subjects - so every
// section came back blocked on gwa_mismatch and the board showed a data
// problem where none existed. A seeded figure that cannot drift is worth more
// than a readable one.
$made = 0;
$terms = 0;
$ratings = 0;

foreach ($PLAN as $s) {
    foreach ($s['people'] as $i => [$name, $number, $gender]) {
        [$first, $last] = array_pad(preg_split('/\s+/', $name, 2), 2, '');
        $sid = (int) $db->insert('students', [
            'student_number' => $number,
            'first_name'     => $first,
            'last_name'      => $last,
            'gender'         => $gender,
            'birth_date'     => '2006-0' . (1 + ($made % 9)) . '-1' . ($made % 9),
            'place_of_birth' => 'Quezon City',
            'address'        => MARKER_ADDR . ' Section ' . $s['section'],
            'contact_number' => '09' . str_pad((string) (100000000 + $made), 9, '0', STR_PAD_LEFT),
            // BOTH markers on every row, so either one alone finds this
            // script's students again on the next run.
            'email'          => strtolower($first) . '.' . strtolower($last) . MARKER_EMAIL,
            'course'         => $s['program'],
            'year_level'     => $s['year'],
            'section'        => $s['section'],
            'status'         => 'enrolled',
        ]);
        $made++;

        // Enrolled but nothing sent. A student with no academic_history row is
        // on the section and absent from the term - a real state, and the one
        // the grade checker cannot see.
        if (in_array($i, $s['no_record'] ?? [], true)) {
            continue;
        }

        $rid = (int) $db->insert('academic_history', [
            'student_id'  => $sid,
            'school_name' => TERM_SCHOOL_NAME,
            'school_year' => $SY,
            'semester'    => $SEM,
            'remarks'     => MARKER_ADDR . ' ' . $SY . ' ' . $SEM,
        ]);
        $terms++;

        // The empty section gets a term row but NO subject rows. The subject
        // list was never sent, which is a different fault from a subject sent
        // with a blank rating, and the board reports the two differently.
        if ($s['state'] === 'empty') {
            continue;
        }

        $blank = $s['blanks'][$i] ?? [];
        $written = [];
        foreach ($s['subjects'] as $j => [$subject, $code, $units, $rating, $status, $instructor]) {
            $isBlank = in_array($j, $blank, true) || $rating === null;
            $db->insert('academic_grades', [
                'academic_history_id'  => $rid,
                'subject'              => $subject,
                'subject_code'         => $code,
                'units'                => $units,
                'final_rating'         => $isBlank ? null : $rating,
                'grade_status'         => $isBlank ? 'incomplete' : $status,
                'instructor'           => $instructor,
                'source_system'        => 'faculty',
                // Distinct within a term: uq_ag_source includes source_ref, and
                // a repeat is rejected outright.
                'source_ref'           => $SY . '-' . $SEM . '-' . $code . '-' . $sid,
                'received_at'          => date('Y-m-d H:i:s', strtotime($SY . '-09-01 09:00')),
                'term_status'          => 'sent',
                'instructor_confirmed' => 0,
            ]);
            $written[] = ['units' => (float) $units, 'final_rating' => $isBlank ? null : $rating];
            $ratings += $isBlank ? 0 : 1;
        }

        // The same helper the page and the gate read, so the stored figure and
        // the computed one are the same computation by construction.
        $computed = termGwa($written);
        if ($computed !== null) {
            $db->update('academic_history', ['gwa_reported' => $computed], 'id = ?', [$rid]);
        }
    }
}

echo "  inserted $made student(s), $terms term(s), $ratings rating(s)\n";

// -- Verify, through the same gate the board uses ---------------
// Recomputing with sectionAcceptance() is what makes this worth running: if
// the seed does not produce the states it claims, the board would be showing
// a bug rather than the data.
echo "\n== what the board should show ==\n";
$bad = 0;
foreach ($PLAN as $s) {
    $roster = [];
    foreach ($db->fetchAll(
        "SELECT ah.id AS rid, ah.gwa FROM students st
           LEFT JOIN academic_history ah
             ON ah.student_id = st.id AND ah.school_year = ? AND ah.semester = ?
          WHERE st.course = ? AND st.section = ?",
        [$SY, $SEM, $s['program'], $s['section']]
    ) as $row) {
        $rid  = (int) ($row['rid'] ?? 0);
        $subs = [];
        foreach ($rid ? $db->fetchAll(
            "SELECT units, final_rating, grade_status FROM academic_grades WHERE academic_history_id = ?",
            [$rid]
        ) : [] as $g) {
            $subs[] = [
                'units' => (float) $g['units'], 'final_rating' => $g['final_rating'],
                'grade_status' => (string) $g['grade_status'],
            ];
        }
        $roster[] = ['name' => 'seed', 'number' => '', 'gwa' => $row['gwa'], 'subjects' => $subs];
    }

    $gate   = sectionAcceptance($SY, $SEM, $roster);
    $expect = $s['state'] === 'ready';
    $ok     = $expect ? $gate['can_accept'] === true : $gate['can_accept'] === false;
    $bad   += $ok ? 0 : 1;
    printf("  %s %s %s: %-10s %s\n",
        $ok ? 'ok  ' : 'FAIL',
        $s['program'], $s['section'],
        $gate['can_accept'] ? 'can accept' : 'waitlist',
        implode(', ', array_column($gate['blocking'], 'code')) ?: 'nothing blocking'
    );
}

echo "\n  " . ($bad === 0
    ? "every section landed in the state it was planted for\n"
    : "$bad section(s) did not land as planned\n");
exit($bad === 0 ? 0 : 1);