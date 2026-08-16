-- ============================================================
--  STUDENT_PORTAL.SQL — Student Portal (Role-Based Login)
--  Adds:
--    1. users.student_id  FK → students.id (ON DELETE SET NULL)
--    2. users.role ENUM  + 'student'
--    3. announcements table (admin posts, student portal view)
--  Safe/idempotent: every statement guards on column/table/index
--  existence, so re-running will not error.
--  Apply with:  mysql -u root registrar_ai < student_portal.sql
-- ============================================================

USE registrar_ai;

-- ────────────────────────────────────────────────
-- 1) users.student_id  → students.id
--    Explicit link between a login account and a
--    student record. ON DELETE SET NULL so deleting
--    a student never breaks the login account.
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'student_id');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users ADD COLUMN student_id INT(11) DEFAULT NULL,
                     ADD KEY idx_student_id (student_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK constraint (only when the column exists and the FK does not)
SET @exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = 'registrar_ai'
                  AND TABLE_NAME = 'users'
                  AND CONSTRAINT_NAME = 'fk_users_student'
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_student
     FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 2) users.role ENUM  + 'student'
--    ALTER ... MODIFY on an indexed enum column can
--    error on MariaDB (index column size). Safest path:
--    check the current type; only alter when 'student'
--    is absent; drop idx_email first if present, then
--    re-add it after the modify.
-- ────────────────────────────────────────────────
SET @coltype := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'registrar_ai'
                   AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');
SET @hasStudent := IF(@coltype IS NOT NULL, LOCATE('student', @coltype), 0);

-- Drop the redundant unique email index (kept idx_email for lookups)
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND INDEX_NAME = 'email');
SET @sql := IF(@idx > 0 AND @hasStudent = 0,
  'ALTER TABLE users DROP INDEX email',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Alter the enum (only when 'student' is missing)
SET @sql := IF(@hasStudent = 0,
  'ALTER TABLE users MODIFY role enum(''admin'',''registrar'',''staff'',''teacher'',''student'') NOT NULL DEFAULT ''staff''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Re-add the unique email index if we dropped it
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND INDEX_NAME = 'email');
SET @sql := IF(@idx = 0 AND @hasStudent = 0,
  'ALTER TABLE users ADD UNIQUE KEY email (email)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 3) announcements table
--    Admin / registrar compose posts; students read.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'announcements');
SET @sql := IF(@tbl = 0, 'CREATE TABLE announcements (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body TEXT,
  author_id INT(11) DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY idx_author (author_id),
  KEY idx_published (is_published, created_at),
  CONSTRAINT fk_announcement_author FOREIGN KEY (author_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Done. Verify with:
--   SHOW COLUMNS FROM users;                       -- student_id present
--   SHOW COLUMNS FROM users LIKE 'role';           -- enum includes 'student'
--   SHOW TABLES LIKE 'announcements';              -- table present
