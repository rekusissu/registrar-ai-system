-- ============================================================
--  MIGRATION: Teacher master subjects & teaching load
--  Adds to the live registrar_ai database (no data loss).
--  Run via phpMyAdmin SQL tab or: mysql -u root registrar_ai < this file
--  Idempotent: safe to re-run.
-- ============================================================

-- 1) Master subject catalog -----------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `title` varchar(150) NOT NULL,
  `units` decimal(4,2) NOT NULL DEFAULT 3.00,
  `department` varchar(100) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Teacher professional profile (1:1 users) -----------------------
CREATE TABLE IF NOT EXISTS `teacher_profiles` (
  `user_id` int(11) NOT NULL,
  `employee_number` varchar(20) DEFAULT NULL,
  `designation` enum('Faculty','Part-time','Adjunct','Admin-Faculty') DEFAULT 'Faculty',
  `department` varchar(100) DEFAULT NULL,
  `highest_degree` varchar(150) DEFAULT NULL,
  `specialization` varchar(200) DEFAULT NULL,
  `years_teaching` int(11) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `employee_number` (`employee_number`),
  CONSTRAINT `teacher_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Teacher ↔ subject assignments (teaching load) ------------------
CREATE TABLE IF NOT EXISTS `teacher_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `section` varchar(30) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `schedule` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_subject` (`subject_id`),
  CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Seed the master subject catalog --------------------------------
INSERT IGNORE INTO `subjects` (`code`, `title`, `units`, `department`, `year_level`, `semester`) VALUES
('GE101',  'Purposive Communication',               3.00, 'General Education', 1, '1st'),
('GE102',  'Mathematics in the Modern World',       3.00, 'General Education', 1, '1st'),
('GE103',  'The Contemporary World',                3.00, 'General Education', 1, '1st'),
('GE104',  'Understanding the Self',                3.00, 'General Education', 1, '1st'),
('GE105',  'Readings in Philippine History',        3.00, 'General Education', 1, '2nd'),
('GE106',  'Science, Technology and Society',       3.00, 'General Education', 1, '2nd'),
('GE107',  'Ethics',                                3.00, 'General Education', 1, '2nd'),
('GE108',  'Art Appreciation',                      3.00, 'General Education', 1, '2nd'),
('GE109',  'Life and Works of Rizal',               3.00, 'General Education', 2, '2nd'),
('PE101',  'Physical Fitness',                      2.00, 'Physical Education', 1, '1st'),
('PE102',  'Rhythmic Activities',                   2.00, 'Physical Education', 1, '2nd'),
('NSTP101','National Service Training Program 1',   3.00, 'General Education', 1, '1st'),
('IT101',  'Introduction to Computing',             3.00, 'BSIT', 1, '1st'),
('IT102',  'Computer Programming 1',                3.00, 'BSIT', 1, '1st'),
('IT103',  'Computer Programming 2',                3.00, 'BSIT', 1, '2nd'),
('IT104',  'Discrete Mathematics',                  3.00, 'BSIT', 1, '2nd'),
('IT105',  'Data Structures and Algorithms',        3.00, 'BSIT', 2, '1st'),
('IT106',  'Information Management',                3.00, 'BSIT', 2, '1st'),
('IT107',  'Networking 1',                          3.00, 'BSIT', 2, '1st'),
('IT108',  'Web Systems and Technologies',          3.00, 'BSIT', 2, '2nd'),
('IT109',  'Systems Analysis and Design',           3.00, 'BSIT', 2, '2nd'),
('IT110',  'Software Engineering 1',                3.00, 'BSIT', 3, '1st'),
('ED101',  'The Teaching Profession',               3.00, 'BSED', 1, '1st'),
('ED102',  'Child and Adolescent Learners and Learning Principles', 3.00, 'BSED', 1, '1st'),
('ED103',  'The Teacher and the School Curriculum', 3.00, 'BSED', 1, '2nd'),
('ED104',  'Facilitating Learner-Centered Teaching', 3.00, 'BSED', 2, '1st'),
('ED105',  'Assessment in Learning 1',              3.00, 'BSED', 2, '1st'),
('ED106',  'Technology for Teaching and Learning 1', 3.00, 'BSED', 2, '2nd'),
('MATH101','College Algebra',                       3.00, 'BSED', 1, '1st'),
('SCI101', 'General Biology',                       3.00, 'BSED', 1, '1st'),
('ENG101', 'Structure of English',                  3.00, 'BSED', 1, '1st'),
('BA101',  'Introduction to Business Management',   3.00, 'BSBA', 1, '1st'),
('ACC101', 'Principles of Accounting',              3.00, 'BSAIS', 1, '1st'),
('HM101',  'Introduction to Hospitality and Tourism', 3.00, 'BSHM', 1, '1st');
