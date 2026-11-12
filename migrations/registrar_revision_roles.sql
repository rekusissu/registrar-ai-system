ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','registrar_admin','staff','acad_officer','department_head','student') NOT NULL DEFAULT 'staff';
CREATE TABLE IF NOT EXISTS user_program_assignments (
 user_id INT NOT NULL, program_code VARCHAR(30) NOT NULL,
 assigned_by INT NOT NULL, assigned_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,program_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS academic_validations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 academic_history_id INT NOT NULL, reviewer_id INT NOT NULL,
 review_status ENUM('reviewed','needs_correction','incomplete') NOT NULL,
 remarks TEXT NOT NULL, created_at DATETIME NOT NULL,
 KEY idx_validation_record(academic_history_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
