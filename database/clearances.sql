-- ============================================================
--  CLEARANCES — clearance workflow table (Phase E)
--  Dedicated per-student clearance status, distinct from
--  document_requests (which handles requested documents).
--  Idempotent: guards on table existence. Safe to re-run.
--  Apply with:  mysql -u root registrar_ai < clearances.sql
-- ============================================================

USE registrar_ai;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'clearances');
SET @sql := IF(@tbl = 0, 'CREATE TABLE clearances (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT(11) NOT NULL,
    status ENUM(''pending'',''partial'',''cleared'') NOT NULL DEFAULT ''pending'',
    issued_by INT(11) DEFAULT NULL,
    issued_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clearance_student (student_id),
    KEY idx_clearance_status (status),
    CONSTRAINT fk_clearance_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_clearance_issued_by FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
