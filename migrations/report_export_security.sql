CREATE TABLE IF NOT EXISTS report_exports (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL, actor_name VARCHAR(100) NOT NULL, actor_role VARCHAR(40) NOT NULL,
 report_name VARCHAR(160) NOT NULL, format VARCHAR(20) NOT NULL,
 resource TEXT NOT NULL, status VARCHAR(30) NOT NULL,
 token_hash CHAR(64) DEFAULT NULL, session_hash CHAR(64) DEFAULT NULL,
 ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL,
 expires_at DATETIME DEFAULT NULL, initiated_at DATETIME DEFAULT NULL,
 UNIQUE KEY uq_export_token (token_hash), KEY idx_export_actor (user_id, created_at),
 KEY idx_export_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
