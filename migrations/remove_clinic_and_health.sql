-- -------------------------------------------------------------------------
--  REMOVE THE CLINIC PORTAL, HEALTH RECORDS AND THE 'nurse' ROLE
-- -------------------------------------------------------------------------
--
--  DESTRUCTIVE. Every row in these five tables is deleted and the 'nurse'
--  role disappears from the database. There is no undo in SQL - take a dump
--  first:
--
--    mysqldump -u USER -p registrar_ai > backup_before_clinic_removal.sql
--
--  What goes
--  ---------
--    clinic_incidents      the nurse portal's incident log
--    clinic_supplies       clinic stock
--    clinic_supply_usage   what was used against which visit
--    health_visits         every clinic visit: complaint, diagnosis,
--                          treatment, medication, vitals, disposition
--    health_records        one row per student's health profile
--
--  These are medical records. Under most retention rules that is exactly the
--  kind of data you are required to keep and are not permitted to destroy.
--  Confirm you are entitled to before running this, and keep the dump.
--
--  What stays
--  ----------
--  students, users (minus the nurse account), documents, guardians and
--  emergency_contacts are untouched. emergency_contacts is the nearest thing
--  to remaining health data - a point of contact for a medical emergency -
--  and is not removed here.
--
--  rfid_scan_logs.event_type still accepts the value 'clinic'. That is a
--  scanned location, not the portal, so the value is harmless and dropping it
--  would rewrite the ENUM for rows that are not being deleted.
-- -------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `clinic_supply_usage`;
DROP TABLE IF EXISTS `clinic_supplies`;
DROP TABLE IF EXISTS `clinic_incidents`;
DROP TABLE IF EXISTS `health_visits`;
DROP TABLE IF EXISTS `health_records`;

SET FOREIGN_KEY_CHECKS = 1;

-- The nurse login. Removed first, because the ALTER below cannot leave a row
-- holding a value the new ENUM does not accept - MySQL would coerce it to ''
-- and leave a real account with no role at all.
DELETE FROM `users` WHERE `role` = 'nurse';

-- Narrow the ENUM. The rows this affects were just deleted above, so nothing
-- is coerced to ''.
ALTER TABLE `users`
  MODIFY COLUMN `role` enum('admin','registrar','staff','teacher','student') NOT NULL DEFAULT 'staff';

-- Verify: every row below should read zero.
--   SELECT COUNT(*) FROM users WHERE role = 'nurse';
--   SELECT COUNT(*) FROM information_schema.TABLES
--     WHERE TABLE_SCHEMA = DATABASE()
--       AND TABLE_NAME IN ('clinic_incidents','clinic_supplies',
--                          'clinic_supply_usage','health_records','health_visits');