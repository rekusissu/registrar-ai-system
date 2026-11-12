CREATE TABLE IF NOT EXISTS guardian_contact_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 student_id INT NOT NULL,
 contact_type ENUM('guardian','emergency') NOT NULL,
 contact_id INT NOT NULL,
 contact_name VARCHAR(200) NOT NULL,
 channel ENUM('call','email') NOT NULL,
 occasion VARCHAR(100) NOT NULL,
 outcome ENUM('reached','no_answer','left_message','email_sent','failed') NOT NULL,
 notes TEXT NOT NULL,
 recorded_by INT NOT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_contact_student(student_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
