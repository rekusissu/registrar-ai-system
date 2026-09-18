-- ============================================================
--  database/document_requests_rework.sql
--  Document Request Module — "pick-up only" + coordinator-free
--  rework for the live demo.
--
--  Applies (re-runnable / idempotent):
--    1. Zero out every finance balance  → students can request again.
--    2. Remove 'Courier' fulfillment     → Pickup (no shipping/delivery).
--    3. Remove 'Cash_on_Delivery'        → Online (pick-up only).
--    4. Add document_requests.approval_reason (registrar approval reason).
--    5. Drop the unused exit_clearances approval rows (Dean / Alumni /
--       Property are no longer part of the workflow).
--
--  Targets MariaDB 10.4+ (XAMPP). Column adds use ADD COLUMN IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;

-- 1. Demo: clear every outstanding balance so new requests are never held.
UPDATE `finance` SET `balance` = 0.00;

-- 2. Pickup / Digital only — fold any legacy 'Courier' rows into 'Pickup'.
UPDATE `document_requests` SET `fulfillment_type` = 'Pickup' WHERE `fulfillment_type` = 'Courier';
ALTER TABLE `document_requests`
  MODIFY `fulfillment_type` enum('Pickup','Digital') NOT NULL DEFAULT 'Pickup';

-- 3. Online payment only — fold legacy 'Cash_on_Delivery' rows into 'Online'.
UPDATE `document_requests` SET `payment_method` = 'Online' WHERE `payment_method` = 'Cash_on_Delivery';
ALTER TABLE `document_requests`
  MODIFY `payment_method` enum('Online') NOT NULL DEFAULT 'Online';

-- 4. Registrar approval reason (shown when the registrar approves a request).
ALTER TABLE `document_requests`
  ADD COLUMN IF NOT EXISTS `approval_reason` varchar(255) DEFAULT NULL AFTER `rejection_reason`;

-- 5. Coordinator offices no longer approve requests — clear their rows.
DELETE FROM `exit_clearances` WHERE 1;