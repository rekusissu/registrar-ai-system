-- ============================================================
--  MIGRATIONS/DOCUMENT_TYPE_ENUM.SQL
--  Every document a student can request must round-trip its type.
--
--  THE BUG
--  -------
--  api/student-documents.php derives document_requests.document_type
--  from the catalog SKU:
--
--      DOC-TOR -> transcript      DOC-COE -> certificate
--      DOC-GM  -> good_moral     DOC-DIPLOMA -> diploma
--      DOC-CTC -> ctc            DOC-HD  -> honorable_dismissal
--      DOC-CD  -> course_description
--
--  but the column is:
--
--      enum('form137','good_moral','transcript','certificate','clearance')
--
--  Four of those seven values are not in the enum, and the fallback
--  (strtolower($sku)) is outside it for every SKU the map does not
--  name. This server does NOT run STRICT_TRANS_TABLES, so MySQL does
--  not reject the value - it silently coerces it to ''. The request is
--  filed, the registrar sees it, and the document type is blank.
--
--  Verified on this host: inserting 'diploma' stores '', not an error.
--
--  Only transcript, certificate and good_moral survived. Diploma, CTC,
--  Honorary Dismissal and Course Description were all filed with an
--  empty type - which reads, on the student's own page and in the
--  registrar's filters, as "the document type is missing".
--
--  THE FIX
--  -------
--  Widen the enum to the vocabulary the code already produces, rather
--  than bending the vocabulary to fit a stale column. The column is
--  documented as legacy (catalog_id + document_catalog.sku is the real
--  source of truth), but it is still read for display and filtering,
--  so it has to be able to hold a real answer.
--
--  form137 and clearance are kept: they are already permitted and
--  older rows may carry them.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ── 1. Does the column need widening? ───────────────────────
-- SHOW COLUMNS returns the enum as one string, so the guard is a
-- substring test rather than a list comparison.
SET @col := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'document_requests'
     AND COLUMN_NAME  = 'document_type'
);

-- Already wide enough: do nothing, so re-running is safe.
SET @needs_fix := (
  SELECT IF(
    @col IS NULL
      OR LOCATE('diploma',  @col) > 0
      OR LOCATE('ctc',      @col) > 0
      OR LOCATE('honorable_dismissal', @col) > 0
      OR LOCATE('course_description', @col) > 0,
    0, 1)
);

SET @sql := IF(@needs_fix = 1,
  'ALTER TABLE document_requests MODIFY `document_type` ENUM(''form137'',''good_moral'',''transcript'',''certificate'',''clearance'',''diploma'',''ctc'',''honorable_dismissal'',''course_description'') NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 2. Repair what the bug already wrote ────────────────────
-- Rows filed with an empty type cannot be recovered exactly: the SKU
-- is still on document_catalog, so re-derive from it rather than
-- guess. Rows whose catalog row is also missing keep '' and are
-- reported below - they are the ones a registrar has to look at.
UPDATE document_requests dr
  JOIN document_catalog dc ON dc.id = dr.catalog_id
   SET dr.document_type = CASE dc.sku
        WHEN 'DOC-TOR'     THEN 'transcript'
        WHEN 'DOC-COE'     THEN 'certificate'
        WHEN 'DOC-GM'      THEN 'good_moral'
        WHEN 'DOC-DIPLOMA' THEN 'diploma'
        WHEN 'DOC-CTC'     THEN 'ctc'
        WHEN 'DOC-HD'      THEN 'honorable_dismissal'
        WHEN 'DOC-CD'      THEN 'course_description'
        ELSE LOWER(dc.sku)
   END
 WHERE dr.document_type = ''
   AND dr.catalog_id IS NOT NULL;

-- ── 3. What is left, for whoever reads this afterwards ───────
-- Every remaining blank type is a request whose catalog row is gone.
-- The count is printed rather than hidden, because a silent 3 rows
-- repaired and 1 row lost is how a repair looks like a success.
SELECT COUNT(*) AS still_missing_document_type
  FROM document_requests
 WHERE document_type = '';