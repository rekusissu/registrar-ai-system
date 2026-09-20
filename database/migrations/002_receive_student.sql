-- ============================================================
--  database/migrations/002_receive_student.sql
--  Enrollment intake pipeline for the Registrar portal.
--
--  Idempotent migration. Applied exactly once per database by
--  database/migrate.php (run automatically on every Docker boot by the
--  entrypoint, and safe to run manually for non-Docker deploys):
--
--    mysql -u root registrar_ai < database/migrations/002_receive_student.sql
--
--  Applies:
--    1. enrollments        — incoming applicants from the (internal mock)
--                            Enrollment System, waiting for registrar intake.
--    2. enrollment_history — auditable per-term enrollment history for
--                            returning students (re-enroll retains the
--                            original student number).
--    3. Seed sample applicant(s) so the Registrar has rows to receive.
-- ============================================================

SET NAMES utf8mb4;

-- 1. Enrollment intake — pending applicants.
CREATE TABLE IF NOT EXISTS `enrollments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  -- Identity (matches the registrar students form)
  `first_name`      VARCHAR(50)  NOT NULL,
  `middle_name`     VARCHAR(50)  DEFAULT NULL,
  `last_name`       VARCHAR(50)  NOT NULL,
  `name_suffix`     VARCHAR(10)  DEFAULT NULL,
  `student_number`  VARCHAR(20)  DEFAULT NULL COMMENT 'Optional during application; assigned at accept',
  -- Personal details
  `birth_date`      DATE         DEFAULT NULL,
  `gender`          ENUM('Male','Female') DEFAULT NULL,
  `civil_status`    VARCHAR(20)  DEFAULT NULL,
  `religion`        VARCHAR(50)  DEFAULT NULL,
  `nationality`     VARCHAR(50)  DEFAULT NULL,
  `place_of_birth`  VARCHAR(100) DEFAULT NULL,
  `father_name`     VARCHAR(100) DEFAULT NULL,
  `mother_name`     VARCHAR(100) DEFAULT NULL,
  -- Contact information
  `email`           VARCHAR(100) DEFAULT NULL,
  `address`         TEXT         DEFAULT NULL,
  `contact_number`  VARCHAR(20)  DEFAULT NULL,
  -- Previous school
  `prev_school_name`        VARCHAR(100) DEFAULT NULL,
  `prev_school_last_year`   VARCHAR(20)  DEFAULT NULL,
  `prev_school_graduated_sy` VARCHAR(20) DEFAULT NULL,
  -- Emergency contact
  `emergency_name`         VARCHAR(100) DEFAULT NULL,
  `emergency_relationship` VARCHAR(50)  DEFAULT NULL,
  `emergency_contact`      VARCHAR(20)  DEFAULT NULL,
  -- Intended enrollment
  `course`      VARCHAR(100) DEFAULT NULL,
  `major`       VARCHAR(100) DEFAULT NULL,
  `year_level`  INT          DEFAULT NULL,
  `school_year` VARCHAR(20)  DEFAULT NULL,
  `semester`    VARCHAR(20)  DEFAULT NULL,
  `section`     VARCHAR(20)  DEFAULT NULL,
  -- Workflow
  `status`      ENUM('pending','received','re-enrolled','duplicate') NOT NULL DEFAULT 'pending',
  `received_at`  DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_enrollments_status` (`status`),
  KEY `idx_enrollments_student_number` (`student_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Per-term enrollment history for returning (re-enrolled) students.
CREATE TABLE IF NOT EXISTS `enrollment_history` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `student_id`    INT NOT NULL,
  `student_number` VARCHAR(20) DEFAULT NULL,
  `course`        VARCHAR(100) DEFAULT NULL,
  `year_level`    INT DEFAULT NULL,
  `school_year`   VARCHAR(20) DEFAULT NULL,
  `semester`      VARCHAR(20) DEFAULT NULL,
  `section`       VARCHAR(20) DEFAULT NULL,
  `status`        VARCHAR(20) DEFAULT 'enrolled',
  `enrolled_at`   DATETIME DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_enrollment_history_student` (`student_id`),
  CONSTRAINT `fk_eh_student` FOREIGN KEY (`student_id`)
    REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Seed sample applicant(s). Runs a no-op when a matching applicant
--    already exists, so the migration stays idempotent on re-run.
INSERT INTO `enrollments`
  (first_name, middle_name, last_name, name_suffix, birth_date, gender, civil_status,
   religion, nationality, place_of_birth, father_name, mother_name,
   email, address, contact_number,
   prev_school_name, prev_school_last_year, prev_school_graduated_sy,
   emergency_name, emergency_relationship, emergency_contact,
   course, major, year_level, school_year, semester, section, status)
SELECT * FROM (SELECT
  'Maria' AS first_name, 'Santos' AS middle_name, 'Reyes' AS last_name, 'Jr.' AS name_suffix,
  '2006-04-12' AS birth_date, 'Female' AS gender, 'Single' AS civil_status,
  'Roman Catholic' AS religion, 'Filipino' AS nationality, 'Quezon City' AS place_of_birth,
  'Carlos Reyes' AS father_name, 'Luz Reyes' AS mother_name,
  'maria.reyes@example.com' AS email, '123 Commonwealth Ave, QC' AS address, '09171112233' AS contact_number,
  'Quezon City Science HS' AS prev_school_name, 'Grade 12' AS prev_school_last_year, '2025-2026' AS prev_school_graduated_sy,
  'Teresa Reyes' AS emergency_name, 'Mother' AS emergency_relationship, '09178889900' AS emergency_contact,
  'BSIT' AS course, NULL AS major, '1' AS year_level, '2026-2027' AS school_year, '1st' AS semester, NULL AS section, 'pending' AS status) t
WHERE NOT EXISTS (SELECT 1 FROM enrollments WHERE first_name='Maria' AND last_name='Reyes');