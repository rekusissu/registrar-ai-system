<?php
// Generate migrations/unseed_100_full.sql - the exact inverse of the seed.
//
//    php tests/gen_seed_sql.php
require_once __DIR__ . '/../shared/config.php';
$target = __DIR__ . '/../migrations/unseed_100_full.sql';

$sql = <<<'SQL'
-- ============================================================================
--  MIGRATIONS/UNSEED_100_FULL.SQL
--
--  Removes everything migrations/seed_100_full.sql created.
--
--    mysql -u root registrar_ai < migrations/unseed_100_full.sql
--
--  HOW ROWS ARE IDENTIFIED
--  -----------------------
--  Two independent markers, and a row must carry BOTH to be deleted:
--
--    student_number LIKE 'S26%'         the number marker
--    email LIKE '%@testdata.example'    the address marker
--
--  One marker alone is not enough. student_number carries only a non-unique
--  index, so two unrelated records can share one and a real student could
--  eventually be issued an S26xxxxx number. Requiring both means this file
--  can only ever remove what the seed actually wrote.
--
--  Nothing outside those markers is touched. Real students, real accounts and
--  any real document request survive.
--
--  ORDER: children before parents.
--  ---------------------------------
--  academic_grades and document_requests have no declared foreign keys today,
--  which is exactly why this order is not left to chance - the deletes still
--  resolve through the student_id chain rather than assuming a cascade exists.
--  Delete the students first and their grades are orphaned: rows the Academic
--  History board will happily render as a cohort nobody can place.
--
--  The SELECTs at the top print what is about to go. Read them. A rollback that
--  prints 100 and a rollback that prints 1,000,000 are both "successful", and
--  only the first one is the file working.
-- ============================================================================

START TRANSACTION;

-- ── What is about to go ──────────────────────────────────────────
SELECT 'students' AS what, COUNT(*) AS n
    FROM students
    WHERE student_number LIKE 'S26%' AND email LIKE '%@testdata.example'
UNION ALL SELECT 'portal accounts', COUNT(*)
    FROM users u JOIN students s ON s.id = u.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'term records', COUNT(*)
    FROM academic_history h JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'subject grades', COUNT(*)
    FROM academic_grades g
      JOIN academic_history h ON h.id = g.academic_history_id
      JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'document requests', COUNT(*)
    FROM document_requests d JOIN students s ON s.id = d.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example';

-- ── Children first ─────────────────────────────────────────────
-- Grades hang off a term record, so they go before it.
DELETE g FROM academic_grades g
  JOIN academic_history h ON h.id = g.academic_history_id
  JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example';

DELETE h FROM academic_history h
  JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example';

DELETE d FROM document_requests d
  JOIN students s ON s.id = d.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example';

-- The portal account goes with its student: users.student_id is how the
-- login resolver finds a student credential in the first place.
DELETE u FROM users u
  JOIN students s ON s.id = u.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example';

-- Parent last.
DELETE FROM students
    WHERE student_number LIKE 'S26%' AND email LIKE '%@testdata.example';

COMMIT;

-- ── What is left ───────────────────────────────────────────────
-- All five should read 0. Anything else means something in the file
-- did not match what the seed wrote.
SELECT 'students still present' AS what, COUNT(*) AS n
    FROM students
    WHERE student_number LIKE 'S26%' AND email LIKE '%@testdata.example'
UNION ALL SELECT 'term records still present', COUNT(*)
    FROM academic_history h JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'document requests still present', COUNT(*)
    FROM document_requests d JOIN students s ON s.id = d.student_id
    WHERE s.student_number LIKE 'S26%' AND s.email LIKE '%@testdata.example';
SQL;

file_put_contents($target, $sql . "\n");
printf("Wrote migrations/unseed_100_full.sql (%s bytes)\n", number_format(strlen($sql)));