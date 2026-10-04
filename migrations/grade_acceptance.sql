-- ============================================================
--  MIGRATIONS/GRADE_ACCEPTANCE.SQL
--  Registrar accepts a section's term, or waits for the grades.
--
--  Faculty Management #296 owns the grades; the Registrar reads them and
--  is responsible for accepting a section's term before it counts. A term
--  is acceptable only when every student on the roster has a grade for
--  every subject recorded against them.
--
--  WHY THESE COLUMNS LIVE ON academic_history AND NOT ON academic_grades.
--  The already-existing academic_grades.term_status describes a SUBJECT
--  row. Acceptance is a fact about a TERM, and it is shared by every
--  subject under it - storing it per subject would mean one fact written
--  N times that could disagree with itself, which is exactly the failure
--  gwa_reported/gwa_computed was introduced to avoid. It sits on the term
--  row beside those two figures, which are also term-level.
--
--  Idempotent: safe to re-run. Guarded per column, because the mysql client
--  stops at the first error and one guard over two ALTERs aborts on a
--  half-run migration, skipping everything after it. Same reason and same
--  shape as migrations/grades_faculty_source.sql.
-- ============================================================

-- ── 1. Who accepted, and when ───────────────────────────────────
-- accepted_at is null until an acceptance actually happens. That null IS
-- the waitlist: a term nobody has accepted reads as outstanding, so no
-- separate "is_accepted" flag is needed and the two cannot drift.
-- accepted_by is the users.id of the registrar who clicked accept; it is
-- kept beside the timestamp rather than inferred, because "who signed
-- this off" must stay answerable after the fact.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'accepted_at');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `accepted_at` timestamp NULL DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'accepted_by');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `accepted_by` int(11) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- No foreign key on accepted_by. The same reasoning as
-- migrations/restore_student_section.sql: users.student_id is ON DELETE
-- SET NULL, and a registrar account being removed must not take an
-- acceptance record with it - the term WAS accepted, and losing that
-- would quietly reopen a closed term.
