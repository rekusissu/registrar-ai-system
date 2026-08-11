-- ============================================================
--  QUEUE_SYSTEM.SQL — Queue Management System (Phase: Queue)
--  Dedicated per-ticket queue rows for the RFID kiosk tap-in
--  flow, live monitor, and registrar serving console.
--  Idempotent: guards on table existence. Safe to re-run.
--  Apply with:  mysql -u root registrar_ai < queue_system.sql
-- ============================================================

USE registrar_ai;

-- ── 1. queue_tickets table ───────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'queue_tickets');
SET @sql := IF(@tbl = 0, 'CREATE TABLE queue_tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    queue_date DATE NOT NULL,
    ticket_number INT UNSIGNED NOT NULL,
    student_id INT(11) DEFAULT NULL,
    student_name VARCHAR(191) NOT NULL,
    student_number VARCHAR(50) DEFAULT NULL,
    course VARCHAR(100) DEFAULT NULL,
    status ENUM(''waiting'',''serving'',''completed'',''no-show'',''removed'') NOT NULL DEFAULT ''waiting'',
    counter INT UNSIGNED NOT NULL DEFAULT 1,
    card_uid VARCHAR(50) DEFAULT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    called_at DATETIME NULL DEFAULT NULL,
    served_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_ticket_day (queue_date, ticket_number),
    KEY idx_queue_date_status (queue_date, status),
    KEY idx_student (student_id),
    KEY idx_joined_at (joined_at),
    CONSTRAINT fk_queue_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Extend rfid_scan_logs.event_type with 'queue_join' ────
--    (idempotent: only ALTERs when 'queue_join' is absent)
SET @coltype := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'registrar_ai'
                   AND TABLE_NAME = 'rfid_scan_logs'
                   AND COLUMN_NAME = 'event_type');
SET @sql := IF(@coltype IS NOT NULL AND LOCATE('queue_join', @coltype) = 0,
    'ALTER TABLE rfid_scan_logs
     MODIFY COLUMN event_type
     enum(''entry'',''exit'',''library'',''cafeteria'',''other'',''queue_join'')
     DEFAULT ''entry''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;