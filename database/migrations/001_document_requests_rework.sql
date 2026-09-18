-- ============================================================
--  database/migrations/001_document_requests_rework.sql
--  Document Request Module — "pick-up only" + coordinator-free rework.
--
--  Idempotent migration. Applied exactly once per database by
--  database/migrate.php (run automatically on every Docker boot by the
--  entrypoint, and safe to run manually for non-Docker deploys):
--
--    mysql -u root -p registrar_ai < database/migrations/001_document_requests_rework.sql
--
--  Applies:
--    1. Zero out every finance balance so students can request again.
--    2. Fold legacy 'Courier' fulfillment -> 'Pickup', then narrow enum.
--    3. Fold legacy 'Cash_on_Delivery' payment -> 'Online', then narrow enum.
--    4. Add document_requests.approval_reason (registrar approval reason).
--    5. Clear exit_clearances (Dean/Alumni/Property no longer approve).
-- ============================================================

SET NAMES utf8mb4;

-- 1. Demo: clear every outstanding balance so new requests are never held.
UPDATE `finance` SET `balance` = 0.00;

-- 2. Fulfillment: pickup/digital only.
--    a) Fold any legacy 'Courier' rows into 'Pickup' (only if the column exists).
SET @ft_exists := (SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'document_requests'
                     AND column_name = 'fulfillment_type');
SET @sql := IF(@ft_exists > 0,
        'UPDATE `document_requests` SET `fulfillment_type` = \'Pickup\' WHERE `fulfillment_type` = \'Courier\'',
        'SELECT 1');
PREPARE _m FROM @sql; EXECUTE _m; DEALLOCATE PREPARE _m;
--    b) Narrow the enum only when no row still references an excluded value.
SET @bad := (SELECT COUNT(*) FROM document_requests WHERE fulfillment_type NOT IN ('Pickup','Digital'));
SET @sql := IF(@bad = 0,
        'ALTER TABLE `document_requests` MODIFY `fulfillment_type` enum(\'Pickup\',\'Digital\') NOT NULL DEFAULT \'Pickup\'',
        'SELECT 1');
PREPARE _m FROM @sql; EXECUTE _m; DEALLOCATE PREPARE _m;

-- 3. Payment: online only.
--    a) Fold any legacy 'Cash_on_Delivery' rows into 'Online' (only if the column exists).
SET @pm_exists := (SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'document_requests'
                     AND column_name = 'payment_method');
SET @sql := IF(@pm_exists > 0,
        'UPDATE `document_requests` SET `payment_method` = \'Online\' WHERE `payment_method` = \'Cash_on_Delivery\'',
        'SELECT 1');
PREPARE _m FROM @sql; EXECUTE _m; DEALLOCATE PREPARE _m;
--    b) Narrow the enum only when no row still references an excluded value.
SET @bad := (SELECT COUNT(*) FROM document_requests WHERE payment_method != 'Online');
SET @sql := IF(@bad = 0,
        'ALTER TABLE `document_requests` MODIFY `payment_method` enum(\'Online\') NOT NULL DEFAULT \'Online\'',
        'SELECT 1');
PREPARE _m FROM @sql; EXECUTE _m; DEALLOCATE PREPARE _m;

-- 4. Registrar approval reason (shown when the registrar approves a request).
ALTER TABLE `document_requests` ADD COLUMN IF NOT EXISTS `approval_reason` varchar(255) DEFAULT NULL AFTER `rejection_reason`;

-- 5. Coordinator offices no longer approve requests — clear their rows.
DELETE FROM `exit_clearances` WHERE 1;