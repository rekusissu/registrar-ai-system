-- -------------------------------------------------------------------------
--  ROLE RESTRUCTURE: admin/registrar/staff/teacher/student
--  → super_admin/staff/acad_officer/department_head/student
-- -------------------------------------------------------------------------
--
--  What changes
--  ------------
--    1. Add `department_id` column to `users` (nullable FK → departments.id).
--       Department Heads are scoped to their own department.
--    2. Widen `users.role` enum to the new five roles.
--    3. Migrate existing rows:
--       admin     → super_admin
--       registrar → staff
--       staff     → staff
--       teacher   → acad_officer
--       student   → student (unchanged)
--
--  Idempotent: safe to run multiple times.
-- -------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. departments table (ensure it exists) ──────────────────────────────
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_code` (`code`),
  KEY `idx_department_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed default departments if empty (idempotent: only inserts when table is empty)
INSERT INTO `departments` (`id`, `name`, `code`, `is_active`, `created_at`)
SELECT * FROM (SELECT 1, 'College of Engineering', 'COE', 1, '2026-01-01 00:00:00') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `departments`);
INSERT INTO `departments` (`id`, `name`, `code`, `is_active`, `created_at`)
SELECT * FROM (SELECT 2, 'College of Nursing', 'CON', 1, '2026-01-01 00:00:00') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `id` = 2);
INSERT INTO `departments` (`id`, `name`, `code`, `is_active`, `created_at`)
SELECT * FROM (SELECT 3, 'College of Business', 'COB', 1, '2026-01-01 00:00:00') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `id` = 3);
INSERT INTO `departments` (`id`, `name`, `code`, `is_active`, `created_at`)
SELECT * FROM (SELECT 4, 'College of Education', 'CED', 1, '2026-01-01 00:00:00') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `id` = 4);
INSERT INTO `departments` (`id`, `name`, `code`, `is_active`, `created_at`)
SELECT * FROM (SELECT 5, 'College of IT', 'CIT', 1, '2026-01-01 00:00:00') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `id` = 5);

-- ── 2. Add department_id to users ────────────────────────────────────────
SET @colExists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'department_id'
);
SET @sql = IF(@colExists = 0,
  'ALTER TABLE `users` ADD COLUMN `department_id` int(11) DEFAULT NULL AFTER `student_id`, ADD KEY `idx_users_department` (`department_id`), ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL',
  'SELECT ''department_id already exists'' AS msg'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 3. Widen the role ENUM ───────────────────────────────────────────────
-- First, migrate any rows that hold old role values the new ENUM won't accept.
UPDATE `users` SET `role` = 'super_admin' WHERE `role` = 'admin';
UPDATE `users` SET `role` = 'staff' WHERE `role` = 'registrar';
UPDATE `users` SET `role` = 'acad_officer' WHERE `role` = 'teacher';
-- staff and student stay as-is.

ALTER TABLE `users`
  MODIFY COLUMN `role` enum('super_admin','staff','acad_officer','department_head','student') NOT NULL DEFAULT 'staff';

-- ── 4. Verify ────────────────────────────────────────────────────────────
--   SELECT role, COUNT(*) FROM users GROUP BY role;
--   SELECT * FROM users WHERE role NOT IN ('super_admin','staff','acad_officer','department_head','student');

SET FOREIGN_KEY_CHECKS = 1;