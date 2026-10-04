<?php
// ============================================================
//  Verifies the data migrations/seed_150_students.sql produced.
//
//    php tests/seed_150_check.php
//
//  Reads the seedtest scratch database, NOT registrar_ai. The seed is applied
//  to a clone so this can never modify real data, and so a failure here is
//  always about the seed rather than about whatever is currently in the live
//  tables.
//
//  What it is checking is not "did the rows go in" - the seed prints its own
//  counts for that. It is checking the invariants the rest of the application
//  relies on but never states: that a term GWA recomputed from its subjects
//  equals the GWA stored on the term, that paid_at is NULL exactly when a
//  request is unpaid, that a section code agrees with the year and term it
//  encodes. Each of those has shipped as a real bug before, and each is
//  invisible to a row count.
// ============================================================
require_once __DIR__ . '/../shared/config.php';

$c = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, 'seedtest', DB_PORT);
if ($c->connect_error) {
    fwrite(STDERR,
        "ERR: cannot reach the seedtest database ({$c->connect_error}).\n"
        . "Create it first:\n"
        . "  mysqldump -u root registrar_ai > clone.sql\n"
        . "  mysql -u root -e \"CREATE DATABASE seedtest\"\n"
        . "  mysql -u root seedtest < clone.sql\n"
        . "  mysql -u root seedtest < migrations/seed_150_students.sql\n");
    exit(1);
}

$MARK  = "s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'";
// Queries that select from students with no alias cannot use $MARK's "s.".
$SEED  = "student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'";
$pass  = 0;
$fail  = 0;

function check(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else    { $fail++; echo "  FAIL $label" . ($note ? "  <- $note" : '') . "\n"; }
}

/** First column of the first row, as an int. Exits on a broken query rather
 *  than reporting a FALSE that looks like a data problem. */
function q(mysqli $c, string $sql): int {
    $r = $c->query($sql);
    if (!$r) { fwrite(STDERR, "SQL ERR: {$c->error}\n  $sql\n"); exit(1); }
    return (int) ($r->fetch_assoc()['n'] ?? 0);
}

echo "The GWA invariant\n";
// shared/term_grades.php averages final_rating weighted by units. The seed
// claims the mean of a term's ratings equals the stored gwa. Checked with the
// app's own function rather than a reimplementation of the formula, so a
// change to that helper surfaces here.
require_once __DIR__ . '/../shared/term_grades.php';

$mismatch = 0; $checked = 0; $worst = 0.0; $sample = null;
foreach ($c->query("SELECT h.id, h.gwa FROM academic_history h
                    JOIN students s ON s.id = h.student_id
                    WHERE $MARK") as $t) {
    $subs = [];
    foreach ($c->query("SELECT units, final_rating FROM academic_grades
                        WHERE academic_history_id = {$t['id']}") as $g) {
        $subs[] = ['units' => (float) $g['units'], 'final_rating' => $g['final_rating']];
    }
    if (!$subs) continue;
    $checked++;
    $calc = termGwa($subs);
    if ($calc === null) { $mismatch++; continue; }
    $diff = abs($calc - (float) $t['gwa']);
    if ($diff > $worst) { $worst = $diff; $sample = [$t['id'], $t['gwa'], $calc]; }
    if ($diff > 0.011) $mismatch++;   // 2dp rounding tolerance
}
check("computed term GWA matches stored gwa on all $checked terms",
      $mismatch === 0, "$mismatch differ, worst=$worst " . json_encode($sample));

$career = careerGwa([['subjects' => [['units' => 3, 'final_rating' => 1.75]]]]);
check('careerGwa handles a seeded term', $career === 1.75, var_export($career, true));

// A rating outside the scale is corrupt, not a failing grade.
$low = q($c, "SELECT COUNT(*) n FROM academic_grades g
              JOIN academic_history h ON h.id = g.academic_history_id
              JOIN students s ON s.id = h.student_id
              WHERE $MARK AND (CAST(g.final_rating AS DECIMAL(4,2)) < 1.00
                            OR CAST(g.final_rating AS DECIMAL(4,2)) > 5.00)");
check('every rating is inside the 1.00-5.00 scale', $low === 0, "rows=$low");

echo "\nDocument lifecycle\n";
check('paid_at is NULL exactly when Awaiting_Payment',
      q($c, "SELECT COUNT(*) n FROM document_requests r JOIN students s ON s.id=r.student_id
             WHERE $MARK AND ((r.document_status='Awaiting_Payment') <> (r.paid_at IS NULL))") === 0);

check('Claimed mirrors legacy status released',
      q($c, "SELECT COUNT(*) n FROM document_requests r JOIN students s ON s.id=r.student_id
             WHERE $MARK AND r.document_status='Claimed' AND r.status<>'released'") === 0);

// document_type is the column the seed's enum-widening block exists for.
$vals = [];
foreach ($c->query("SELECT DISTINCT document_type d FROM document_requests r
                    JOIN students s ON s.id = r.student_id WHERE $MARK") as $r) $vals[] = $r['d'];
check('no request has a blank document_type',
      !in_array('', $vals, true) && !in_array(null, $vals, true), implode(',', $vals));
check('6 distinct legacy document types are represented', count($vals) === 6,
      count($vals) . ': ' . implode(',', $vals));

foreach ([
    'a Claimed request carries claimed_at'        => "document_status='Claimed' AND claimed_at IS NULL",
    'a Claimed request carries completed_date'    => "document_status='Claimed' AND completed_date IS NULL",
    'a Ready request carries ready_at'            => "document_status='Ready' AND ready_at IS NULL",
    'a Ready request carries release_date'        => "document_status='Ready' AND release_date IS NULL",
    'a Rejected request carries a reason'         => "document_status='Rejected' AND (rejection_reason IS NULL OR rejection_reason='')",
    'an Awaiting_Payment request has no ready_at' => "document_status='Awaiting_Payment' AND ready_at IS NOT NULL",
    'a request is missing its qr_hash'            => "qr_hash IS NULL OR qr_hash=''",
    'a request is missing its request_id'         => "request_id IS NULL OR request_id=''",
    'a request is missing its purpose'            => "purpose IS NULL OR purpose=''",
] as $label => $cond) {
    check($label, q($c, "SELECT COUNT(*) n FROM document_requests r
                          JOIN students s ON s.id=r.student_id
                          WHERE $MARK AND $cond") === 0);
}

check('fee_amount equals base_fee x quantity per the catalog',
      q($c, "SELECT COUNT(*) n FROM document_requests r
             JOIN students s ON s.id = r.student_id
             JOIN document_catalog c ON c.id = r.catalog_id
             WHERE $MARK AND r.fee_amount <> ROUND(c.base_fee *
                   (CASE WHEN c.fee_type='flat' THEN 1 ELSE r.quantity END), 2)") === 0);

echo "\nTimeline events\n";
check('every seeded request has a timeline event',
      q($c, "SELECT COUNT(*) n FROM document_requests r JOIN students s ON s.id=r.student_id
             LEFT JOIN document_request_events e ON e.request_id = r.id
             WHERE $MARK AND e.id IS NULL") === 0);
check('an unpaid request has no payment event',
      q($c, "SELECT COUNT(*) n FROM document_requests r JOIN students s ON s.id=r.student_id
             LEFT JOIN document_request_events e ON e.request_id=r.id AND e.status='Payment confirmed'
             WHERE $MARK AND r.document_status='Awaiting_Payment' AND e.id IS NOT NULL") === 0);
check('every paid request does have a payment event',
      q($c, "SELECT COUNT(*) n FROM document_requests r JOIN students s ON s.id=r.student_id
             LEFT JOIN document_request_events e ON e.request_id=r.id AND e.status='Payment confirmed'
             WHERE $MARK AND r.paid_at IS NOT NULL AND e.id IS NULL") === 0);

echo "\nUniqueness\n";
foreach ([
    'students.student_number'      => "SELECT student_number v, COUNT(*) c FROM students WHERE $SEED GROUP BY v HAVING c>1",
    'students.email'               => "SELECT email v, COUNT(*) c FROM students WHERE $SEED GROUP BY v HAVING c>1",
    'students.contact_number'      => "SELECT contact_number v, COUNT(*) c FROM students WHERE $SEED GROUP BY v HAVING c>1",
    'document_requests.request_id' => "SELECT r.request_id v, COUNT(*) c FROM document_requests r JOIN students s ON s.id=r.student_id WHERE $MARK GROUP BY v HAVING c>1",
    'qr_hash (UNIQUE index)'       => "SELECT qr_hash v, COUNT(*) c FROM document_requests r JOIN students s ON s.id=r.student_id WHERE $MARK GROUP BY v HAVING c>1",
] as $label => $sql) {
    $dupes = $c->query($sql)->num_rows;
    check("no duplicate $label", $dupes === 0, "$dupes duplicated");
}

echo "\nSections\n";
require_once __DIR__ . '/../shared/section_code.php';

// Must equal what sectionCodeFromParts() produces for that student's own
// year+term - the code the enrolment office would actually assign.
$bad = 0; $sample = null;
foreach ($c->query("SELECT section, year_level, semester, student_number FROM students
                    WHERE $SEED") as $r) {
    $want = sectionCodeFromParts((int) $r['year_level'], $r['semester'], 1);
    if ($r['section'] !== $want) { $bad++; if (!$sample) $sample = [$r['student_number'], $r['section'], $want]; }
}
check('every section equals sectionCodeFromParts(year, term, 1)', $bad === 0,
      "$bad differ " . json_encode($sample));

$combos = $c->query("SELECT DISTINCT year_level, semester FROM students WHERE $SEED")->num_rows;
check('all 8 year+term combinations are present', $combos === 8, "got $combos");

check('every year level appears in both terms',
      q($c, "SELECT COUNT(*) n FROM (SELECT year_level FROM students WHERE $SEED
              GROUP BY year_level HAVING COUNT(DISTINCT semester) <> 2) x") === 0);

$max = (int) ($c->query("SELECT MAX(c) m FROM (SELECT COUNT(*) c FROM students
                         WHERE $SEED GROUP BY section) y")->fetch_assoc()['m'] ?? 0);
check("no section exceeds 50 students (largest is $max)", $max <= 50, "largest=$max");

echo "\nStudent IDs\n";
// student_ids.id_number is what the ID pages read. students.student_number is
// not, so an ID has to exist as a row here or it does not show up at all.
check('every seeded student has an ID row',
      q($c, "SELECT COUNT(*) n FROM student_ids i JOIN students s ON s.id=i.student_id
             WHERE $MARK") === 150);
check('no ID is blank',
      q($c, "SELECT COUNT(*) n FROM student_ids i JOIN students s ON s.id=i.student_id
             WHERE $MARK AND (i.id_number IS NULL OR i.id_number='')") === 0);
check('every ID matches the YYYY-XXXX format',
      q($c, "SELECT COUNT(*) n FROM student_ids i JOIN students s ON s.id=i.student_id
             WHERE $MARK AND i.id_number NOT REGEXP '^[0-9]{4}-[0-9]{4}$'") === 0);
check('every ID is a school_id',
      q($c, "SELECT COUNT(*) n FROM student_ids i JOIN students s ON s.id=i.student_id
             WHERE $MARK AND i.id_type <> 'school_id'") === 0);
check('no duplicate id_number issued',
      q($c, "SELECT COUNT(*) n FROM (SELECT id_number FROM student_ids
             WHERE id_number LIKE '20%' GROUP BY id_number HAVING COUNT(*) > 1) x") === 0);
check('seeded IDs are numbered from 9001, clear of real ones',
      q($c, "SELECT COUNT(*) n FROM student_ids i JOIN students s ON s.id=i.student_id
             WHERE $MARK AND CAST(SUBSTRING(i.id_number, -4) AS UNSIGNED) < 9001") === 0);

echo "\nNo RFID, which must now hold WITH the IDs present\n";
// An earlier version of the seed wrote no student_ids rows at all and simply
// asserted the table was empty. Now that it issues 150 IDs, THIS is the
// assertion that proves an ID exists with no card behind it - which is the real
// requirement, and the weaker one could not check it.
check('no rows in rfid_cards',
      q($c, "SELECT COUNT(*) n FROM rfid_cards x JOIN students s ON s.id=x.student_id
             WHERE $MARK") === 0);
check('no seeded ID is linked to a card',
      q($c, "SELECT COUNT(*) n FROM student_ids i JOIN students s ON s.id=i.student_id
             WHERE $MARK AND i.rfid_card_id IS NOT NULL") === 0);
check('no login account was created',
      q($c, "SELECT COUNT(*) n FROM users u JOIN students s ON s.id=u.student_id
             WHERE $MARK") === 0);
check('no user carries an rfid_uid',
      q($c, "SELECT COUNT(*) n FROM users u
             WHERE u.rfid_uid IS NOT NULL AND u.rfid_uid <> ''") === 0);
check('no student has an LRN',
      q($c, "SELECT COUNT(*) n FROM students WHERE $SEED
             AND lrn IS NOT NULL AND lrn <> ''") === 0);

echo "\nAcademic history shape\n";
check('a Year 1 student has no college history rows',
      q($c, "SELECT COUNT(*) n FROM academic_history h JOIN students s ON s.id=h.student_id
             WHERE $MARK AND s.year_level=1 AND h.school_name<>'Senior High School'") === 0);
check('every school_year is well formed',
      q($c, "SELECT COUNT(*) n FROM academic_history h JOIN students s ON s.id=h.student_id
             WHERE $MARK AND h.school_name<>'Senior High School'
               AND h.school_year NOT REGEXP '^[0-9]{4}-[0-9]{4}$'") === 0);
check('no term is pre-accepted by Faculty',
      q($c, "SELECT COUNT(*) n FROM academic_history h JOIN students s ON s.id=h.student_id
             WHERE $MARK AND h.accepted_at IS NOT NULL") === 0);
check('every grade has a rating and a valid status',
      q($c, "SELECT COUNT(*) n FROM academic_grades g
             JOIN academic_history h ON h.id=g.academic_history_id
             JOIN students s ON s.id=h.student_id
             WHERE $MARK AND (g.final_rating IS NULL OR g.grade_status IS NULL
                              OR g.grade_status NOT IN ('passed','failed','incomplete','dropped'))") === 0);
check('remarks agree with grade_status (no Failed row marked Passed)',
      q($c, "SELECT COUNT(*) n FROM academic_grades g
             JOIN academic_history h ON h.id=g.academic_history_id
             JOIN students s ON s.id=h.student_id
             WHERE $MARK AND ((g.grade_status='failed') <> (g.remarks='Failed'))") === 0);
check('at least one subject failed, or no probation path is exercised',
      q($c, "SELECT COUNT(*) n FROM academic_grades g
             JOIN academic_history h ON h.id=g.academic_history_id
             JOIN students s ON s.id=h.student_id
             WHERE $MARK AND g.grade_status='failed'") > 0);

echo "\nReferential integrity\n";
// academic_grades reaches its student through academic_history, not directly,
// so it is checked by its own path rather than through x.student_id - which
// does not exist on that table.
check('no orphan academic_grades',
      q($c, "SELECT COUNT(*) n FROM academic_grades g
             LEFT JOIN academic_history h ON h.id = g.academic_history_id
             WHERE h.id IS NULL") === 0);

foreach (['document_requests', 'academic_history', 'enrollment_history'] as $t) {
    check("no orphan $t",
          q($c, "SELECT COUNT(*) n FROM $t x LEFT JOIN students s ON s.id = x.student_id
                 WHERE s.id IS NULL") === 0);
}

check('no orphan document_request_events',
      q($c, "SELECT COUNT(*) n FROM document_request_events e
             LEFT JOIN document_requests r ON r.id = e.request_id
             WHERE r.id IS NULL") === 0);

echo "\n" . ($fail === 0 ? "OK - $pass check(s) passed.\n"
                        : "FAILED - $fail of " . ($pass + $fail) . " check(s).\n");
exit($fail === 0 ? 0 : 1);