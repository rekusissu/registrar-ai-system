-- ============================================================
--  Clinic Portal Expansion — tables for supplies, incidents
--  Run: mysql -u root registrar_ai < database/clinic_portal_expansion.sql
-- ============================================================

-- Medicine / supply inventory
CREATE TABLE IF NOT EXISTS `clinic_supplies` (
    `id`            INT(11) NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150) NOT NULL,
    `category`      VARCHAR(80)  DEFAULT NULL,
    `quantity`      INT(11)      DEFAULT 0,
    `unit`          VARCHAR(30)  DEFAULT 'pcs',
    `min_quantity`  INT(11)      DEFAULT 5,
    `description`   TEXT         DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_cs_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Supply usage / dispensing log
CREATE TABLE IF NOT EXISTS `clinic_supply_usage` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `supply_id`       INT(11) NOT NULL,
    `quantity_used`   INT(11) NOT NULL DEFAULT 1,
    `health_visit_id` INT(11) DEFAULT NULL,
    `used_by`         INT(11) DEFAULT NULL,
    `notes`           TEXT    DEFAULT NULL,
    `used_at`         DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_csu_supply` (`supply_id`),
    KEY `idx_csu_visit`  (`health_visit_id`),
    CONSTRAINT `fk_csu_supply` FOREIGN KEY (`supply_id`) REFERENCES `clinic_supplies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Incident reports (non-visit clinic events)
CREATE TABLE IF NOT EXISTS `clinic_incidents` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `student_id`      INT(11) DEFAULT NULL,
    `incident_type`   VARCHAR(100) NOT NULL,
    `description`     TEXT DEFAULT NULL,
    `location`        VARCHAR(150) DEFAULT NULL,
    `incident_date`   DATE DEFAULT NULL,
    `incident_time`   TIME DEFAULT NULL,
    `severity`        ENUM('low','medium','high','critical') DEFAULT 'low',
    `action_taken`    TEXT DEFAULT NULL,
    `follow_up`       TEXT DEFAULT NULL,
    `status`          ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    `reported_by`     INT(11) DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_ci_student` (`student_id`),
    KEY `idx_ci_status`  (`status`),
    KEY `idx_ci_date`    (`incident_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
