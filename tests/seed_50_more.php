<?php
// ============================================================
//  A second batch: 50 more students, and applicants waiting
//  behind the Receive Student button.
//
//    php tests/seed_50_more.php            (dry run)
//    php tests/seed_50_more.php --execute
//    php tests/seed_50_more.php --undo
//
//  WHY A SECOND BATCH RATHER THAN ONE BIGGER ONE
//  --------------------------------------------
//  tests/seed_100_full.php is already generated into
//  migrations/seed_100_full.sql and imported on live hosts. Changing its
//  size would invalidate every copy of that file out there, so this is a
//  separate marker (S27) with its own unseed. Together the two give the
//  150-student roster the masterlist is exercised against.
//
//  STATUS AND YEAR: VARIED, BUT NOT RANDOM
//  ----------------------------------------
//  The distribution is a walk with a stride coprime to the list lengths,
//  not RAND(). A random seed is unrepeatable, which makes a bug reported
//  against "student 137" impossible to re-create. The batch is
//  deliberately shaped DIFFERENTLY from the first 100 - a different
//  status weighting and a different year skew - so 150 rows cannot be
//  mistaken for one list written twice, and the year-level split on the
//  students page has something to find.
//
//  THE APPLICANTS
//  --------------
//  The Receive Student button on registrar/students.php is wired to the
//  enrollments table, which the real Enrollment System writes. With the
//  table empty the modal opens on "No applicants from the Enrollment
//  System" and the button looks broken. These rows stand in for that
//  feed.
//
//  They are APPLICANTS, not students: no users row, no grades, no
//  documents. Accepting one through the UI is what creates the student,
//  which is the flow these rows exist to be accepted BY. Several are
//  seeded as 'received' and 'duplicate' so the modal shows its other
//  two states rather than only the empty-looking one.
//
//  Markers, both required to agree on removal:
//    students    student_number LIKE 'S27%'
//    enrollments email LIKE '%@seed.receive.test'
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$MODE = in_array('--undo', $argv, true) ? 'undo'
      : (in_array('--execute', $argv, true) ? 'execute' : 'dry');

$db = Database::getInstance();

$MARK   = 'SEEDDATA-';
$PREFIX = 'S27';
$COUNT  = 50;
$APPLICANT_COUNT = 28;

$PROGRAMS = [
    'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
    'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
    'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)',
    'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)',
    'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)',
    'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)',
    'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)',
    'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)',
];

// Weighted differently from batch one: fewer enrolled, more of the
// terminal states, so the status filter is worth using.
$STATUSES = ['enrolled', 'enrolled', 'active', 'active', 'active',
             'graduate', 'graduate', 'alumni', 'dropped', 'dropped'];

// Skewed downward rather than even. Batch one was an even 4-way split,
// so the masterlist's year-level bars are genuinely different here.
$YEAR_WEIGHTS = [1 => 0.40, 2 => 0.30, 3 => 0.20, 4 => 0.10];

$SEMESTERS    = ['1st', '2nd', 'Summer'];
$SCHOOL_YEARS = ['2026-2027', '2025-2026', '2024-2025'];

$SURNAMES = ['Abad','Basco','Cordero','Diaz','Evangelista','Fajardo','Gatmaitan',
    'Hernandez','Ignacio','Javier','Lazaro','Magsaysay','Nolasco','Pangilinan',
    'Quiambao','Roxas II','Sison','Tolentino','Uy','Valencia','Yulo','Zamora'];
$FIRST_M  = ['Noel','Erick','Rogelio','Arnel','Baldwin','Crisanto','Dante','Erwin'];
$FIRST_F  = ['Rhea','Marilen','Jhoanna','Kaye','Liezl','Maegan','Nina','Irish'];
$MIDDLE   = ['S','R','C','M','A','P','',''];
$GENDERS  = ['Male','Female'];

$SUBJECTS = [
    1 => [['GE-001','Purposive Communication',3.0],['MATH-101','Mathematics in the Contemporary World',3.0],
          ['IT-101','Introduction to Computing',3.0],['PE-101','Physical Education',2.0]],
    2 => [['IT-201','Data Structures',3.0],['MATH-102','Calculus 1',3.0],['ENT-101','Entrepreneurship',3.0]],
    3 => [['IT-301','Information Management',3.0],['ACC-301','Accounting 1',3.0],['RES-101','Research Methods',3.0]],
    4 => [['IT-401','Capstone Project 1',4.0],['MGT-402','Strategic Management',3.0]],
];

// Applicant mix. Most are pending, because that is the state a
// registrar actually works through; a few are already received or
// flagged duplicate so those rows of the modal have something in them.
$APPLICANT_STATES = ['pending', 'pending', 'pending', 'pending', 'pending',
                     'pending', 'pending', 'pending', 'pending', 'pending',
                     'received', 'received', 'duplicate', 'duplicate'];

// ── The plan ───────────────────────────────────────────────────
//
// Deterministic throughout. No RAND(), so the dry run and the run after
// it produce the same fifty students and any difference between them
// means something broke rather than that luck changed.
$plan = [];
for ($i = 0; $i < $COUNT; $i++) {
    $gender   = ($i % 2 === 0) ? 'Male' : 'Female';
    $first    = ($gender === 'Male')
        ? $FIRST_M[intdiv($i, 2) % count($FIRST_M)]
        : $FIRST_F[intdiv($i, 2) % count($FIRST_F)];
    $last     = $SURNAMES[($i * 3) % count($SURNAMES)];
    $status   = $STATUSES[($i * 7) % count($STATUSES)];
    $semester = $SEMESTERS[($i * 5) % count($SEMESTERS)];

    // Year by weight, walked with a stride coprime to the denominator,
    // so all four levels appear but the skew holds.
    $slot     = ($i * 7) % 100;
    $cum      = 0.0;
    $yearLevel = 4;
    foreach ($YEAR_WEIGHTS as $level => $weight) {
        $cum += $weight;
        if ($slot < $cum * 100) { $yearLevel = $level; break; }
    }

    $semDigit = ['1st' => '1', '2nd' => '2', 'Summer' => '3'][$semester];
    $section  = $yearLevel . $semDigit . str_pad((string) (1 + ($i % 3)), 3, '0', STR_PAD_LEFT);

    $ratings = [];
    for ($k = 0; $k < 3; $k++) {
        $ratings[] = round(1.00 + (($i * 11 + $k * 5) % 41) / 10, 2);
    }

    $plan[] = [
        'student_number' => $PREFIX . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
        'first_name' => $first,
        'middle_name'=> $MIDDLE[$i % count($MIDDLE)],
        'last_name'  => $last,
        'gender'     => $gender,
        'course'     => $PROGRAMS[$i % count($PROGRAMS)],
        'year_level' => $yearLevel,
        'school_year'=> $SCHOOL_YEARS[($i * 2) % count($SCHOOL_YEARS)],
        'semester'   => $semester,
        'section'    => $section,
        'status'     => $status,
        'ratings'    => $ratings,
        'subjects'   => $SUBJECTS[$yearLevel],
    ];
}

// ── Remove ─────────────────────────────────────────────────────
if ($MODE === 'undo') {
    $ids = $db->fetchAll("SELECT id FROM students WHERE student_number LIKE ?", [$PREFIX . '%']);
    $nStu = count($ids);
    $nUsr = $nHis = $nGrd = 0;

    if ($nStu) {
        $in  = '(' . implode(',', array_map('intval', array_column($ids, 'id'))) . ')';
        $hid = implode(',', array_map('intval',
            array_column($db->fetchAll("SELECT id FROM academic_history WHERE student_id IN {$in}"), 'id')
        )) ?: '0';

        // Counted BEFORE the delete - a summary printing zeros after
        // removing rows reads as "there was nothing there", which is the
        // one thing --undo exists to let you check.
        $nGrd = (int) $db->fetchColumn("SELECT COUNT(*) FROM academic_grades WHERE academic_history_id IN ({$hid})");
        $nHis = (int) $db->fetchColumn("SELECT COUNT(*) FROM academic_history WHERE student_id IN {$in}");
        $nUsr = (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE student_id IN {$in}");

        $db->query("DELETE FROM academic_grades WHERE academic_history_id IN ({$hid})");
        $db->query("DELETE FROM academic_history WHERE student_id IN {$in}");
        $db->query("DELETE FROM users WHERE student_id IN {$in}");
        $db->query("DELETE FROM students WHERE id IN {$in}");
    }
    $nApp = (int) $db->fetchColumn("DELETE FROM enrollments WHERE email LIKE '%@seed.receive.test'");

    printf("Removed batch two.\n  students %d · accounts %d · term records %d · grades %d · applicants %d\n",
        $nStu, $nUsr, $nHis, $nGrd, $nApp);
    exit(0);
}

// ── Guard ──────────────────────────────────────────────────────
$existing = (int) $db->fetchColumn("SELECT COUNT(*) FROM students WHERE student_number LIKE ?", [$PREFIX . '%']);
if ($existing > 0) {
    fwrite(STDERR, "Refusing to seed: {$existing} students numbered {$PREFIX}#### already exist.\n"
        . "Run with --undo first, or they will be duplicated.\n");
    exit(1);
}

echo "Batch two plan: {$COUNT} students + {$APPLICANT_COUNT} applicants\n";
printf("  programs %d · year weights %s · statuses %d\n",
    count($PROGRAMS),
    implode('/', array_map(static fn($w) => (int) ($w * 100) . '%', $YEAR_WEIGHTS)),
    count(array_unique($STATUSES)));

if ($MODE !== 'execute') {
    echo "\nDRY RUN - nothing written. Re-run with --execute.\n\n";
    printf("%-8s %-11s %-4s %-10s %-9s %-6s %-10s\n", 'NUMBER', 'LAST', 'YEAR', 'SCHOOL YEAR', 'COURSE', 'SECT', 'STATUS');
    echo str_repeat('-', 78) . "\n";
    foreach ($plan as $r) {
        printf("%-8s %-11s %-4d %-10s %-9s %-6s %-10s\n",
            $r['student_number'], $r['last_name'], $r['year_level'],
            $r['school_year'], substr($r['course'], 9, 8), $r['section'], $r['status']);
    }
    echo "\n";
    exit(0);
}

// ── Write ──────────────────────────────────────────────────────
//
// One transaction. A half-written batch is worse than none: fifty
// students with no sections, or applicants with no names, is a fixture
// that renders and misleads.
$db->beginTransaction();
try {
    $nUsers = $nHist = $nGrades = 0;
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
                (string) (100000000 + (int) substr($row['student_number'], 1) * 3 % 900000000),
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
            'email_is_placeholder' => 1,
        ]);

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

        $historyId = (int) $db->insert('academic_history', [
            'student_id'  => $studentId,
            'school_name' => 'Bestlink College of the Philippines',
            'school_year' => $row['school_year'],
            'semester'    => $row['semester'],
            'grade_level' => 'Year ' . $row['year_level'],
            'accepted_at' => date('Y-m-d H:i:s', strtotime('-' . (2 + (int) substr($row['student_number'], 2)) . ' days')),
            'accepted_by' => 2,
        ]);
        $nHist++;

        $weighted = 0.0;
        $units    = 0.0;
        foreach ($row['subjects'] as $k => $subject) {
            [$code, $name, $u] = $subject;
            $rating = $row['ratings'][$k % count($row['ratings'])];
            $passed = $rating <= 3.0;

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
                'received_at'          => date('Y-m-d H:i:s', strtotime('-8 days')),
                'instructor'           => 'FACULTY-' . str_pad((string) (1 + ($k % 8)), 2, '0', STR_PAD_LEFT),
                'term_status'          => 'final',
                'instructor_confirmed' => 1,
            ]);
            $weighted += $rating * $u;
            $units    += $u;
            $nGrades++;
        }
        $db->query(
            'UPDATE academic_history SET gwa = ?, credits = ?, subjects_completed = ? WHERE id = ?',
            [round($weighted / $units, 2), $units, count($row['subjects']), $historyId]
        );
    }

    // ── Applicants waiting behind the Receive Student button ──
    //
    // APPLICANTS, not students: no users row, no grades, no documents.
    // Accepting one through the UI is what creates the student, and that
    // is the flow these rows exist to be accepted BY.
    //
    // student_number is left NULL on purpose for the new ones - the
    // Enrollment System issues it on Accept, so pre-assigning one would
    // collide with the number the UI then allocates. The duplicates
    // carry an existing number instead, which is what makes the
    // Duplication Check have something real to find.
    $nApplicants = 0;
    for ($i = 0; $i < $APPLICANT_COUNT; $i++) {
        $gender   = ($i % 2 === 0) ? 'Male' : 'Female';
        $first    = ($gender === 'Male')
            ? $FIRST_M[$i % count($FIRST_M)]
            : $FIRST_F[$i % count($FIRST_F)];
        $last     = $SURNAMES[($i * 5 + 3) % count($SURNAMES)];
        $state    = $APPLICANT_STATES[$i % count($APPLICANT_STATES)];
        $yearLevel = 1 + ($i % 4);
        $course   = $PROGRAMS[($i * 3) % count($PROGRAMS)];
        $isDuplicate = ($state === 'duplicate');

        $db->insert('enrollments', [
            'first_name'            => $first,
            'middle_name'           => $MIDDLE[$i % count($MIDDLE)],
            'last_name'             => $last,
            'gender'                => $gender,
            'civil_status'          => 'Single',
            'nationality'           => 'Filipino',
            'religion'              => 'Roman Catholic',
            'place_of_birth'        => 'Quezon City',
            'birth_date'            => sprintf('200%d-%02d-%02d', 5 + ($yearLevel - 1) % 3, 1 + ($i % 12), 1 + ($i % 27)),
            // Duplicates point at a real seeded student, which is what
            // makes the Duplication Check match instead of always
            // reporting "no match found".
            'student_number'        => $isDuplicate ? 'S26' . str_pad((string) (($i * 3) % 100 + 1), 5, '0', STR_PAD_LEFT) : null,
            'father_name'           => $last . ' Sr.',
            'mother_name'           => $SURNAMES[($i + 7) % count($SURNAMES)],
            'email'                 => strtolower($first . '.' . $last) . '.' . $i . '@seed.receive.test',
            'address'               => $MARK . ' ' . $last . ', Quezon City',
            'contact_number'        => '09' . str_pad((string) (200000000 + $i * 7919), 9, '0', STR_PAD_LEFT),
            'prev_school_name'      => $isDuplicate ? null : 'Quezon City Science High School',
            'prev_school_last_year' => $isDuplicate ? null : 'Grade 12',
            'prev_school_graduated_sy' => $isDuplicate ? null : '2025-2026',
            'emergency_name'        => $SURNAMES[($i + 11) % count($SURNAMES)] . ', ' . $last,
            'emergency_relationship'=> 'Parent',
            'emergency_contact'     => '09' . str_pad((string) (300000000 + $i * 6151), 9, '0', STR_PAD_LEFT),
            'course'                => $course,
            'year_level'            => $yearLevel,
            'school_year'           => $SCHOOL_YEARS[$i % count($SCHOOL_YEARS)],
            'semester'              => $SEMESTERS[$i % count($SEMESTERS)],
            'status'                => $state,
            'received_at'           => $state === 'pending' ? null
                                        : date('Y-m-d H:i:s', strtotime('-' . (1 + $i) . ' days')),
        ]);
        $nApplicants++;
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Seeding failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

printf("Seeded batch two.\n");
printf("  students %d · accounts %d · term records %d · grades %d\n",
    $COUNT, $nUsers, $nHist, $nGrades);
printf("  applicants waiting behind Receive Student: %d\n", $nApplicants);
echo "\nStudents sign in with their student number and the password 'password'.\n";
echo "Remove them all with:\n  php tests/seed_50_more.php --undo\n";