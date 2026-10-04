<?php
// ============================================================
//  Remove the OFFICIAL GRADES SEED from Academic History.
//
//    php tests/clear_official_grades_seed.php            dry run
//    php tests/clear_official_grades_seed.php --execute  deletes
//
//  WHY THIS EXISTS
//
//  tests/seed_official_grades.php attaches a graded transcript to an EXISTING
//  student so that the GWA, the weighting and the advisory findings have real
//  numbers to read. It is marked, and it is repeatable - but until it is cleared
//  the Academic History page reports "1 in view", "1 complete" and a career GWA
//  for a term that never happened.
//
//  WHAT IT DELIBERATELY DOES NOT DELETE
//
//  The STUDENT. That seed attaches to a student who already existed; it does
//  not create one. Benjie Duque (id 867) is a real enrolment record, and the
//  Masterlist needs him. This script removes only academic_history and
//  academic_grades rows, which is precisely "the data on the Academic History
//  page". Deleting the student would go beyond that, and is a separate call.
//
//  SCOPE
//
//  academic_history.remarks LIKE 'SEEDDATA-OFFICIAL-GRADES%' - the marker the
//  seed writes, and the only thing this matches on. Grades are removed by their
//  parent history id rather than by their own marker, because academic_grades
//  carries no marker column: the seed tags the TERM, and the subjects belong
//  to the term. A term deleted without its subjects would leave orphans that no
//  page can reach and no query can explain.
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai academic_history academic_grades > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

define('MARKER', 'SEEDDATA-OFFICIAL-GRADES');

$terms = $db->fetchAll(
    'SELECT h.id, h.student_id, h.school_year, h.semester, s.student_number,
            CONCAT(s.first_name, " ", s.last_name) AS who
       FROM academic_history h
       JOIN students s ON s.id = h.student_id
      WHERE h.remarks LIKE ?
   ORDER BY h.id',
    [MARKER . '%']
);

echo PHP_EOL, '  Official grades seed', PHP_EOL;

if (!$terms) {
    echo '  Nothing to remove.' . PHP_EOL, PHP_EOL;
    exit(0);
}

$histIds = array_map('intval', array_column($terms, 'id'));
$histIn  = implode(',', $histIds);

foreach ($terms as $t) {
    echo "    #{$t['id']}  {$t['who']}  {$t['school_year']} {$t['semester']}" . PHP_EOL;
}

$g = $db->fetchOne("SELECT COUNT(*) c FROM academic_grades WHERE academic_history_id IN ($histIn)");
$gradeCount = (int) ($g['c'] ?? 0);
echo '  ' . count($histIds) . ' academic_history row(s), '
   . $gradeCount . ' academic_grades row(s)' . PHP_EOL;

// Say plainly that the student survives, because "removed the seed" and
// "removed the student" are very different claims and only one of them is
// true here.
$studentIds = array_values(array_unique(array_map('intval', array_column($terms, 'student_id'))));
echo '  ' . count($studentIds) . ' student record(s) are KEPT - only their'
   . ' academic history is removed.' . PHP_EOL, PHP_EOL;

if (!$execute) {
    echo '  Dry run. Nothing was deleted.' . PHP_EOL;
    echo '  Re-run with --execute to remove these rows.', PHP_EOL, PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    // Subjects before the term that owns them. One transaction, so a failure
    // halfway leaves neither orphans nor a term with no subjects.
    $db->query("DELETE FROM academic_grades WHERE academic_history_id IN ($histIn)");
    $db->query("DELETE FROM academic_history WHERE id IN ($histIn)");
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, '  FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo '  Removed ' . count($histIds) . ' history row(s), '
   . $gradeCount . ' grade row(s).' . PHP_EOL, PHP_EOL;
