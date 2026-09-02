-- ============================================================
--  REGISTRAR_AI.SQL  —  full schema + seed snapshot
-- ============================================================
--  Database:       registrar_ai
--  Server version: 10.4.32-MariaDB
--  PHP version:    8.0.30
--  Generated:      Aug 26, 2026 at 13:51   (mysqldump)
--
--  Import with:  mysql -u root registrar_ai < registrar_ai.sql
--  Each table is dropped before it is recreated, so importing
--  over an existing registrar_ai REPLACES it. Foreign key checks
--  are disabled for the duration of the import, so table order
--  in this file does not matter.
--
--  All 29 tables are dumped with their structure. Rows are
--  included for 15 of them. Four are structure-only on purpose —
--  they hold live security tokens or regenerable cache, and
--  neither belongs in version control:
--      otp_codes         one-time login codes (otp_hash)
--      login_attempts    lockout / rate-limit state per IP
--      ai_cache          cached AI gateway responses
--      masterlist_cache  cached masterlist lookups
--  The remaining 10 tables are also structure-only in this
--  snapshot simply because they hold no rows in the live DB.
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

LOCK TABLES `academic_grades` WRITE;
/*!40000 ALTER TABLE `academic_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_grades` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `academic_history` WRITE;
/*!40000 ALTER TABLE `academic_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_history` ENABLE KEYS */;
UNLOCK TABLES;
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
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (2,3,'user_create','users',4,NULL,NULL,'::1','curl/8.21.0','2026-08-11 18:53:47'),(3,3,'user_disable','users',4,NULL,NULL,'::1','curl/8.21.0','2026-08-11 18:54:51'),(4,3,'queue_call_next','queue_tickets',1,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:04:00'),(5,3,'queue_complete','queue_tickets',1,'{\"status\":\"serving\"}','{\"status\":\"completed\"}','::1','curl/8.21.0','2026-08-11 21:04:15'),(6,3,'queue_call_next','queue_tickets',2,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:04:27'),(7,3,'queue_skip','queue_tickets',2,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:04:27'),(8,3,'queue_call_next','queue_tickets',3,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:04:52'),(9,3,'queue_skip','queue_tickets',3,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:04:52'),(10,3,'queue_call_next','queue_tickets',4,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:05:34'),(11,3,'queue_skip','queue_tickets',4,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:05:53'),(12,3,'queue_call_next','queue_tickets',5,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:05:53'),(13,3,'queue_no_show','queue_tickets',5,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:06:21'),(14,3,'queue_remove','queue_tickets',6,'{\"status\":\"waiting\"}','{\"status\":\"removed\"}','::1','curl/8.21.0','2026-08-11 21:07:06'),(15,3,'queue_call_next','queue_tickets',8,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(16,3,'queue_skip','queue_tickets',8,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(17,3,'queue_call_next','queue_tickets',9,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(18,3,'queue_complete','queue_tickets',9,'{\"status\":\"serving\"}','{\"status\":\"completed\"}','::1','curl/8.21.0','2026-08-11 21:21:13'),(19,5,'document_request_submit','document_requests',4,NULL,NULL,'::1','curl/8.21.0','2026-08-16 18:33:44'),(20,2,'announcement_create','announcements',1,NULL,NULL,'::1','curl/8.21.0','2026-08-16 18:33:48'),(21,3,'queue_complete','queue_tickets',10,'{\"status\":\"serving\"}','{\"status\":\"completed\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 18:35:08'),(22,3,'queue_call_next','queue_tickets',11,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 18:35:14'),(23,3,'queue_skip','queue_tickets',11,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 18:35:34'),(24,2,'queue_call_next','queue_tickets',12,'{\"status\":\"waiting\"}','{\"status\":\"serving\"}','::1','curl/8.21.0','2026-08-16 19:02:39'),(25,2,'queue_no_show','queue_tickets',12,'{\"status\":\"serving\"}','{\"status\":\"no-show\"}','::1','curl/8.21.0','2026-08-16 19:02:39'),(26,1,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'0.0.0.0',NULL,'2026-08-16 19:26:32'),(27,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:28:05'),(28,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:28:14'),(29,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:29:35'),(30,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 19:29:35'),(31,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:29:44'),(32,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"how to get good moral\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:39:17'),(33,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"I need my transcript of records, what should I do and how long does it take?\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:39:18'),(34,5,'queue_cancel','queue_tickets',12,'{\"details\":\"Cancelled own ticket #012\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:54:13'),(35,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:59:35'),(36,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 19:59:35'),(37,5,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 19:59:46'),(38,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 19:59:46'),(39,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"reset\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:00:00'),(40,2,'password_reset',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 20:00:01'),(41,2,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:00:12'),(42,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-16 20:00:13'),(43,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"I am requesting my transcript of records. Could you explain the steps and how long it typically takes to process?\\\",\\\"tier\\\":\\\"llm:transcript-of-records-request\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:12:18'),(44,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"qwerty zzzyyy 12345 blorp ing bing bop\\\",\\\"tier\\\":\\\"llm:unintelligible-input\\\"}\"}',NULL,'::1','curl/8.21.0','2026-08-16 20:12:27'),(45,5,'otp_issued',NULL,NULL,'{\"details\":\"{\\\"purpose\\\":\\\"login\\\",\\\"delivered\\\":false}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:18:11'),(46,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:18:18'),(47,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:19:40'),(48,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"1+1\\\",\\\"tier\\\":\\\"llm:out-of-scope-math\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:19:57'),(49,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"i cant see my grade\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:20:14'),(50,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"how can i retrieve my TOR\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:20:33'),(51,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:21:07'),(52,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"explain que management\\\",\\\"tier\\\":\\\"llm:queue-management\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:21:52'),(53,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"how about my academic records\\\",\\\"tier\\\":\\\"llm:academic-records\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:22:29'),(54,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"why was my que number cancelled?\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-16 20:24:41'),(55,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 16:48:53'),(56,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 16:49:06'),(57,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hello\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 16:49:29'),(58,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 18:38:39'),(59,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 18:38:58'),(60,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:28:55'),(61,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:48'),(62,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:51'),(63,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:53'),(64,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:55'),(65,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:greeting\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:52:56'),(66,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"what is ai\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 20:53:01'),(67,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:08:29'),(68,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"ho\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:24'),(69,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"document process\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:30'),(70,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"hi\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:38'),(71,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"adada\\\",\\\"tier\\\":\\\"llm:fallback\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:42'),(72,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"health record\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:47'),(73,5,'student_ai_chat',NULL,NULL,'{\"details\":\"{\\\"question\\\":\\\"document request\\\",\\\"tier\\\":\\\"keyword\\\"}\"}',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:11:56'),(74,3,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:13:00'),(75,3,'announcement_update','announcements',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:13:18'),(76,3,'announcement_update','announcements',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 22:13:19'),(77,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-24 23:09:04'),(78,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-25 00:49:45'),(79,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 01:53:28'),(80,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 03:42:57'),(81,5,'document_request_submit','document_requests',5,NULL,'{\"request_id\":\"DOC-2026-0005\",\"catalog_id\":1,\"fee\":500,\"document_status\":\"Pending_Clearance\"}','::1','curl/8.21.0','2026-08-27 03:42:57'),(82,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 03:43:18'),(83,5,'document_request_submit','document_requests',6,NULL,'{\"request_id\":\"DOC-2026-0006\",\"catalog_id\":2,\"fee\":100,\"document_status\":\"Awaiting_Payment\"}','::1','curl/8.21.0','2026-08-27 03:43:18'),(84,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 03:44:15'),(85,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 03:44:57'),(86,2,'login_success',NULL,NULL,NULL,NULL,'::1',NULL,'2026-08-27 04:03:13'),(93,2,'exit_clearance_clear','exit_clearances',7,NULL,'{\"office\":\"Alumni\"}','::1',NULL,'2026-08-27 04:03:13'),(94,2,'exit_clearance_clear','exit_clearances',7,NULL,'{\"office\":\"Dean\"}','::1',NULL,'2026-08-27 04:03:13'),(95,2,'exit_clearance_clear','exit_clearances',7,NULL,'{\"office\":\"Property\"}','::1',NULL,'2026-08-27 04:03:13'),(97,5,'login_success',NULL,NULL,NULL,NULL,'::1',NULL,'2026-08-27 04:04:29'),(98,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:06:11'),(99,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:07:21'),(100,5,'document_request_submit','document_requests',9,NULL,'{\"request_id\":\"DOC-2026-0005\",\"catalog_id\":2,\"fee\":100,\"document_status\":\"Pending_Clearance\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:08:21'),(101,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:11:52'),(102,2,'document_request_process','document_requests',9,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:12:02'),(103,2,'document_pdf_generate','document_requests',9,NULL,'{\"filename\":\"DOC-2026-0005-DOC-COE.pdf\",\"fingerprint\":\"454c709e4f5c8de680749b53450a57e6a0d4ff63535b6c01939ff027cdbb1aba\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:12:17'),(104,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:12:45'),(105,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:14:43'),(106,2,'document_pdf_generate','document_requests',9,NULL,'{\"filename\":\"DOC-2026-0005-DOC-COE.pdf\",\"fingerprint\":\"4f90d94f9a2bc0fcf6f45927a61157be57e78bda2ed597d3a0b4ac43cae2b7cb\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:14:47'),(107,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:15:09'),(108,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 04:20:56'),(109,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:22:15'),(110,2,'document_pdf_generate','document_requests',9,NULL,'{\"filename\":\"DOC-2026-0005-DOC-COE.pdf\",\"fingerprint\":\"afe80ace617c05a8260ccab916c630add00045e7c5f80620847e81dff57853ba\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:22:29'),(111,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:22:45'),(112,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:26:14'),(113,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 04:30:50'),(114,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 04:31:04'),(115,2,'document_request_ready','document_requests',9,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:31:31'),(116,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:31:45'),(117,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:35:42'),(118,6,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 04:40:05'),(119,5,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:40:26'),(120,6,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:41:00'),(121,6,'document_request_submit','document_requests',10,NULL,'{\"request_id\":\"DOC-2026-0006\",\"catalog_id\":3,\"fee\":150,\"document_status\":\"Awaiting_Payment\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:42:05'),(122,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:42:40'),(123,2,'document_request_ready','document_requests',10,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:42:47'),(124,2,'document_request_claim','document_requests',10,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:43:02'),(125,6,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 04:43:10'),(126,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:34:33'),(127,6,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:40:26'),(128,6,'document_request_submit','document_requests',11,NULL,'{\"request_id\":\"DOC-2026-0007\",\"catalog_id\":5,\"fee\":50,\"document_status\":\"Awaiting_Payment\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:41:31'),(129,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:42:14'),(130,2,'document_request_ready','document_requests',11,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:42:23'),(131,6,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:43:17'),(132,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:44:07'),(133,2,'document_request_claim','document_requests',11,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:44:15'),(134,6,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:44:31'),(135,5,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 05:45:35'),(136,2,'login_success',NULL,NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-27 05:46:09'),(137,5,'document_request_submit','document_requests',12,NULL,'{\"request_id\":\"DOC-2026-0008\",\"catalog_id\":1,\"fee\":500,\"document_status\":\"Awaiting_Payment\"}','::1','curl/8.21.0','2026-08-27 05:46:54'),(138,5,'document_request_submit','document_requests',13,NULL,'{\"request_id\":\"DOC-2026-0009\",\"catalog_id\":2,\"fee\":100,\"document_status\":\"Processing\"}','::1','curl/8.21.0','2026-08-27 05:47:23'),(139,2,'login_success',NULL,NULL,NULL,NULL,'::1','curl/8.21.0','2026-08-27 05:47:46'),(140,2,'document_request_claim','document_requests',13,NULL,NULL,'::1','curl/8.21.0','2026-08-27 05:48:34');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `authorized_cards` WRITE;
/*!40000 ALTER TABLE `authorized_cards` DISABLE KEYS */;
/*!40000 ALTER TABLE `authorized_cards` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `document_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_catalog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `base_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fee_type` enum('flat','per_page','per_syllabus') NOT NULL DEFAULT 'flat',
  `requirement` text DEFAULT NULL,
  `triggers_exit_clearance` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sku` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `document_catalog` WRITE;
/*!40000 ALTER TABLE `document_catalog` DISABLE KEYS */;
INSERT INTO `document_catalog` VALUES (1,'DOC-TOR','Transcript of Records','Complete academic record (TOR)',250.00,'per_page','Scanned copy of valid ID',1,1,'2026-08-26 15:26:51'),(2,'DOC-COE','Certificate of Enrollment','Proof of current enrollment',100.00,'flat',NULL,0,1,'2026-08-26 15:26:51'),(3,'DOC-GM','Certificate of Good Moral','Good moral character certificate',150.00,'flat','No pending disciplinary cases',0,1,'2026-08-26 15:26:51'),(4,'DOC-DIPLOMA','Diploma Replacement','Replacement of lost diploma',1000.00,'flat','Notarized Affidavit of Loss',0,1,'2026-08-26 15:26:51'),(5,'DOC-CTC','Certified True Copy','Certified true copy of a record',50.00,'per_page',NULL,0,1,'2026-08-26 15:26:51'),(6,'DOC-HD','Honorable Dismissal','Transfer / honorable dismissal',300.00,'flat','Completed Exit Clearance',1,1,'2026-08-26 15:26:51'),(7,'DOC-CD','Course Description','Subject syllabus / course description',100.00,'per_syllabus',NULL,0,1,'2026-08-26 15:26:51');
/*!40000 ALTER TABLE `document_catalog` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `document_request_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `document_request_events` WRITE;
/*!40000 ALTER TABLE `document_request_events` DISABLE KEYS */;
INSERT INTO `document_request_events` VALUES (15,9,'Pending_Clearance','Request submitted (DOC-2026-0005) — held pending clearance',5,'2026-08-27 00:08:21'),(16,9,'Processing','Started processing',2,'2026-08-27 00:12:02'),(17,9,'Processing','Digital PDF generated · SHA-256 454c709e4f5c…',2,'2026-08-27 00:12:17'),(18,9,'Processing','Digital PDF generated · SHA-256 4f90d94f9a2b…',2,'2026-08-27 00:14:47'),(19,9,'Processing','Digital PDF generated · SHA-256 afe80ace617c…',2,'2026-08-27 00:22:29'),(20,9,'Ready','Ready for release',2,'2026-08-27 00:31:31'),(21,10,'Awaiting_Payment','Request submitted (DOC-2026-0006) — awaiting payment',6,'2026-08-27 00:42:05'),(22,10,'Processing','Payment completed (TXN-MOCK-2255)',6,'2026-08-27 00:42:20'),(23,10,'Ready','Ready for release',2,'2026-08-27 00:42:47'),(24,10,'Claimed','Released / claimed',2,'2026-08-27 00:43:02'),(25,11,'Awaiting_Payment','Request submitted (DOC-2026-0007) — awaiting payment',6,'2026-08-27 01:41:31'),(26,11,'Processing','Payment completed (TXN-MOCK-9531)',6,'2026-08-27 01:41:54'),(27,11,'Ready','Ready for release',2,'2026-08-27 01:42:22'),(28,11,'Shipped','Rider booked (LALA-DOC-1201 — Rider Mockington)',2,'2026-08-27 01:42:47'),(29,11,'Claimed','Released / claimed',2,'2026-08-27 01:44:15');
/*!40000 ALTER TABLE `document_request_events` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `document_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` varchar(24) DEFAULT NULL,
  `student_id` int(11) NOT NULL,
  `document_type` enum('form137','good_moral','transcript','certificate','clearance') NOT NULL,
  `catalog_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `request_type` enum('Express','Regular') NOT NULL DEFAULT 'Regular',
  `fulfillment_type` enum('Pickup','Digital','Courier') NOT NULL DEFAULT 'Pickup',
  `delivery_address` text DEFAULT NULL,
  `payment_method` enum('Online','Cash_on_Delivery') NOT NULL DEFAULT 'Online',
  `purpose` varchar(255) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `status` enum('pending','processing','approved','denied','completed','released') DEFAULT 'pending',
  `document_status` enum('Pending_Clearance','Awaiting_Payment','Processing','Ready','Shipped','Claimed','Rejected') NOT NULL DEFAULT 'Awaiting_Payment',
  `rejection_reason` varchar(255) DEFAULT NULL,
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
  CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_document_requests_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `document_catalog` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `document_requests` WRITE;
/*!40000 ALTER TABLE `document_requests` DISABLE KEYS */;
INSERT INTO `document_requests` VALUES (1,'DOC-2026-0001',1,'form137',NULL,1,'Regular','Pickup',NULL,'Online','Transfer to UP Manila',NULL,'pending','Awaiting_Payment',NULL,'b0352559c9b0242199bd4b0887a8fd585a239c3826c09aef3714e0d971fa60e5',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-07-07 10:42:46',NULL,NULL,0.00,NULL,NULL,NULL),(2,'DOC-2026-0002',2,'good_moral',3,1,'Regular','Pickup',NULL,'Online','Job application',NULL,'approved','Ready',NULL,'fd374421aab1ca9a6da1c2c211e710ab96b457d5d3117bec0cb058b6da40c150',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-07-06 10:42:46',NULL,NULL,0.00,NULL,NULL,NULL),(3,'DOC-2026-0003',3,'transcript',1,1,'Regular','Pickup',NULL,'Online','Scholarship application',NULL,'processing','Processing',NULL,'cfad2114f185a25e8579a8a9bfe3fbab404318943e8773c20d2ba7a9fcafad5b',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-07-05 10:42:46',NULL,NULL,0.00,NULL,NULL,NULL),(4,'DOC-2026-0004',1,'good_moral',3,1,'Regular','Pickup',NULL,'Online','Job application','HR Dept','pending','Awaiting_Payment',NULL,'3bf55e76b0f7e821e781b41267a0f96e9697eaef8eb2947abac2ae6dfab02958',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-16 18:33:44',NULL,NULL,0.00,NULL,NULL,NULL),(9,'DOC-2026-0005',1,'certificate',2,1,'Express','Digital',NULL,'Online','blue job','school','approved','Ready',NULL,'5a0ef037a7fa8ee91c8670d0671b9e2e464137acc9a8d131c3c9a45f7a25c467',NULL,NULL,NULL,NULL,'2026-08-27 00:31:31',NULL,NULL,'uploads/document_pdfs/DOC-2026-0005-DOC-COE.pdf','afe80ace617c05a8260ccab916c630add00045e7c5f80620847e81dff57853ba',2,NULL,NULL,'2026-08-27 04:08:21','2026-08-27 00:31:31',NULL,100.00,NULL,NULL,NULL),(10,'DOC-2026-0006',2,'good_moral',3,1,'Regular','Pickup',NULL,'Online','job application','Roldan','released','Claimed',NULL,'463a0cb8c28239e221ff8ade771c2f6e8f71dd26691e07b3aa5b63b341dcd149',NULL,'TXN-MOCK-2255',NULL,'2026-08-27 00:42:20','2026-08-27 00:42:47',NULL,'2026-08-27 00:43:02',NULL,NULL,2,NULL,NULL,'2026-08-27 04:42:05','2026-08-27 00:43:02','2026-08-27 00:43:02',150.00,NULL,NULL,'2026-08-27 00:43:02'),(11,'DOC-2026-0007',2,'',5,1,'Regular','Courier','dito lang','Online','job application','Roldan','released','Claimed',NULL,'2a2781eda5cf79b097da335e7f89b8fe462ba492fded485740c0587f0050dbb0',NULL,'TXN-MOCK-9531','LALA-DOC-1201','2026-08-27 01:41:54','2026-08-27 01:42:22','2026-08-27 01:42:47','2026-08-27 01:44:15',NULL,NULL,2,NULL,NULL,'2026-08-27 05:41:31','2026-08-27 01:44:15','2026-08-27 01:44:15',50.00,126.00,NULL,'2026-08-27 01:44:15');
/*!40000 ALTER TABLE `document_requests` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `documents` WRITE;
/*!40000 ALTER TABLE `documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `documents` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `emergency_contacts` WRITE;
/*!40000 ALTER TABLE `emergency_contacts` DISABLE KEYS */;
/*!40000 ALTER TABLE `emergency_contacts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `exit_clearances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exit_clearances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `office` enum('Alumni','Dean','Property') NOT NULL,
  `status` enum('PENDING','CLEARED') NOT NULL DEFAULT 'PENDING',
  `cleared_by` int(11) DEFAULT NULL,
  `cleared_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exit_req_office` (`request_id`,`office`),
  KEY `idx_exit_status` (`status`),
  CONSTRAINT `fk_exit_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `exit_clearances` WRITE;
/*!40000 ALTER TABLE `exit_clearances` DISABLE KEYS */;
/*!40000 ALTER TABLE `exit_clearances` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `finance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finance_student` (`student_id`),
  CONSTRAINT `fk_finance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `finance` WRITE;
/*!40000 ALTER TABLE `finance` DISABLE KEYS */;
INSERT INTO `finance` VALUES (1,1,0.00,'2026-08-26 16:39:54'),(2,2,0.00,'2026-08-26 15:26:51'),(3,3,0.00,'2026-08-26 15:26:51');
/*!40000 ALTER TABLE `finance` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `guardians` WRITE;
/*!40000 ALTER TABLE `guardians` DISABLE KEYS */;
INSERT INTO `guardians` VALUES (1,1,'Ramon Dela Cruz','father','09171234560',NULL,NULL,1,1,'2026-07-07 10:42:46'),(2,1,'Elena Dela Cruz','mother','09171234561',NULL,NULL,0,1,'2026-07-07 10:42:46'),(3,2,'Carlos Santos','father','09181234570',NULL,NULL,1,1,'2026-07-07 10:42:46');
/*!40000 ALTER TABLE `guardians` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `health_records` WRITE;
/*!40000 ALTER TABLE `health_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `health_records` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `health_visits` WRITE;
/*!40000 ALTER TABLE `health_visits` DISABLE KEYS */;
/*!40000 ALTER TABLE `health_visits` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `mock_lalamove_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `mock_lalamove_orders` WRITE;
/*!40000 ALTER TABLE `mock_lalamove_orders` DISABLE KEYS */;
INSERT INTO `mock_lalamove_orders` VALUES (1,'LALA-DOC-1201','QUO-3154',11,'Bestlink College of the Philippines','dito lang','Document',126.00,3.80,'Rider Mockington','0917-000-2573','ASSIGNING_RIDER','http://mock-lala.com/track/LALA-DOC-1201','2026-08-27 05:42:47');
/*!40000 ALTER TABLE `mock_lalamove_orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `mock_payment_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  `payment_url` varchar(255) DEFAULT NULL,
  `callback_url` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `raw_request` text DEFAULT NULL,
  `raw_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_txn_id` (`transaction_id`),
  KEY `idx_txn_request` (`request_id`),
  CONSTRAINT `fk_txn_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `mock_payment_transactions` WRITE;
/*!40000 ALTER TABLE `mock_payment_transactions` DISABLE KEYS */;
INSERT INTO `mock_payment_transactions` VALUES (3,'TXN-MOCK-2255',10,2,150.00,'PHP','completed','Online','now','http://mock-gateway.com/pay/TXN-MOCK-2255',NULL,'2026-08-27 00:42:20','{\"action\":\"create\",\"request_id\":10,\"student_id\":2}','{\"action\":\"webhook\",\"transaction_id\":\"TXN-MOCK-2255\",\"status\":\"COMPLETED\"}','2026-08-27 04:42:10'),(4,'TXN-MOCK-9531',11,2,176.00,'PHP','completed','Online','now','http://mock-gateway.com/pay/TXN-MOCK-9531',NULL,'2026-08-27 01:41:54','{\"action\":\"create\",\"request_id\":11,\"student_id\":2}','{\"action\":\"webhook\",\"transaction_id\":\"TXN-MOCK-9531\",\"status\":\"COMPLETED\"}','2026-08-27 05:41:36');
/*!40000 ALTER TABLE `mock_payment_transactions` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `queue_tickets` WRITE;
/*!40000 ALTER TABLE `queue_tickets` DISABLE KEYS */;
INSERT INTO `queue_tickets` VALUES (12,'2026-08-16',1,1,'Juan Dela Cruz','2026-0001','BSIT','cancelled',1,NULL,'2026-08-16 03:01:52','2026-08-16 15:02:39','2026-08-16 15:54:13');
/*!40000 ALTER TABLE `queue_tickets` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `rfid_cards` WRITE;
/*!40000 ALTER TABLE `rfid_cards` DISABLE KEYS */;
INSERT INTO `rfid_cards` VALUES (9,1,'0006929950','rfid','active','2026-07-14','2027-07-15','','2026-07-14 09:26:57',NULL,'2026-08-11 06:47:20');
/*!40000 ALTER TABLE `rfid_cards` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `rfid_scan_logs` WRITE;
/*!40000 ALTER TABLE `rfid_scan_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `rfid_scan_logs` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `status_tracker` WRITE;
/*!40000 ALTER TABLE `status_tracker` DISABLE KEYS */;
INSERT INTO `status_tracker` VALUES (1,1,NULL,'active','New student enrolled',NULL,'2026-07-07 10:42:46',NULL,NULL),(2,2,NULL,'active','New student enrolled',NULL,'2026-07-07 10:42:46',NULL,NULL),(3,3,NULL,'active','New student enrolled',NULL,'2026-07-07 10:42:46',NULL,NULL);
/*!40000 ALTER TABLE `status_tracker` ENABLE KEYS */;
UNLOCK TABLES;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `student_ids` WRITE;
/*!40000 ALTER TABLE `student_ids` DISABLE KEYS */;
INSERT INTO `student_ids` VALUES (1,1,'2026-0001','school_id','2026-08-24',NULL,'active',NULL,NULL,'2026-08-24 12:50:25',NULL,NULL,'blue');
/*!40000 ALTER TABLE `student_ids` ENABLE KEYS */;
UNLOCK TABLES;
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

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (1,'2026-0001','Juan',NULL,'Dela Cruz',NULL,NULL,'2005-05-15',NULL,NULL,NULL,'123 Main St., Manila','09171234567','juan@email.com','./assets/uploads/students/student_1_1787564957.gif','BS Computer Science',NULL,1,NULL,NULL,NULL,NULL,'active','2026-07-07 10:42:46','2026-08-24 09:49:17',NULL,NULL,NULL,NULL,NULL),(2,'2026-0002','Maria',NULL,'Santos',NULL,NULL,'2006-03-20',NULL,NULL,NULL,'456 Oak St., Quezon City','09181234568','maria@email.com',NULL,'BS Education',NULL,1,NULL,NULL,NULL,NULL,'active','2026-07-07 10:42:46','2026-07-07 10:42:46',NULL,NULL,NULL,NULL,NULL),(3,'2026-0003','Ana',NULL,'Reyes',NULL,NULL,'2005-11-10',NULL,NULL,NULL,'789 Pine St., Pasig','09191234569','ana@email.com',NULL,'BS Nursing',NULL,1,NULL,NULL,NULL,NULL,'active','2026-07-07 10:42:46','2026-07-07 10:42:46',NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin@bestlink.edu.ph','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','System Administrator','admin',NULL,1,'2026-07-07 10:42:45','2026-08-24 04:47:37',NULL,'ADM-001',0,NULL),(2,'registrar@bestlink.edu.ph','$2y$10$zj33OjRB93RcPZWd2/f4VudcEqzDCfZdLAajEcZQ7LABuuEKeqFyu','Registrar Staff','registrar',NULL,1,'2026-07-07 10:42:45','2026-08-26 16:02:51',NULL,'RGS-001',0,NULL),(3,'roldantiu89@gmail.com','$2y$10$f9PmndF92hBFI/jeJAWxC.Pua3Osob3.zkWHn9GRSTQXSyPX8x0dK','Roldan Tiu','admin',NULL,1,'2026-08-11 11:40:30','2026-08-24 04:47:37',NULL,'ADM-002',0,NULL),(5,'juan.student@bestlink.edu.ph','$2y$10$sm.k4/VQXpOG/e87XRS/Q.Zfe1ZKFbMCuVesLdAo8gEQaNCq3PQey','Juan Dela Cruz (Test Student)','student',NULL,1,'2026-08-16 06:32:46','2026-08-26 15:41:34',1,'2026-0001',0,NULL),(6,'maria.student@bestlink.edu.ph','$2y$10$1dLpgIKZLtuUq3X52f.1ceX8r9aQFgn8mCsA9Oa/VEBEqWLYqzFvW','Maria Santos','student',NULL,1,'2026-08-27 04:39:54','2026-08-26 16:39:54',2,'2026-0002',0,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
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
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
