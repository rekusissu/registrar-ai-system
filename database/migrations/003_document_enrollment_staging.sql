-- ============================================================
--  database/migrations/003_document_enrollment_staging.sql
--  Digital File Storage - enrollment-number staging.
--  Documents can be received BEFORE the student is enrolled:
--  they are stored under their enrollment number and linked to
--  the student record once the student is accepted.
--  Staged documents older than 30 days are flagged abandoned.
--
--  Idempotent. Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

-- 1) Allow documents without a student (staged by enrollment number).
SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'enroll_no');
SET @s1 := IF(@c1 = 0,
  'ALTER TABLE `documents` ADD COLUMN `enroll_no` VARCHAR(20) DEFAULT NULL AFTER `student_id`,
   ADD KEY `idx_documents_enroll_no` (`enroll_no`)',
  'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'enroll_status');
SET @s2 := IF(@c2 = 0,
  'ALTER TABLE `documents` ADD COLUMN `enroll_status` VARCHAR(20) DEFAULT NULL AFTER `enroll_no`',
  'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- 3) Relax student_id to allow NULL for staged docs (MariaDB-safe).
SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'student_id' AND IS_NULLABLE = 'YES');
SET @s3 := IF(@c3 = 0,
  'ALTER TABLE `documents` MODIFY `student_id` INT(11) DEFAULT NULL',
  'SELECT 1');
PREPARE st3 FROM @s3; EXECUTE st3; DEALLOCATE PREPARE st3;