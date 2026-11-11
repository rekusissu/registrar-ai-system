CREATE TABLE IF NOT EXISTS id_card_details (
  rfid_card_id INT(11) NOT NULL,
  student_id INT(11) NOT NULL,
  details_json TEXT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by INT(11) DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rfid_card_id),
  KEY idx_id_details_student (student_id),
  CONSTRAINT fk_id_details_card FOREIGN KEY (rfid_card_id) REFERENCES rfid_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_id_details_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_id_details_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
