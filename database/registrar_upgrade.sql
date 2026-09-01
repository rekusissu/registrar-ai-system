-- ============================================================
--  REGISTRAR UPGRADE — PHASE 1  (migration for the 10 subsystems)
--  Apply with:  mysql -u root registrar_ai < registrar_upgrade.sql
--  Safe/idempotent: every statement guards on column/table existence,
--  so re-running will not error. Tested against MariaDB 10.4.
-- ============================================================

USE registrar_ai;

-- ────────────────────────────────────────────────
-- 1) SUBSYSTEM 1 — PERSONAL INFO DATABASE
--    Extend `students` with DepEd/Form 137 fields.
-- ────────────────────────────────────────────────
SET @col := 'lrn';
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'students' AND COLUMN_NAME = @col);
SET @sql := IF(@exists = 0,
  'ALTER TABLE students ADD COLUMN lrn varchar(12) DEFAULT NULL COMMENT ''LRN - Learner Reference Number''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'students' AND COLUMN_NAME = 'name_suffix');
SET @sql := IF(@exists = 0,
  'ALTER TABLE students ADD COLUMN name_suffix varchar(10) DEFAULT NULL COMMENT ''e.g. Jr., III, Sr.''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'students' AND COLUMN_NAME = 'mother_name');
SET @sql := IF(@exists = 0,
  'ALTER TABLE students ADD COLUMN mother_name varchar(100) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'students' AND COLUMN_NAME = 'father_name');
SET @sql := IF(@exists = 0,
  'ALTER TABLE students ADD COLUMN father_name varchar(100) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'students' AND COLUMN_NAME = 'birth_country');
SET @sql := IF(@exists = 0,
  'ALTER TABLE students ADD COLUMN birth_country varchar(60) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 2) SUBSYSTEM 2 — GUARDIAN & EMERGENCY CONTACT
--    New dedicated emergency_contacts table (separate from guardians).
--    guardians table already supports is_primary / is_emergency.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'emergency_contacts');
SET @sql := IF(@tbl = 0, 'CREATE TABLE emergency_contacts (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT(11) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    relationship VARCHAR(50) DEFAULT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    KEY idx_student_id (student_id),
    CONSTRAINT fk_emergency_student FOREIGN KEY (student_id)
      REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 3) SUBSYSTEM 3 — ACADEMIC HISTORY (Form 137 grading)
--    New per-subject grades table under academic_history.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'academic_grades');
SET @sql := IF(@tbl = 0, 'CREATE TABLE IF NOT EXISTS academic_grades (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  academic_history_id INT(11) NOT NULL,
  subject VARCHAR(120) NOT NULL,
  units DECIMAL(4,2) DEFAULT NULL,
  grade VARCHAR(10) DEFAULT NULL,
  remarks VARCHAR(40) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  KEY idx_academic_history_id (academic_history_id),
  CONSTRAINT fk_grade_academy FOREIGN KEY (academic_history_id)
    REFERENCES academic_history(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add quarter / remarks columns to academic_history for Form 137 rendering
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'semester');
SET @sql := IF(@exists = 0,
  'ALTER TABLE academic_history ADD COLUMN semester varchar(20) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'credits');
SET @sql := IF(@exists = 0,
  'ALTER TABLE academic_history ADD COLUMN credits DECIMAL(6,2) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 4) SUBSYSTEM 4 — HEALTH RECORD LOG
--    New `health_visits` timeline + extended health_records columns.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'health_visits');
SET @sql := IF(@tbl = 0, 'CREATE TABLE IF NOT EXISTS health_visits (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT(11) NOT NULL,
  visit_date DATE DEFAULT NULL,
  complaint VARCHAR(255) DEFAULT NULL,
  diagnosis VARCHAR(255) DEFAULT NULL,
  temperature DECIMAL(4,1) DEFAULT NULL,
  blood_pressure VARCHAR(12) DEFAULT NULL,
  treatment VARCHAR(255) DEFAULT NULL,
  medication TEXT DEFAULT NULL,
  physician VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  KEY idx_student_id (student_id),
  CONSTRAINT fk_visit_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'health_records' AND COLUMN_NAME = 'blood_pressure');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_records ADD COLUMN blood_pressure varchar(12) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'health_records' AND COLUMN_NAME = 'dietary_restrictions');
SET @sql := IF(@exists = 0, 'ALTER TABLE health_records ADD COLUMN dietary_restrictions text DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 5) SUBSYSTEM 5 — RFID / QR CODE INTEGRATION
--    Add QR code payload + type to rfid_cards (cards can be QR-issued).
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'rfid_cards' AND COLUMN_NAME = 'qr_code_path');
SET @sql := IF(@exists = 0,
  'ALTER TABLE rfid_cards ADD COLUMN qr_code_path varchar(255) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'rfid_cards' AND COLUMN_NAME = 'issued_at');
SET @sql := IF(@exists = 0, 'ALTER TABLE rfid_cards ADD COLUMN issued_at timestamp DEFAULT current_timestamp()', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 6) SUBSYSTEM 6 — STUDENT ID GENERATION
--    Add QR payload + issue metadata + card layout to student_ids.
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'student_ids' AND COLUMN_NAME = 'qr_payload');
SET @sql := IF(@exists = 0, 'ALTER TABLE student_ids ADD COLUMN qr_payload varchar(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'student_ids' AND COLUMN_NAME = 'school_year');
SET @sql := IF(@exists = 0, 'ALTER TABLE student_ids ADD COLUMN school_year varchar(20) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'student_ids' AND COLUMN_NAME = 'card_color');
SET @sql := IF(@exists = 0, 'ALTER TABLE student_ids ADD COLUMN card_color varchar(20) DEFAULT ''blue''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 7) SUBSYSTEM 7 — DOCUMENT REQUESTS
--    Add fee / official-receipt / release fields to document_requests.
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'fee_amount');
SET @sql := IF(@exists = 0, 'ALTER TABLE document_requests ADD COLUMN fee_amount DECIMAL(10,2) DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'official_receipt');
SET @sql := IF(@exists = 0, 'ALTER TABLE document_requests ADD COLUMN official_receipt varchar(40) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'release_date');
SET @sql := IF(@exists = 0, 'ALTER TABLE document_requests ADD COLUMN release_date datetime DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 8) SUBSYSTEM 8 — STUDENT STATUS TRACKER
--    Create status_tracker table if it doesn't exist.
--    Add effective dates to status_tracker for LOA / transfers.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'status_tracker');
SET @sql := IF(@tbl = 0, 'CREATE TABLE status_tracker (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT(11) NOT NULL,
  previous_status VARCHAR(50) DEFAULT NULL,
  current_status VARCHAR(50) NOT NULL,
  reason TEXT DEFAULT NULL,
  changed_by INT(11) DEFAULT NULL,
  effective_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY idx_student_id (student_id),
  KEY idx_created_at (created_at),
  CONSTRAINT fk_status_tracker_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_status_tracker_user FOREIGN KEY (changed_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'status_tracker' AND COLUMN_NAME = 'effective_date');
SET @sql := IF(@exists = 0, 'ALTER TABLE status_tracker ADD COLUMN effective_date DATE DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'status_tracker' AND COLUMN_NAME = 'end_date');
SET @sql := IF(@exists = 0, 'ALTER TABLE status_tracker ADD COLUMN end_date DATE DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 9) SUBSYSTEM 9 — DIGITAL FILE STORAGE
--    documents table already exists (doc_type enrollment/transcript/
--    health/photo/clearance/other). Add category + access flag.
-- ────────────────────────────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'category');
SET @sql := IF(@exists = 0, 'ALTER TABLE documents ADD COLUMN category varchar(40) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'is_locked');
SET @sql := IF(@exists = 0, 'ALTER TABLE documents ADD COLUMN is_locked TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ────────────────────────────────────────────────
-- 10) SUBSYSTEM 10 — MASTERLIST GENERATION
--     masterlist_cache exists; no schema change. Done.
-- ────────────────────────────────────────────────

-- ────────────────────────────────────────────────
-- 11) RFID CARD READERS (scanning stations registry)
--     Table for physical reader stations (Main Gate, Library, Lab, etc).
--     Referenced by api/card-readers.php, registrar/rfid-readers.php,
--     registrar/rfid-kiosk.php and api/rfid-scan.php.
-- ────────────────────────────────────────────────
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'registrar_ai' AND TABLE_NAME = 'card_readers');
SET @sql := IF(@tbl = 0, 'CREATE TABLE card_readers (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  location VARCHAR(100) DEFAULT NULL,
  reader_type ENUM(''entrance'',''exit'',''both'') DEFAULT ''both'',
  reader_code VARCHAR(50) DEFAULT NULL,
  status ENUM(''active'',''inactive'') DEFAULT ''active'',
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed the default scanning stations (idempotent: only when table is empty)
SET @cnt := (SELECT COUNT(*) FROM card_readers);
SET @sql := IF(@cnt = 0, 'INSERT INTO card_readers (name, location, reader_type, reader_code, status) VALUES
  (''Main Gate'', ''Main Gate'', ''entrance'', ''gate-01'', ''active''),
  (''Library'', ''Library'', ''both'', ''lib-01'', ''active''),
  (''CS Lab'', ''CS Laboratory'', ''entrance'', ''lab-01'', ''active'')', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Done. Verify with:  SHOW TABLES LIKE 'health_visits';