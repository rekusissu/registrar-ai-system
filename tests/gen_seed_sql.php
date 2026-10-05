<?php
// Generate migrations/seed_100_full.sql from what is actually in the
// database, so the file and the live rows cannot disagree.
//
//    php tests/gen_seed_sql.php        (needs seed_100_full.php --execute first)
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();

const NUM  = "student_number LIKE 'S26%'";
const MAIL = "email LIKE '%@testdata.example'";

function q($v): string {
    if ($v === null) return 'NULL';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v) || is_float($v)) return (string) $v;
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";
}

/** One INSERT statement, columns named explicitly. */
function block(string $table, array $cols, array $rows): string {
    if (!$rows) return '';
    $head = "INSERT INTO `{$table}` (\n  " . implode(', ', array_map(static fn($c) => "`{$c}`", $cols)) . "\n) VALUES\n";
    $vals = [];
    foreach ($rows as $r) {
        $vals[] = '  (' . implode(', ', array_map(static fn($c) => q($r[$c] ?? null), $cols)) . ')';
    }
    return $head . implode(",\n", $vals) . ";\n\n";
}

$students = $db->fetchAll('SELECT * FROM students WHERE ' . NUM . ' ORDER BY id');
if (!$students) {
    fwrite(STDERR, "Nothing seeded. Run: php tests/seed_100_full.php --execute\n");
    exit(1);
}
$in  = '(' . implode(',', array_map('intval', array_column($students, 'id'))) . ')';
$users   = $db->fetchAll("SELECT * FROM users WHERE student_id IN {$in} ORDER BY id");
$history = $db->fetchAll("SELECT * FROM academic_history WHERE student_id IN {$in} ORDER BY id");
$hids    = '(' . implode(',', array_column($history, 'id') ?: [0]) . ')';
$grades  = $db->fetchAll("SELECT * FROM academic_grades WHERE academic_history_id IN {$hids} ORDER BY id");
$docs    = $db->fetchAll("SELECT * FROM document_requests WHERE student_id IN {$in} ORDER BY id");

$h = [];
$h[] = "-- ============================================================================";
$h[] = "--  MIGRATIONS/SEED_100_FULL.SQL";
$h[] = "--";
$h[] = "--  100 students across every dimension the registrar filters by, with the";
$h[] = "--  records that hang off them: portal accounts, term records, subject grades,";
$h[] = "--  and one document request each.";
$h[] = "--";
$h[] = "--    mysql -u root registrar_ai < migrations/seed_100_full.sql";
$h[] = "--";
$h[] = "--  GENERATED, THEN VERIFIED - NOT HAND-WRITTEN";
$h[] = "--  --------------------------------------";
$h[] = "--  Written out of the seeded rows, so it cannot drift from what the PHP";
$h[] = "--  seeder produced. Hand-typed INSERTs are how a fixture ends up disagreeing";
$h[] = "--  with itself: a section no student is filed under, or a term GWA that no";
$h[] = "--  longer matches the grades beneath it.";
$h[] = "--";
$h[] = "--  TWO MARKERS, AND REMOVAL NEEDS BOTH TO AGREE";
$h[] = "--  ----------------------------------------";
$h[] = "--    student_number LIKE 'S26%'         the number marker";
$h[] = "--    email LIKE '%@testdata.example'    the address marker";
$h[] = "--";
$h[] = "--  One alone is not enough. student_number carries only a non-unique index,";
$h[] = "--  so a real student could eventually be issued an S26xxxxx number.";
$h[] = "--  Requiring BOTH means the companion unseed file can only ever remove what";
$h[] = "--  this one wrote. See migrations/unseed_100_full.sql.";
$h[] = "--";
$h[] = "--  WHAT IS IN HERE";
$h[] = "--  ---------------";
$h[] = '--  ' . count($students) . ' students, ' . count($users) . ' portal accounts, ' . count($history) . ' term records,';
$h[] = '--  ' . count($grades) . ' subject grades, ' . count($docs) . ' document requests.';
$h[] = "--";
$h[] = "--  Five programs, all four year levels, three school years, three semesters";
$h[] = "--  and every value of students.status. A fixture where every row shares one";
$h[] = "--  program and one term cannot tell a grouping bug from a correct grouping -";
$h[] = "--  and grouping is what the masterlist blocks, the folder browser and the";
$h[] = "--  Insight figures all do.";
$h[] = "--";
$h[] = "--  The requests cover all eight document_status values, and that is the one";
$h[] = "--  that matters most: the AI Insight chart collapses eight statuses into five";
$h[] = "--  series, so a fixture that only ever files 'Filed' leaves four series";
$h[] = "--  permanently empty - which is how a wrong status mapping passes review,";
$h[] = "--  because nothing ever looks wrong.";
$h[] = "--";
$h[] = "--  @testdata.example addresses are undeliverable by construction. They give";
$h[] = "--  the row the shape of a real one; email_is_placeholder is set, so the";
$h[] = "--  welcome-mail and any bounce sweep skip them.";
$h[] = "--";
$h[] = "--  Sign in as any seeded student with their student number and the password";
$h[] = "--  'password'.";
$h[] = "--";
$h[] = "--  Back up anything you cannot lose first:";
$h[] = "--    mysqldump -u root --single-transaction registrar_ai > backup.sql";
$h[] = "-- ============================================================================";
$h[] = '';
$h[] = 'START TRANSACTION;';
$h[] = '';

$out = implode("\n", $h) . "\n";

// IDs are written explicitly. Every child points at its parent's id, so a
// file that leaned on AUTO_INCREMENT would only import correctly into a
// database whose counter happened to start in the right place.
$out .= "-- ── Students ──────────────────────────────────────────────────────────\n";
$out .= block('students',
    ['id','student_number','first_name','middle_name','last_name','gender','civil_status',
     'birth_date','nationality','address','contact_number','email','course','year_level',
     'school_year','semester','section','status','graduation_date','school_year_graduated',
     'email_is_placeholder'],
    $students);

$out .= "-- ── Portal accounts ──────────────────────────────────────────────────\n";
$out .= "--  username is the student_number, which is what resolveLoginUser()\n";
$out .= "--  matches a student credential on. One shared password: 'password'.\n";
$out .= block('users',
    ['id','email','password_hash','full_name','role','student_id','username','is_active'],
    $users);

$out .= "-- ── Term records ─────────────────────────────────────────────────────\n";
$out .= "--  gwa is the unit-weighted mean of the grades below, computed at seed\n";
$out .= "--  time so the stored figure matches what the page recomputes.\n";
$out .= block('academic_history',
    ['id','student_id','school_name','school_year','semester','grade_level','gwa','credits',
     'subjects_completed','accepted_at','accepted_by'],
    $history);

$out .= "-- ── Subject grades ───────────────────────────────────────────────────\n";
$out .= "--  final_rating is on the school's 1.00-5.00 scale, 1.00 best, 5.00\n";
$out .= "--  worst. Ratings are spread across the whole scale so a GWA report has\n";
$out .= "--  variation to summarise rather than a hundred identical figures.\n";
$out .= block('academic_grades',
    ['id','academic_history_id','subject_code','subject','subject_type','units','grade',
     'final_rating','grade_status','source_system','semester_taken','received_at','instructor',
     'term_status','instructor_confirmed'],
    $grades);

$out .= "-- ── Document requests ────────────────────────────────────────────────\n";
$out .= "--  catalog_id points at document_catalog (7 active items). Each stage\n";
$out .= "--  carries the timestamp that stage actually implies: a Shipped row with\n";
$out .= "--  no shipped_at reads as \"shipped, but when?\" to every age calculation.\n";
$out .= block('document_requests',
    ['id','student_id','document_type','catalog_id','quantity','request_type','fulfillment_type',
     'payment_method','purpose','document_status','blocked_reason','rejection_reason',
     'processed_by','processed_date','fee_amount','ready_at','shipped_at','claimed_at',
     'request_date','recipient'],
    $docs);

$out .= "COMMIT;\n\n";
$out .= "-- ── What landed ──────────────────────────────────────────────────────\n";
$out .= "SELECT 'students' AS what, COUNT(*) AS n FROM students WHERE " . NUM . "\n";
$out .= "UNION ALL SELECT 'portal accounts', COUNT(*) FROM users u JOIN students s ON s.id=u.student_id WHERE s." . NUM . "\n";
$out .= "UNION ALL SELECT 'term records', COUNT(*) FROM academic_history h JOIN students s ON s.id=h.student_id WHERE s." . NUM . "\n";
$out .= "UNION ALL SELECT 'subject grades', COUNT(*) FROM academic_grades g JOIN academic_history h ON h.id=g.academic_history_id JOIN students s ON s.id=h.student_id WHERE s." . NUM . "\n";
$out .= "UNION ALL SELECT 'document requests', COUNT(*) FROM document_requests d JOIN students s ON s.id=d.student_id WHERE s." . NUM . ";\n";

$target = __DIR__ . '/../migrations/seed_100_full.sql';
file_put_contents($target, $out);

printf("Wrote migrations/seed_100_full.sql (%s bytes)\n", number_format(strlen($out)));
printf("  students %d · accounts %d · term records %d · grades %d · requests %d\n",
    count($students), count($users), count($history), count($grades), count($docs));