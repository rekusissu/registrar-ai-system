-- ============================================================
--  REGISTER BENJIE DUQUE -- idempotent user seed
--  Adds the developer login account for Benjie Duque.
--  Safe to re-run: INSERT ... SELECT ... WHERE NOT EXISTS.
--  Also applies an additive repair the live `users` table
--  needs for the Phase-5 login path (matches the shipped
--  dump + code expectations):
--    1. users.student_id (NULL by default; used by
--       resolveLoginUser(), api/users.php, registrar/users.php)
--  Apply with:
--     mysql -u root registrar_ai < database/register_benjie_duque.sql
-- ============================================================

USE registrar_ai;

-- 1) Add users.student_id (NULL by default; used by login + user links)
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'student_id');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users ADD COLUMN student_id INT(11) DEFAULT NULL, ADD KEY idx_users_student_id (student_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Benjie Duque account: benjieduque@bestlink.edu.ph / benjieduque / role registrar
SET @next_id := (SELECT COALESCE(MAX(id), 0) + 1 FROM users);
SET @already := (SELECT COUNT(*) FROM users WHERE email = 'benjieduque@bestlink.edu.ph');
SET @sql := IF(@already = 0,
  CONCAT("INSERT INTO users (id, email, username, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (",
         @next_id,
         ", 'benjieduque@bestlink.edu.ph', 'benjieduque', '$2y$10$PBEKNT13tam3a8ctAMQuteomzT11lJTIhaJ.e5dDVJYqDvX7f1w6G', 'Benjie Duque', 'registrar', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
