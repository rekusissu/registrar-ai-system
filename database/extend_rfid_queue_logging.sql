-- ============================================================
--  EXTEND_RFID_QUEUE_LOGGING.SQL
--  Add queue event types to rfid_scan_logs.event_type ENUM
--  Apply with: mysql -u root registrar_ai < extend_rfid_queue_logging.sql
-- ============================================================

USE registrar_ai;

-- Extend event_type ENUM to include queue state transition events
ALTER TABLE `rfid_scan_logs`
MODIFY `event_type` enum('entry','exit','library','cafeteria','other','queue_join','queue_call','queue_serving','queue_completed','queue_no_show','queue_cancelled') DEFAULT 'entry';

-- Verify the update
SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'registrar_ai'
AND TABLE_NAME = 'rfid_scan_logs'
AND COLUMN_NAME = 'event_type';