-- Migration: Document Request Wizard
-- Date: 2026-10-08
-- Description:
--   Backs the guided request wizard: doc-type picker -> details ->
--   uploads -> review -> payment -> confirmation, then tracking.
--
--   TWO JOBS, IN THIS ORDER. Read the first one before the second.
--
-- ─────────────────────────────────────────────────────────────────────
-- 0. THE ENUMS ARE CURRENTLY CORRUPTING EVERY ONLINE FILING.
--
--    This is not a housekeeping item. migrations/document_walkin_only.sql
--    narrowed three enums to walk-in values, and the file meant to reverse
--    that (document_online_lifecycle.sql) has not been applied here. The
--    live table still holds:
--
--        source           enum('walk_in')
--        document_status  enum('Filed','Pending_Clearance','Processing',
--                              'Ready','Claimed','Rejected')
--        fulfillment_type enum('Pickup','Digital')
--
--    Meanwhile api/student-documents.php writes 'online',
--    'Awaiting_Payment' and 'Delivery'. This server runs WITHOUT
--    STRICT_TRANS_TABLES (sql_mode is NO_ZERO_IN_DATE, NO_ZERO_DATE,
--    NO_ENGINE_SUBSTITUTION only), so MySQL/MariaDB does not reject those
--    writes - it coerces them to ''. Verified against the live database
--    before writing this file:
--
--        INSERT ... source='online', document_status='Awaiting_Payment',
--                       fulfillment_type='Delivery'
--        -> "insert reported OK"
--        -> stored as source='', document_status='', fulfillment_type=''
--
--    Every student who filed online therefore got a row the registrar
--    desk cannot read: no provenance, no stage, no fulfilment mode. The
--    four rows currently in the table all show document_status='' for
--    exactly this reason, and the desk's status pill has been falling
--    through to its 'filed' default on all of them.
--
--    So section 1 is not "prepare for the wizard". It repairs rows that
--    are already wrong. It runs FIRST because MySQL validates a value
--    against the column's CURRENT definition: writing 'Awaiting_Payment'
--    before the enum admits it would coerce to '' again.
--
--    Ordering is widened -> migrate -> (never narrowed). A migration that
--    narrows an enum out from under its writers is data loss, not a
--    rollback.
-- ─────────────────────────────────────────────────────────────────────

-- ─────────────────────────────────────────────────────────────────────
-- 1a. source: how the desk tells an online filing from a counter filing.
--
--     The walk-in narrowing left an ONLINE request with no honest value
--     to store, which is the provenance gap this restores.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'source'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `source` enum('walk_in','online') NOT NULL DEFAULT 'walk_in'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 1b. fulfillment_type: courier comes back as a third fulfilment mode.
--     'Digital' is kept.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'fulfillment_type'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `fulfillment_type`
       enum('Pickup','Delivery','Digital') NOT NULL DEFAULT 'Pickup'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 1c. document_status: regain Awaiting_Payment and Shipped, and gain the
--     two states the wizard owns - Draft and Cancelled.
--
--     Draft   a request the student started and has not submitted. It is
--             NOT work: the desk's counts, rail and clock must all ignore
--             it, and a half-finished form must never appear as a filed
--             request that somebody failed to start.
--     Cancelled  the student withdrew it. A real terminal outcome, and it
--             is deliberately KEPT on the desk rather than deleted: money
--             may have been paid against it, and "he cancelled it" is the
--             answer to a question somebody will ask.
--
--     The seven pre-existing values are kept byte-for-byte in their
--     original order so a rollback stays a re-run rather than an edit.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'document_status'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `document_status`
       enum('Draft','Filed','Pending_Clearance','Awaiting_Payment',
            'Processing','Ready','Shipped','Claimed','Rejected','Cancelled')
       NOT NULL DEFAULT 'Filed'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 1d. payment_method: the wizard offers four ways to pay, not two.
--
--     Bank_Transfer is a real third channel (deposit slip + reference),
--     and it needs to be STORED rather than implied - finance reconciles
--     bank remittances against the reference the desk types in, which is
--     the same argument document_receipt_upload.sql makes for keeping the
--     GCash reference beside the gateway's transaction id.
--
--     'Counter' and 'Cash_on_Delivery' mean the same thing and BOTH are
--     kept: 'Counter' is what the desk's own walk-in form sends,
--     'Cash_on_Delivery' is what the student portal sends, and
--     api/student-documents.php maps one onto the other. Dropping either
--     would reject a caller that is still in production.
-- ─────────────────────────────────────────────────────────────────────
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_method'
);
SET @s := IF(@has = 1,
  "ALTER TABLE `document_requests`
     MODIFY COLUMN `payment_method`
       enum('Online','Bank_Transfer','Cash_on_Delivery','Counter')
       NOT NULL DEFAULT 'Counter'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 2. Repair the rows the narrowing already emptied.
--
--    Restricted to document_status='' - the exact signature of the
--    coercion - rather than guessed at per row. Everything else about
--    those requests is intact (catalog_id, fee, student, purpose), so
--    they are recoverable and it would be wrong to leave them blank.
--
--    filed_at is used as the discriminator for Awaiting_Payment: an
--    online filing entered that state and never left it, and the only
--    other candidates are the two statuses still in the enum.
--
--    A row emptied in ALL THREE columns was filed through the online
--    form, so it goes back to 'online' and is re-staged on what it
--    actually was. The three columns are repaired together because they
--    were corrupted together: a row cannot be meaningfully half-restored.
-- ─────────────────────────────────────────────────────────────────────

UPDATE `document_requests`
   SET `source`           = 'online',
       `document_status`  = 'Filed',
       `fulfillment_type` = 'Pickup'
 WHERE `document_status` = ''
   AND `source`           = ''
   AND `fulfillment_type` = '';

-- Of those, the ones that paid online are waiting on money, not filed.
--
-- paid_at is the discriminator rather than payment_method, because
-- payment_method was NOT coerced (it still held 'Online' on the probe
-- row) and so it is the one column that survived intact.
UPDATE `document_requests`
   SET `document_status` = 'Awaiting_Payment'
 WHERE `source`          = 'online'
   AND `document_status` = ''
   AND `payment_method`  = 'Online'
   AND `paid_at` IS NULL;

-- ─────────────────────────────────────────────────────────────────────
-- 3. The wizard's own columns.
--
--    All ADDITIVE and all guarded, so this file is safe to run twice.
-- ─────────────────────────────────────────────────────────────────────

-- Where the student had got to. 0 = nothing chosen yet.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'wizard_step'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `wizard_step` tinyint(3) NOT NULL DEFAULT 0
     COMMENT 'Furthest wizard step reached: 0 none, 1..4 (drafts only)'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Free-text notes the student typed in "Additional Notes".
--
-- Distinct from `purpose`, which is the one-sentence reason the document
-- is needed. notes is everything else: what the Dean's office already
-- has, which courier window suits, who to call about it.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'notes'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `notes` text NULL
     COMMENT 'Additional notes the student typed, free text'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Which preset purpose was chosen, kept apart from the typed sentence.
--
-- The preset is what the desk FILTERS on (employment / board exam /
-- further studies), and it has to be a controlled value to be filterable
-- at all. Storing it as free text inside `purpose` would make the filter
-- a LIKE across prose.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'purpose_code'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `purpose_code` varchar(40) NULL
     COMMENT 'Controlled purpose: employment|board_exam|further_studies|transfer|other'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- When the student finished the wizard. The stamp that separates "filed"
-- from "still being filled in".
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'submitted_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `submitted_at` datetime NULL
     COMMENT 'When the student completed the wizard and submitted'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The promise the confirmation screen makes, written down once at
-- filing rather than recomputed per page load.
--
-- A date that shifts every time it is derived is a date nobody can quote
-- over the counter. See doc_estimated_release() in shared/doc_wizard.php
-- for how it is calculated.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'estimated_release_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `estimated_release_at` datetime NULL
     COMMENT 'Promised release date, computed once at filing'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The over-the-counter / bank reference a student is quoted.
--
-- Issued at filing so the student can quote it at a bank branch hours
-- before the desk looks at the request, which is the whole point of a
-- reference number. 6 digits from a per-year counter; see
-- doc_payment_reference() for the collision handling.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'payment_reference'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `payment_reference` varchar(24) NULL
     COMMENT 'Payment reference quoted to the student (OTC / bank)'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Withdrawal. Cancelled requests are kept, so the row has to be able to
-- say who cancelled, when, and why.
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'cancelled_at'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `cancelled_at` datetime NULL
     COMMENT 'When the student withdrew the request'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_requests'
     AND COLUMN_NAME = 'cancellation_reason'
);
SET @s := IF(@has = 0,
  "ALTER TABLE `document_requests`
     ADD COLUMN `cancellation_reason` varchar(255) NULL
     COMMENT 'Why the student withdrew it'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─────────────────────────────────────────────────────────────────────
-- 4. document_type_requirements - the per-document checklist.
--
--    The wizard's upload step lists what THIS document needs ("Clearance
--    from Library", "2x2 ID Picture"), and that list differs per SKU. It
--    cannot live in document_catalog.requirement, which is a single
--    free-text string: there is nowhere to record that an item is
--    required rather than advisory, how many files it accepts, or what
--    order it appears in.
--
--    Data, not code, so the office can add a requirement to a document
--    without a deploy. Required-ness is a column rather than a prefix on
--    the label, because "required" is the one thing the wizard has to be
--    able to ASK the database rather than guess.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `document_type_requirements` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `catalog_id`  int(11) NOT NULL,
  `code`        varchar(40)  NOT NULL COMMENT 'Stable key, e.g. library_clearance',
  `label`       varchar(160) NOT NULL COMMENT 'What the student sees',
  `hint`        varchar(255) NULL     COMMENT 'Format guidance, e.g. "white background"',
  `is_required` tinyint(1)   NOT NULL DEFAULT 1,
  `max_files`   tinyint(3)   NOT NULL DEFAULT 1,
  `sort_order`  int(11)      NOT NULL DEFAULT 0,
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_code` (`catalog_id`, `code`),
  KEY `idx_catalog_active` (`catalog_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────
-- 5. document_request_attachments - one row per uploaded file.
--
--    The wizard's upload step accepts SEVERAL files for one request (one
--    per checklist item, plus a drag-and-drop dropzone), and
--    document_requests.requirement_file_path holds exactly one path.
--    So the wizard's uploads needed somewhere of their own; retrofitting
--    "file1, file2, file3" into one varchar would make every future
--    query a FIND_IN_SET.
--
--    requirement_code ties a file back to the checklist item it satisfies,
--    so "still missing: library clearance" is a LEFT JOIN rather than a
--    guess from the filename.
--
--    The SHA-256 is recorded for the same reason document_requests
--    already keeps record_file_sha256: evidence that can be quietly
--    swapped is not evidence.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `document_request_attachments` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `request_id`      int(11) NOT NULL,
  `requirement_code` varchar(40) NULL COMMENT 'Checklist item, or NULL for a loose file',
  `original_name`   varchar(180) NOT NULL COMMENT 'As uploaded; display only, never a path',
  `file_path`       varchar(255) NOT NULL COMMENT 'Relative to repo root',
  `sha256`          char(64)     NOT NULL COMMENT 'Proves the bytes are unaltered',
  `mime_type`       varchar(80)  NULL,
  `size_bytes`      int(11)      NOT NULL DEFAULT 0,
  `uploaded_at`     datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_request` (`request_id`),
  KEY `idx_att_req_code` (`request_id`, `requirement_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────
-- 6. Seed the checklist.
--
--    Idempotent by (catalog_id, code), and seeded from document_catalog
--    so a SKU added later can be given its own requirements without this
--    file being edited.
--
--    Two items are universal (valid school ID, and the 2x2 photo that
--    the office needs to staple to a printed document), so they are
--    attached to every active SKU rather than repeated seven times.
--
--    Honorable Dismissal additionally needs the Dean's signature, which
--    is the single hardest document to issue and the one most likely to
--    be filed without the paperwork.
-- ─────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'school_id', 'Valid School ID',
       'Front side, name and number clearly readable', 1, 1, 1
  FROM `document_catalog` c WHERE c.is_active = 1;

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'photo_2x2', '2x2 Photo (white background)',
       'Recent, plain background, no hat or sunglasses', 1, 1, 2
  FROM `document_catalog` c WHERE c.is_active = 1;

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'library_clearance', 'Library Clearance',
       'Signed and stamped by the Library', 0, 1, 3
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku IN ('DOC-TOR','DOC-DIPLOMA','DOC-HD');

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'accounting_clearance', 'Accounting Clearance',
       'Signed and stamped by the Accounting Office', 0, 1, 4
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku IN ('DOC-TOR','DOC-DIPLOMA');

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'dean_signature', "Dean's Office Endorsement",
       'The original endorsement letter, not a photo of a photo', 1, 1, 1
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku = 'DOC-HD';

INSERT IGNORE INTO `document_type_requirements`
  (`catalog_id`, `code`, `label`, `hint`, `is_required`, `max_files`, `sort_order`)
SELECT c.id, 'affidavit_loss', 'Affidavit of Loss',
       'Signed in front of a notary public, with a valid ID', 1, 1, 1
  FROM `document_catalog` c
 WHERE c.is_active = 1
   AND c.sku IN ('DOC-DIPLOMA','DOC-HD');

-- ─────────────────────────────────────────────────────────────────────
-- 7. Adopt the pre-existing single requirement file.
--
--    document_requests.requirement_file_path predates this table and
--    already points at a real uploaded file on every request that has
--    one. Leaving it stranded would mean the wizard's upload step says
--    "nothing attached" for a request that plainly has a file, and the
--    student would re-upload a document the office already holds.
--
--    Deliberately NOT done in SQL. The row needs the file's SHA-256 and
--    byte size - otherwise it proves nothing about which bytes are on
--    disk, which is the whole reason the column exists - and it must
--    skip files that are referenced but absent, or the new table gains
--    a row pointing at nothing, which is worse than having no row.
--    Neither is expressible here.
--
--    Run:  php scripts/adopt_legacy_attachments.php
--
--    The existing column is NOT dropped. It is what the walk-in counter
--    form still writes, and a migration that removed it would break a
--    caller in production.
-- ─────────────────────────────────────────────────────────────────────