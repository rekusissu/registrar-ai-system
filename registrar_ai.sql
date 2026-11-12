-- BCP Registrar: consolidated deployment database, 2026-11-13.
-- FRESH INSTALL / REPLACE ONLY: drops and recreates the listed application tables.
-- Existing hosted records: use migrations/deployment_upgrade_20261113.sql instead.
-- Keeps only the requested owner account as super_admin; its existing password hash is preserved.
-- Includes document catalog, departments and requirement definitions. No demo users/students,
-- grades, requests, files, queue activity, login tokens, caches or operational audit history.
-- Uploads are stored separately on disk. No database name/CREATE DATABASE required by hosting.
SET NAMES utf8mb4;
SET @bundle_old_fk=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `academic_grades`;
CREATE TABLE `academic_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_history_id` int(11) NOT NULL,
  `subject` varchar(120) NOT NULL,
  `units` decimal(4,2) DEFAULT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `remarks` varchar(40) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject_code` varchar(30) DEFAULT NULL,
  `subject_type` varchar(30) DEFAULT NULL,
  `prerequisite` varchar(120) DEFAULT NULL,
  `instructor` varchar(100) DEFAULT NULL,
  `schedule` varchar(120) DEFAULT NULL,
  `room` varchar(50) DEFAULT NULL,
  `semester_taken` varchar(20) DEFAULT NULL,
  `midterm_grade` varchar(10) DEFAULT NULL,
  `final_grade` varchar(10) DEFAULT NULL,
  `final_rating` varchar(10) DEFAULT NULL,
  `grade_status` enum('passed','failed','incomplete','dropped') DEFAULT NULL,
  `source_system` varchar(32) NOT NULL DEFAULT 'faculty',
  `source_ref` varchar(64) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `term_status` varchar(20) DEFAULT NULL,
  `instructor_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ag_source` (`academic_history_id`,`source_system`,`source_ref`),
  KEY `idx_academic_history_id` (`academic_history_id`),
  KEY `idx_ag_faculty` (`faculty_id`),
  CONSTRAINT `fk_grade_academy` FOREIGN KEY (`academic_history_id`) REFERENCES `academic_history` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `academic_history`;
CREATE TABLE `academic_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `school_name` varchar(100) NOT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `grade_level` varchar(20) DEFAULT NULL,
  `gwa` decimal(5,2) DEFAULT NULL,
  `subjects_completed` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `semester` varchar(20) DEFAULT NULL,
  `credits` decimal(6,2) DEFAULT NULL,
  `gwa_reported` decimal(5,2) DEFAULT NULL,
  `gwa_computed` decimal(5,2) DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `accepted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `academic_history_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `academic_validations`;
CREATE TABLE `academic_validations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `academic_history_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `review_status` enum('reviewed','needs_correction','incomplete') NOT NULL,
  `remarks` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_validation_record` (`academic_history_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `ai_cache`;
CREATE TABLE `ai_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prompt_hash` varchar(64) NOT NULL,
  `prompt` text NOT NULL,
  `response` text NOT NULL,
  `model` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prompt_hash` (`prompt_hash`),
  KEY `idx_prompt_hash` (`prompt_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `card_readers`;
CREATE TABLE `card_readers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `reader_type` enum('entrance','exit','both') NOT NULL DEFAULT 'both',
  `reader_code` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reader_code` (`reader_code`),
  KEY `idx_status` (`status`),
  KEY `idx_reader_type` (`reader_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `communication_log`;
CREATE TABLE `communication_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `recipient_name` varchar(100) DEFAULT NULL,
  `message_type` varchar(20) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sent',
  `ref` varchar(100) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_type_status` (`message_type`,`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `contact_change_requests`;
CREATE TABLE `contact_change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `contact_type` varchar(10) NOT NULL,
  `request_type` varchar(10) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `payload` text NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `review_note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ccr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `contact_recipients`;
CREATE TABLE `contact_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `relationship` varchar(40) NOT NULL DEFAULT 'parent',
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `send_billing` tinyint(1) NOT NULL DEFAULT 0,
  `send_grades` tinyint(1) NOT NULL DEFAULT 0,
  `send_emergency` tinyint(1) NOT NULL DEFAULT 0,
  `auth_token` varchar(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_emailed` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_email` (`student_id`,`email`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `fk_contact_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_code` (`code`),
  KEY `idx_department_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `discipline_records`;
CREATE TABLE `discipline_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `recorded_at` date DEFAULT NULL COMMENT 'Date the case was filed',
  `nature` varchar(255) DEFAULT NULL COMMENT 'Nature of the case',
  `resolution` varchar(100) DEFAULT NULL COMMENT 'Penalty imposed, if any',
  `status` enum('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_discipline_student` (`student_id`,`status`),
  KEY `fk_discipline_by` (`recorded_by`),
  CONSTRAINT `fk_discipline_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_discipline_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `document_ai_audit`;
CREATE TABLE `document_ai_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `input_summary` text DEFAULT NULL,
  `result` text DEFAULT NULL,
  `confidence` decimal(3,2) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_daa_document` (`document_id`),
  KEY `idx_daa_student` (`student_id`),
  KEY `idx_daa_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `document_catalog`;
CREATE TABLE `document_catalog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `base_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sla_days` int(11) DEFAULT NULL COMMENT 'Target turnaround in days for this document',
  `fee_type` enum('flat','per_page','per_syllabus') NOT NULL DEFAULT 'flat',
  `requirement` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `document_request_attachments`;
CREATE TABLE `document_request_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `requirement_code` varchar(40) DEFAULT NULL COMMENT 'Checklist item, or NULL for a loose file',
  `original_name` varchar(180) NOT NULL COMMENT 'As uploaded; display only, never a path',
  `file_path` varchar(255) NOT NULL COMMENT 'Relative to repo root',
  `sha256` char(64) NOT NULL COMMENT 'Proves the bytes are unaltered',
  `mime_type` varchar(80) DEFAULT NULL,
  `size_bytes` int(11) NOT NULL DEFAULT 0,
  `uploaded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_request` (`request_id`),
  KEY `idx_att_req_code` (`request_id`,`requirement_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `document_request_events`;
CREATE TABLE `document_request_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `status` varchar(40) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_request` (`request_id`),
  CONSTRAINT `fk_events_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `document_requests`;
CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` varchar(24) DEFAULT NULL,
  `source` enum('walk_in','online') NOT NULL DEFAULT 'walk_in',
  `walkin_at` datetime DEFAULT NULL COMMENT 'Walked in at the counter',
  `walkin_by` int(11) DEFAULT NULL COMMENT 'Registrar who took the request',
  `counter` tinyint(3) NOT NULL DEFAULT 1 COMMENT 'Releasing counter (1-3)',
  `released_by` int(11) DEFAULT NULL COMMENT 'Registrar who released the document',
  `record_file_path` varchar(255) DEFAULT NULL COMMENT 'Retained signed record copy',
  `record_file_sha256` varchar(64) DEFAULT NULL,
  `record_file_generated_at` datetime DEFAULT NULL,
  `record_copies_issued` int(11) NOT NULL DEFAULT 0,
  `student_id` int(11) NOT NULL,
  `document_type` enum('form137','good_moral','transcript','certificate','clearance','diploma','ctc','honorable_dismissal','course_description') NOT NULL,
  `catalog_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `request_type` enum('Express','Regular') NOT NULL DEFAULT 'Regular',
  `fulfillment_type` enum('Pickup','Delivery','Digital') NOT NULL DEFAULT 'Pickup',
  `delivery_address` text DEFAULT NULL,
  `payment_method` enum('Online','Bank_Transfer','Cash_on_Delivery','Counter') NOT NULL DEFAULT 'Counter',
  `purpose` varchar(255) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `status` enum('pending','processing','approved','denied','completed','released') DEFAULT 'pending',
  `document_status` enum('Draft','Filed','Pending_Clearance','Awaiting_Payment','Processing','Ready','Shipped','Claimed','Rejected','Cancelled') NOT NULL DEFAULT 'Filed',
  `blocked_reason` varchar(160) DEFAULT NULL COMMENT 'What this request is waiting on, if anything',
  `blocked_since` datetime DEFAULT NULL COMMENT 'When the current blockage began',
  `blocked_source` varchar(16) DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `approval_reason` varchar(255) DEFAULT NULL,
  `qr_hash` varchar(64) DEFAULT NULL,
  `requirement_file_path` varchar(255) DEFAULT NULL,
  `payment_ref` varchar(40) DEFAULT NULL,
  `lalamove_order_ref` varchar(40) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `pdf_fingerprint` varchar(64) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `denial_reason` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_date` datetime DEFAULT NULL,
  `completed_date` datetime DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT 0.00,
  `delivery_fee` decimal(10,2) DEFAULT NULL,
  `official_receipt` varchar(40) DEFAULT NULL,
  `release_date` datetime DEFAULT NULL,
  `payment_receipt_path` varchar(255) DEFAULT NULL COMMENT 'Relative path to the uploaded GCash receipt image/PDF',
  `payment_receipt_sha256` char(64) DEFAULT NULL COMMENT 'SHA-256 of the receipt at upload; proves the file is unaltered',
  `payment_receipt_filename` varchar(180) DEFAULT NULL COMMENT 'Original filename as uploaded, for display only',
  `payment_receipt_uploaded_at` datetime DEFAULT NULL COMMENT 'When the student attached the receipt',
  `payment_receipt_ref` varchar(40) DEFAULT NULL COMMENT 'GCash reference number as printed on the receipt',
  `payment_receipt_verified_at` datetime DEFAULT NULL COMMENT 'When staff confirmed the receipt matches the request',
  `payment_receipt_verified_by` int(11) DEFAULT NULL COMMENT 'users.id of the staff member who verified the receipt',
  `payment_receipt_waived_at` datetime DEFAULT NULL COMMENT 'When staff accepted the request WITHOUT a receipt',
  `payment_receipt_waived_by` int(11) DEFAULT NULL COMMENT 'users.id of the staff member who waived the receipt requirement',
  `payment_receipt_waived_reason` varchar(255) DEFAULT NULL COMMENT 'Why the receipt requirement was waived — required, never blank',
  `pickup_notified_at` datetime DEFAULT NULL COMMENT 'When the pickup/availability email was successfully sent',
  `pickup_notified_to` varchar(255) DEFAULT NULL COMMENT 'Recipients the pickup email actually reached',
  `pickup_notify_error` varchar(255) DEFAULT NULL COMMENT 'Why the pickup email could not be sent; NULL when it sent',
  `requirements_reviewed_at` datetime DEFAULT NULL,
  `requirements_reviewed_by` int(11) DEFAULT NULL,
  `requirements_review_note` text DEFAULT NULL,
  `wizard_step` tinyint(3) NOT NULL DEFAULT 0 COMMENT 'Furthest wizard step reached: 0 none, 1..4 (drafts only)',
  `notes` text DEFAULT NULL COMMENT 'Additional notes the student typed, free text',
  `purpose_code` varchar(40) DEFAULT NULL COMMENT 'Controlled purpose: employment|board_exam|further_studies|transfer|other',
  `submitted_at` datetime DEFAULT NULL COMMENT 'When the student completed the wizard and submitted',
  `estimated_release_at` datetime DEFAULT NULL COMMENT 'Promised release date, computed once at filing',
  `payment_reference` varchar(24) DEFAULT NULL COMMENT 'Payment reference quoted to the student (OTC / bank)',
  `cancelled_at` datetime DEFAULT NULL COMMENT 'When the student withdrew the request',
  `cancellation_reason` varchar(255) DEFAULT NULL COMMENT 'Why the student withdrew it',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_id` (`request_id`),
  UNIQUE KEY `uq_qr_hash` (`qr_hash`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_document_type` (`document_type`),
  KEY `idx_catalog_id` (`catalog_id`),
  KEY `idx_document_status` (`document_status`),
  KEY `idx_request_type` (`request_type`),
  KEY `idx_fulfillment_type` (`fulfillment_type`),
  KEY `fk_document_requests_walkin_by` (`walkin_by`),
  KEY `fk_document_requests_released_by` (`released_by`),
  CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_document_requests_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `document_catalog` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_requests_released_by` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_requests_walkin_by` FOREIGN KEY (`walkin_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `document_type_requirements`;
CREATE TABLE `document_type_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `catalog_id` int(11) NOT NULL,
  `code` varchar(40) NOT NULL COMMENT 'Stable key, e.g. library_clearance',
  `label` varchar(160) NOT NULL COMMENT 'What the student sees',
  `hint` varchar(255) DEFAULT NULL COMMENT 'Format guidance, e.g. "white background"',
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `max_files` tinyint(3) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_code` (`catalog_id`,`code`),
  KEY `idx_catalog_active` (`catalog_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `enroll_no` varchar(20) DEFAULT NULL,
  `enroll_status` varchar(20) DEFAULT NULL,
  `doc_type` enum('enrollment','transcript','health','photo','clearance','other','form_137','psa') NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_sha256` varchar(64) DEFAULT NULL COMMENT 'SHA-256 of the stored file, printed on a CTC',
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` varchar(40) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  `file_hash` varchar(64) DEFAULT NULL,
  `content_text` text DEFAULT NULL,
  `ai_classified` tinyint(1) DEFAULT 0,
  `ai_confidence` decimal(3,2) DEFAULT NULL,
  `ai_valid` tinyint(1) DEFAULT NULL,
  `ai_validation_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_doc_type` (`doc_type`),
  KEY `idx_documents_enroll_no` (`enroll_no`),
  KEY `idx_documents_file_hash` (`file_hash`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `emergency_contacts`;
CREATE TABLE `emergency_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `fk_emergency_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `enrollment_history`;
CREATE TABLE `enrollment_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `student_number` varchar(20) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'enrolled',
  `enrolled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enrollment_history_student` (`student_id`),
  CONSTRAINT `fk_eh_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `enrollments`;
CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `name_suffix` varchar(10) DEFAULT NULL,
  `student_number` varchar(20) DEFAULT NULL COMMENT 'Optional during application; assigned at accept',
  `birth_date` date DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `place_of_birth` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `prev_school_name` varchar(100) DEFAULT NULL,
  `prev_school_last_year` varchar(20) DEFAULT NULL,
  `prev_school_graduated_sy` varchar(20) DEFAULT NULL,
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_relationship` varchar(50) DEFAULT NULL,
  `emergency_contact` varchar(20) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `major` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `status` enum('pending','received','re-enrolled','duplicate') NOT NULL DEFAULT 'pending',
  `received_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enrollments_status` (`status`),
  KEY `idx_enrollments_student_number` (`student_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `finance`;
CREATE TABLE `finance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finance_student` (`student_id`),
  CONSTRAINT `fk_finance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `guardian_contact_attempts`;
CREATE TABLE `guardian_contact_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `contact_type` enum('guardian','emergency') NOT NULL,
  `contact_id` int(11) NOT NULL,
  `contact_name` varchar(200) NOT NULL,
  `channel` enum('call','email') NOT NULL,
  `occasion` varchar(100) NOT NULL,
  `outcome` enum('reached','no_answer','left_message','email_sent','failed') NOT NULL,
  `notes` text NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_student` (`student_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `guardians`;
CREATE TABLE `guardians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `relationship` enum('father','mother','guardian','spouse','sibling') NOT NULL,
  `contact_number` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `is_emergency` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_contact` (`contact_number`),
  CONSTRAINT `guardians_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `id_card_details`;
CREATE TABLE `id_card_details` (
  `rfid_card_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `details_json` text NOT NULL,
  `reason` varchar(255) NOT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`rfid_card_id`),
  KEY `idx_id_details_student` (`student_id`),
  KEY `fk_id_details_user` (`updated_by`),
  CONSTRAINT `fk_id_details_card` FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_id_details_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_id_details_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_ip_time` (`email`,`ip_address`,`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `mock_lalamove_orders`;
CREATE TABLE `mock_lalamove_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` varchar(40) NOT NULL,
  `quotation_id` varchar(40) DEFAULT NULL,
  `request_id` int(11) DEFAULT NULL,
  `pickup` varchar(255) DEFAULT NULL,
  `dropoff` varchar(255) DEFAULT NULL,
  `item` varchar(100) DEFAULT NULL,
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `distance_km` decimal(6,2) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'ASSIGNING_RIDER',
  `tracking_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lala_order_id` (`order_id`),
  KEY `idx_lala_request` (`request_id`),
  CONSTRAINT `fk_lala_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `mock_payment_transactions`;
CREATE TABLE `mock_payment_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(40) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'PHP',
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `method` varchar(20) NOT NULL DEFAULT 'Online',
  `due_on` enum('now','delivery') NOT NULL DEFAULT 'now',
  `gateway` varchar(20) NOT NULL DEFAULT 'mock',
  `paymongo_intent_id` varchar(40) DEFAULT NULL,
  `paymongo_payment_id` varchar(40) DEFAULT NULL,
  `payment_url` varchar(255) DEFAULT NULL,
  `callback_url` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `raw_request` text DEFAULT NULL,
  `raw_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_txn_id` (`transaction_id`),
  UNIQUE KEY `uq_txn_paymongo_intent` (`paymongo_intent_id`),
  KEY `idx_txn_request` (`request_id`),
  KEY `idx_txn_gateway` (`gateway`),
  KEY `idx_txn_paymongo_payment` (`paymongo_payment_id`),
  CONSTRAINT `fk_txn_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `purpose` enum('login','reset') NOT NULL DEFAULT 'login',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verify_attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`),
  KEY `idx_otp_purpose` (`user_id`,`purpose`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `password_reset_grants`;
CREATE TABLE `password_reset_grants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grant_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_grant_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `queue_day_settings`;
CREATE TABLE `queue_day_settings` (
  `queue_date` date NOT NULL,
  `opens_time` time NOT NULL DEFAULT '08:00:00',
  `closes_time` time NOT NULL DEFAULT '17:00:00',
  `cutoff_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `cutoff_forced_at` datetime DEFAULT NULL,
  `cutoff_forced_by` int(10) unsigned DEFAULT NULL,
  `max_taps_student` int(10) unsigned NOT NULL DEFAULT 0,
  `max_taps_priority` int(10) unsigned NOT NULL DEFAULT 0,
  `max_daily_taps` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`queue_date`),
  KEY `idx_qds_forced_by` (`cutoff_forced_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `queue_tickets`;
CREATE TABLE `queue_tickets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `queue_date` date NOT NULL,
  `ticket_number` int(10) unsigned NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `student_name` varchar(191) NOT NULL,
  `student_number` varchar(50) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `status` enum('waiting','serving','completed','no-show','removed','cancelled') NOT NULL DEFAULT 'waiting',
  `counter` int(10) unsigned NOT NULL DEFAULT 1,
  `txn_type` enum('service','claim') NOT NULL DEFAULT 'service',
  `priority_group` enum('student','priority') NOT NULL DEFAULT 'student',
  `purpose` enum('general','document_request','payment','enrollment') NOT NULL DEFAULT 'general',
  `document_request_id` int(11) DEFAULT NULL COMMENT 'Document request this visit relates to',
  `card_uid` varchar(50) DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `called_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_day` (`queue_date`,`ticket_number`),
  KEY `idx_queue_date_status` (`queue_date`,`status`),
  KEY `idx_student` (`student_id`),
  KEY `idx_joined_at` (`joined_at`),
  KEY `idx_queue_purpose` (`queue_date`,`purpose`),
  KEY `idx_queue_lane` (`queue_date`,`status`,`txn_type`,`priority_group`),
  KEY `fk_queue_document_request` (`document_request_id`),
  CONSTRAINT `fk_queue_document_request` FOREIGN KEY (`document_request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_queue_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `report_exports`;
CREATE TABLE `report_exports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `actor_name` varchar(100) NOT NULL,
  `actor_role` varchar(40) NOT NULL,
  `report_name` varchar(160) NOT NULL,
  `format` varchar(20) NOT NULL,
  `resource` text NOT NULL,
  `status` varchar(30) NOT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `session_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `initiated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_export_token` (`token_hash`),
  KEY `idx_export_actor` (`user_id`,`created_at`),
  KEY `idx_export_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `rfid_cards`;
CREATE TABLE `rfid_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `card_uid` varchar(50) NOT NULL,
  `card_type` enum('rfid','qrcode') DEFAULT 'rfid',
  `status` enum('active','inactive','lost','expired','available','archived') DEFAULT 'available',
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `registered_at` timestamp NULL DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_uid` (`card_uid`),
  KEY `student_id` (`student_id`),
  KEY `idx_card_uid` (`card_uid`),
  KEY `idx_status` (`status`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_available` (`status`,`student_id`),
  CONSTRAINT `rfid_cards_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `rfid_scan_logs`;
CREATE TABLE `rfid_scan_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_uid` varchar(50) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT 'Main Gate',
  `event_type` enum('entry','exit','library','cafeteria','clinic','other','queue_join','queue_call','queue_serving','queue_completed','queue_no_show','queue_cancelled') DEFAULT 'entry',
  `status` enum('success','denied','unknown') DEFAULT 'success',
  `scanner_id` varchar(50) DEFAULT 'scanner-01',
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_card_uid` (`card_uid`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_scanned_at` (`scanned_at`),
  KEY `idx_location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `staff_notification_reads`;
CREATE TABLE `staff_notification_reads` (
  `user_id` int(11) NOT NULL,
  `last_read_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `status_tracker`;
CREATE TABLE `status_tracker` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `current_status` varchar(50) NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `effective_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `changed_by` (`changed_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_current_status` (`current_status`),
  KEY `idx_student_end_date` (`student_id`,`end_date`),
  CONSTRAINT `status_tracker_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `status_tracker_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `student_ids`;
CREATE TABLE `student_ids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `id_number` varchar(20) DEFAULT '' COMMENT 'Enrollment department ID - assigned later',
  `id_type` enum('school_id','library','cafeteria') DEFAULT 'school_id',
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','inactive','lost') DEFAULT 'active',
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `rfid_card_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qr_payload` varchar(255) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `card_color` varchar(20) DEFAULT 'blue',
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `idx_id_number` (`id_number`),
  KEY `idx_status` (`status`),
  KEY `idx_student_ids_rfid_card_id` (`rfid_card_id`),
  CONSTRAINT `fk_student_ids_rfid_card` FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_ids_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `student_notifications`;
CREATE TABLE `student_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `related_doc_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sn_student` (`student_id`),
  KEY `idx_sn_read` (`is_read`),
  CONSTRAINT `fk_sn_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_number` varchar(20) DEFAULT '' COMMENT 'Enrollment department ID -- assigned later',
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated') DEFAULT NULL,
  `birth_date` date NOT NULL,
  `place_of_birth` varchar(100) DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `address` text NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `major` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `status` enum('enrolled','active','graduate','alumni','dropped') NOT NULL DEFAULT 'enrolled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lrn` varchar(12) DEFAULT NULL COMMENT 'LRN - Learner Reference Number',
  `name_suffix` varchar(10) DEFAULT NULL COMMENT 'e.g. Jr., III, Sr.',
  `mother_name` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `birth_country` varchar(60) DEFAULT NULL,
  `graduation_date` date DEFAULT NULL COMMENT 'Date the degree was conferred',
  `previous_school` varchar(150) DEFAULT NULL COMMENT 'School last attended before this one',
  `school_year_graduated` varchar(20) DEFAULT NULL,
  `last_year_level_completed` varchar(30) DEFAULT NULL,
  `email_bounced_at` datetime DEFAULT NULL,
  `email_bounce_reason` varchar(255) DEFAULT NULL,
  `email_is_placeholder` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_student_number` (`student_number`),
  KEY `idx_status` (`status`),
  KEY `idx_course` (`course`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `user_program_assignments`;
CREATE TABLE `user_program_assignments` (
  `user_id` int(11) NOT NULL,
  `program_code` varchar(30) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`program_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','registrar_admin','staff','acad_officer','department_head','student') NOT NULL DEFAULT 'staff',
  `rfid_uid` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `email_bounced_at` datetime DEFAULT NULL,
  `email_bounce_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_users_department` (`department_id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Business/reference configuration
INSERT INTO `departments` (`id`,`name`,`code`,`is_active`,`created_at`) VALUES
('1','College of Engineering','COE','1','2026-01-01 00:00:00');
INSERT INTO `departments` (`id`,`name`,`code`,`is_active`,`created_at`) VALUES
('2','College of Nursing','CON','1','2026-01-01 00:00:00');
INSERT INTO `departments` (`id`,`name`,`code`,`is_active`,`created_at`) VALUES
('3','College of Business','COB','1','2026-01-01 00:00:00');
INSERT INTO `departments` (`id`,`name`,`code`,`is_active`,`created_at`) VALUES
('4','College of Education','CED','1','2026-01-01 00:00:00');
INSERT INTO `departments` (`id`,`name`,`code`,`is_active`,`created_at`) VALUES
('5','College of IT','CIT','1','2026-01-01 00:00:00');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('13','DOC-COE','Certificate of Enrollment','Proof of current enrollment','100.00','1','flat',NULL,'1','2026-11-10 18:52:56');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('14','DOC-TOR','Transcript of Records','Complete academic record (TOR)','250.00','3','per_page','Scanned copy of valid ID','1','2026-11-10 18:52:56');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('15','DOC-GM','Certificate of Good Moral','Good moral character certificate','150.00','3','flat','No pending disciplinary cases','1','2026-11-10 18:52:56');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('16','DOC-DIPLOMA','Diploma Replacement','Replacement of lost diploma','1000.00','5','flat','Notarized Affidavit of Loss','1','2026-11-10 18:52:56');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('17','DOC-CTC','Certified True Copy','Certified true copy of a record','50.00','2','per_page',NULL,'1','2026-11-10 18:52:56');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('18','DOC-HD','Honorable Dismissal','Transfer / honorable dismissal','300.00','10','flat',NULL,'1','2026-11-10 18:52:56');
INSERT INTO `document_catalog` (`id`,`sku`,`name`,`description`,`base_fee`,`sla_days`,`fee_type`,`requirement`,`is_active`,`created_at`) VALUES
('19','DOC-CD','Course Description','Subject syllabus / course description','100.00','1','per_syllabus',NULL,'1','2026-11-10 18:52:56');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('1','13','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('2','14','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('3','15','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('4','16','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('5','17','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('6','18','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('7','19','school_id','Valid School ID','Front side, name and number clearly readable','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('8','13','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('9','14','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('10','15','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('11','16','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('12','17','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('13','18','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('14','19','photo_2x2','2x2 Photo (white background)','Recent, plain background, no hat or sunglasses','1','1','2','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('15','16','library_clearance','Library Clearance','Signed and stamped by the Library','0','1','3','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('16','18','library_clearance','Library Clearance','Signed and stamped by the Library','0','1','3','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('17','14','library_clearance','Library Clearance','Signed and stamped by the Library','0','1','3','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('18','16','accounting_clearance','Accounting Clearance','Signed and stamped by the Accounting Office','0','1','4','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('19','14','accounting_clearance','Accounting Clearance','Signed and stamped by the Accounting Office','0','1','4','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('21','18','dean_signature','Dean\'s Office Endorsement','The original endorsement letter, not a photo of a photo','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('22','16','affidavit_loss','Affidavit of Loss','Signed in front of a notary public, with a valid ID','1','1','1','1','2026-11-13 00:52:40');
INSERT INTO `document_type_requirements` (`id`,`catalog_id`,`code`,`label`,`hint`,`is_required`,`max_files`,`sort_order`,`is_active`,`created_at`) VALUES
('23','18','affidavit_loss','Affidavit of Loss','Signed in front of a notary public, with a valid ID','1','1','1','1','2026-11-13 00:52:40');

-- Owner account (no plaintext password)
INSERT INTO `users` (`id`,`email`,`password_hash`,`full_name`,`role`,`rfid_uid`,`is_active`,`created_at`,`updated_at`,`student_id`,`department_id`,`username`,`login_attempts`,`locked_until`,`password_changed_at`,`email_bounced_at`,`email_bounce_reason`) VALUES
('3','roldantiu89@gmail.com','$2y$10$goIPlSwZLw21GfQLYBsoxOCwqaO86NGUssooNsG4FHGOi3IbFb6aO','Roldan Tiu','super_admin',NULL,'1','2026-07-07 14:42:45','2026-11-11 18:27:25',NULL,NULL,'ADM-002','0',NULL,NULL,NULL,NULL);

SET FOREIGN_KEY_CHECKS=@bundle_old_fk;
COMMIT;
