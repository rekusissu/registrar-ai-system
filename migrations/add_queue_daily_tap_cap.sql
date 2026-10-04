-- ============================================================
--  MIGRATIONS/ADD_QUEUE_DAILY_TAP_CAP.SQL
--  A daily cap on the NUMBER OF NUMBERS ISSUED, per day.
--
--  WHY THIS EXISTS
--
--  queue_day_settings had max_taps_student and max_taps_priority, and
--  both were per STUDENT: they counted how many tickets that one person
--  already held today. That is an abuse guard, and it is a real need -
--  but it is not the number the office runs on.
--
--  What the office actually plans against is throughput: how many people
--  the counter can serve in an eight-hour day, roughly 500-600. There was
--  no setting for that at all. With the per-student cap set to the only
--  number anybody could enter, 600, every individual student was allowed
--  600 taps a day and the daily total was unbounded, which is the exact
--  inverse of the intended control.
--
--  So: add the capacity the office plans against, and leave the
--  per-student caps exactly as they were. They guard different things and
--  both are wanted.
--
--  0 means UNLIMITED, matching every other cap in this table, so an
--  install that has not set it behaves as it does today.
-- ============================================================

-- GUARDED on existence rather than a bare ALTER.
--
-- registrar_ai.sql now declares this column, so on a fresh install built from
-- the seed the bare statement aborted with ERROR 1060 (Duplicate column) -
-- which means the seed and this migration could not both be right, and one of
-- them had to give. Guarding means both are: a no-op where the column already
-- exists, and still added to an older database that lacks it.
--
-- tests/dump_freshness.php imports the seed into an empty database and then
-- replays every migration expecting each to change nothing. That check is what
-- caught this collision, so it is written for.
--
-- Order is deliberately NOT pinned with AFTER: on a seeded table the column is
-- already in place and in a different position, and re-ordering it would mean
-- the migration was not a no-op. Order carries no meaning for one column.
--
-- Read once per join, on the same FOR UPDATE block that counts this student's
-- own taps, so the day's total is read under the row lock and two rapid taps
-- cannot both read a stale count and both pass.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'queue_day_settings'
     AND COLUMN_NAME = 'max_daily_taps'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE `queue_day_settings`
     ADD COLUMN `max_daily_taps` int unsigned NOT NULL DEFAULT 0
        COMMENT ''Total numbers issuable for the whole day. 0 = unlimited.''',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;