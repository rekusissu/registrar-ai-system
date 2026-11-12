-- Existing-database upgrade, 2026-11-13. Take a backup before schema changes.
-- No DROP TABLE, TRUNCATE, account deletion or password replacement. Re-runnable.
-- Preserves unknown legacy roles for manual review instead of silently granting privileges.
SET NAMES utf8mb4;
SET @bundle_old_fk=@@FOREIGN_KEY_CHECKS; SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `academic_grades` (
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

CREATE TABLE IF NOT EXISTS `academic_history` (
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

CREATE TABLE IF NOT EXISTS `academic_validations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `academic_history_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `review_status` enum('reviewed','needs_correction','incomplete') NOT NULL,
  `remarks` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_validation_record` (`academic_history_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_cache` (
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

CREATE TABLE IF NOT EXISTS `audit_logs` (
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

CREATE TABLE IF NOT EXISTS `card_readers` (
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

CREATE TABLE IF NOT EXISTS `communication_log` (
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

CREATE TABLE IF NOT EXISTS `contact_change_requests` (
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

CREATE TABLE IF NOT EXISTS `contact_recipients` (
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

CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_code` (`code`),
  KEY `idx_department_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `discipline_records` (
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

CREATE TABLE IF NOT EXISTS `document_ai_audit` (
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

CREATE TABLE IF NOT EXISTS `document_catalog` (
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

CREATE TABLE IF NOT EXISTS `document_request_attachments` (
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

CREATE TABLE IF NOT EXISTS `document_request_events` (
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

CREATE TABLE IF NOT EXISTS `document_requests` (
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

CREATE TABLE IF NOT EXISTS `document_type_requirements` (
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

CREATE TABLE IF NOT EXISTS `documents` (
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

CREATE TABLE IF NOT EXISTS `emergency_contacts` (
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

CREATE TABLE IF NOT EXISTS `enrollment_history` (
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

CREATE TABLE IF NOT EXISTS `enrollments` (
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

CREATE TABLE IF NOT EXISTS `finance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finance_student` (`student_id`),
  CONSTRAINT `fk_finance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `guardian_contact_attempts` (
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

CREATE TABLE IF NOT EXISTS `guardians` (
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

CREATE TABLE IF NOT EXISTS `id_card_details` (
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

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_ip_time` (`email`,`ip_address`,`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mock_lalamove_orders` (
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

CREATE TABLE IF NOT EXISTS `mock_payment_transactions` (
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

CREATE TABLE IF NOT EXISTS `otp_codes` (
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

CREATE TABLE IF NOT EXISTS `password_reset_grants` (
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

CREATE TABLE IF NOT EXISTS `queue_day_settings` (
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

CREATE TABLE IF NOT EXISTS `queue_tickets` (
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

CREATE TABLE IF NOT EXISTS `report_exports` (
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

CREATE TABLE IF NOT EXISTS `rfid_cards` (
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

CREATE TABLE IF NOT EXISTS `rfid_scan_logs` (
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

CREATE TABLE IF NOT EXISTS `staff_notification_reads` (
  `user_id` int(11) NOT NULL,
  `last_read_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `status_tracker` (
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

CREATE TABLE IF NOT EXISTS `student_ids` (
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

CREATE TABLE IF NOT EXISTS `student_notifications` (
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

CREATE TABLE IF NOT EXISTS `students` (
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

CREATE TABLE IF NOT EXISTS `user_program_assignments` (
  `user_id` int(11) NOT NULL,
  `program_code` varchar(30) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`program_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
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


SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='id')=0,'ALTER TABLE `academic_grades` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='academic_history_id')=0,'ALTER TABLE `academic_grades` ADD COLUMN `academic_history_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='subject')=0,'ALTER TABLE `academic_grades` ADD COLUMN `subject` varchar(120) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='units')=0,'ALTER TABLE `academic_grades` ADD COLUMN `units` decimal(4,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='grade')=0,'ALTER TABLE `academic_grades` ADD COLUMN `grade` varchar(10) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='remarks')=0,'ALTER TABLE `academic_grades` ADD COLUMN `remarks` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `academic_grades` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='subject_code')=0,'ALTER TABLE `academic_grades` ADD COLUMN `subject_code` varchar(30) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='subject_type')=0,'ALTER TABLE `academic_grades` ADD COLUMN `subject_type` varchar(30) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='prerequisite')=0,'ALTER TABLE `academic_grades` ADD COLUMN `prerequisite` varchar(120) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='instructor')=0,'ALTER TABLE `academic_grades` ADD COLUMN `instructor` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='schedule')=0,'ALTER TABLE `academic_grades` ADD COLUMN `schedule` varchar(120) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='room')=0,'ALTER TABLE `academic_grades` ADD COLUMN `room` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='semester_taken')=0,'ALTER TABLE `academic_grades` ADD COLUMN `semester_taken` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='midterm_grade')=0,'ALTER TABLE `academic_grades` ADD COLUMN `midterm_grade` varchar(10) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='final_grade')=0,'ALTER TABLE `academic_grades` ADD COLUMN `final_grade` varchar(10) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='final_rating')=0,'ALTER TABLE `academic_grades` ADD COLUMN `final_rating` varchar(10) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='grade_status')=0,'ALTER TABLE `academic_grades` ADD COLUMN `grade_status` enum(\'passed\',\'failed\',\'incomplete\',\'dropped\') DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='source_system')=0,'ALTER TABLE `academic_grades` ADD COLUMN `source_system` varchar(32) NOT NULL DEFAULT \'faculty\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='source_ref')=0,'ALTER TABLE `academic_grades` ADD COLUMN `source_ref` varchar(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='faculty_id')=0,'ALTER TABLE `academic_grades` ADD COLUMN `faculty_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='received_at')=0,'ALTER TABLE `academic_grades` ADD COLUMN `received_at` timestamp NULL DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='term_status')=0,'ALTER TABLE `academic_grades` ADD COLUMN `term_status` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_grades' AND COLUMN_NAME='instructor_confirmed')=0,'ALTER TABLE `academic_grades` ADD COLUMN `instructor_confirmed` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='id')=0,'ALTER TABLE `academic_history` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `academic_history` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='school_name')=0,'ALTER TABLE `academic_history` ADD COLUMN `school_name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='school_year')=0,'ALTER TABLE `academic_history` ADD COLUMN `school_year` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='grade_level')=0,'ALTER TABLE `academic_history` ADD COLUMN `grade_level` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='gwa')=0,'ALTER TABLE `academic_history` ADD COLUMN `gwa` decimal(5,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='subjects_completed')=0,'ALTER TABLE `academic_history` ADD COLUMN `subjects_completed` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='remarks')=0,'ALTER TABLE `academic_history` ADD COLUMN `remarks` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `academic_history` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='semester')=0,'ALTER TABLE `academic_history` ADD COLUMN `semester` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='credits')=0,'ALTER TABLE `academic_history` ADD COLUMN `credits` decimal(6,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='gwa_reported')=0,'ALTER TABLE `academic_history` ADD COLUMN `gwa_reported` decimal(5,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='gwa_computed')=0,'ALTER TABLE `academic_history` ADD COLUMN `gwa_computed` decimal(5,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='accepted_at')=0,'ALTER TABLE `academic_history` ADD COLUMN `accepted_at` timestamp NULL DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_history' AND COLUMN_NAME='accepted_by')=0,'ALTER TABLE `academic_history` ADD COLUMN `accepted_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_validations' AND COLUMN_NAME='id')=0,'ALTER TABLE `academic_validations` ADD COLUMN `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_validations' AND COLUMN_NAME='academic_history_id')=0,'ALTER TABLE `academic_validations` ADD COLUMN `academic_history_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_validations' AND COLUMN_NAME='reviewer_id')=0,'ALTER TABLE `academic_validations` ADD COLUMN `reviewer_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_validations' AND COLUMN_NAME='review_status')=0,'ALTER TABLE `academic_validations` ADD COLUMN `review_status` enum(\'reviewed\',\'needs_correction\',\'incomplete\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_validations' AND COLUMN_NAME='remarks')=0,'ALTER TABLE `academic_validations` ADD COLUMN `remarks` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_validations' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `academic_validations` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='id')=0,'ALTER TABLE `ai_cache` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='prompt_hash')=0,'ALTER TABLE `ai_cache` ADD COLUMN `prompt_hash` varchar(64) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='prompt')=0,'ALTER TABLE `ai_cache` ADD COLUMN `prompt` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='response')=0,'ALTER TABLE `ai_cache` ADD COLUMN `response` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='model')=0,'ALTER TABLE `ai_cache` ADD COLUMN `model` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `ai_cache` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_cache' AND COLUMN_NAME='expires_at')=0,'ALTER TABLE `ai_cache` ADD COLUMN `expires_at` timestamp NULL DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='id')=0,'ALTER TABLE `audit_logs` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='user_id')=0,'ALTER TABLE `audit_logs` ADD COLUMN `user_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='action')=0,'ALTER TABLE `audit_logs` ADD COLUMN `action` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='table_name')=0,'ALTER TABLE `audit_logs` ADD COLUMN `table_name` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='record_id')=0,'ALTER TABLE `audit_logs` ADD COLUMN `record_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='old_values')=0,'ALTER TABLE `audit_logs` ADD COLUMN `old_values` longtext DEFAULT NULL CHECK (json_valid(`old_values`))','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='new_values')=0,'ALTER TABLE `audit_logs` ADD COLUMN `new_values` longtext DEFAULT NULL CHECK (json_valid(`new_values`))','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='ip_address')=0,'ALTER TABLE `audit_logs` ADD COLUMN `ip_address` varchar(45) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='user_agent')=0,'ALTER TABLE `audit_logs` ADD COLUMN `user_agent` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `audit_logs` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='id')=0,'ALTER TABLE `card_readers` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='name')=0,'ALTER TABLE `card_readers` ADD COLUMN `name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='location')=0,'ALTER TABLE `card_readers` ADD COLUMN `location` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='reader_type')=0,'ALTER TABLE `card_readers` ADD COLUMN `reader_type` enum(\'entrance\',\'exit\',\'both\') NOT NULL DEFAULT \'both\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='reader_code')=0,'ALTER TABLE `card_readers` ADD COLUMN `reader_code` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='status')=0,'ALTER TABLE `card_readers` ADD COLUMN `status` enum(\'active\',\'inactive\') NOT NULL DEFAULT \'active\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `card_readers` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='card_readers' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `card_readers` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='id')=0,'ALTER TABLE `communication_log` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `communication_log` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='contact_id')=0,'ALTER TABLE `communication_log` ADD COLUMN `contact_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='recipient_email')=0,'ALTER TABLE `communication_log` ADD COLUMN `recipient_email` varchar(190) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='recipient_name')=0,'ALTER TABLE `communication_log` ADD COLUMN `recipient_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='message_type')=0,'ALTER TABLE `communication_log` ADD COLUMN `message_type` varchar(20) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='subject')=0,'ALTER TABLE `communication_log` ADD COLUMN `subject` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='status')=0,'ALTER TABLE `communication_log` ADD COLUMN `status` varchar(20) NOT NULL DEFAULT \'sent\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='ref')=0,'ALTER TABLE `communication_log` ADD COLUMN `ref` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='detail')=0,'ALTER TABLE `communication_log` ADD COLUMN `detail` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='sent_by')=0,'ALTER TABLE `communication_log` ADD COLUMN `sent_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communication_log' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `communication_log` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='id')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='contact_type')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `contact_type` varchar(10) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='request_type')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `request_type` varchar(10) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='target_id')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `target_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='payload')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `payload` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='reason')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `reason` varchar(500) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='status')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `status` varchar(10) NOT NULL DEFAULT \'pending\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='review_note')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `review_note` varchar(500) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='reviewed_at')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `reviewed_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_change_requests' AND COLUMN_NAME='reviewed_by')=0,'ALTER TABLE `contact_change_requests` ADD COLUMN `reviewed_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='id')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='full_name')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `full_name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='relationship')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `relationship` varchar(40) NOT NULL DEFAULT \'parent\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='email')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `email` varchar(190) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='phone')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `phone` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='send_billing')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `send_billing` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='send_grades')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `send_grades` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='send_emergency')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `send_emergency` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='auth_token')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `auth_token` varchar(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='token_expires_at')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `token_expires_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='verified')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `verified` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='last_emailed')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `last_emailed` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_recipients' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `contact_recipients` ADD COLUMN `updated_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND COLUMN_NAME='id')=0,'ALTER TABLE `departments` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND COLUMN_NAME='name')=0,'ALTER TABLE `departments` ADD COLUMN `name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND COLUMN_NAME='code')=0,'ALTER TABLE `departments` ADD COLUMN `code` varchar(20) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND COLUMN_NAME='is_active')=0,'ALTER TABLE `departments` ADD COLUMN `is_active` tinyint(1) DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `departments` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='id')=0,'ALTER TABLE `discipline_records` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `discipline_records` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='recorded_at')=0,'ALTER TABLE `discipline_records` ADD COLUMN `recorded_at` date DEFAULT NULL COMMENT \'Date the case was filed\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='nature')=0,'ALTER TABLE `discipline_records` ADD COLUMN `nature` varchar(255) DEFAULT NULL COMMENT \'Nature of the case\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='resolution')=0,'ALTER TABLE `discipline_records` ADD COLUMN `resolution` varchar(100) DEFAULT NULL COMMENT \'Penalty imposed, if any\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='status')=0,'ALTER TABLE `discipline_records` ADD COLUMN `status` enum(\'pending\',\'resolved\',\'dismissed\') NOT NULL DEFAULT \'pending\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='remarks')=0,'ALTER TABLE `discipline_records` ADD COLUMN `remarks` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='recorded_by')=0,'ALTER TABLE `discipline_records` ADD COLUMN `recorded_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline_records' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `discipline_records` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='id')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='document_id')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `document_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='action')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `action` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='input_summary')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `input_summary` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='result')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `result` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='confidence')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `confidence` decimal(3,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='created_by')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `created_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_ai_audit' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `document_ai_audit` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='id')=0,'ALTER TABLE `document_catalog` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='sku')=0,'ALTER TABLE `document_catalog` ADD COLUMN `sku` varchar(20) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='name')=0,'ALTER TABLE `document_catalog` ADD COLUMN `name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='description')=0,'ALTER TABLE `document_catalog` ADD COLUMN `description` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='base_fee')=0,'ALTER TABLE `document_catalog` ADD COLUMN `base_fee` decimal(10,2) NOT NULL DEFAULT 0.00','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='sla_days')=0,'ALTER TABLE `document_catalog` ADD COLUMN `sla_days` int(11) DEFAULT NULL COMMENT \'Target turnaround in days for this document\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='fee_type')=0,'ALTER TABLE `document_catalog` ADD COLUMN `fee_type` enum(\'flat\',\'per_page\',\'per_syllabus\') NOT NULL DEFAULT \'flat\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='requirement')=0,'ALTER TABLE `document_catalog` ADD COLUMN `requirement` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='is_active')=0,'ALTER TABLE `document_catalog` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_catalog' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `document_catalog` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='id')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='request_id')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `request_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='requirement_code')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `requirement_code` varchar(40) DEFAULT NULL COMMENT \'Checklist item, or NULL for a loose file\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='original_name')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `original_name` varchar(180) NOT NULL COMMENT \'As uploaded; display only, never a path\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='file_path')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `file_path` varchar(255) NOT NULL COMMENT \'Relative to repo root\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='sha256')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `sha256` char(64) NOT NULL COMMENT \'Proves the bytes are unaltered\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='mime_type')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `mime_type` varchar(80) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='size_bytes')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `size_bytes` int(11) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_attachments' AND COLUMN_NAME='uploaded_at')=0,'ALTER TABLE `document_request_attachments` ADD COLUMN `uploaded_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_events' AND COLUMN_NAME='id')=0,'ALTER TABLE `document_request_events` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_events' AND COLUMN_NAME='request_id')=0,'ALTER TABLE `document_request_events` ADD COLUMN `request_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_events' AND COLUMN_NAME='status')=0,'ALTER TABLE `document_request_events` ADD COLUMN `status` varchar(40) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_events' AND COLUMN_NAME='note')=0,'ALTER TABLE `document_request_events` ADD COLUMN `note` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_events' AND COLUMN_NAME='created_by')=0,'ALTER TABLE `document_request_events` ADD COLUMN `created_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_request_events' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `document_request_events` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='id')=0,'ALTER TABLE `document_requests` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='request_id')=0,'ALTER TABLE `document_requests` ADD COLUMN `request_id` varchar(24) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='source')=0,'ALTER TABLE `document_requests` ADD COLUMN `source` enum(\'walk_in\',\'online\') NOT NULL DEFAULT \'walk_in\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='walkin_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `walkin_at` datetime DEFAULT NULL COMMENT \'Walked in at the counter\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='walkin_by')=0,'ALTER TABLE `document_requests` ADD COLUMN `walkin_by` int(11) DEFAULT NULL COMMENT \'Registrar who took the request\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='counter')=0,'ALTER TABLE `document_requests` ADD COLUMN `counter` tinyint(3) NOT NULL DEFAULT 1 COMMENT \'Releasing counter (1-3)\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='released_by')=0,'ALTER TABLE `document_requests` ADD COLUMN `released_by` int(11) DEFAULT NULL COMMENT \'Registrar who released the document\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='record_file_path')=0,'ALTER TABLE `document_requests` ADD COLUMN `record_file_path` varchar(255) DEFAULT NULL COMMENT \'Retained signed record copy\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='record_file_sha256')=0,'ALTER TABLE `document_requests` ADD COLUMN `record_file_sha256` varchar(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='record_file_generated_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `record_file_generated_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='record_copies_issued')=0,'ALTER TABLE `document_requests` ADD COLUMN `record_copies_issued` int(11) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `document_requests` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='document_type')=0,'ALTER TABLE `document_requests` ADD COLUMN `document_type` enum(\'form137\',\'good_moral\',\'transcript\',\'certificate\',\'clearance\',\'diploma\',\'ctc\',\'honorable_dismissal\',\'course_description\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='catalog_id')=0,'ALTER TABLE `document_requests` ADD COLUMN `catalog_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='quantity')=0,'ALTER TABLE `document_requests` ADD COLUMN `quantity` int(11) NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='request_type')=0,'ALTER TABLE `document_requests` ADD COLUMN `request_type` enum(\'Express\',\'Regular\') NOT NULL DEFAULT \'Regular\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='fulfillment_type')=0,'ALTER TABLE `document_requests` ADD COLUMN `fulfillment_type` enum(\'Pickup\',\'Delivery\',\'Digital\') NOT NULL DEFAULT \'Pickup\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='delivery_address')=0,'ALTER TABLE `document_requests` ADD COLUMN `delivery_address` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_method')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_method` enum(\'Online\',\'Bank_Transfer\',\'Cash_on_Delivery\',\'Counter\') NOT NULL DEFAULT \'Counter\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='purpose')=0,'ALTER TABLE `document_requests` ADD COLUMN `purpose` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='recipient')=0,'ALTER TABLE `document_requests` ADD COLUMN `recipient` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='status')=0,'ALTER TABLE `document_requests` ADD COLUMN `status` enum(\'pending\',\'processing\',\'approved\',\'denied\',\'completed\',\'released\') DEFAULT \'pending\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='document_status')=0,'ALTER TABLE `document_requests` ADD COLUMN `document_status` enum(\'Draft\',\'Filed\',\'Pending_Clearance\',\'Awaiting_Payment\',\'Processing\',\'Ready\',\'Shipped\',\'Claimed\',\'Rejected\',\'Cancelled\') NOT NULL DEFAULT \'Filed\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='blocked_reason')=0,'ALTER TABLE `document_requests` ADD COLUMN `blocked_reason` varchar(160) DEFAULT NULL COMMENT \'What this request is waiting on, if anything\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='blocked_since')=0,'ALTER TABLE `document_requests` ADD COLUMN `blocked_since` datetime DEFAULT NULL COMMENT \'When the current blockage began\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='blocked_source')=0,'ALTER TABLE `document_requests` ADD COLUMN `blocked_source` varchar(16) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='rejection_reason')=0,'ALTER TABLE `document_requests` ADD COLUMN `rejection_reason` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='approval_reason')=0,'ALTER TABLE `document_requests` ADD COLUMN `approval_reason` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='qr_hash')=0,'ALTER TABLE `document_requests` ADD COLUMN `qr_hash` varchar(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='requirement_file_path')=0,'ALTER TABLE `document_requests` ADD COLUMN `requirement_file_path` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_ref')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_ref` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='lalamove_order_ref')=0,'ALTER TABLE `document_requests` ADD COLUMN `lalamove_order_ref` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='paid_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `paid_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='ready_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `ready_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='shipped_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `shipped_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='claimed_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `claimed_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='pdf_path')=0,'ALTER TABLE `document_requests` ADD COLUMN `pdf_path` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='pdf_fingerprint')=0,'ALTER TABLE `document_requests` ADD COLUMN `pdf_fingerprint` varchar(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='processed_by')=0,'ALTER TABLE `document_requests` ADD COLUMN `processed_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='denial_reason')=0,'ALTER TABLE `document_requests` ADD COLUMN `denial_reason` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='file_path')=0,'ALTER TABLE `document_requests` ADD COLUMN `file_path` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='request_date')=0,'ALTER TABLE `document_requests` ADD COLUMN `request_date` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='processed_date')=0,'ALTER TABLE `document_requests` ADD COLUMN `processed_date` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='completed_date')=0,'ALTER TABLE `document_requests` ADD COLUMN `completed_date` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='fee_amount')=0,'ALTER TABLE `document_requests` ADD COLUMN `fee_amount` decimal(10,2) DEFAULT 0.00','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='delivery_fee')=0,'ALTER TABLE `document_requests` ADD COLUMN `delivery_fee` decimal(10,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='official_receipt')=0,'ALTER TABLE `document_requests` ADD COLUMN `official_receipt` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='release_date')=0,'ALTER TABLE `document_requests` ADD COLUMN `release_date` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_path')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_path` varchar(255) DEFAULT NULL COMMENT \'Relative path to the uploaded GCash receipt image/PDF\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_sha256')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_sha256` char(64) DEFAULT NULL COMMENT \'SHA-256 of the receipt at upload; proves the file is unaltered\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_filename')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_filename` varchar(180) DEFAULT NULL COMMENT \'Original filename as uploaded, for display only\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_uploaded_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_uploaded_at` datetime DEFAULT NULL COMMENT \'When the student attached the receipt\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_ref')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_ref` varchar(40) DEFAULT NULL COMMENT \'GCash reference number as printed on the receipt\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_verified_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_verified_at` datetime DEFAULT NULL COMMENT \'When staff confirmed the receipt matches the request\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_verified_by')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_verified_by` int(11) DEFAULT NULL COMMENT \'users.id of the staff member who verified the receipt\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_waived_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_waived_at` datetime DEFAULT NULL COMMENT \'When staff accepted the request WITHOUT a receipt\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_waived_by')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_waived_by` int(11) DEFAULT NULL COMMENT \'users.id of the staff member who waived the receipt requirement\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_receipt_waived_reason')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_receipt_waived_reason` varchar(255) DEFAULT NULL COMMENT \'Why the receipt requirement was waived — required, never blank\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='pickup_notified_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `pickup_notified_at` datetime DEFAULT NULL COMMENT \'When the pickup/availability email was successfully sent\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='pickup_notified_to')=0,'ALTER TABLE `document_requests` ADD COLUMN `pickup_notified_to` varchar(255) DEFAULT NULL COMMENT \'Recipients the pickup email actually reached\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='pickup_notify_error')=0,'ALTER TABLE `document_requests` ADD COLUMN `pickup_notify_error` varchar(255) DEFAULT NULL COMMENT \'Why the pickup email could not be sent; NULL when it sent\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='requirements_reviewed_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `requirements_reviewed_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='requirements_reviewed_by')=0,'ALTER TABLE `document_requests` ADD COLUMN `requirements_reviewed_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='requirements_review_note')=0,'ALTER TABLE `document_requests` ADD COLUMN `requirements_review_note` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='wizard_step')=0,'ALTER TABLE `document_requests` ADD COLUMN `wizard_step` tinyint(3) NOT NULL DEFAULT 0 COMMENT \'Furthest wizard step reached: 0 none, 1..4 (drafts only)\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='notes')=0,'ALTER TABLE `document_requests` ADD COLUMN `notes` text DEFAULT NULL COMMENT \'Additional notes the student typed, free text\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='purpose_code')=0,'ALTER TABLE `document_requests` ADD COLUMN `purpose_code` varchar(40) DEFAULT NULL COMMENT \'Controlled purpose: employment|board_exam|further_studies|transfer|other\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='submitted_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `submitted_at` datetime DEFAULT NULL COMMENT \'When the student completed the wizard and submitted\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='estimated_release_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `estimated_release_at` datetime DEFAULT NULL COMMENT \'Promised release date, computed once at filing\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='payment_reference')=0,'ALTER TABLE `document_requests` ADD COLUMN `payment_reference` varchar(24) DEFAULT NULL COMMENT \'Payment reference quoted to the student (OTC / bank)\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='cancelled_at')=0,'ALTER TABLE `document_requests` ADD COLUMN `cancelled_at` datetime DEFAULT NULL COMMENT \'When the student withdrew the request\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requests' AND COLUMN_NAME='cancellation_reason')=0,'ALTER TABLE `document_requests` ADD COLUMN `cancellation_reason` varchar(255) DEFAULT NULL COMMENT \'Why the student withdrew it\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='id')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='catalog_id')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `catalog_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='code')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `code` varchar(40) NOT NULL COMMENT \'Stable key, e.g. library_clearance\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='label')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `label` varchar(160) NOT NULL COMMENT \'What the student sees\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='hint')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `hint` varchar(255) DEFAULT NULL COMMENT \'Format guidance, e.g. \"white background\"\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='is_required')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `is_required` tinyint(1) NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='max_files')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `max_files` tinyint(3) NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='sort_order')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='is_active')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_type_requirements' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `document_type_requirements` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='id')=0,'ALTER TABLE `documents` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `documents` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='enroll_no')=0,'ALTER TABLE `documents` ADD COLUMN `enroll_no` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='enroll_status')=0,'ALTER TABLE `documents` ADD COLUMN `enroll_status` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='doc_type')=0,'ALTER TABLE `documents` ADD COLUMN `doc_type` enum(\'enrollment\',\'transcript\',\'health\',\'photo\',\'clearance\',\'other\',\'form_137\',\'psa\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='filename')=0,'ALTER TABLE `documents` ADD COLUMN `filename` varchar(255) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='file_path')=0,'ALTER TABLE `documents` ADD COLUMN `file_path` varchar(500) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='file_size')=0,'ALTER TABLE `documents` ADD COLUMN `file_size` bigint(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='file_type')=0,'ALTER TABLE `documents` ADD COLUMN `file_type` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='file_sha256')=0,'ALTER TABLE `documents` ADD COLUMN `file_sha256` varchar(64) DEFAULT NULL COMMENT \'SHA-256 of the stored file, printed on a CTC\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='description')=0,'ALTER TABLE `documents` ADD COLUMN `description` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='uploaded_by')=0,'ALTER TABLE `documents` ADD COLUMN `uploaded_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `documents` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='category')=0,'ALTER TABLE `documents` ADD COLUMN `category` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='is_locked')=0,'ALTER TABLE `documents` ADD COLUMN `is_locked` tinyint(1) DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='file_hash')=0,'ALTER TABLE `documents` ADD COLUMN `file_hash` varchar(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='content_text')=0,'ALTER TABLE `documents` ADD COLUMN `content_text` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='ai_classified')=0,'ALTER TABLE `documents` ADD COLUMN `ai_classified` tinyint(1) DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='ai_confidence')=0,'ALTER TABLE `documents` ADD COLUMN `ai_confidence` decimal(3,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='ai_valid')=0,'ALTER TABLE `documents` ADD COLUMN `ai_valid` tinyint(1) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='ai_validation_note')=0,'ALTER TABLE `documents` ADD COLUMN `ai_validation_note` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='id')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='full_name')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `full_name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='relationship')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `relationship` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='contact_number')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `contact_number` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='address')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `address` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='is_primary')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `is_primary` tinyint(1) DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emergency_contacts' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `emergency_contacts` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='id')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='student_number')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `student_number` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='course')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `course` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='year_level')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `year_level` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='school_year')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `school_year` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='semester')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `semester` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='section')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `section` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='status')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `status` varchar(20) DEFAULT \'enrolled\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='enrolled_at')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `enrolled_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_history' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `enrollment_history` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='id')=0,'ALTER TABLE `enrollments` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='first_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `first_name` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='middle_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `middle_name` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='last_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `last_name` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='name_suffix')=0,'ALTER TABLE `enrollments` ADD COLUMN `name_suffix` varchar(10) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='student_number')=0,'ALTER TABLE `enrollments` ADD COLUMN `student_number` varchar(20) DEFAULT NULL COMMENT \'Optional during application; assigned at accept\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='birth_date')=0,'ALTER TABLE `enrollments` ADD COLUMN `birth_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='gender')=0,'ALTER TABLE `enrollments` ADD COLUMN `gender` enum(\'Male\',\'Female\') DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='civil_status')=0,'ALTER TABLE `enrollments` ADD COLUMN `civil_status` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='religion')=0,'ALTER TABLE `enrollments` ADD COLUMN `religion` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='nationality')=0,'ALTER TABLE `enrollments` ADD COLUMN `nationality` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='place_of_birth')=0,'ALTER TABLE `enrollments` ADD COLUMN `place_of_birth` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='father_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `father_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='mother_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `mother_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='email')=0,'ALTER TABLE `enrollments` ADD COLUMN `email` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='address')=0,'ALTER TABLE `enrollments` ADD COLUMN `address` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='contact_number')=0,'ALTER TABLE `enrollments` ADD COLUMN `contact_number` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='prev_school_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `prev_school_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='prev_school_last_year')=0,'ALTER TABLE `enrollments` ADD COLUMN `prev_school_last_year` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='prev_school_graduated_sy')=0,'ALTER TABLE `enrollments` ADD COLUMN `prev_school_graduated_sy` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='emergency_name')=0,'ALTER TABLE `enrollments` ADD COLUMN `emergency_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='emergency_relationship')=0,'ALTER TABLE `enrollments` ADD COLUMN `emergency_relationship` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='emergency_contact')=0,'ALTER TABLE `enrollments` ADD COLUMN `emergency_contact` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='course')=0,'ALTER TABLE `enrollments` ADD COLUMN `course` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='major')=0,'ALTER TABLE `enrollments` ADD COLUMN `major` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='year_level')=0,'ALTER TABLE `enrollments` ADD COLUMN `year_level` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='school_year')=0,'ALTER TABLE `enrollments` ADD COLUMN `school_year` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='semester')=0,'ALTER TABLE `enrollments` ADD COLUMN `semester` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='section')=0,'ALTER TABLE `enrollments` ADD COLUMN `section` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='status')=0,'ALTER TABLE `enrollments` ADD COLUMN `status` enum(\'pending\',\'received\',\'re-enrolled\',\'duplicate\') NOT NULL DEFAULT \'pending\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='received_at')=0,'ALTER TABLE `enrollments` ADD COLUMN `received_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `enrollments` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollments' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `enrollments` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance' AND COLUMN_NAME='id')=0,'ALTER TABLE `finance` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `finance` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance' AND COLUMN_NAME='balance')=0,'ALTER TABLE `finance` ADD COLUMN `balance` decimal(10,2) NOT NULL DEFAULT 0.00','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `finance` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='id')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='contact_type')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `contact_type` enum(\'guardian\',\'emergency\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='contact_id')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `contact_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='contact_name')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `contact_name` varchar(200) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='channel')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `channel` enum(\'call\',\'email\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='occasion')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `occasion` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='outcome')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `outcome` enum(\'reached\',\'no_answer\',\'left_message\',\'email_sent\',\'failed\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='notes')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `notes` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='recorded_by')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `recorded_by` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardian_contact_attempts' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `guardian_contact_attempts` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='id')=0,'ALTER TABLE `guardians` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `guardians` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='full_name')=0,'ALTER TABLE `guardians` ADD COLUMN `full_name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='relationship')=0,'ALTER TABLE `guardians` ADD COLUMN `relationship` enum(\'father\',\'mother\',\'guardian\',\'spouse\',\'sibling\') NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='contact_number')=0,'ALTER TABLE `guardians` ADD COLUMN `contact_number` varchar(15) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='email')=0,'ALTER TABLE `guardians` ADD COLUMN `email` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='address')=0,'ALTER TABLE `guardians` ADD COLUMN `address` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='is_primary')=0,'ALTER TABLE `guardians` ADD COLUMN `is_primary` tinyint(1) DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='is_emergency')=0,'ALTER TABLE `guardians` ADD COLUMN `is_emergency` tinyint(1) DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guardians' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `guardians` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='rfid_card_id')=0,'ALTER TABLE `id_card_details` ADD COLUMN `rfid_card_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `id_card_details` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='details_json')=0,'ALTER TABLE `id_card_details` ADD COLUMN `details_json` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='reason')=0,'ALTER TABLE `id_card_details` ADD COLUMN `reason` varchar(255) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='version')=0,'ALTER TABLE `id_card_details` ADD COLUMN `version` int(10) unsigned NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='updated_by')=0,'ALTER TABLE `id_card_details` ADD COLUMN `updated_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='id_card_details' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `id_card_details` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND COLUMN_NAME='id')=0,'ALTER TABLE `login_attempts` ADD COLUMN `id` int(10) unsigned NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND COLUMN_NAME='email')=0,'ALTER TABLE `login_attempts` ADD COLUMN `email` varchar(191) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND COLUMN_NAME='ip_address')=0,'ALTER TABLE `login_attempts` ADD COLUMN `ip_address` varchar(45) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND COLUMN_NAME='success')=0,'ALTER TABLE `login_attempts` ADD COLUMN `success` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND COLUMN_NAME='attempted_at')=0,'ALTER TABLE `login_attempts` ADD COLUMN `attempted_at` datetime NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='id')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='order_id')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `order_id` varchar(40) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='quotation_id')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `quotation_id` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='request_id')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `request_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='pickup')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `pickup` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='dropoff')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `dropoff` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='item')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `item` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='total_fee')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='distance_km')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `distance_km` decimal(6,2) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='driver_name')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `driver_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='driver_phone')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `driver_phone` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='status')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `status` varchar(40) NOT NULL DEFAULT \'ASSIGNING_RIDER\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='tracking_url')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `tracking_url` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_lalamove_orders' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `mock_lalamove_orders` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='id')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='transaction_id')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `transaction_id` varchar(40) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='request_id')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `request_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='amount')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `amount` decimal(10,2) NOT NULL DEFAULT 0.00','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='currency')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `currency` varchar(8) NOT NULL DEFAULT \'PHP\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='status')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `status` enum(\'pending\',\'completed\',\'failed\') NOT NULL DEFAULT \'pending\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='method')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `method` varchar(20) NOT NULL DEFAULT \'Online\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='due_on')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `due_on` enum(\'now\',\'delivery\') NOT NULL DEFAULT \'now\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='gateway')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `gateway` varchar(20) NOT NULL DEFAULT \'mock\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='paymongo_intent_id')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `paymongo_intent_id` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='paymongo_payment_id')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `paymongo_payment_id` varchar(40) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='payment_url')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `payment_url` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='callback_url')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `callback_url` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='paid_at')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `paid_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='raw_request')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `raw_request` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='raw_response')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `raw_response` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mock_payment_transactions' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `mock_payment_transactions` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='id')=0,'ALTER TABLE `otp_codes` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='user_id')=0,'ALTER TABLE `otp_codes` ADD COLUMN `user_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='otp_hash')=0,'ALTER TABLE `otp_codes` ADD COLUMN `otp_hash` varchar(255) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='purpose')=0,'ALTER TABLE `otp_codes` ADD COLUMN `purpose` enum(\'login\',\'reset\') NOT NULL DEFAULT \'login\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='expires_at')=0,'ALTER TABLE `otp_codes` ADD COLUMN `expires_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='used_at')=0,'ALTER TABLE `otp_codes` ADD COLUMN `used_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `otp_codes` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='otp_codes' AND COLUMN_NAME='verify_attempts')=0,'ALTER TABLE `otp_codes` ADD COLUMN `verify_attempts` int(11) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_grants' AND COLUMN_NAME='id')=0,'ALTER TABLE `password_reset_grants` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_grants' AND COLUMN_NAME='user_id')=0,'ALTER TABLE `password_reset_grants` ADD COLUMN `user_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_grants' AND COLUMN_NAME='token_hash')=0,'ALTER TABLE `password_reset_grants` ADD COLUMN `token_hash` varchar(255) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_grants' AND COLUMN_NAME='expires_at')=0,'ALTER TABLE `password_reset_grants` ADD COLUMN `expires_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_grants' AND COLUMN_NAME='used_at')=0,'ALTER TABLE `password_reset_grants` ADD COLUMN `used_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_grants' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `password_reset_grants` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='queue_date')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `queue_date` date NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='opens_time')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `opens_time` time NOT NULL DEFAULT \'08:00:00\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='closes_time')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `closes_time` time NOT NULL DEFAULT \'17:00:00\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='cutoff_enabled')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `cutoff_enabled` tinyint(1) NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='cutoff_forced_at')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `cutoff_forced_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='cutoff_forced_by')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `cutoff_forced_by` int(10) unsigned DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='max_taps_student')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `max_taps_student` int(10) unsigned NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='max_taps_priority')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `max_taps_priority` int(10) unsigned NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='max_daily_taps')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `max_daily_taps` int(10) unsigned NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_day_settings' AND COLUMN_NAME='updated_by')=0,'ALTER TABLE `queue_day_settings` ADD COLUMN `updated_by` int(10) unsigned DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='id')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `id` int(10) unsigned NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='queue_date')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `queue_date` date NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='ticket_number')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `ticket_number` int(10) unsigned NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='student_name')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `student_name` varchar(191) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='student_number')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `student_number` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='course')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `course` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='status')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `status` enum(\'waiting\',\'serving\',\'completed\',\'no-show\',\'removed\',\'cancelled\') NOT NULL DEFAULT \'waiting\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='counter')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `counter` int(10) unsigned NOT NULL DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='txn_type')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `txn_type` enum(\'service\',\'claim\') NOT NULL DEFAULT \'service\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='priority_group')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `priority_group` enum(\'student\',\'priority\') NOT NULL DEFAULT \'student\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='purpose')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `purpose` enum(\'general\',\'document_request\',\'payment\',\'enrollment\') NOT NULL DEFAULT \'general\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='document_request_id')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `document_request_id` int(11) DEFAULT NULL COMMENT \'Document request this visit relates to\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='card_uid')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `card_uid` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='joined_at')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `joined_at` datetime NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='called_at')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `called_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_tickets' AND COLUMN_NAME='served_at')=0,'ALTER TABLE `queue_tickets` ADD COLUMN `served_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='id')=0,'ALTER TABLE `report_exports` ADD COLUMN `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='user_id')=0,'ALTER TABLE `report_exports` ADD COLUMN `user_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='actor_name')=0,'ALTER TABLE `report_exports` ADD COLUMN `actor_name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='actor_role')=0,'ALTER TABLE `report_exports` ADD COLUMN `actor_role` varchar(40) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='report_name')=0,'ALTER TABLE `report_exports` ADD COLUMN `report_name` varchar(160) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='format')=0,'ALTER TABLE `report_exports` ADD COLUMN `format` varchar(20) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='resource')=0,'ALTER TABLE `report_exports` ADD COLUMN `resource` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='status')=0,'ALTER TABLE `report_exports` ADD COLUMN `status` varchar(30) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='token_hash')=0,'ALTER TABLE `report_exports` ADD COLUMN `token_hash` char(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='session_hash')=0,'ALTER TABLE `report_exports` ADD COLUMN `session_hash` char(64) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='ip_address')=0,'ALTER TABLE `report_exports` ADD COLUMN `ip_address` varchar(45) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `report_exports` ADD COLUMN `created_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='expires_at')=0,'ALTER TABLE `report_exports` ADD COLUMN `expires_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports' AND COLUMN_NAME='initiated_at')=0,'ALTER TABLE `report_exports` ADD COLUMN `initiated_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='id')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='card_uid')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `card_uid` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='card_type')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `card_type` enum(\'rfid\',\'qrcode\') DEFAULT \'rfid\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='status')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `status` enum(\'active\',\'inactive\',\'lost\',\'expired\',\'available\',\'archived\') DEFAULT \'available\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='issued_date')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `issued_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='expiry_date')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `expiry_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='notes')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `notes` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='archive_reason')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `archive_reason` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='registered_at')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `registered_at` timestamp NULL DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='assigned_at')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `assigned_at` timestamp NULL DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='qr_code_path')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `qr_code_path` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_cards' AND COLUMN_NAME='issued_at')=0,'ALTER TABLE `rfid_cards` ADD COLUMN `issued_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='id')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='card_uid')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `card_uid` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='location')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `location` varchar(100) DEFAULT \'Main Gate\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='event_type')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `event_type` enum(\'entry\',\'exit\',\'library\',\'cafeteria\',\'clinic\',\'other\',\'queue_join\',\'queue_call\',\'queue_serving\',\'queue_completed\',\'queue_no_show\',\'queue_cancelled\') DEFAULT \'entry\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='status')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `status` enum(\'success\',\'denied\',\'unknown\') DEFAULT \'success\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='scanner_id')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `scanner_id` varchar(50) DEFAULT \'scanner-01\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='scanned_at')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `scanned_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='ip_address')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `ip_address` varchar(45) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfid_scan_logs' AND COLUMN_NAME='user_agent')=0,'ALTER TABLE `rfid_scan_logs` ADD COLUMN `user_agent` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_notification_reads' AND COLUMN_NAME='user_id')=0,'ALTER TABLE `staff_notification_reads` ADD COLUMN `user_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_notification_reads' AND COLUMN_NAME='last_read_id')=0,'ALTER TABLE `staff_notification_reads` ADD COLUMN `last_read_id` int(11) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_notification_reads' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `staff_notification_reads` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='id')=0,'ALTER TABLE `status_tracker` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `status_tracker` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='previous_status')=0,'ALTER TABLE `status_tracker` ADD COLUMN `previous_status` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='current_status')=0,'ALTER TABLE `status_tracker` ADD COLUMN `current_status` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='reason')=0,'ALTER TABLE `status_tracker` ADD COLUMN `reason` text DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='changed_by')=0,'ALTER TABLE `status_tracker` ADD COLUMN `changed_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `status_tracker` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='effective_date')=0,'ALTER TABLE `status_tracker` ADD COLUMN `effective_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='status_tracker' AND COLUMN_NAME='end_date')=0,'ALTER TABLE `status_tracker` ADD COLUMN `end_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='id')=0,'ALTER TABLE `student_ids` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `student_ids` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='id_number')=0,'ALTER TABLE `student_ids` ADD COLUMN `id_number` varchar(20) DEFAULT \'\' COMMENT \'Enrollment department ID - assigned later\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='id_type')=0,'ALTER TABLE `student_ids` ADD COLUMN `id_type` enum(\'school_id\',\'library\',\'cafeteria\') DEFAULT \'school_id\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='issue_date')=0,'ALTER TABLE `student_ids` ADD COLUMN `issue_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='expiry_date')=0,'ALTER TABLE `student_ids` ADD COLUMN `expiry_date` date DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='status')=0,'ALTER TABLE `student_ids` ADD COLUMN `status` enum(\'active\',\'inactive\',\'lost\') DEFAULT \'active\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='photo_path')=0,'ALTER TABLE `student_ids` ADD COLUMN `photo_path` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='qr_code_path')=0,'ALTER TABLE `student_ids` ADD COLUMN `qr_code_path` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='rfid_card_id')=0,'ALTER TABLE `student_ids` ADD COLUMN `rfid_card_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `student_ids` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='qr_payload')=0,'ALTER TABLE `student_ids` ADD COLUMN `qr_payload` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='school_year')=0,'ALTER TABLE `student_ids` ADD COLUMN `school_year` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_ids' AND COLUMN_NAME='card_color')=0,'ALTER TABLE `student_ids` ADD COLUMN `card_color` varchar(20) DEFAULT \'blue\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='id')=0,'ALTER TABLE `student_notifications` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `student_notifications` ADD COLUMN `student_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='title')=0,'ALTER TABLE `student_notifications` ADD COLUMN `title` varchar(200) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='message')=0,'ALTER TABLE `student_notifications` ADD COLUMN `message` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='type')=0,'ALTER TABLE `student_notifications` ADD COLUMN `type` varchar(50) DEFAULT \'info\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='is_read')=0,'ALTER TABLE `student_notifications` ADD COLUMN `is_read` tinyint(1) DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='related_doc_id')=0,'ALTER TABLE `student_notifications` ADD COLUMN `related_doc_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='created_by')=0,'ALTER TABLE `student_notifications` ADD COLUMN `created_by` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `student_notifications` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_notifications' AND COLUMN_NAME='read_at')=0,'ALTER TABLE `student_notifications` ADD COLUMN `read_at` timestamp NULL DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='id')=0,'ALTER TABLE `students` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='student_number')=0,'ALTER TABLE `students` ADD COLUMN `student_number` varchar(20) DEFAULT \'\' COMMENT \'Enrollment department ID -- assigned later\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='first_name')=0,'ALTER TABLE `students` ADD COLUMN `first_name` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='middle_name')=0,'ALTER TABLE `students` ADD COLUMN `middle_name` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='last_name')=0,'ALTER TABLE `students` ADD COLUMN `last_name` varchar(50) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='gender')=0,'ALTER TABLE `students` ADD COLUMN `gender` enum(\'Male\',\'Female\') DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='civil_status')=0,'ALTER TABLE `students` ADD COLUMN `civil_status` enum(\'Single\',\'Married\',\'Widowed\',\'Separated\') DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='birth_date')=0,'ALTER TABLE `students` ADD COLUMN `birth_date` date NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='place_of_birth')=0,'ALTER TABLE `students` ADD COLUMN `place_of_birth` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='nationality')=0,'ALTER TABLE `students` ADD COLUMN `nationality` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='religion')=0,'ALTER TABLE `students` ADD COLUMN `religion` varchar(50) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='address')=0,'ALTER TABLE `students` ADD COLUMN `address` text NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='contact_number')=0,'ALTER TABLE `students` ADD COLUMN `contact_number` varchar(15) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='email')=0,'ALTER TABLE `students` ADD COLUMN `email` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='photo')=0,'ALTER TABLE `students` ADD COLUMN `photo` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='course')=0,'ALTER TABLE `students` ADD COLUMN `course` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='major')=0,'ALTER TABLE `students` ADD COLUMN `major` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='year_level')=0,'ALTER TABLE `students` ADD COLUMN `year_level` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='school_year')=0,'ALTER TABLE `students` ADD COLUMN `school_year` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='semester')=0,'ALTER TABLE `students` ADD COLUMN `semester` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='adviser_id')=0,'ALTER TABLE `students` ADD COLUMN `adviser_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='section')=0,'ALTER TABLE `students` ADD COLUMN `section` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='status')=0,'ALTER TABLE `students` ADD COLUMN `status` enum(\'enrolled\',\'active\',\'graduate\',\'alumni\',\'dropped\') NOT NULL DEFAULT \'enrolled\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `students` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `students` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='lrn')=0,'ALTER TABLE `students` ADD COLUMN `lrn` varchar(12) DEFAULT NULL COMMENT \'LRN - Learner Reference Number\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='name_suffix')=0,'ALTER TABLE `students` ADD COLUMN `name_suffix` varchar(10) DEFAULT NULL COMMENT \'e.g. Jr., III, Sr.\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='mother_name')=0,'ALTER TABLE `students` ADD COLUMN `mother_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='father_name')=0,'ALTER TABLE `students` ADD COLUMN `father_name` varchar(100) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='birth_country')=0,'ALTER TABLE `students` ADD COLUMN `birth_country` varchar(60) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='graduation_date')=0,'ALTER TABLE `students` ADD COLUMN `graduation_date` date DEFAULT NULL COMMENT \'Date the degree was conferred\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='previous_school')=0,'ALTER TABLE `students` ADD COLUMN `previous_school` varchar(150) DEFAULT NULL COMMENT \'School last attended before this one\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='school_year_graduated')=0,'ALTER TABLE `students` ADD COLUMN `school_year_graduated` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='last_year_level_completed')=0,'ALTER TABLE `students` ADD COLUMN `last_year_level_completed` varchar(30) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='email_bounced_at')=0,'ALTER TABLE `students` ADD COLUMN `email_bounced_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='email_bounce_reason')=0,'ALTER TABLE `students` ADD COLUMN `email_bounce_reason` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='email_is_placeholder')=0,'ALTER TABLE `students` ADD COLUMN `email_is_placeholder` tinyint(1) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_program_assignments' AND COLUMN_NAME='user_id')=0,'ALTER TABLE `user_program_assignments` ADD COLUMN `user_id` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_program_assignments' AND COLUMN_NAME='program_code')=0,'ALTER TABLE `user_program_assignments` ADD COLUMN `program_code` varchar(30) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_program_assignments' AND COLUMN_NAME='assigned_by')=0,'ALTER TABLE `user_program_assignments` ADD COLUMN `assigned_by` int(11) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_program_assignments' AND COLUMN_NAME='assigned_at')=0,'ALTER TABLE `user_program_assignments` ADD COLUMN `assigned_at` datetime NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id')=0,'ALTER TABLE `users` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email')=0,'ALTER TABLE `users` ADD COLUMN `email` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='password_hash')=0,'ALTER TABLE `users` ADD COLUMN `password_hash` varchar(255) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='full_name')=0,'ALTER TABLE `users` ADD COLUMN `full_name` varchar(100) NOT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role')=0,'ALTER TABLE `users` ADD COLUMN `role` enum(\'super_admin\',\'registrar_admin\',\'staff\',\'acad_officer\',\'department_head\',\'student\') NOT NULL DEFAULT \'staff\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='rfid_uid')=0,'ALTER TABLE `users` ADD COLUMN `rfid_uid` varchar(20) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='is_active')=0,'ALTER TABLE `users` ADD COLUMN `is_active` tinyint(1) DEFAULT 1','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='created_at')=0,'ALTER TABLE `users` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE `users` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='student_id')=0,'ALTER TABLE `users` ADD COLUMN `student_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='department_id')=0,'ALTER TABLE `users` ADD COLUMN `department_id` int(11) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='username')=0,'ALTER TABLE `users` ADD COLUMN `username` varchar(60) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='login_attempts')=0,'ALTER TABLE `users` ADD COLUMN `login_attempts` int(11) NOT NULL DEFAULT 0','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='locked_until')=0,'ALTER TABLE `users` ADD COLUMN `locked_until` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='password_changed_at')=0,'ALTER TABLE `users` ADD COLUMN `password_changed_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_bounced_at')=0,'ALTER TABLE `users` ADD COLUMN `email_bounced_at` datetime DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_bounce_reason')=0,'ALTER TABLE `users` ADD COLUMN `email_bounce_reason` varchar(255) DEFAULT NULL','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;

-- Expand role storage before mapping legacy values; retain unfamiliar values.
ALTER TABLE users MODIFY COLUMN role VARCHAR(40) NOT NULL DEFAULT 'staff';
UPDATE users SET role=CASE LOWER(TRIM(role)) WHEN 'admin' THEN 'super_admin' WHEN 'registrar' THEN 'staff' WHEN 'teacher' THEN 'acad_officer' ELSE LOWER(TRIM(role)) END;
SET @bundle_sql=IF((SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin','registrar_admin','staff','acad_officer','department_head','student'))=0,'ALTER TABLE users MODIFY COLUMN role ENUM(\'super_admin\',\'registrar_admin\',\'staff\',\'acad_officer\',\'department_head\',\'student\') NOT NULL DEFAULT \'staff\'','DO 0');
PREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;
INSERT INTO users (`email`,`password_hash`,`full_name`,`role`,`rfid_uid`,`is_active`,`created_at`,`updated_at`,`student_id`,`department_id`,`username`,`login_attempts`,`locked_until`,`password_changed_at`,`email_bounced_at`,`email_bounce_reason`) SELECT 'roldantiu89@gmail.com','$2y$10$goIPlSwZLw21GfQLYBsoxOCwqaO86NGUssooNsG4FHGOi3IbFb6aO','Roldan Tiu','super_admin',NULL,'1','2026-07-07 14:42:45','2026-11-11 18:27:25',NULL,NULL,'ADM-002','0',NULL,NULL,NULL,NULL WHERE NOT EXISTS(SELECT 1 FROM users WHERE LOWER(email)=LOWER('Roldantiu89@gmail.com')) AND NOT EXISTS(SELECT 1 FROM users WHERE username='ADM-002');
UPDATE users SET role='super_admin',is_active=1 WHERE LOWER(email)=LOWER('Roldantiu89@gmail.com');

-- Consolidated: document_request_wizard.sql
-- Migration: Document Request Wizard
-- Date: 2026-10-08
-- Description:
--   Backs the guided request wizard: doc-type picker -> details ->
--   uploads -> review -> payment -> confirmation, then tracking.
--
--   TWO JOBS, IN THIS ORDER. Read the first one before the second.
--
-- ─────────────────────────────────────────────────────────────────────
-- 0. THE ENUMS ARE CURRENTLY CORRUPTING EVERY ONLINE FILING.
--
--    This is not a housekeeping item. migrations/document_walkin_only.sql
--    narrowed three enums to walk-in values, and the file meant to reverse
--    that (document_online_lifecycle.sql) has not been applied here. The
--    live table still holds:
--
--        source           enum('walk_in')
--        document_status  enum('Filed','Pending_Clearance','Processing',
--                              'Ready','Claimed','Rejected')
--        fulfillment_type enum('Pickup','Digital')
--
--    Meanwhile api/student-documents.php writes 'online',
--    'Awaiting_Payment' and 'Delivery'. This server runs WITHOUT
--    STRICT_TRANS_TABLES (sql_mode is NO_ZERO_IN_DATE, NO_ZERO_DATE,
--    NO_ENGINE_SUBSTITUTION only), so MySQL/MariaDB does not reject those
--    writes - it coerces them to ''. Verified against the live database
--    before writing this file:
--
--        INSERT ... source='online', document_status='Awaiting_Payment',
--                       fulfillment_type='Delivery'
--        -> "insert reported OK"
--        -> stored as source='', document_status='', fulfillment_type=''
--
--    Every student who filed online therefore got a row the registrar
--    desk cannot read: no provenance, no stage, no fulfilment mode. The
--    four rows currently in the table all show document_status='' for
--    exactly this reason, and the desk's status pill has been falling
--    through to its 'filed' default on all of them.
--
--    So section 1 is not "prepare for the wizard". It repairs rows that
--    are already wrong. It runs FIRST because MySQL validates a value
--    against the column's CURRENT definition: writing 'Awaiting_Payment'
--    before the enum admits it would coerce to '' again.
--
--    Ordering is widened -> migrate -> (never narrowed). A migration that
--    narrows an enum out from under its writers is data loss, not a
--    rollback.
-- ─────────────────────────────────────────────────────────────────────

-- ─────────────────────────────────────────────────────────────────────
-- 1a. source: how the desk tells an online filing from a counter filing.
--
--     The walk-in narrowing left an ONLINE request with no honest value
--     to store, which is the provenance gap this restores.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'source'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `source` enum('walk_in','online') NOT NULL DEFAULT 'walk_in'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 1b. fulfillment_type: courier comes back as a third fulfilment mode.
--     'Digital' is kept.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'fulfillment_type'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `fulfillment_type`
       enum('Pickup','Delivery','Digital') NOT NULL DEFAULT 'Pickup'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 1c. document_status: regain Awaiting_Payment and Shipped, and gain the
--     two states the wizard owns - Draft and Cancelled.
--
--     Draft   a request the student started and has not submitted. It is
--             NOT work: the desk's counts, rail and clock must all ignore
--             it, and a half-finished form must never appear as a filed
--             request that somebody failed to start.
--     Cancelled  the student withdrew it. A real terminal outcome, and it
--             is deliberately KEPT on the desk rather than deleted: money
--             may have been paid against it, and "he cancelled it" is the
--             answer to a question somebody will ask.
--
--     The seven pre-existing values are kept byte-for-byte in their
--     original order so a rollback stays a re-run rather than an edit.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'document_status'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `document_status`
       enum('Draft','Filed','Pending_Clearance','Awaiting_Payment',
            'Processing','Ready','Shipped','Claimed','Rejected','Cancelled')
       NOT NULL DEFAULT 'Filed'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 1d. payment_method: the wizard offers four ways to pay, not two.
--
--     Bank_Transfer is a real third channel (deposit slip + reference),
--     and it needs to be STORED rather than implied - finance reconciles
--     bank remittances against the reference the desk types in, which is
--     the same argument document_receipt_upload.sql makes for keeping the
--     GCash reference beside the gateway's transaction id.
--
--     'Counter' and 'Cash_on_Delivery' mean the same thing and BOTH are
--     kept: 'Counter' is what the desk's own walk-in form sends,
--     'Cash_on_Delivery' is what the student portal sends, and
--     api/student-documents.php maps one onto the other. Dropping either
--     would reject a caller that is still in production.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_method'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `payment_method`
       enum('Online','Bank_Transfer','Cash_on_Delivery','Counter')
       NOT NULL DEFAULT 'Counter'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Repair the rows the narrowing already emptied.
--
--    Restricted to document_status='' - the exact signature of the
--    coercion - rather than guessed at per row. Everything else about
--    those requests is intact (catalog_id, fee, student, purpose), so
--    they are recoverable and it would be wrong to leave them blank.
--
--    filed_at is used as the discriminator for Awaiting_Payment: an
--    online filing entered that state and never left it, and the only
--    other candidates are the two statuses still in the enum.
--
--    A row emptied in ALL THREE columns was filed through the online
--    form, so it goes back to 'online' and is re-staged on what it
--    actually was. The three columns are repaired together because they
--    were corrupted together: a row cannot be meaningfully half-restored.
-- ─────────────────────────────────────────────────────────────────────

UPDATE `document_requests`
   SET `source`           = 'online',
       `document_status`  = 'Filed',
       `fulfillment_type` = 'Pickup'
 WHERE `document_status` = ''
   AND `source`           = ''
   AND `fulfillment_type` = '';

-- Of those, the ones that paid online are waiting on money, not filed.
--
-- paid_at is the discriminator rather than payment_method, because
-- payment_method was NOT coerced (it still held 'Online' on the probe
-- row) and so it is the one column that survived intact.
UPDATE `document_requests`
   SET `document_status` = 'Awaiting_Payment'
 WHERE `source`          = 'online'
   AND `document_status` = ''
   AND `payment_method`  = 'Online'
   AND `paid_at` IS NULL;

-- ─────────────────────────────────────────────────────────────────────
-- 3. The wizard's own columns.
--
--    All ADDITIVE and all guarded, so this file is safe to run twice.
-- ─────────────────────────────────────────────────────────────────────

-- Where the student had got to. 0 = nothing chosen yet.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'wizard_step'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `wizard_step` tinyint(3) NOT NULL DEFAULT 0
     COMMENT 'Furthest wizard step reached: 0 none, 1..4 (drafts only)'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Free-text notes the student typed in "Additional Notes".
--
-- Distinct from `purpose`, which is the one-sentence reason the document
-- is needed. notes is everything else: what the Dean's office already
-- has, which courier window suits, who to call about it.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'notes'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `notes` text NULL
     COMMENT 'Additional notes the student typed, free text'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Which preset purpose was chosen, kept apart from the typed sentence.
--
-- The preset is what the desk FILTERS on (employment / board exam /
-- further studies), and it has to be a controlled value to be filterable
-- at all. Storing it as free text inside `purpose` would make the filter
-- a LIKE across prose.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'purpose_code'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `purpose_code` varchar(40) NULL
     COMMENT 'Controlled purpose: employment|board_exam|further_studies|transfer|other'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- When the student finished the wizard. The stamp that separates "filed"
-- from "still being filled in".
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'submitted_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `submitted_at` datetime NULL
     COMMENT 'When the student completed the wizard and submitted'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The promise the confirmation screen makes, written down once at
-- filing rather than recomputed per page load.
--
-- A date that shifts every time it is derived is a date nobody can quote
-- over the counter. See doc_estimated_release() in shared/doc_wizard.php
-- for how it is calculated.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'estimated_release_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `estimated_release_at` datetime NULL
     COMMENT 'Promised release date, computed once at filing'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The over-the-counter / bank reference a student is quoted.
--
-- Issued at filing so the student can quote it at a bank branch hours
-- before the desk looks at the request, which is the whole point of a
-- reference number. 6 digits from a per-year counter; see
-- doc_payment_reference() for the collision handling.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_reference'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_reference` varchar(24) NULL
     COMMENT 'Payment reference quoted to the student (OTC / bank)'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Withdrawal. Cancelled requests are kept, so the row has to be able to
-- say who cancelled, when, and why.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'cancelled_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `cancelled_at` datetime NULL
     COMMENT 'When the student withdrew the request'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'cancellation_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `cancellation_reason` varchar(255) NULL
     COMMENT 'Why the student withdrew it'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 4. document_type_requirements - the per-document checklist.
--
--    The wizard's upload step lists what THIS document needs ("Clearance
--    from Library", "2x2 ID Picture"), and that list differs per SKU. It
--    cannot live in document_catalog.requirement, which is a single
--    free-text string: there is nowhere to record that an item is
--    required rather than advisory, how many files it accepts, or what
--    order it appears in.
--
--    Data, not code, so the office can add a requirement to a document
--    without a deploy. Required-ness is a column rather than a prefix on
--    the label, because "required" is the one thing the wizard has to be
--    able to ASK the database rather than guess.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `document_type_requirements` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `catalog_id`  int(11) NOT NULL,
  `code`        varchar(40)  NOT NULL COMMENT 'Stable key, e.g. library_clearance',
  `label`       varchar(160) NOT NULL COMMENT 'What the student sees',
  `hint`        varchar(255) NULL     COMMENT 'Format guidance, e.g. "white background"',
  `is_required` tinyint(1)   NOT NULL DEFAULT 1,
  `max_files`   tinyint(3)   NOT NULL DEFAULT 1,
  `sort_order`  int(11)      NOT NULL DEFAULT 0,
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_code` (`catalog_id`, `code`),
  KEY `idx_catalog_active` (`catalog_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────
-- 5. document_request_attachments - one row per uploaded file.
--
--    The wizard's upload step accepts SEVERAL files for one request (one
--    per checklist item, plus a drag-and-drop dropzone), and
--    document_requests.requirement_file_path holds exactly one path.
--    So the wizard's uploads needed somewhere of their own; retrofitting
--    "file1, file2, file3" into one varchar would make every future
--    query a FIND_IN_SET.
--
--    requirement_code ties a file back to the checklist item it satisfies,
--    so "still missing: library clearance" is a LEFT JOIN rather than a
--    guess from the filename.
--
--    The SHA-256 is recorded for the same reason document_requests
--    already keeps record_file_sha256: evidence that can be quietly
--    swapped is not evidence.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `document_request_attachments` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `request_id`      int(11) NOT NULL,
  `requirement_code` varchar(40) NULL COMMENT 'Checklist item, or NULL for a loose file',
  `original_name`   varchar(180) NOT NULL COMMENT 'As uploaded; display only, never a path',
  `file_path`       varchar(255) NOT NULL COMMENT 'Relative to repo root',
  `sha256`          char(64)     NOT NULL COMMENT 'Proves the bytes are unaltered',
  `mime_type`       varchar(80)  NULL,
  `size_bytes`      int(11)      NOT NULL DEFAULT 0,
  `uploaded_at`     datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_request` (`request_id`),
  KEY `idx_att_req_code` (`request_id`, `requirement_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────
-- 6. Seed the checklist.
--
--    Idempotent by (catalog_id, code), and seeded from document_catalog
--    so a SKU added later can be given its own requirements without this
--    file being edited.
--
--    Two items are universal (valid school ID, and the 2x2 photo that
--    the office needs to staple to a printed document), so they are
--    attached to every active SKU rather than repeated seven times.
--
--    Honorable Dismissal additionally needs the Dean's signature, which
--    is the single hardest document to issue and the one most likely to
--    be filed without the paperwork.
-- ─────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'school_id', 'Valid School ID',
       'Front side, name and number clearly readable', 1, 1, 1
  FROM `document_catalog` c WHERE c.is_active = 1;

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'photo_2x2', '2x2 Photo (white background)',
       'Recent, plain background, no hat or sunglasses', 1, 1, 2
  FROM `document_catalog` c WHERE c.is_active = 1;

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'library_clearance', 'Library Clearance',
       'Signed and stamped by the Library', 0, 1, 3
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku IN ('DOC-TOR','DOC-DIPLOMA','DOC-HD');

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'accounting_clearance', 'Accounting Clearance',
       'Signed and stamped by the Accounting Office', 0, 1, 4
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku IN ('DOC-TOR','DOC-DIPLOMA');

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'dean_signature', "Dean's Office Endorsement",
       'The original endorsement letter, not a photo of a photo', 1, 1, 1
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku = 'DOC-HD';

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'affidavit_loss', 'Affidavit of Loss',
       'Signed in front of a notary public, with a valid ID', 1, 1, 1
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku IN ('DOC-DIPLOMA','DOC-HD');

-- ─────────────────────────────────────────────────────────────────────
-- 7. Adopt the pre-existing single requirement file.
--
--    document_requests.requirement_file_path predates this table and
--    already points at a real uploaded file on every request that has
--    one. Leaving it stranded would mean the wizard's upload step says
--    "nothing attached" for a request that plainly has a file, and the
--    student would re-upload a document the office already holds.
--
--    Deliberately NOT done in SQL. The row needs the file's SHA-256 and
--    byte size - otherwise it proves nothing about which bytes are on
--    disk, which is the whole reason the column exists - and it must
--    skip files that are referenced but absent, or the new table gains
--    a row pointing at nothing, which is worse than having no row.
--    Neither is expressible here.
--
--    Run:  php scripts/adopt_legacy_attachments.php
--
--    The existing column is NOT dropped. It is what the walk-in counter
--    form still writes, and a migration that removed it would break a
--    caller in production.
-- ─────────────────────────────────────────────────────────────────────

-- Consolidated: grade_acceptance.sql
-- ============================================================
--  MIGRATIONS/GRADE_ACCEPTANCE.SQL
--  Registrar accepts a section's term, or waits for the grades.
--
--  Faculty Management #296 owns the grades; the Registrar reads them and
--  is responsible for accepting a section's term before it counts. A term
--  is acceptable only when every student on the roster has a grade for
--  every subject recorded against them.
--
--  WHY THESE COLUMNS LIVE ON academic_history AND NOT ON academic_grades.
--  The already-existing academic_grades.term_status describes a SUBJECT
--  row. Acceptance is a fact about a TERM, and it is shared by every
--  subject under it - storing it per subject would mean one fact written
--  N times that could disagree with itself, which is exactly the failure
--  gwa_reported/gwa_computed was introduced to avoid. It sits on the term
--  row beside those two figures, which are also term-level.
--
--  Idempotent: safe to re-run. Guarded per column, because the mysql client
--  stops at the first error and one guard over two ALTERs aborts on a
--  half-run migration, skipping everything after it. Same reason and same
--  shape as migrations/grades_faculty_source.sql.
-- ============================================================

-- ── 1. Who accepted, and when ───────────────────────────────────
-- accepted_at is null until an acceptance actually happens. That null IS
-- the waitlist: a term nobody has accepted reads as outstanding, so no
-- separate "is_accepted" flag is needed and the two cannot drift.
-- accepted_by is the users.id of the registrar who clicked accept; it is
-- kept beside the timestamp rather than inferred, because "who signed
-- this off" must stay answerable after the fact.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'accepted_at');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `accepted_at` timestamp NULL DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'accepted_by');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `accepted_by` int(11) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- No foreign key on accepted_by. The same reasoning as
-- migrations/restore_student_section.sql: users.student_id is ON DELETE
-- SET NULL, and a registrar account being removed must not take an
-- acceptance record with it - the term WAS accepted, and losing that
-- would quietly reopen a closed term.


-- Consolidated: grades_faculty_source.sql
-- ============================================================
--  MIGRATIONS/GRADES_FACULTY_SOURCE.SQL
--  Academic history: grades become Faculty-owned, Registrar reads.
--
--  Boundary change approved 2026-10-02 (see DEPARTMENTS.md):
--  Faculty Management #296 owns the grade record; the Registrar holds
--  terms and subjects for reporting and prints the grade template.
--
--  Everything here is ADDITIVE. No live data is dropped — the only
--  candidates would be the never-populated columns
--  (schedule/room/prerequisite/semester_taken/subject_type), which are
--  where #293's data lands later, so removing them would be churn.
--
--  Idempotent: safe to re-run.
--
--  GUARDED, not bare ALTERs. registrar_ai.sql now declares these columns and
--  indexes, so a fresh install built from the dump made this file abort with
--  ERROR 1060 (Duplicate column name) / 1061 (Duplicate key name) - meaning the
--  dump and this migration could not both be right. Guarding means both are: a
--  no-op where the objects already exist, still additive on an older database
--  that lacks them. tests/dump_freshness.php section 6 caught it.
--
--  EACH COLUMN AND KEY GETS ITS OWN GUARD, because the mysql client stops at
--  the first error: one guard over six ADD COLUMNs is only safe when all six
--  are absent together, and on a half-run migration the single ALTER aborts on
--  the first duplicate and every statement after it is skipped.
--
--  Column order is deliberately NOT pinned with AFTER: on a seeded table the
--  columns are already present in a different position, and re-ordering them
--  would mean the migration was not a no-op. Order carries no meaning here.
-- ============================================================

-- ── 1. Provenance ────────────────────────────────────────────────
-- Where a row came from, so nothing in the UI has to care. 'faculty' is
-- the default and the intended producer; 'import' and 'manual' exist so a
-- backfill or a hand-keyed correction can be identified later rather than
-- silently blending into Faculty-sourced rows.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'source_system');
SET @s := IF(@has = 0,
  "ALTER TABLE `academic_grades` ADD COLUMN `source_system` varchar(32) NOT NULL DEFAULT 'faculty'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'source_ref');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `source_ref` varchar(64) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'faculty_id');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `faculty_id` int(11) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'received_at');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `received_at` timestamp NULL DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'term_status');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `term_status` varchar(20) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 0 = the free-text instructor has NOT been checked against a faculty
-- record. Kept rather than dropped: a name can arrive from Faculty that
-- we have not reconciled, and "unverified" has to be distinguishable
-- from "confirmed" once the reference lands.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND COLUMN_NAME = 'instructor_confirmed');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD COLUMN `instructor_confirmed` tinyint(1) NOT NULL DEFAULT 0',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Re-syncing must not duplicate ────────────────────────────
-- The same subject arrives again on every fetch. Without this, a second
-- sync of an unchanged term doubles every row.
--
-- NULL source_ref rows are exempt because UNIQUE treats NULLs as
-- distinct — that is deliberate: a row with no source key yet (a
-- hand-entered or legacy row) is not claimed by any single source row.
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND INDEX_NAME = 'uq_ag_source');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades`
    ADD UNIQUE KEY `uq_ag_source` (`academic_history_id`,`source_system`,`source_ref`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_grades' AND INDEX_NAME = 'idx_ag_faculty');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_grades` ADD KEY `idx_ag_faculty` (`faculty_id`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Term-level GWA, both figures ──────────────────────────────
-- gwa_reported is Faculty's number; gwa_computed is ours from
-- shared/term_grades.php. They are kept side by side on purpose: once
-- Faculty owns the grades, a local recompute that disagrees with the
-- official figure must be VISIBLE, not silently overwrite it.
--
-- `gwa` (the pre-existing column) is left in place and continues to hold
-- the computed value, so every existing reader — the TOR, the student
-- grade views, gwa_agreement_check.php — keeps working untouched. These
-- two are the new, explicit pair.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'gwa_reported');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `gwa_reported` decimal(5,2) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_history' AND COLUMN_NAME = 'gwa_computed');
SET @s := IF(@has = 0,
  'ALTER TABLE `academic_history` ADD COLUMN `gwa_computed` decimal(5,2) DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 4. Seed the computed figure from the existing `gwa` ──────────
-- The computed value already exists; it was just stored in a column
-- named after the concept rather than the source. This makes the two
-- explicit columns meaningful from day one, so the disagreement check
-- has something to compare on rows that predate the migration.
UPDATE `academic_history`
   SET `gwa_computed` = `gwa`
 WHERE `gwa` IS NOT NULL AND `gwa_computed` IS NULL;

-- ── 5. Backfill received_at for legacy rows ──────────────────────
-- Everything existing predates any source system, so it is recorded as
-- having arrived when the row was created rather than left NULL, which
-- would make "never synced" indistinguishable from "not yet tracked".
UPDATE `academic_grades`
   SET `received_at` = `created_at`
 WHERE `received_at` IS NULL;

-- Consolidated: document_receipt_upload.sql
-- Migration: GCash payment receipt for online document requests
-- Date: 2026-10-02
-- Description:
--   Adds the receipt half of online payment. The gateway confirmation says
--   money moved; the receipt says what it was for. Two independent signals
--   agreeing is worth much more than one, and when they DISAGREE that is
--   precisely the case a registrar needs to look at â€” the gateway knows a
--   transaction id, the receipt carries the GCash reference number finance
--   reconciles on. Neither alone can tell a mislabelled transfer from a
--   wrong amount.
--
--   Design decision (agreed with the requester): the receipt is REQUIRED to
--   leave Awaiting_Payment for Processing, but staff may waive it with a
--   typed reason. Not "optional" â€” optional means nobody uploads, and the
--   people who don't upload are exactly the ones you need to see the
--   receipt for. Not a hard block either â€” that builds a queue of students
--   who genuinely paid and lost the screenshot, which gets resolved
--   informally anyway, leaving you nominally-mandatory-but-actually-not and
--   no way to tell which is which.
--
--   So the record always answers: was the receipt seen, or was it waived,
--   and by whom.
--
--   Safe to run more than once: every statement is guarded by an
--   information_schema check and is a no-op once the column exists.
--
--   NOTE ON document_status: this migration does NOT touch that column.
--   MODIFY COLUMN REPLACES an enum rather than adding to it, and the live
--   table has gained 'Awaiting_Payment' and 'Shipped' since the seed file
--   was written (registrar_ai.sql still lists the older six-value enum).
--   Anyone rebuilding the enum from the seed instead of from SHOW COLUMNS
--   would silently drop both values and rewrite every Awaiting_Payment row
--   to ''. The gate implemented alongside this reads the enum as it stands;
--   it does not redefine it.

-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
-- 1. The receipt file itself.
--
--    Stored as a path relative to the repo root, matching
--    requirement_file_path, and kept in its own directory so the .htaccess
--    that blocks script execution applies to it (see uploads/.htaccess).
--
--    The SHA-256 is recorded for the same reason document_requests already
--    keeps record_file_sha256: to answer "is the file the registrar is
--    looking at the file that was uploaded?" after the fact. A receipt is
--    evidence, and evidence that can be quietly swapped is not evidence.
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_path'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_path` varchar(255) NULL
     COMMENT 'Relative path to the uploaded GCash receipt image/PDF'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_sha256'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_sha256` char(64) NULL
     COMMENT 'SHA-256 of the receipt at upload; proves the file is unaltered'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The name the student UPLOADED under, kept for display only.
--
-- The stored file is renamed (generateFilename()), so payment_receipt_path
-- reads as an opaque hash. A registrar verifying a receipt needs to see
-- "gcash-receipt-2026.jpg", not the hash, and finance matching it against
-- a phone screenshot needs the name they sent it from. It is never used to
-- build a path -- only payment_receipt_path is -- so a hostile filename
-- cannot reach the filesystem through this column.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_filename'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_filename` varchar(180) NULL
     COMMENT 'Original filename as uploaded, for display only'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_uploaded_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_uploaded_at` datetime NULL
     COMMENT 'When the student attached the receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
-- 2. The GCash reference number, typed by the student off the screenshot.
--
--    Separate from payment_ref, which holds the GATEWAY's transaction id.
--    Keeping them apart is the point: when finance reconciles the school
--    account against GCash's settlement report, they match on the GCash
--    reference. Folding one into the other would make a mismatch
--    indistinguishable from a missing value.
--
--    Deliberately NOT an enum and NOT normalised: finance reconciles on the
--    exact string as printed, and a '0'-stripping normaliser would quietly
--    turn a valid 13-digit reference into an unmatchable one.
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_ref'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_ref` varchar(40) NULL
     COMMENT 'GCash reference number as printed on the receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
-- 3. Verification / waiver audit trail.
--
--    Two mutually exclusive outcomes, both leaving a trail:
--      verified_* â€” a registrar looked at the receipt and accepted it.
--      waived_*   â€” they accepted it WITHOUT one, with a typed reason.
--
--    Both also write to document_request_events, so the reason appears in
--    the same timeline as every other status change rather than only in
--    these columns.
-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_verified_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_verified_at` datetime NULL
     COMMENT 'When staff confirmed the receipt matches the request'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_verified_by'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_verified_by` int(11) NULL
     COMMENT 'users.id of the staff member who verified the receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_waived_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_waived_at` datetime NULL
     COMMENT 'When staff accepted the request WITHOUT a receipt'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_waived_by'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_waived_by` int(11) NULL
     COMMENT 'users.id of the staff member who waived the receipt requirement'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_receipt_waived_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_receipt_waived_reason` varchar(255) NULL
     COMMENT 'Why the receipt requirement was waived — required, never blank'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 4. Pickup email audit.
--
--    The user asked for an email when the document is ready. A notification
--    the office cannot prove it sent is one that quietly stops being sent,
--    so the timestamp, the recipients that actually received it, and the
--    failure reason are all recorded here.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'pickup_notified_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `pickup_notified_at` datetime NULL
     COMMENT 'When the pickup/availability email was successfully sent'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'pickup_notified_to'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `pickup_notified_to` varchar(255) NULL
     COMMENT 'Recipients the pickup email actually reached'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'pickup_notify_error'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `pickup_notify_error` varchar(255) NULL
     COMMENT 'Why the pickup email could not be sent; NULL when it sent'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 5. Backfill: requests already paid get their receipt marked as waived,
--    with the reason saying so honestly.
--
--    Without this, every request paid online BEFORE this migration would
--    look like a student who refused to upload, and the desk would chase
--    people who have already paid. The backfilled reason says 'paid before
--    receipts were required', which is the true reason.
--
--    Restricted to ONLINE requests that actually got paid, because those
--    are the only ones the new gate would ever hold up.
--
--    The upload directory itself is NOT created here — it is made lazily by
--    the upload handler, exactly as uploads/document_requirements/ already
--    is. A SQL migration has no business knowing where the repo lives on
--    disk, and the table is the part that genuinely needs a schema.
-- ─────────────────────────────────────────────────────────────────────

UPDATE `document_requests`
   SET `payment_receipt_waived_at`     = COALESCE(`paid_at`, NOW()),
       `payment_receipt_waived_reason` = 'Waived: paid online before receipts were required.'
 WHERE `payment_method` = 'Online'
   AND `paid_at` IS NOT NULL
   AND `payment_receipt_path` IS NULL
   AND `payment_receipt_waived_at` IS NULL;

-- Consolidated: add_previous_school_fields.sql
-- Migration: Add previous school fields to students table
-- Date: 2026-09-23
-- Description: Adds columns for previous school information to support the Personal Info Viewing feature
--
-- GUARDED on existence rather than bare ALTERs. registrar_ai.sql now declares
-- these three columns, so on a fresh install built from the seed this bare
-- statement aborted with ERROR 1060 (Duplicate column name) — which meant the
-- seed and this migration could not both be right, and one of them had to
-- give. Guarding means both are: the migration is a no-op where the columns
-- already exist and still adds them to an older database that lacks them.
--
-- tests/dump_freshness.php imports the seed into an empty database and then
-- replays every migration expecting each to change nothing. That check is what
-- caught the collision.
--
-- EACH COLUMN IS GUARDED SEPARATELY. One guard covering three ADD COLUMNs
-- is only safe when all three are absent together: on a database holding one
-- or two of them — a half-run migration, a manual fix, an interrupted deploy
-- — the single ALTER aborts on the first duplicate and the client stops, so
-- the remaining columns never get added. The client stops at the first error,
-- which means everything after the failure in this file is skipped too.
-- tests/dump_freshness.php section 7 reproduces that state and checks each
-- column comes back.
--
-- Column order is deliberately NOT pinned with AFTER: on a seeded table the
-- columns are already in place and in a different position, and re-ordering
-- them would mean the migration was not a no-op. Order carries no meaning here.
--
-- EACH COLUMN GETS ITS OWN GUARD. One guard covering three ADD COLUMNs is only
-- safe when all three are absent together. On a database holding one or two of
-- them — a half-run migration, a manual fix, an interrupted deploy — the single
-- ALTER aborts on the first duplicate, and because the mysql client stops at
-- the first error, every statement after the failure in this file is skipped
-- too. The migration would then fail to install the very columns it exists to
-- install. tests/dump_freshness.php section 7 reproduces that state and checks
-- each column comes back.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'previous_school'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `previous_school` varchar(150) DEFAULT NULL COMMENT 'Name of previous school'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'school_year_graduated'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `school_year_graduated` varchar(20) DEFAULT NULL COMMENT 'School year graduated from previous school'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
     AND COLUMN_NAME = 'last_year_level_completed'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `students` ADD COLUMN `last_year_level_completed` varchar(30) DEFAULT NULL COMMENT 'Last year level completed in previous school'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- Consolidated: add_queue_daily_tap_cap.sql
-- ============================================================
--  MIGRATIONS/ADD_QUEUE_DAILY_TAP_CAP.SQL
--  A daily cap on the NUMBER OF NUMBERS ISSUED, per day.
--
--  WHY THIS EXISTS
--
--  queue_day_settings had max_taps_student and max_taps_priority, and
--  both were per STUDENT: they counted how many tickets that one person
--  already held today. That is an abuse guard, and it is a real need -
--  but it is not the number the office runs on.
--
--  What the office actually plans against is throughput: how many people
--  the counter can serve in an eight-hour day, roughly 500-600. There was
--  no setting for that at all. With the per-student cap set to the only
--  number anybody could enter, 600, every individual student was allowed
--  600 taps a day and the daily total was unbounded, which is the exact
--  inverse of the intended control.
--
--  So: add the capacity the office plans against, and leave the
--  per-student caps exactly as they were. They guard different things and
--  both are wanted.
--
--  0 means UNLIMITED, matching every other cap in this table, so an
--  install that has not set it behaves as it does today.
-- ============================================================

-- GUARDED on existence rather than a bare ALTER.
--
-- registrar_ai.sql now declares this column, so on a fresh install built from
-- the seed the bare statement aborted with ERROR 1060 (Duplicate column) -
-- which means the seed and this migration could not both be right, and one of
-- them had to give. Guarding means both are: a no-op where the column already
-- exists, and still added to an older database that lacks it.
--
-- tests/dump_freshness.php imports the seed into an empty database and then
-- replays every migration expecting each to change nothing. That check is what
-- caught this collision, so it is written for.
--
-- Order is deliberately NOT pinned with AFTER: on a seeded table the column is
-- already in place and in a different position, and re-ordering it would mean
-- the migration was not a no-op. Order carries no meaning for one column.
--
-- Read once per join, on the same FOR UPDATE block that counts this student's
-- own taps, so the day's total is read under the row lock and two rapid taps
-- cannot both read a stale count and both pass.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'queue_day_settings'
     AND COLUMN_NAME = 'max_daily_taps'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE `queue_day_settings`
     ADD COLUMN `max_daily_taps` int unsigned NOT NULL DEFAULT 0
        COMMENT ''Total numbers issuable for the whole day. 0 = unlimited.''',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Consolidated: add_staff_notification_reads.sql
-- ============================================================
--  Staff notification read cursor.
--
--  The staff bell is fed from audit_logs, which is a shared,
--  append-only trail. Read state therefore cannot live on the
--  trail itself: "read" is a fact about one user looking at one
--  feed, not about the row. This table stores, per user, the
--  highest audit_logs.id they have already seen. Anything newer
--  is unread.
--
--  Deliberately NOT foreign-keyed to users.id. A session's user_id can
--  outlive its row in users (re-seeding, account deletion, a restored
--  backup), and a hard FK then turned every "mark all as read" into a
--  500 — the cursor is a per-user preference, so an orphaned user id
--  is harmless and simply ignored.
--
--  Note on re-runs: the CREATE below is IF NOT EXISTS, so on a database
--  that already has an older copy of this table the CREATE is a no-op
--  and the ALTERs after it are what make the script converge on the
--  intended shape either way.
-- ============================================================

CREATE TABLE IF NOT EXISTS `staff_notification_reads` (
  `user_id`      int(11) NOT NULL,
  `last_read_id` int(11) NOT NULL DEFAULT 0,
  `updated_at`   timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Drop the foreign key if an older copy of this table has one. The
-- ON DELETE CASCADE it provided is no longer wanted: deleting a user
-- should not have to reach into notification state.
SET @drop_fk := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `staff_notification_reads` DROP FOREIGN KEY `fk_snr_user`',
    'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME      = 'staff_notification_reads'
    AND CONSTRAINT_NAME = 'fk_snr_user'
);
PREPARE stmt FROM @drop_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- Consolidated: status_loa_window.sql
-- Migration: LOA and transfer windows
-- Date: 2026-09-28
-- Description:
--   status_tracker already carried effective_date and end_date, described in
--   the project notes as the support for "LOA / transfer windows". Nothing
--   ever wrote them: trackStatusChange() inserted six columns and stopped.
--   So a leave of absence had no expiry. A student granted LOA in March was
--   still on LOA in December, and no review could tell whether the window had
--   closed, because no window had ever been recorded.
--
--   This migration only ensures the columns exist and makes them queryable.
--   It writes no data and changes no existing status: a status is a
--   registrar's decision, and a migration does not get to make one.
--
--   Safe to run more than once: every statement is guarded.

-- ─────────────────────────────────────────────────────────────────────
-- 1. The window columns
--
--    effective_date is when the status actually takes hold. It is not the
--    same as created_at: a status entered on the 28th for a leave starting
--    on the 1st of next month was not effective that day.
--
--    end_date is when the status lapses on its own. Only meaningful for
--    statuses that are time-boxed by nature — leave of absence, a transfer
--    out, a probationary period. Left NULL for statuses that do not expire
--    (active, at-risk, dropped, graduated).
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_tracker' AND COLUMN_NAME = 'effective_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `status_tracker` ADD COLUMN `effective_date` date DEFAULT NULL COMMENT 'When this status actually takes effect'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_tracker' AND COLUMN_NAME = 'end_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `status_tracker` ADD COLUMN `end_date` date DEFAULT NULL COMMENT 'When a time-boxed status lapses (LOA, transfer, probation)'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Make "who is past their window" a cheap question
--
--    The check is a range scan on end_date alone, and the page asks it on
--    every load. Without this index the sweep reads the whole journal and
--    sorts it, which is exactly the cost a review page cannot afford.
-- ─────────────────────────────────────────────────────────────────────

SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_tracker' AND INDEX_NAME = 'idx_student_end_date'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `status_tracker` ADD INDEX `idx_student_end_date` (`student_id`, `end_date`)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


SET FOREIGN_KEY_CHECKS=@bundle_old_fk;
SELECT role,COUNT(*) AS accounts FROM users GROUP BY role;
SELECT id,username,role FROM users WHERE role NOT IN ('super_admin','registrar_admin','staff','acad_officer','department_head','student');
