-- ============================================================
--  UPDATE_STUDENT_NUMBERS.SQL
--  Migrate student numbers to 9-digit format starting at 100000001
--  Apply with:  mysql -u root registrar_ai < update_student_numbers.sql
-- ============================================================

USE registrar_ai;

-- Step 1: Backup existing student numbers to a temp column
ALTER TABLE `students` ADD COLUMN `student_number_old` varchar(20) DEFAULT NULL;
UPDATE `students` SET `student_number_old` = `student_number`;

-- Step 2: Update student numbers to 9-digit format (100000001, 100000002, etc.)
-- This uses the existing auto_increment ID as the basis
UPDATE `students`
SET `student_number` = LPAD(100000000 + `id`, 9, '0')
WHERE `student_number` IS NOT NULL;

-- Step 3: Verify the update
SELECT id, student_number_old, student_number FROM `students`;

-- Optional: Drop the backup column after verification (uncomment when ready)
-- ALTER TABLE `students` DROP COLUMN `student_number_old`;
