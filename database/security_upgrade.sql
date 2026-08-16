-- ============================================================
--  SECURITY_UPGRADE.SQL — Phase 5: Cybersecurity + Portal enrichment
--  Adds:
--    1. users.username            (staff login ID, UNIQUE)
--    2. users.login_attempts      (failed-attempt counter)
--    3. users.locked_until        (10-min lockout timestamp)
--    4. otp_codes table           (hashed one-time codes for login/reset)
--    5. students.status enum    + 'enrolled'   (additive)
--    6. queue_tickets.status enum+ 'cancelled' (additive)
--    7. academic_grades           + rich subject/grade columns
--    8. health_records            + medical_history / surgical_history
--  Safe/idempotent: every statement guards on column/table/index
--  existence, so re-running will not error.
--  Apply with:  mysql -u root registrar_ai < security_upgrade.sql
-- ============================================================

USE registrar_ai;

-- ────────────────────────────────────────────────
-- 1) users.username  (staff login ID, UNIQUE)
--    Student rows keep this NULL — students log in
--    with their students.student_number instead.
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users ADD COLUMN username VARCHAR(60) DEFAULT NULL, ADD UNIQUE KEY uq_users_username (username)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 2) users.login_attempts  (failed-attempt counter)
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'login_attempts');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users ADD COLUMN login_attempts INT NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 3) users.locked_until  (10-min lockout timestamp; NULL = not locked)
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 4) otp_codes table
--    Hashed one-time codes. purpose: login | reset.
--    Used once, expires after 5 minutes.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'otp_codes');
SET @sql := IF(@tbl = 0, 'CREATE TABLE otp_codes (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT(11) NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  purpose ENUM(''login'',''reset'') NOT NULL DEFAULT ''login'',
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  KEY idx_otp_user (user_id),
  KEY idx_otp_purpose (user_id, purpose),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 5) students.status enum  + 'enrolled'  (additive)
--    Keeps all existing values; adds the canonical Enrolled.
-- ────────────────────────────────────────────────
SET @coltype := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'registrar_ai'
                   AND TABLE_NAME = 'students' AND COLUMN_NAME = 'status');
SET @hasEnrolled := IF(@coltype IS NOT NULL, LOCATE('enrolled', @coltype), 0);
SET @sql := IF(@hasEnrolled = 0,
  'ALTER TABLE students MODIFY status enum(''active'',''probation'',''at-risk'',''loa'',''enrolled'',''graduated'',''transferred'',''dropped'') DEFAULT ''enrolled''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 6) queue_tickets.status enum + 'cancelled' (additive)
-- ────────────────────────────────────────────────
SET @coltype := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'registrar_ai'
                   AND TABLE_NAME = 'queue_tickets' AND COLUMN_NAME = 'status');
SET @hasCancelled := IF(@coltype IS NOT NULL, LOCATE('cancelled', @coltype), 0);
SET @sql := IF(@hasCancelled = 0,
  'ALTER TABLE queue_tickets MODIFY status enum(''waiting'',''serving'',''completed'',''no-show'',''removed'',''cancelled'') NOT NULL DEFAULT ''waiting''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 7) academic_grades — rich subject / grade columns
--    All nullable so existing rows (name/units/grade/remarks)
--    keep working; new fields are best-effort.
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'subject_code');
SET @sql := IF(@exists = 0, 'ALTER TABLE academic_grades
  ADD COLUMN subject_code VARCHAR(30) DEFAULT NULL,
  ADD COLUMN subject_type VARCHAR(30) DEFAULT NULL,
  ADD COLUMN prerequisite VARCHAR(120) DEFAULT NULL,
  ADD COLUMN instructor VARCHAR(100) DEFAULT NULL,
  ADD COLUMN schedule VARCHAR(120) DEFAULT NULL,
  ADD COLUMN room VARCHAR(50) DEFAULT NULL,
  ADD COLUMN semester_taken VARCHAR(20) DEFAULT NULL,
  ADD COLUMN midterm_grade VARCHAR(10) DEFAULT NULL,
  ADD COLUMN final_grade VARCHAR(10) DEFAULT NULL,
  ADD COLUMN final_rating VARCHAR(10) DEFAULT NULL,
  ADD COLUMN grade_status ENUM(''passed'',''failed'',''incomplete'',''dropped'') DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 8) health_records — medical / surgical history
--    pre_existing_conditions doubles as "Current Medical Conditions".
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'health_records' AND COLUMN_NAME = 'medical_history');
SET @sql := IF(@exists = 0,
  'ALTER TABLE health_records
    ADD COLUMN medical_history TEXT DEFAULT NULL,
    ADD COLUMN surgical_history TEXT DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 9) Seed usernames for the known staff accounts
--    (only where username is still NULL). Students get their
--    student_number resolution instead, so they stay untouched.
-- ────────────────────────────────────────────────
UPDATE users
   SET username = CASE email
        WHEN 'admin@bestlink.edu.ph'     THEN 'ADM-001'
        WHEN 'registrar@bestlink.edu.ph' THEN 'RGS-001'
        WHEN 'roldantiu89@gmail.com'     THEN 'ADM-002'
        ELSE username END
 WHERE username IS NULL AND email IN
       ('admin@bestlink.edu.ph','registrar@bestlink.edu.ph','roldantiu89@gmail.com');

-- Done. Verify with:
--   SHOW COLUMNS FROM users;                  -- username / login_attempts / locked_until
--   SHOW TABLES LIKE 'otp_codes';             -- table present
--   SHOW COLUMNS FROM students LIKE 'status'; -- enum includes 'enrolled'
--   SHOW COLUMNS FROM queue_tickets LIKE 'status'; -- enum includes 'cancelled'
--   SHOW COLUMNS FROM academic_grades;        -- rich subject/grade columns
--   SHOW COLUMNS FROM health_records;         -- medical/surgical history
