-- ============================================================
--  REGISTRAR_AI.SQL  —  full schema + seed snapshot
-- ============================================================
--  Database:       registrar_ai
--  Server version: 10.4.32-MariaDB
--  PHP version:    8.0.30
--  Generated:      Aug 24, 2026 at 08:25 EDT  (mysqldump)
--
--  Import with:  mysql -u root registrar_ai < registrar_ai.sql
--  Each table is dropped before it is recreated, so importing
--  over an existing registrar_ai REPLACES it. Foreign key checks
--  are disabled for the duration of the import, so table order
--  in this file does not matter.
--
--  All 23 tables are dumped with their structure. Rows are
--  included for 19 of them. These four are structure-only on
--  purpose — they hold live security tokens or regenerable
--  cache, and neither belongs in version control:
--      otp_codes         one-time login codes (otp_hash)
--      login_attempts    lockout / rate-limit state per IP
--      ai_cache          cached AI gateway responses
--      masterlist_cache  cached masterlist lookups
--
--  Incremental migrations that ran on top of this baseline live
--  in database/*.sql. This file is the consolidated result.
-- ============================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure
--
DROP TABLE IF EXISTS `academic_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  PRIMARY KEY (`id`),
  KEY `idx_academic_history_id` (`academic_history_id`),
  CONSTRAINT `fk_grade_academy` FOREIGN KEY (`academic_history_id`) REFERENCES `academic_history` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `academic_history`
--

DROP TABLE IF EXISTS `academic_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `academic_history_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_cache`
--

DROP TABLE IF EXISTS `ai_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `body` text DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_author` (`author_id`),
  KEY `idx_published` (`is_published`,`created_at`),
  CONSTRAINT `fk_announcement_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `authorized_cards`
--

DROP TABLE IF EXISTS `authorized_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `authorized_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_uid` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` enum('admin','registrar','superadmin') DEFAULT 'registrar',
  `can_change_station` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_uid` (`card_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clearances`
--

DROP TABLE IF EXISTS `clearances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clearances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `status` enum('pending','partial','cleared') NOT NULL DEFAULT 'pending',
  `issued_by` int(11) DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clearance_student` (`student_id`),
  KEY `idx_clearance_status` (`status`),
  KEY `fk_clearance_issued_by` (`issued_by`),
  CONSTRAINT `fk_clearance_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clearance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_requests`
--

DROP TABLE IF EXISTS `document_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `document_type` enum('form137','good_moral','transcript','certificate','clearance') NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `status` enum('pending','processing','approved','denied','completed','released') DEFAULT 'pending',
  `processed_by` int(11) DEFAULT NULL,
  `denial_reason` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_date` datetime DEFAULT NULL,
  `completed_date` datetime DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT 0.00,
  `official_receipt` varchar(40) DEFAULT NULL,
  `release_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_document_type` (`document_type`),
  CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `doc_type` enum('enrollment','transcript','health','photo','clearance','other') NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` varchar(40) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_doc_type` (`doc_type`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `emergency_contacts`
--

DROP TABLE IF EXISTS `emergency_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `guardians`
--

DROP TABLE IF EXISTS `guardians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `health_records`
--

DROP TABLE IF EXISTS `health_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `health_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `pre_existing_conditions` text DEFAULT NULL,
  `immunization_records` text DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `clinic_visits` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `blood_pressure` varchar(12) DEFAULT NULL,
  `dietary_restrictions` text DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `surgical_history` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `health_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `health_visits`
--

DROP TABLE IF EXISTS `health_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `health_visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `visit_date` date DEFAULT NULL,
  `complaint` varchar(255) DEFAULT NULL,
  `diagnosis` varchar(255) DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `blood_pressure` varchar(12) DEFAULT NULL,
  `treatment` varchar(255) DEFAULT NULL,
  `medication` text DEFAULT NULL,
  `physician` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `fk_visit_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_ip_time` (`email`,`ip_address`,`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `masterlist_cache`
--

DROP TABLE IF EXISTS `masterlist_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `masterlist_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `query_hash` varchar(64) NOT NULL,
  `query_text` text DEFAULT NULL,
  `result_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_data`)),
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `query_hash` (`query_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_query_hash` (`query_hash`),
  CONSTRAINT `masterlist_cache_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `otp_codes`
--

DROP TABLE IF EXISTS `otp_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `purpose` enum('login','reset') NOT NULL DEFAULT 'login',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`),
  KEY `idx_otp_purpose` (`user_id`,`purpose`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `queue_tickets`
--

DROP TABLE IF EXISTS `queue_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  `card_uid` varchar(50) DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `called_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_day` (`queue_date`,`ticket_number`),
  KEY `idx_queue_date_status` (`queue_date`,`status`),
  KEY `idx_student` (`student_id`),
  KEY `idx_joined_at` (`joined_at`),
  CONSTRAINT `fk_queue_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rfid_cards`
--

DROP TABLE IF EXISTS `rfid_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rfid_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `card_uid` varchar(50) NOT NULL,
  `card_type` enum('rfid','qrcode') DEFAULT 'rfid',
  `status` enum('active','inactive','lost','expired') DEFAULT 'active',
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qr_code_path` varchar(255) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_uid` (`card_uid`),
  KEY `student_id` (`student_id`),
  KEY `idx_card_uid` (`card_uid`),
  KEY `idx_status` (`status`),
  CONSTRAINT `rfid_cards_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rfid_scan_logs`
--

DROP TABLE IF EXISTS `rfid_scan_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rfid_scan_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_uid` varchar(50) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT 'Main Gate',
  `event_type` enum('entry','exit','library','cafeteria','other','queue_join') DEFAULT 'entry',
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `status_tracker`
--

DROP TABLE IF EXISTS `status_tracker`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  CONSTRAINT `status_tracker_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `status_tracker_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `student_ids`
--

DROP TABLE IF EXISTS `student_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_ids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `id_number` varchar(20) NOT NULL,
  `id_type` enum('school_id','library','cafeteria') DEFAULT 'school_id',
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','inactive','lost') DEFAULT 'active',
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qr_payload` varchar(255) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `card_color` varchar(20) DEFAULT 'blue',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number` (`id_number`),
  KEY `student_id` (`student_id`),
  KEY `idx_id_number` (`id_number`),
  KEY `idx_status` (`status`),
  CONSTRAINT `student_ids_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_number` varchar(20) NOT NULL,
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
  `status` enum('active','probation','at-risk','loa','enrolled','graduated','transferred','dropped') DEFAULT 'enrolled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lrn` varchar(12) DEFAULT NULL COMMENT 'LRN - Learner Reference Number',
  `name_suffix` varchar(10) DEFAULT NULL COMMENT 'e.g. Jr., III, Sr.',
  `mother_name` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `birth_country` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_number` (`student_number`),
  KEY `idx_student_number` (`student_number`),
  KEY `idx_status` (`status`),
  KEY `idx_course` (`course`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','registrar','staff','teacher','student') NOT NULL DEFAULT 'staff',
  `rfid_uid` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_id` int(11) DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_student_id` (`student_id`),
  CONSTRAINT `fk_users_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Data
--

-- Dumping data for table `academic_grades`
--

LOCK TABLES `academic_grades` WRITE;
/*!40000 ALTER TABLE `academic_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `academic_history`
--

LOCK TABLES `academic_history` WRITE;
/*!40000 ALTER TABLE `academic_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
INSERT INTO `announcements` (`id`, `title`, `body`, `author_id`, `is_published`, `created_at`, `updated_at`) VALUES (1,'Midterm Grades Available','Midterm grades are now available. Please check your portal.',2,1,'2026-08-16 18:33:48','2026-08-24 22:13:19');
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES (2,3,'user_create','users',4,NULL,NULL,'::1','curl/8.21.0','2026-08-11 18:53:47'),(3,3,'user_disable','users',4,NULL,NULL,'::1','curl/8.21.0','2026-08-11 18:54:51'),(4,3,'queue_call_next','queue_tickets',1,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:04:00'),(5,3,'queue_complete','queue_tickets',1,'{\"status\":\"serving\"}','{\"status\":\"completed\"}','::1','curl/8.21.0','2026-08-11 21:04:15'),(6,3,'queue_call_next','queue_tickets',2,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:04:27'),(7,3,'queue_skip','queue_tickets',2,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:04:27'),(8,3,'queue_call_next','queue_tickets',3,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:04:52'),(9,3,'queue_skip','queue_tickets',3,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:04:52'),(10,3,'queue_call_next','queue_tickets',4,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:05:34'),(11,3,'queue_skip','queue_tickets',4,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:05:53'),(12,3,'queue_call_next','queue_tickets',5,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:05:53'),(13,3,'queue_no_show','queue_tickets',5,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:06:21'),(14,3,'queue_remove','queue_tickets',6,'{\"status\":\"waiting\"}','{\"status\":\"removed\"}','::1','curl/8.21.0','2026-08-11 21:07:06'),(15,3,'queue_call_next','queue_tickets',8,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(16,3,'queue_skip','queue_tickets',8,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(17,3,'queue_call_next','queue_tickets',9,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(18,3,'queue_complete','queue_tickets',9,'{\"status\":\"serving\"}','{\"status\":\"completed\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(19,5,'document_request_submit','document_requests',4,NULL,NULL,'::1','curl/8.21.0','2026-08-16 18:33:44'),(20,2,'announcement_create','announcements',1,NULL,NULL,'::1','curl/8.21.0','2026-08-16 18:33:48'),(21,3,'queue_complete','queue_tickets',10,'{\"status\":\"serving\"}','{\"status\":\"completed\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 18:35:08'),(22,3,'queue_call_next','queue_tickets',11,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 18:35:14'),(23,3,'queue_skip','queue_tickets',11,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 18:35:34'),(24,2,'queue_call_next','queue_tickets',12,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-16 19:02:39'),(25,2,'queue_no_show','queue_tickets',12,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-16 19:02:39'),(26,1,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'0.0.0.0',NULL,'2026-08-16 19:26:32'),(27,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:28:05'),(28,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:28:14'),(29,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:29:35'),(30,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 19:29:35'),(31,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:29:44'),(32,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"how to get good moral\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:39:17'),(33,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"I need my transcript of records, what should I do and how long does it take?\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:39:18'),(34,5,'queue_cancel','queue_tickets',12,'{\"details\":\"Cancelled own ticket #012\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:54:13'),(35,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:59:35'),(36,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 19:59:35'),(37,5,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:59:46'),(38,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 19:59:46'),(39,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"reset\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:00:00'),(40,2,'password_reset',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 20:00:01'),(41,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:00:12'),(42,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 20:00:13'),(43,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"I am requesting my transcript of records. Could you explain the steps and how long it typically takes to process?\\\",\\\"tier\\\":\\\"llm:transcript-of-records-request\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:12:18'),(44,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"qwerty zzzyyy 12345 blorp ing bing bop\\\",\\\"tier\\\":\\\"llm:unintelligible-input\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:12:27'),(45,5,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:18:11'),(46,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:18:18'),(47,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:19:40'),(48,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"1+1\\\",\\\"tier\\\":\\\"llm:out-of-scope-math\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:19:57'),(49,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"i cant see my grade\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:20:14'),(50,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"how can i retrieve my TOR\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:20:33'),(51,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:21:07'),(52,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"explain que management\\\",\\\"tier\\\":\\\"llm:queue-management\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:21:52'),(53,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"how about my academic records\\\",\\\"tier\\\":\\\"llm:academic-records\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:22:29'),(54,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"why was my que number cancelled?\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:24:41'),(55,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 16:48:53'),(56,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 16:49:06'),(57,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hello\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 16:49:29'),(58,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 18:38:39'),(59,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 18:38:58'),(60,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:28:55'),(61,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:48'),(62,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:51'),(63,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:53'),(64,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:55'),(65,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:56'),(66,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"what is ai\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:53:01'),(67,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:08:29'),(68,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"ho\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:24'),(69,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"document process\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:30'),(70,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:38'),(71,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"adada\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:42'),(72,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"health record\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:47'),(73,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"document request\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:56'),(74,3,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:13:00'),(75,3,'announcement_update','announcements',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:13:18'),(76,3,'announcement_update','announcements',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:13:19'),(77,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 23:09:04');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `authorized_cards`
--

LOCK TABLES `authorized_cards` WRITE;
/*!40000 ALTER TABLE `authorized_cards` DISABLE KEYS */;
/*!40000 ALTER TABLE `authorized_cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `clearances`
--

LOCK TABLES `clearances` WRITE;
/*!40000 ALTER TABLE `clearances` DISABLE KEYS */;
/*!40000 ALTER TABLE `clearances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `documents`
--

LOCK TABLES `documents` WRITE;
/*!40000 ALTER TABLE `documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `document_requests`
--

LOCK TABLES `document_requests` WRITE;
/*!40000 ALTER TABLE `document_requests` DISABLE KEYS */;
INSERT INTO `document_requests` (`id`, `student_id`, `document_type`, `purpose`, `recipient`, `status`, `processed_by`, `denial_reason`, `file_path`, `request_date`, `processed_date`, `completed_date`, `fee_amount`, `official_receipt`, `release_date`) VALUES (1,1,'form137','Transfer to UP Manila',NULL,'pending',NULL,NULL,NULL,'2026-07-07 10:42:46',NULL,NULL,0.00,NULL,NULL),(2,2,'good_moral','Job application',NULL,'approved',NULL,NULL,NULL,'2026-07-06 10:42:46',NULL,NULL,0.00,NULL,NULL),(3,3,'transcript','Scholarship application',NULL,'processing',NULL,NULL,NULL,'2026-07-05 10:42:46',NULL,NULL,0.00,NULL,NULL),(4,1,'good_moral','Job application','HR Dept','pending',NULL,NULL,NULL,'2026-08-16 18:33:44',NULL,NULL,0.00,NULL,NULL);
/*!40000 ALTER TABLE `document_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `emergency_contacts`
--

LOCK TABLES `emergency_contacts` WRITE;
/*!40000 ALTER TABLE `emergency_contacts` DISABLE KEYS */;
/*!40000 ALTER TABLE `emergency_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `guardians`
--

LOCK TABLES `guardians` WRITE;
/*!40000 ALTER TABLE `guardians` DISABLE KEYS */;
INSERT INTO `guardians` (`id`, `student_id`, `full_name`, `relationship`, `contact_number`, `email`, `address`, `is_primary`, `is_emergency`, `created_at`) VALUES (1,1,'Ramon Dela Cruz','father','09171234560',NULL,NULL,1,1,'2026-07-07 10:42:46'),(2,1,'Elena Dela Cruz','mother','09171234561',NULL,NULL,0,1,'2026-07-07 10:42:46'),(3,2,'Carlos Santos','father','09181234570',NULL,NULL,1,1,'2026-07-07 10:42:46');
/*!40000 ALTER TABLE `guardians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `health_records`
--

LOCK TABLES `health_records` WRITE;
/*!40000 ALTER TABLE `health_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `health_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `health_visits`
--

LOCK TABLES `health_visits` WRITE;
/*!40000 ALTER TABLE `health_visits` DISABLE KEYS */;
/*!40000 ALTER TABLE `health_visits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `queue_tickets`
--

LOCK TABLES `queue_tickets` WRITE;
/*!40000 ALTER TABLE `queue_tickets` DISABLE KEYS */;
INSERT INTO `queue_tickets` (`id`, `queue_date`, `ticket_number`, `student_id`, `student_name`, `student_number`, `course`, `status`, `counter`, `card_uid`, `joined_at`, `called_at`, `served_at`) VALUES (12,'2026-08-16',1,1,'Juan Dela Cruz','2026-0001','BSIT','cancelled',1,NULL,'2026-08-16 03:01:52','2026-08-16 15:02:39','2026-08-16 15:54:13');
/*!40000 ALTER TABLE `queue_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `rfid_cards`
--

LOCK TABLES `rfid_cards` WRITE;
/*!40000 ALTER TABLE `rfid_cards` DISABLE KEYS */;
INSERT INTO `rfid_cards` (`id`, `student_id`, `card_uid`, `card_type`, `status`, `issued_date`, `expiry_date`, `notes`, `created_at`, `qr_code_path`, `issued_at`) VALUES (9,1,'0006929950','rfid','active','2026-07-14','2027-07-15','','2026-07-14 09:26:57',NULL,'2026-08-11 06:47:20');
/*!40000 ALTER TABLE `rfid_cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `rfid_scan_logs`
--

LOCK TABLES `rfid_scan_logs` WRITE;
/*!40000 ALTER TABLE `rfid_scan_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `rfid_scan_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `status_tracker`
--

LOCK TABLES `status_tracker` WRITE;
/*!40000 ALTER TABLE `status_tracker` DISABLE KEYS */;
INSERT INTO `status_tracker` (`id`, `student_id`, `previous_status`, `current_status`, `reason`, `changed_by`, `created_at`, `effective_date`, `end_date`) VALUES (1,1,NULL,'active','New student enrolled',NULL,'2026-07-07 10:42:46',NULL,NULL),(2,2,NULL,'active','New student enrolled',NULL,'2026-07-07 10:42:46',NULL,NULL),(3,3,NULL,'active','New student enrolled',NULL,'2026-07-07 10:42:46',NULL,NULL);
/*!40000 ALTER TABLE `status_tracker` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` (`id`, `student_number`, `first_name`, `middle_name`, `last_name`, `gender`, `civil_status`, `birth_date`, `place_of_birth`, `nationality`, `religion`, `address`, `contact_number`, `email`, `photo`, `course`, `major`, `year_level`, `school_year`, `semester`, `adviser_id`, `section`, `status`, `created_at`, `updated_at`, `lrn`, `name_suffix`, `mother_name`, `father_name`, `birth_country`) VALUES (1,'2026-0001','Juan',NULL,'Dela Cruz',NULL,NULL,'2005-05-15',NULL,NULL,NULL,'123 Main St., Manila','09171234567','juan@email.com','./assets/uploads/students/student_1_1787564957.gif','BS Computer Science',NULL,1,NULL,NULL,NULL,NULL,'active','2026-07-07 10:42:46','2026-08-24 09:49:17',NULL,NULL,NULL,NULL,NULL),(2,'2026-0002','Maria',NULL,'Santos',NULL,NULL,'2006-03-20',NULL,NULL,NULL,'456 Oak St., Quezon City','09181234568','maria@email.com',NULL,'BS Education',NULL,1,NULL,NULL,NULL,NULL,'active','2026-07-07 10:42:46','2026-07-07 10:42:46',NULL,NULL,NULL,NULL,NULL),(3,'2026-0003','Ana',NULL,'Reyes',NULL,NULL,'2005-11-10',NULL,NULL,NULL,'789 Pine St., Pasig','09191234569','ana@email.com',NULL,'BS Nursing',NULL,1,NULL,NULL,NULL,NULL,'active','2026-07-07 10:42:46','2026-07-07 10:42:46',NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `student_ids`
--

LOCK TABLES `student_ids` WRITE;
/*!40000 ALTER TABLE `student_ids` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_ids` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`id`, `email`, `password_hash`, `full_name`, `role`, `rfid_uid`, `is_active`, `created_at`, `updated_at`, `student_id`, `username`, `login_attempts`, `locked_until`) VALUES (1,'admin@bestlink.edu.ph','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','System Administrator','admin',NULL,1,'2026-07-07 10:42:45','2026-08-24 04:47:37',NULL,'ADM-001',0,NULL),(2,'registrar@bestlink.edu.ph','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','Registrar Staff','registrar',NULL,1,'2026-07-07 10:42:45','2026-08-24 04:47:37',NULL,'RGS-001',0,NULL),(3,'roldantiu89@gmail.com','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','Roldan Tiu','admin',NULL,1,'2026-08-11 11:40:30','2026-08-24 04:47:37',NULL,'ADM-002',0,NULL),(5,'juan.student@bestlink.edu.ph','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','Juan Dela Cruz (Test Student)','student',NULL,1,'2026-08-16 06:32:46','2026-08-24 04:48:53',1,'2026-0001',0,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
