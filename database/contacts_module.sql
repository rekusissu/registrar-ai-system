-- ============================================================
--  database/contacts_module.sql
--  Emergency & Contacts module — email recipients + audit trail.
--
--  contact_recipients : a student's email contacts (parent / guardian
--    / sponsor / emergency / other) with per-contact permission flags
--    (send_billing, send_grades, send_emergency), a one-time auth token
--    for the "Test Email → Confirm Receipt" verification link, and the
--    last-emailed timestamp. `verified` flips to 1 when the recipient
--    clicks the confirm link.
--
--  communication_log  : audit trail of every message sent to a contact
--    (test / invoice / snapshot / transcript / emergency / resend),
--    mirroring the existing activity-log convention so the Registrar
--    can answer "I never received the bill" disputes.
--
--  Timestamps are written by PHP (Asia/Manila wall clock) — never
--  MySQL NOW() (the server runs UTC; see the mysql-timezone-skew note).
--
--  Re-runnable (CREATE TABLE IF NOT EXISTS). Targets MariaDB 10.4+.
--  Requires the students table (registrar_ai.sql).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `contact_recipients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `relationship` VARCHAR(40) NOT NULL DEFAULT 'parent', -- parent|guardian|sponsor|emergency|other
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `send_billing` TINYINT(1) NOT NULL DEFAULT 0,
  `send_grades` TINYINT(1) NOT NULL DEFAULT 0,
  `send_emergency` TINYINT(1) NOT NULL DEFAULT 0,
  `auth_token` VARCHAR(64) DEFAULT NULL,
  `token_expires_at` DATETIME DEFAULT NULL,
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `last_emailed` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_contact_email` (`student_id`,`email`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `fk_contact_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `communication_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `contact_id` INT DEFAULT NULL,
  `recipient_email` VARCHAR(190) NOT NULL,
  `recipient_name` VARCHAR(100) DEFAULT NULL,
  `message_type` VARCHAR(20) NOT NULL, -- test|invoice|snapshot|transcript|emergency|resend
  `subject` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'sent', -- sent|failed|verified
  `ref` VARCHAR(100) DEFAULT NULL,       -- e.g. DOC-2026-0001 for invoices
  `detail` TEXT DEFAULT NULL,
  `sent_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_student` (`student_id`),
  KEY `idx_type_status` (`message_type`,`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
