-- ============================================================
--  CLINIC PORTAL — HEALTH RECORD LOG  (migration)
--  Adds the nurse role and the clinic-visit fields used by the
--  Health Record Log Clinic Portal, and exposes the new columns
--  on the existing `health_visits` timeline.
--
--  Apply with:  php run_clinic_migration.php
--          or:  mysql -u root registrar_ai < clinic_portal.sql
--  Safe/idempotent: every statement guards on column/table
--  existence, so re-running will not error.
-- ============================================================

USE registrar_ai;

-- ────────────────────────────────────────────────────────────
-- 1) Add the `nurse` role to users.role enum
-- ────────────────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                  AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%nurse%');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users MODIFY COLUMN role ENUM(''admin'',''registrar'',''staff'',''teacher'',''student'',''nurse'') NOT NULL DEFAULT ''staff''',
  'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────────────────
-- 2) Ensure `health_visits` exists (base) before adding columns
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `health_visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `visit_date` date DEFAULT NULL,
  `complaint` varchar(255) DEFAULT NULL,
  `diagnosis` varchar(255) DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `blood_pressure` varchar(12) DEFAULT NULL,
  `treatment` varchar(255) DEFAULT NULL,
  `medication` text DEFAULT NULL,
  `physician` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `fk_visit_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- date_time: exact date & time of the clinic visit (logged on save)
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'date_time');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN date_time datetime DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reason_for_visit: dropdown value or free text ("Other")
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'reason_for_visit');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN reason_for_visit varchar(255) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- assessment: nurse's assessment / initial diagnosis
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'assessment');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN assessment varchar(255) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- action_taken: intervention performed
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'action_taken');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN action_taken varchar(255) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- nurse_notes: free-form notes entered by the nurse
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'nurse_notes');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN nurse_notes text DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- record_status: visibility/sync status shown on both portals
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'record_status');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN record_status enum(''Recorded'',''Pending'',''Cancelled'') NOT NULL DEFAULT ''Recorded''', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- recorded_by: users.id who logged the visit (logical FK)
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'recorded_by');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN recorded_by int(11) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- index for fast per-nurse lookups
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND INDEX_NAME = 'idx_recorded_by');
SET @sql := IF(@idx = 0, 'ALTER TABLE health_visits ADD INDEX idx_recorded_by (recorded_by)', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- ────────────────────────────────────────────────────────────
-- 4) Medical profile + vitals columns (denormalized per visit)
--    profile fields come from the student's latest visit
-- ────────────────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'blood_type');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN blood_type varchar(5) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'allergies');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN allergies text DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'height');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN height decimal(5,2) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'weight');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN weight decimal(5,2) DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'pre_existing_conditions');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN pre_existing_conditions text DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_visits' AND COLUMN_NAME = 'immunization_records');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_visits ADD COLUMN immunization_records text DEFAULT NULL', 'SET @dummy = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;