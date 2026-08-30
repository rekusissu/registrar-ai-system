-- ============================================================
--  database/contact_change_requests.sql
--  Registrar-authoritative contacts — student change-request queue.
--
--  contact_change_requests : the queue of change requests a student
--    submits from the (read-only) student portal. The Registrar
--    approves or rejects each one from registrar/guardians.php.
--    Approving APPLIES the proposed change to the real table it
--    targets:
--      contact_type = 'guardian'  → guardians
--      contact_type = 'emergency' → emergency_contacts
--      contact_type = 'email'     → contact_recipients
--
--    `payload` holds a JSON object of the PROPOSED fields. For
--    update/remove, `target_id` is the id of the row in the target
--    table; for add it is NULL.
--
--    Timestamps are written by PHP (Asia/Manila wall clock) — never
--    MySQL NOW() (the server runs UTC; see the mysql-timezone-skew
--    note).
--
--  Re-runnable (CREATE TABLE IF NOT EXISTS). Targets MariaDB 10.4+.
--  Requires the students table (registrar_ai.sql).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `contact_change_requests` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `student_id`  INT NOT NULL,
  `contact_type` VARCHAR(10) NOT NULL,      -- guardian | emergency | email
  `request_type` VARCHAR(10) NOT NULL,      -- add | update | remove
  `target_id`   INT DEFAULT NULL,           -- row being updated/removed (NULL for add)
  `payload`     TEXT NOT NULL,              -- JSON of proposed fields
  `reason`      VARCHAR(500) DEFAULT NULL,  -- student's note to the Registrar
  `status`      VARCHAR(10) NOT NULL DEFAULT 'pending', -- pending | approved | rejected
  `review_note` VARCHAR(500) DEFAULT NULL,  -- Registrar's note on approve/reject
  `created_at`  DATETIME NOT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ccr_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
