-- ============================================================
--  database/document_requests_v2.sql
--  Document Request Module v2 — schema migration.
--
--  Adds: priced document catalog (SKUs), finance balances (the
--  clearance-gate check), new document_requests workflow fields,
--  mock payment / Lalamove tables, exit clearances, and a
--  status-event timeline. Migrates the existing (gen-1) rows.
--
--  Targets MariaDB 10.4+ (XAMPP). Re-runnable: column adds use
--  `ADD COLUMN IF NOT EXISTS`, table creates use `IF NOT EXISTS`,
--  seeds use `INSERT ... ON DUPLICATE KEY UPDATE`.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. document_catalog ──────────────────────────────────────
-- SKU-coded priced document catalog. `triggers_exit_clearance`
-- flags docs that force the Alumni/Dean/Property hard stop.
CREATE TABLE IF NOT EXISTS `document_catalog` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `document_catalog`
  (`id`, `sku`, `name`, `description`, `base_fee`, `fee_type`, `requirement`, `triggers_exit_clearance`)
VALUES
  (1, 'DOC-TOR',     'Transcript of Records',        'Complete academic record (TOR)', 250.00,  'per_page',     'Scanned copy of valid ID', 1),
  (2, 'DOC-COE',     'Certificate of Enrollment',    'Proof of current enrollment',   100.00,  'flat',         NULL,                       0),
  (3, 'DOC-GM',      'Certificate of Good Moral',    'Good moral character certificate', 150.00, 'flat',        'No pending disciplinary cases', 0),
  (4, 'DOC-DIPLOMA', 'Diploma Replacement',          'Replacement of lost diploma',   1000.00, 'flat',         'Notarized Affidavit of Loss', 0),
  (5, 'DOC-CTC',     'Certified True Copy',          'Certified true copy of a record', 50.00, 'per_page',    NULL,                       0),
  (6, 'DOC-HD',      'Honorable Dismissal',          'Transfer / honorable dismissal', 300.00, 'flat',         'Completed Exit Clearance', 1),
  (7, 'DOC-CD',      'Course Description',           'Subject syllabus / course description', 100.00, 'per_syllabus', NULL, 0)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `base_fee` = VALUES(`base_fee`),
  `fee_type` = VALUES(`fee_type`), `requirement` = VALUES(`requirement`),
  `triggers_exit_clearance` = VALUES(`triggers_exit_clearance`);

-- ── 2. finance ───────────────────────────────────────────────
-- Clearance-gate balance table (spec: SELECT balance FROM finance
-- WHERE student_id = 'XYZ'). Student 1 is seeded with an
-- outstanding balance to demo the Pending_Clearance block.
CREATE TABLE IF NOT EXISTS `finance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finance_student` (`student_id`),
  CONSTRAINT `fk_finance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `finance` (`student_id`, `balance`) VALUES
  (1, 2500.00),
  (2, 0.00),
  (3, 0.00)
ON DUPLICATE KEY UPDATE `balance` = VALUES(`balance`);

-- ── 3. document_requests — add v2 workflow columns ──────────
-- Legacy columns (status, denial_reason, fee_amount, release_date,
-- …) are kept populated for the migrated rows. New fields drive the
-- v2 flow. `document_status` is added NULL first, the legacy rows
-- are mapped, then it is tightened to NOT NULL DEFAULT.
ALTER TABLE `document_requests`
  ADD COLUMN IF NOT EXISTS `request_id` varchar(24) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `catalog_id` int(11) DEFAULT NULL AFTER `document_type`,
  ADD COLUMN IF NOT EXISTS `quantity` int(11) NOT NULL DEFAULT 1 AFTER `catalog_id`,
  ADD COLUMN IF NOT EXISTS `request_type` enum('Express','Regular') NOT NULL DEFAULT 'Regular' AFTER `quantity`,
  ADD COLUMN IF NOT EXISTS `fulfillment_type` enum('Pickup','Digital','Courier') NOT NULL DEFAULT 'Pickup' AFTER `request_type`,
  ADD COLUMN IF NOT EXISTS `delivery_address` text DEFAULT NULL AFTER `fulfillment_type`,
  ADD COLUMN IF NOT EXISTS `document_status` enum('Pending_Clearance','Awaiting_Payment','Processing','Ready','Shipped','Claimed','Rejected') DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` varchar(255) DEFAULT NULL AFTER `document_status`,
  ADD COLUMN IF NOT EXISTS `qr_hash` varchar(64) DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN IF NOT EXISTS `requirement_file_path` varchar(255) DEFAULT NULL AFTER `qr_hash`,
  ADD COLUMN IF NOT EXISTS `payment_ref` varchar(40) DEFAULT NULL AFTER `requirement_file_path`,
  ADD COLUMN IF NOT EXISTS `lalamove_order_ref` varchar(40) DEFAULT NULL AFTER `payment_ref`,
  ADD COLUMN IF NOT EXISTS `paid_at` datetime DEFAULT NULL AFTER `lalamove_order_ref`,
  ADD COLUMN IF NOT EXISTS `ready_at` datetime DEFAULT NULL AFTER `paid_at`,
  ADD COLUMN IF NOT EXISTS `shipped_at` datetime DEFAULT NULL AFTER `ready_at`,
  ADD COLUMN IF NOT EXISTS `claimed_at` datetime DEFAULT NULL AFTER `shipped_at`,
  ADD COLUMN IF NOT EXISTS `pdf_path` varchar(255) DEFAULT NULL AFTER `claimed_at`,
  ADD COLUMN IF NOT EXISTS `pdf_fingerprint` varchar(64) DEFAULT NULL AFTER `pdf_path`;

-- ── 4. Migrate the existing (gen-1) rows ─────────────────────
-- Map the old status vocabulary onto the v2 statuses.
UPDATE `document_requests`
   SET `document_status` = CASE `status`
         WHEN 'pending'    THEN 'Awaiting_Payment'
         WHEN 'processing' THEN 'Processing'
         WHEN 'approved'   THEN 'Ready'
         WHEN 'completed'  THEN 'Ready'
         WHEN 'released'   THEN 'Claimed'
         WHEN 'denied'     THEN 'Rejected'
       END
 WHERE `document_status` IS NULL;

-- Best-effort map old document types onto catalog SKUs.
UPDATE `document_requests`
   SET `catalog_id` = CASE `document_type`
         WHEN 'transcript' THEN 1  -- DOC-TOR
         WHEN 'good_moral' THEN 3  -- DOC-GM
         WHEN 'certificate' THEN 2 -- DOC-COE
       END
 WHERE `catalog_id` IS NULL;

-- Request IDs + QR hashes for legacy rows.
UPDATE `document_requests`
   SET `request_id` = CONCAT('DOC-', YEAR(`request_date`), '-', LPAD(`id`, 4, '0'))
 WHERE `request_id` IS NULL;

UPDATE `document_requests`
   SET `qr_hash` = SHA2(CONCAT(`request_id`, UUID()), 256)
 WHERE `qr_hash` IS NULL;

-- Tighten document_status to NOT NULL.
ALTER TABLE `document_requests`
  MODIFY `document_status` enum('Pending_Clearance','Awaiting_Payment','Processing','Ready','Shipped','Claimed','Rejected') NOT NULL DEFAULT 'Awaiting_Payment';

-- Indexes for the new filter paths.
ALTER TABLE `document_requests`
  ADD UNIQUE KEY IF NOT EXISTS `uq_request_id` (`request_id`),
  ADD UNIQUE KEY IF NOT EXISTS `uq_qr_hash` (`qr_hash`),
  ADD KEY IF NOT EXISTS `idx_catalog_id` (`catalog_id`),
  ADD KEY IF NOT EXISTS `idx_document_status` (`document_status`),
  ADD KEY IF NOT EXISTS `idx_request_type` (`request_type`),
  ADD KEY IF NOT EXISTS `idx_fulfillment_type` (`fulfillment_type`);

-- ── 5. mock_payment_transactions ────────────────────────────
CREATE TABLE IF NOT EXISTS `mock_payment_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(40) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'PHP',
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'pending',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 6. mock_lalamove_orders ─────────────────────────────────
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

-- ── 7. exit_clearances ──────────────────────────────────────
-- The "hard stop": Alumni / Dean / Property must all be CLEARED
-- before a clearance-triggered request can be approved.
CREATE TABLE IF NOT EXISTS `exit_clearances` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 8. document_request_events ──────────────────────────────
-- Chronological status timeline for the student tracking view.
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

-- ── 9. foreign key: document_requests.catalog_id ────────────
-- Kept last (and run-once) so the data migration above can set
-- catalog_id before the constraint is enforced.
ALTER TABLE `document_requests`
  ADD CONSTRAINT `fk_document_requests_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `document_catalog` (`id`) ON DELETE SET NULL;
