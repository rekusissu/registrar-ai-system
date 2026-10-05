<?php
// Generate migrations/seed_50_more.sql and migrations/unseed_50_more.sql
// from what is actually in the database.
//
//    php tests/gen_seed50_sql.php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

const NUM  = "student_number LIKE 'S27%'";
const MAIL = "email LIKE '%@testdata.example'";
const APP  = "email LIKE '%@seed.receive.test'";

function q($v): string {
    if ($v === null) return 'NULL';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v) || is_float($v)) return (string) $v;
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";
}

/** One INSERT, chunked 100 rows per statement so no query is a monster line. */
function block(string $table, array $cols, array $rows, int $chunk = 100): string {
    if (!$rows) return '';
    $head = "INSERT INTO `{$table}` (\n  " . implode(', ', array_map(static fn($c) => "`{$c}`", $cols)) . "\n) VALUES\n";
    $out  = '';
    foreach (array_chunk($rows, $chunk) as $batch) {
        $vals = [];
        foreach ($batch as $r) {
            $vals[] = '  (' . implode(', ', array_map(static fn($c) => q($r[$c] ?? null), $cols)) . ')';
        }
        $out .= $head . implode(",\n", $vals) . ";\n\n";
    }
    return $out;
}

/**
 * The preamble both files open with.
 *
 * SET NAMES first: the names in this file are UTF-8, and a latin1
 * connection would store them as mojibake.
 *
 * No transaction, deliberately. phpMyAdmin already opens one around an
 * import, so a bare START TRANSACTION inside it raises MySQL 1568 and
 * phpMyAdmin aborts the whole file, reporting only "the import could not
 * be completed" - which reads like a corrupt file rather than a
 * transaction clash. Not hypothetical: the first version of
 * seed_100_full.sql did exactly this and failed on every host.
 */
function preamble(string $title, string $extra = ''): string {
    // $title IS the path (e.g. "MIGRATIONS/SEED_50_MORE.SQL"). It is printed
    // verbatim on the line below and must NOT be turned back into a
    // filename here: doing that produced
    //     mysql ... < migrations/migrations/seed_50_more.sql.sql
    // which is a command that cannot work. The mysql line uses $title
    // directly - it is already the path, and lowercasing it is cosmetic
    // only, so the two lines cannot drift apart.
    $b = [
        '-- ============================================================================',
        '--  ' . $title,
        '--',
        '--    mysql -u root registrar_ai < ' . strtolower($title),
        '--    phpMyAdmin: Import > choose this file.',
        '--',
        '--  NO TRANSACTION ON PURPOSE',
        '--  ------------------------',
        '--  phpMyAdmin wraps an import in a transaction. A second START TRANSACTION',
        '--  raises MySQL 1568 and phpMyAdmin aborts the entire file with a generic',
        '--  "import could not be completed". phpMyAdmin commits for us; the mysql',
        '--  client commits by default. Nothing is lost by leaving it alone.',
        '--',
        '--  TWO MARKERS, BOTH REQUIRED',
        '--  -----------------------',
        "--    students     student_number LIKE 'S27%'   AND  email LIKE '%@testdata.example'",
        "--    enrollments  email LIKE '%@seed.receive.test'",
        '--',
        '--  One marker is not enough. student_number carries only a non-unique index,',
        '--  so a real student could eventually be issued an S27xxxx number.',
        $extra,
        '-- ============================================================================',
        '',
        'SET NAMES utf8mb4;',
        'SET FOREIGN_KEY_CHECKS = 0;',
        '',
    ];
    return implode("\n", array_filter($b, static fn($l) => $l !== null));
}

$students = $db->fetchAll('SELECT * FROM students WHERE ' . NUM . ' ORDER BY id');
if (!$students) {
    fwrite(STDERR, "Nothing seeded. Run: php tests/seed_50_more.php --execute\n");
    exit(1);
}
$in      = '(' . implode(',', array_map('intval', array_column($students, 'id'))) . ')';
$users   = $db->fetchAll("SELECT * FROM users WHERE student_id IN {$in} ORDER BY id");
$history = $db->fetchAll("SELECT * FROM academic_history WHERE student_id IN {$in} ORDER BY id");
$hids    = '(' . implode(',', array_column($history, 'id') ?: [0]) . ')';
$grades  = $db->fetchAll("SELECT * FROM academic_grades WHERE academic_history_id IN {$hids} ORDER BY id");
$apps    = $db->fetchAll('SELECT * FROM enrollments WHERE ' . APP . ' ORDER BY id');

// ── Clear any earlier run, so a re-import is safe ─────────────
$out  = preamble('MIGRATIONS/SEED_50_MORE.SQL', implode("\n", [
    '--  SAFE TO RUN TWICE',
    '--  -----------------',
    '--  The rows below carry explicit ids, so a second import would collide on',
    '--  the primary key. This clears any previous run first, which also makes it',
    '--  the fix for an import that died halfway through.',
    '',
    '--  Children before parents: academic_grades has no declared foreign key',
    '--  today, so the order is not left to chance. Delete a student first and its',
    '--  grades are orphaned - rows the Academic History board renders as a',
    '--  cohort nobody can place.',
]));
$out .= "DELETE g FROM academic_grades g\n";
$out .= "  JOIN academic_history h ON h.id = g.academic_history_id\n";
$out .= "  JOIN students s ON s.id = h.student_id\n";
$out .= " WHERE s." . NUM . " AND s." . MAIL . ";\n\n";
$out .= "DELETE h FROM academic_history h\n  JOIN students s ON s.id = h.student_id\n";
$out .= " WHERE s." . NUM . " AND s." . MAIL . ";\n\n";
$out .= "DELETE u FROM users u\n  JOIN students s ON s.id = u.student_id\n";
$out .= " WHERE s." . NUM . " AND s." . MAIL . ";\n\n";
$out .= "DELETE FROM students WHERE " . NUM . " AND " . MAIL . ";\n\n";
$out .= "DELETE FROM enrollments WHERE " . APP . ";\n\n";

$out .= "-- ── Students ──────────────────────────────────────────────────────────\n";
$out .= block('students',
    ['id','student_number','first_name','middle_name','last_name','gender','civil_status',
     'birth_date','nationality','address','contact_number','email','course','year_level',
     'school_year','semester','section','status','graduation_date','school_year_graduated',
     'email_is_placeholder'], $students);

$out .= "-- ── Portal accounts (username = student_number, as resolveLoginUser expects) ─\n";
$out .= "--  One shared password: 'password'.\n";
$out .= block('users',
    ['id','email','password_hash','full_name','role','student_id','username','is_active'], $users);

$out .= "-- ── Term records ─────────────────────────────────────────────────────\n";
$out .= "--  gwa is the unit-weighted mean of the grades below.\n";
$out .= block('academic_history',
    ['id','student_id','school_name','school_year','semester','grade_level','gwa','credits',
     'subjects_completed','accepted_at','accepted_by'], $history);

$out .= "-- ── Subject grades ───────────────────────────────────────────────────\n";
$out .= "--  final_rating is on the school's 1.00-5.00 scale, 1.00 best.\n";
$out .= block('academic_grades',
    ['id','academic_history_id','subject_code','subject','subject_type','units','grade',
     'final_rating','grade_status','source_system','semester_taken','received_at','instructor',
     'term_status','instructor_confirmed'], $grades);

$out .= "-- ── Applicants, waiting behind the Receive Student button ────────────\n";
$out .= "--  These are APPLICANTS, not students: no users row, no grades, no\n";
$out .= "--  documents. Accepting one through the UI is what creates the student,\n";
$out .= "--  and that is the flow they exist to be accepted BY.\n";
$out .= "--\n";
$out .= "--  The duplicates deliberately carry an EXISTING student_number, so the\n";
$out .= "--  modal's Duplication Check has something real to match rather than\n";
$out .= "--  always reporting \"no match found\". The rest carry NULL, because the\n";
$out .= "--  Enrollment System issues the number on Accept.\n";
$out .= block('enrollments',
    ['id','first_name','middle_name','last_name','gender','civil_status','nationality',
     'religion','place_of_birth','birth_date','student_number','father_name','mother_name',
     'email','address','contact_number','prev_school_name','prev_school_last_year',
     'prev_school_graduated_sy','emergency_name','emergency_relationship','emergency_contact',
     'course','year_level','school_year','semester','status','received_at'], $apps);

$out .= "SET FOREIGN_KEY_CHECKS = 1;\n\n";
$out .= "-- ── What landed ──────────────────────────────────────────────────────\n";
$out .= "SELECT 'students' AS what, COUNT(*) AS n FROM students WHERE " . NUM . "\n";
$out .= "UNION ALL SELECT 'portal accounts', COUNT(*) FROM users u JOIN students s ON s.id=u.student_id WHERE s." . NUM . "\n";
$out .= "UNION ALL SELECT 'term records', COUNT(*) FROM academic_history h JOIN students s ON s.id=h.student_id WHERE s." . NUM . "\n";
$out .= "UNION ALL SELECT 'subject grades', COUNT(*) FROM academic_grades g JOIN academic_history h ON h.id=g.academic_history_id JOIN students s ON s.id=h.student_id WHERE s." . NUM . "\n";
$out .= "UNION ALL SELECT 'applicants', COUNT(*) FROM enrollments WHERE " . APP . ";\n";

file_put_contents(__DIR__ . '/../migrations/seed_50_more.sql', $out);
printf("Wrote migrations/seed_50_more.sql (%s bytes)\n", number_format(strlen($out)));
printf("  students %d · accounts %d · history %d · grades %d · applicants %d\n",
    count($students), count($users), count($history), count($grades), count($apps));

// ── The drop ──────────────────────────────────────────────────
$drop = preamble('MIGRATIONS/UNSEED_50_MORE.SQL', implode("\n", [
    '--  Removes everything migrations/seed_50_more.sql created.',
    '--',
    '--  Nothing outside the two markers is touched. Batch one, the real students',
    '--  and any real enrollment all survive.',
    '--',
    '--  ORDER: children before parents. academic_grades has no declared foreign',
    '--  key today, which is exactly why this order is not left to chance.',
]));

$drop .= "-- ── What is about to go ──────────────────────────────────────────\n";
$drop .= "SELECT 'students' AS what, COUNT(*) AS n\n";
$drop .= "    FROM students\n";
$drop .= " WHERE " . NUM . " AND " . MAIL . "\n";
$drop .= "UNION ALL SELECT 'portal accounts', COUNT(*)\n";
$drop .= "    FROM users u JOIN students s ON s.id = u.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . "\n";
$drop .= "UNION ALL SELECT 'term records', COUNT(*)\n";
$drop .= "    FROM academic_history h JOIN students s ON s.id = h.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . "\n";
$drop .= "UNION ALL SELECT 'subject grades', COUNT(*)\n";
$drop .= "    FROM academic_grades g\n";
$drop .= "      JOIN academic_history h ON h.id = g.academic_history_id\n";
$drop .= "      JOIN students s ON s.id = h.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . "\n";
$drop .= "UNION ALL SELECT 'applicants', COUNT(*) FROM enrollments WHERE " . APP . ";\n\n";

$drop .= "-- ── Children first ─────────────────────────────────────────────\n";
$drop .= "DELETE g FROM academic_grades g\n";
$drop .= "  JOIN academic_history h ON h.id = g.academic_history_id\n";
$drop .= "  JOIN students s ON s.id = h.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . ";\n\n";
$drop .= "DELETE h FROM academic_history h\n  JOIN students s ON s.id = h.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . ";\n\n";
$drop .= "DELETE u FROM users u\n  JOIN students s ON s.id = u.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . ";\n\n";
$drop .= "DELETE FROM students WHERE " . NUM . " AND " . MAIL . ";\n\n";
$drop .= "-- Applicants reference nothing we created, so they go on their own.\n";
$drop .= "DELETE FROM enrollments WHERE " . APP . ";\n\n";

$drop .= "SET FOREIGN_KEY_CHECKS = 1;\n\n";
$drop .= "-- ── What is left ───────────────────────────────────────────────\n";
$drop .= "-- All four should read 0.\n";
$drop .= "SELECT 'students still present' AS what, COUNT(*) AS n\n";
$drop .= "    FROM students\n";
$drop .= " WHERE " . NUM . " AND " . MAIL . "\n";
$drop .= "UNION ALL SELECT 'term records still present', COUNT(*)\n";
$drop .= "    FROM academic_history h JOIN students s ON s.id = h.student_id\n";
$drop .= " WHERE s." . NUM . " AND s." . MAIL . "\n";
$drop .= "UNION ALL SELECT 'applicants still present', COUNT(*)\n";
$drop .= "    FROM enrollments WHERE " . APP . ";\n";

file_put_contents(__DIR__ . '/../migrations/unseed_50_more.sql', $drop);
printf("Wrote migrations/unseed_50_more.sql (%s bytes)\n", number_format(strlen($drop)));