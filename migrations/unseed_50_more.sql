-- ============================================================================
--  MIGRATIONS/UNSEED_50_MORE.SQL
--
--    mysql -u root registrar_ai < migrations/unseed_50_more.sql
--    phpMyAdmin: Import > choose this file.
--
--  NO TRANSACTION ON PURPOSE
--  ------------------------
--  phpMyAdmin wraps an import in a transaction. A second START TRANSACTION
--  raises MySQL 1568 and phpMyAdmin aborts the entire file with a generic
--  "import could not be completed". phpMyAdmin commits for us; the mysql
--  client commits by default. Nothing is lost by leaving it alone.
--
--  TWO MARKERS, BOTH REQUIRED
--  -----------------------
--    students     student_number LIKE 'S27%'   AND  email LIKE '%@testdata.example'
--    enrollments  email LIKE '%@seed.receive.test'
--
--  One marker is not enough. student_number carries only a non-unique index,
--  so a real student could eventually be issued an S27xxxx number.
--  Removes everything migrations/seed_50_more.sql created.
--
--  Nothing outside the two markers is touched. Batch one, the real students
--  and any real enrollment all survive.
--
--  ORDER: children before parents. academic_grades has no declared foreign
--  key today, which is exactly why this order is not left to chance.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- ── What is about to go ──────────────────────────────────────────
SELECT 'students' AS what, COUNT(*) AS n
    FROM students
 WHERE student_number LIKE 'S27%' AND email LIKE '%@testdata.example'
UNION ALL SELECT 'portal accounts', COUNT(*)
    FROM users u JOIN students s ON s.id = u.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'term records', COUNT(*)
    FROM academic_history h JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'subject grades', COUNT(*)
    FROM academic_grades g
      JOIN academic_history h ON h.id = g.academic_history_id
      JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'applicants', COUNT(*) FROM enrollments WHERE email LIKE '%@seed.receive.test';

-- ── Children first ─────────────────────────────────────────────
DELETE g FROM academic_grades g
  JOIN academic_history h ON h.id = g.academic_history_id
  JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example';

DELETE h FROM academic_history h
  JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example';

DELETE u FROM users u
  JOIN students s ON s.id = u.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example';

DELETE FROM students WHERE student_number LIKE 'S27%' AND email LIKE '%@testdata.example';

-- Applicants reference nothing we created, so they go on their own.
DELETE FROM enrollments WHERE email LIKE '%@seed.receive.test';

SET FOREIGN_KEY_CHECKS = 1;

-- ── What is left ───────────────────────────────────────────────
-- All four should read 0.
SELECT 'students still present' AS what, COUNT(*) AS n
    FROM students
 WHERE student_number LIKE 'S27%' AND email LIKE '%@testdata.example'
UNION ALL SELECT 'term records still present', COUNT(*)
    FROM academic_history h JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example'
UNION ALL SELECT 'applicants still present', COUNT(*)
    FROM enrollments WHERE email LIKE '%@seed.receive.test';
