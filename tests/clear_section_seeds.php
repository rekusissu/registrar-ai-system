<?php
// ============================================================
//  Remove the SECTION ACCEPTANCE SEED from the live database.
//
//    php tests/clear_section_seeds.php            dry run, deletes nothing
//    php tests/clear_section_seeds.php --execute  deletes, after a backup
//
//  WHY THIS EXISTS
//
//  tests/seed_section_acceptance.php plants 13 students across five sections so
//  every state on the acceptance board - ready, waitlist, empty, accepted - is
//  reachable without a term's real grades. That is exactly what makes it
//  unacceptable to leave in place: once the board shows the real catalogue, a
//  folder full of invented students is indistinguishable from real ones at a
//  glance, and a registrar could accept a section that does not exist.
//
//  WHY NOT JUST DELETE FROM students
//
//  Because the delete would be REFUSED. academic_history and fourteen other
//  tables hold a foreign key to students.id. So the child rows are discovered
//  from information_schema and cleared first, in that order. The list is read
//  rather than written, so a table added to the schema later is handled without
//  editing this file.
//
//  A SEPARATE SCRIPT FROM clear_official_grades_seed.php
//
//  The two seeds carry different markers on different tables and are removed in
//  opposite directions - this one deletes STUDENTS and works outward through the
//  foreign keys, that one deletes TERMS and leaves the student alone. Sharing
//  one script would have meant one marker quietly not matching.
//
//  SCOPE
//
//  Only rows carrying this seed's own markers:
//    * students.email LIKE '%@seed.sections.test'
//    * students.address  LIKE 'SEEDDATA-SECTIONS%'
//  Two markers because one account in this system has no email at all, and an
//  email-only filter leaves it behind forever.
//
//  Nothing outside those markers is touched, and no user account is deleted -
//  users.student_id is NULL-ed rather than deleted through, so the account
//  survives with its link broken. That is the treatment
//  tests/clear_e2e_residue.php established, for the same reason: a user row
//  may own audit rows and queue work that outlives the student.
//
//  Take a backup first:
//    mysqldump -u root --single-transaction registrar_ai students academic_history academic_grades > backup.sql
// ============================================================
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';

$execute = in_array('--execute', $argv, true);
$db      = Database::getInstance();

define('MARKER_EMAIL', '%@seed.sections.test');
define('MARKER_ADDR',  'SEEDDATA-SECTIONS%');

// ----------------------------------------------------------------------------
//  WHO IS MATCHED
// ----------------------------------------------------------------------------
$students = $db->fetchAll(
    'SELECT id, student_number, email, address
       FROM students
      WHERE email LIKE ? OR address LIKE ?
   ORDER BY id',
    [MARKER_EMAIL, MARKER_ADDR]
);
$studentIds = array_map('intval', array_column($students, 'id'));

echo PHP_EOL, '  Section acceptance seed', PHP_EOL;
echo '  ' . count($students) . ' seeded student(s)', PHP_EOL;

if (!$students) {
    echo '  Nothing to remove.' . PHP_EOL, PHP_EOL;
    exit(0);
}
foreach ($students as $s) {
    echo "    #{$s['id']} {$s['student_number']}" . PHP_EOL;
}

$in = implode(',', $studentIds);

// The history rows and the grades under them. These are what the acceptance
// board actually reads, so they are counted separately - "removed the seed" and
// "removed the seed's grades" are different claims.
$histRows = $db->fetchAll("SELECT id FROM academic_history WHERE student_id IN ($in)");
$histIds  = array_map('intval', array_column($histRows, 'id'));
$histIn   = $histIds ? implode(',', $histIds) : '0';

$gradeCount = 0;
if ($histIds) {
    $g = $db->fetchOne("SELECT COUNT(*) c FROM academic_grades WHERE academic_history_id IN ($histIn)");
    $gradeCount = (int) ($g['c'] ?? 0);
}
echo '  ' . count($histIds) . ' academic_history row(s), '
   . $gradeCount . ' academic_grades row(s)' . PHP_EOL;

// ----------------------------------------------------------------------------
//  CHILD ROWS - DISCOVERED, NOT GUESSED
// ----------------------------------------------------------------------------
//
//  Every (table, column) here holds a student id we are about to delete.
//  Deleting these first is what lets the parent rows go. A hardcoded list is
//  what made an earlier version of the e2e cleanup fail on audit_logs.user_id.
$fkRows = $db->fetchAll(
    "SELECT TABLE_NAME t, COLUMN_NAME c
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME = 'students'
        AND REFERENCED_COLUMN_NAME = 'id'
      ORDER BY TABLE_NAME, COLUMN_NAME"
);

$skip = ['users' => true];   // handled below, and differently
$plan = [];
foreach ($fkRows as $fk) {
    if (isset($skip[$fk['t']])) {
        continue;
    }
    $n = $db->fetchOne("SELECT COUNT(*) c FROM `{$fk['t']}` WHERE `{$fk['c']}` IN ($in)");
    if ((int) ($n['c'] ?? 0) > 0) {
        $plan[] = ['t' => $fk['t'], 'c' => $fk['c'], 'n' => (int) $n['c']];
    }
}

foreach ($plan as $p) {
    echo "  {$p['n']} row(s) in {$p['t']}.{$p['c']}" . PHP_EOL;
}

$userLinks = $db->fetchOne("SELECT COUNT(*) c FROM users WHERE student_id IN ($in)");
echo '  ' . (int) ($userLinks['c'] ?? 0) . ' user account(s) linked (unlinked, not deleted)'
   . PHP_EOL, PHP_EOL;

// ----------------------------------------------------------------------------
//  DO IT
// ----------------------------------------------------------------------------
if (!$execute) {
    echo '  Dry run. Nothing was deleted.' . PHP_EOL;
    echo '  Re-run with --execute to remove these rows.', PHP_EOL, PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    // Children first. users.student_id is NULL-ed rather than deleted through:
    // the account may own audit rows and queue work that has nothing to do
    // with this seed.
    $db->query("UPDATE users SET student_id = NULL WHERE student_id IN ($in)");

    foreach ($plan as $p) {
        $db->query("DELETE FROM `{$p['t']}` WHERE `{$p['c']}` IN ($in)");
    }

    if ($histIds) {
        $db->query("DELETE FROM academic_grades WHERE academic_history_id IN ($histIn)");
        $db->query("DELETE FROM academic_history WHERE id IN ($histIn)");
    }
    $db->query("DELETE FROM students WHERE id IN ($in)");
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, '  FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo '  Removed ' . count($studentIds) . ' student(s), '
   . count($histIds) . ' history row(s), ' . $gradeCount . ' grade row(s).'
   . PHP_EOL, PHP_EOL;
