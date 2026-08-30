-- ============================================================
--  database/document_requests_cod.sql
--  Document Request Module v2 — Cash on Delivery + student-paid
--  shipping migration.
--
--  Adds:
--    document_requests.payment_method   ('Online' | 'Cash_on_Delivery')
--    document_requests.delivery_fee     courier fee quoted up-front,
--                                       borne by the student
--    mock_payment_transactions.method   ('Online' | 'Cash_on_Delivery')
--    mock_payment_transactions.due_on   ('now' | 'delivery')
--
--  Re-runnable (ADD COLUMN IF NOT EXISTS). Targets MariaDB 10.4+
--  (XAMPP).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `document_requests`
  ADD COLUMN IF NOT EXISTS `payment_method` enum('Online','Cash_on_Delivery') NOT NULL DEFAULT 'Online' AFTER `delivery_address`,
  ADD COLUMN IF NOT EXISTS `delivery_fee` decimal(10,2) DEFAULT NULL AFTER `fee_amount`;

ALTER TABLE `mock_payment_transactions`
  ADD COLUMN IF NOT EXISTS `method` varchar(20) NOT NULL DEFAULT 'Online' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `due_on` enum('now','delivery') NOT NULL DEFAULT 'now' AFTER `method`;
