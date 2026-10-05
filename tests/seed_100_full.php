<?php
// ============================================================
//  Create 100 students across every dimension the registrar
//  actually filters by, plus the records that hang off them.
//
//    php tests/seed_100_full.php            (dry run - prints the plan)
//    php tests/seed_100_full.php --execute  (writes)
//    php tests/seed_100_full.php --undo     (removes, then exits)
//
//  WHY ONE SCRIPT AND NOT FOUR
//  --------------------------
//  The dimensions are not independent. A student's year_level picks
//  their section code, the section picks the academic_history row they
//  are graded in, and that row is what their document requests are
//  measured against. Seeding them separately is how a fixture ends up
//  with Year 3 students sitting in a 11001 section and grades filed
//  against a term nobody is enrolled in - a dataset that renders and is
//  wrong, which is worse than no dataset because it looks like an
//  answer.
//
//  EVERY DIMENSION IS VARIED ON PURPOSE
//  ------------------------------------
//  Five programs, all four year levels, three school years, three
//  semesters, and every value in students.status. A seed where every
//  row shares a program and a term cannot tell a grouping bug from a
//  correct grouping, and the folder browser, the masterlist blocks and
//  the Insight figures all group.
//
//  SCOPE
//  -----
//  Inserts only. Nothing is updated and nothing existing is deleted -
//  the five real accounts and the one real student are left alone.
//
//  Every row is stamped TESTDATA- in the address and numbered in the
//  S26xxxxx range, so --undo finds all of them by one LIKE and cannot
//  touch a real record.
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$MODE = in_array('--undo', $argv, true) ? 'undo'
      : (in_array('--execute', $argv, true) ? 'execute' : 'dry');

$db = Database::getInstance();

$MARK   = 'TESTDATA-';                 // the removal marker
$PREFIX = 'S26';                       // student numbers: S2600001..
$COUNT  = 100;

// ── The dimensions ─────────────────────────────────────────────
//
// Program names are the FULL catalogue titles, not acronyms, because
// that is what students.course holds - a clerk types the acronym and
// the folder browser then files them in a second, near-empty folder.
$PROGRAMS = [
    'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
    'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
    'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)',
    'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)',
    'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)',
];

// Three school years so the period comparisons on the Insight page
// have something to compare against. 2026-2027 is "now" - a report of
// a month with no enrolments compares to nothing and every average
// reads as zero.
$SCHOOL_YEARS = ['2026-2027', '2025-2026', '2024-2025'];
$SEMESTERS    = ['1st', '2nd', 'Summer'];

// Every value in the enum, so a status filter is exercised rather
// than assumed. 'graduate' and 'alumni' carry a graduation date,
// because a graduate with no graduation_date is a record the office
// cannot print a certificate from.
$STATUSES = ['enrolled', 'enrolled', 'enrolled', 'active', 'active',
             'graduate', 'alumni', 'dropped'];

$SURNAMES = ['Dela Cruz','Santos','Reyes','Bautista','Ocampo','Garcia','Mendoza',
    'Torres','Ramos','Cruz','Villanueva','Aquino','Castillo','Flores','Rivera',
    'Gonzales','Domingo','De Guzman','Navarro','Salazar','Padilla','Cortez',
    'Lopez','Perez','Roxas','Serrano','Aguilar','Bermudez','Cabrera'];
$FIRST_M  = ['Juan','Pedro','Jose','Mark','Paul','Ryan','Carlo','Diego','Miguel','Antonio'];
$FIRST_F  = ['Maria','Ana','Cristina','Grace','Angel','Nicole','Jenny','Kim','Nicole','Frances'];
$MIDDLE   = ['S','R','C','M','A','D','P','L','',''];
$GENDERS  = ['Male','Female'];

// ── Subjects, by year level ────────────────────────────────────
//
// Real course codes for the first two years. A third- and fourth-year
// subject list would be invented, and an invented code in a printed
// transcript is a fabrication, so those rows reuse the year-1/2 pool
// under the level's own prefix - flagged as such below.
$SUBJECTS = [
    1 => [['GE-001','Purposive Communication',3.0],['MATH-101','Mathematics in the Contemporary World',3.0],
          ['IT-101','Introduction to Computing',3.0],['PE-101','Physical Education',2.0],['HIST-101','Contemporary Philippine History',3.0]],
    2 => [['IT-201','Data Structures',3.0],['IT-202','Systems Integration',3.0],['MATH-102','Calculus 1',3.0],
          ['ENT-101','Entrepreneurship',3.0],['HUM-101','Readings in Philippine History',3.0]],
    3 => [['IT-301','Information Management',3.0],['ACC-301','Accounting 1',3.0],['MGT-301','Principles of Management',3.0],
          ['IT-302','Web Systems and Technologies',3.0],['RES-101','Research Methods',3.0]],
    4 => [['IT-401','Capstone Project 1',4.0],['IT-402','Systems Administration',3.0],
          ['MGT-402','Strategic Management',3.0],['IT-403','Thesis Writing',3.0],['PE-401','Physical Education 2',2.0]],
];

// ── The eight document workflow stages ─────────────────────────
//
// document_requests.document_status is an 8-value enum and the AI
// Insight chart collapses it into five series. A fixture that only
// ever files 'Filed' leaves four series permanently empty, which is
// how a wrong status mapping survived review: nothing ever looked
// wrong because nothing was ever drawn. Every stage gets a share, and
// each stage carries the timestamp column that stage actually implies,
// so the desk's own age calculations have something to read.
$DOC_STAGES = [
    ['Filed',              0.30, []],
    ['Pending_Clearance',  0.15, ['blocked_reason' => 'Awaiting adviser endorsement']],
    ['Awaiting_Payment',   0.10, ['payment_method' => 'Online']],
    ['Processing',         0.15, ['processed_by' => 2, 'processed_date' => true]],
    ['Ready',              0.12, ['ready_at' => true]],
    ['Shipped',            0.08, ['ready_at' => true, 'shipped_at' => true]],
    ['Claimed',            0.07, ['ready_at' => true, 'shipped_at' => true, 'claimed_at' => true]],
    ['Rejected',           0.03, ['rejection_reason' => 'Incomplete requirement: notarized affidavit of loss']],
];

// ── The plan ───────────────────────────────────────────────────
//
// Built first, printed in full, then written. Deterministic: no
// random_int, so a dry run and the run that follows it produce the
// same hundred students and a diff between them means something broke.
$plan = [];
for ($i = 0; $i < $COUNT; $i++) {
    $programIdx = $i % count($PROGRAMS);
    $yearLevel  = ($i % 4) + 1;
    $semIdx     = $i % count($SEMESTERS);
    $syIdx      = $i % count($SCHOOL_YEARS);
    $semester   = $SEMESTERS[$semIdx];
    $gender     = ($i % 2 === 0) ? 'Male' : 'Female';
    $first      = ($gender === 'Male')
        ? $FIRST_M[intdiv($i, 2) % count($FIRST_M)]
        : $FIRST_F[intdiv($i, 2) % count($FIRST_F)];
    $last       = $SURNAMES[$i % count($SURNAMES)];
    $status     = $STATUSES[$i % count($STATUSES)];

    // Section code is [year][semester][###] - the third and fourth
    // years get ### 001..002, the first two split across three so
    // "more than one section per year" is actually exercised rather
    // than assumed.
    $semDigit   = ['1st' => '1', '2nd' => '2', 'Summer' => '3'][$semester];
    $groupCount = ($yearLevel <= 2) ? 3 : 2;
    $group      = intdiv($i % ($groupCount * 2), 2) % $groupCount;
    $section    = $yearLevel . $semDigit . str_pad((string) ($group + 1), 3, '0', STR_PAD_LEFT);

    // Ratings walk the scale in a fixed order, so the hundred students
    // cover every value between 1.00 and 5.00 without depending on a
    // random seed: a dry run and the run after it produce the same data,
    // which is what makes a difference between them mean something broke.
    $ratings = [];
    for ($k = 0; $k < 4; $k++) {
        $ratings[] = round(1.00 + (($i * 7 + $k * 3) % 41) / 10, 2);
    }

    // Grades are spread over the whole 1.00-5.00 scale rather than
    // clustered, so a GWA report has variation to summarise. 1.00 is
    // best and 5.00 worst on this school's scale.
    $plan[] = [
        'student_number' => $PREFIX . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
        'first_name'     => $first,
        'middle_name'    => $MIDDLE[$i % count($MIDDLE)],
        'last_name'      => $last,
        'gender'         => $gender,
        'course'         => $PROGRAMS[$programIdx],
        'year_level'     => $yearLevel,
        'school_year'    => $SCHOOL_YEARS[$syIdx],
        'semester'       => $semester,
        'section'        => $section,
        'status'         => $status,
        // Ratings walk the scale in a fixed order so the set contains
        // every value without depending on a random seed.
        'ratings'        => $ratings,
        'subjects'       => $SUBJECTS[$yearLevel],
        'document_stage' => null, // assigned below
    ];
}

// Hand out the document stages by weight, walking the plan in order
// so every student gets at most one request and the eight stages land
// in their stated shares.
$stageBag = [];
foreach ($DOC_STAGES as $stage) {
    $n = (int) round($stage[1] * $COUNT);
    for ($k = 0; $k < $n && count($stageBag) < $COUNT; $k++) {
        $stageBag[] = $stage[0];
    }
}
while (count($stageBag) < $COUNT) {
    $stageBag[] = 'Filed';
}
foreach ($plan as $i => &$row) {
    $row['document_stage'] = $stageBag[$i];
}
unset($row);

// ── Remove ─────────────────────────────────────────────────────
//
// One marker finds all of it. The children go first because they
// reference the student: academic_grades -> academic_history ->
// document_requests -> users -> students. Deleting a student while its
// grades are still there leaves orphans that the Academic History
// board would happily render as a cohort nobody can place.
if ($MODE === 'undo') {
    $ids = $db->fetchAll(
        "SELECT id FROM students WHERE student_number LIKE ?", [$PREFIX . '%']
    );
    if (!$ids) {
        echo "Nothing to remove: no students numbered {$PREFIX}#####.\n";
        exit(0);
    }
    $idList = implode(',', array_map('intval', array_column($ids, 'id')));
    $in     = '(' . $idList . ')';

    $histIds = implode(',', array_map(
        'intval',
        array_column($db->fetchAll("SELECT id FROM academic_history WHERE student_id IN {$in}"), 'id')
    )) ?: '0';

    // Counted BEFORE the delete. A summary that prints zeros after
    // deleting four hundred rows is worse than no summary: it reads as
    // "there was nothing there", which is the one thing --undo exists
    // to let you check.
    $nGrades = (int) $db->fetchColumn("SELECT COUNT(*) FROM academic_grades WHERE academic_history_id IN ({$histIds})");
    $nHist   = (int) $db->fetchColumn("SELECT COUNT(*) FROM academic_history WHERE student_id IN {$in}");
    $nDocs   = (int) $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE student_id IN {$in}");
    $nUsers  = (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE student_id IN {$in}");

    $db->query("DELETE FROM academic_grades WHERE academic_history_id IN ({$histIds})");
    $db->query("DELETE FROM academic_history WHERE student_id IN {$in}");
    $db->query("DELETE FROM document_requests WHERE student_id IN {$in}");
    $db->query("DELETE FROM users WHERE student_id IN {$in}");
    $db->query("DELETE FROM students WHERE id IN {$in}");

    printf(
        "Removed %d students and everything hanging off them.\n"
        . "  students %d · portal accounts %d · term records %d · grades %d · requests %d\n",
        count($ids), count($ids), $nUsers, $nHist, $nGrades, $nDocs
    );
    echo "Leftover check (all should be 0):\n";
    foreach (['students', 'academic_history', 'document_requests'] as $t) {
        $where = $t === 'students' ? "student_number LIKE '{$PREFIX}%'" : "student_id IN {$in}";
        if ($t === 'academic_history') { $where = "student_id IN {$in}"; }
        echo '  ' . $t . ': ' . (int) $db->fetchColumn("SELECT COUNT(*) FROM {$t} WHERE {$where}") . "\n";
    }
    exit(0);
}

// ── Refuse to double-seed ──────────────────────────────────────
//
// Re-running would create a second student with the same number, and
// resolveLoginUser() matches on student_number: two rows, one of which
// nobody can ever reach. Better to stop than to leave that behind.
$existing = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM students WHERE student_number LIKE ?", [$PREFIX . '%']
);
if ($existing > 0) {
    fwrite(STDERR, "Refusing to seed: {$existing} students numbered {$PREFIX}##### already exist.\n"
        . "Run with --undo first, or they will be duplicated.\n");
    exit(1);
}

$catalog = $db->fetchAll('SELECT id, base_fee FROM document_catalog WHERE is_active = 1 ORDER BY id');
if (!$catalog) {
    fwrite(STDERR, "document_catalog is empty; requests would have nothing to point at.\n");
    exit(1);
}

echo "Seed plan: {$COUNT} students\n";
printf("  programs %d · year levels 4 · semesters 3 · school years %d · statuses %d\n",
    count($PROGRAMS), count($SCHOOL_YEARS), count(array_unique($STATUSES)));
printf("  per student: 1 account · 1 term record · %d subject grades · 1 document request\n\n",
    count($SUBJECTS[1]));

if ($MODE !== 'execute') {
    echo "DRY RUN - nothing written. Re-run with --execute.\n\n";
    printf("%-9s %-11s %-4s %-6s %-10s %-9s %-6s %-10s %s\n",
        'NUMBER', 'LAST', 'YEAR', 'SEM', 'SCHOOL YEAR', 'COURSE', 'SECT', 'STATUS', 'DOC STAGE');
    echo str_repeat('-', 104) . "\n";
    foreach ($plan as $r) {
        printf("%-9s %-11s %-4d %-6s %-10s %-9s %-6s %-10s %s\n",
            $r['student_number'], $r['last_name'], $r['year_level'], $r['semester'],
            $r['school_year'], substr($r['course'], 9, 8), $r['section'],
            $r['status'], $r['document_stage']);
    }
    echo "\n";
    exit(0);
}

// ── Write ──────────────────────────────────────────────────────
//
// One transaction. A half-written fixture is worse than none: 100
// students with no sections, or sections with no grades, is a dataset
// that renders and misleads.
$db->beginTransaction();
try {
    $nUsers = $nHist = $nGrades = $nDocs = 0;
    $passwordHash = password_hash('password', PASSWORD_DEFAULT);

    foreach ($plan as $row) {
        $email = strtolower(str_replace(' ', '.',
            $row['first_name'] . '.' . $row['last_name'])) . '.' . strtolower($row['student_number'])
            . '@testdata.example';

        $studentId = (int) $db->insert('students', [
            'student_number' => $row['student_number'],
            'first_name'     => $row['first_name'],
            'middle_name'    => $row['middle_name'],
            'last_name'      => $row['last_name'],
            'gender'         => $row['gender'],
            'civil_status'   => 'Single',
            'birth_date'     => sprintf('200%d-%02d-%02d',
                                 1 + ($row['year_level'] - 1) % 5,
                                 1 + (hexdec($row['student_number'][3]) % 12),
                                 1 + (hexdec($row['student_number'][4]) % 28)),
            'nationality'    => 'Filipino',
            'address'        => $MARK . ' ' . $row['last_name'] . ', Quezon City',
            'contact_number' => '09' . str_pad(
                (string) (100000000 + (int) substr($row['student_number'], 1) * 7 % 900000000),
                9, '0', STR_PAD_LEFT),
            'email'          => $email,
            'course'         => $row['course'],
            'year_level'     => $row['year_level'],
            'school_year'    => $row['school_year'],
            'semester'       => $row['semester'],
            'section'        => $row['section'],
            'status'         => $row['status'],
            'graduation_date' => in_array($row['status'], ['graduate', 'alumni'], true)
                ? $row['school_year'] . '-04-30' : null,
            'school_year_graduated' => in_array($row['status'], ['graduate', 'alumni'], true)
                ? $row['school_year'] : null,
            // Flagged so the welcome-mail and any bounce sweep skip
            // these: they are not addresses anyone can receive at.
            'email_is_placeholder' => 1,
        ]);

        // The portal account. username is the student number, which is
        // what resolveLoginUser() matches a student credential on.
        $db->insert('users', [
            'email'         => $email,
            'password_hash' => $passwordHash,
            'full_name'     => trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']),
            'role'          => 'student',
            'student_id'    => $studentId,
            'username'      => $row['student_number'],
            'is_active'     => 1,
        ]);
        $nUsers++;

        // ── The term record and its grades ──
        $historyId = (int) $db->insert('academic_history', [
            'student_id'  => $studentId,
            'school_name' => 'Bestlink College of the Philippines',
            'school_year' => $row['school_year'],
            'semester'    => $row['semester'],
            'grade_level' => 'Year ' . $row['year_level'],
            'accepted_at' => date('Y-m-d H:i:s', strtotime('-' . (3 + (int) substr($row['student_number'], 2)) . ' days')),
            'accepted_by' => 2,
        ]);
        $nHist++;

        $weighted = 0.0;
        $units    = 0.0;
        foreach ($row['subjects'] as $k => $subject) {
            [$code, $name, $u] = $subject;
            $rating = $row['ratings'][$k % count($row['ratings'])];
            $passed = $rating <= 3.0;   // 3.00 is the pass mark on this scale

            $db->insert('academic_grades', [
                'academic_history_id'  => $historyId,
                'subject_code'         => $code,
                'subject'              => $name,
                'subject_type'         => ($code[0] === 'G' || $code[0] === 'P')
                                          ? 'General Education' : 'Professional',
                'units'                => $u,
                'grade'                => $passed ? 'P' : 'F',
                'final_rating'         => $rating,
                'grade_status'         => $passed ? 'passed' : 'failed',
                'source_system'        => 'faculty',
                'semester_taken'       => $row['semester'],
                'received_at'          => date('Y-m-d H:i:s', strtotime('-10 days')),
                'instructor'           => 'FACULTY-' . str_pad((string) (1 + ($k % 8)), 2, '0', STR_PAD_LEFT),
                'term_status'          => 'final',
                'instructor_confirmed' => 1,
            ]);
            $weighted += $rating * $u;
            $units    += $u;
            $nGrades++;
        }

        // The summary figures the page reads, computed from the same
        // weighted average the page will recompute. A mismatch after
        // seeding is then a real bug, not the fixture disagreeing
        // with itself.
        $gwa = $units > 0 ? round($weighted / $units, 2) : null;
        $db->query(
            'UPDATE academic_history SET gwa = ?, credits = ?, subjects_completed = ? WHERE id = ?',
            [$gwa, $units, count($row['subjects']), $historyId]
        );

        // ── One document request, at this student's own stage ──
        $stage     = $row['document_stage'];
        $stageMeta = null;
        foreach ($DOC_STAGES as $s) {
            if ($s[0] === $stage) { $stageMeta = $s[2]; break; }
        }
        $stageMeta = $stageMeta ?: [];
        $item      = $catalog[$i % count($catalog)];
        $ageDays   = 1 + ($i % 45);
        $requestDate = date('Y-m-d H:i:s', strtotime("-{$ageDays} days"));

        // Each stage carries the timestamp that stage actually implies.
        // A Shipped request with no shipped_at reads as "shipped, but
        // when?" to every age calculation on the desk.
        $docRow = [
            'student_id'      => $studentId,
            'document_type'   => ['form137','good_moral','transcript','certificate','clearance'][$i % 5],
            'catalog_id'      => (int) $item['id'],
            'quantity'        => 1,
            'request_type'    => ($i % 4 === 0) ? 'Express' : 'Regular',
            'fulfillment_type' => ['Pickup','Delivery','Digital'][$i % 3],
            'payment_method'  => ['Online','Counter','Cash_on_Delivery'][$i % 3],
            'purpose'         => 'Test data - ' . $stage,
            'document_status' => $stage,
            'fee_amount'      => (float) $item['base_fee'],
            'request_date'    => $requestDate,
            'recipient'       => trim($row['first_name'] . ' ' . $row['last_name']),
        ];
        foreach (['ready_at', 'shipped_at', 'claimed_at'] as $stamp) {
            if (!empty($stageMeta[$stamp])) {
                $docRow[$stamp] = date('Y-m-d H:i:s', strtotime("-{$ageDays} days +1 hour"));
            }
        }
        foreach (['processed_date', 'processed_by', 'payment_method',
                  'blocked_reason', 'rejection_reason'] as $extra) {
            if (array_key_exists($extra, $stageMeta)) { $docRow[$extra] = $stageMeta[$extra]; }
        }
        $db->insert('document_requests', $docRow);
        $nDocs++;
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Seeding failed and was rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Seeded %d students.\n", $COUNT);
printf("  students            %d\n", $COUNT);
printf("  portal accounts     %d\n", $nUsers);
printf("  term records        %d\n", $nHist);
printf("  subject grades      %d\n", $nGrades);
printf("  document requests   %d\n", $nDocs);
echo "\nEvery seeded student can sign in with their student number and the password 'password'.\n";
echo "Remove them all with:\n  php tests/seed_100_full.php --undo\n";