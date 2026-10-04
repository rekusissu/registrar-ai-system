-- ============================================================================
--  MIGRATIONS/UNSEED_150_STUDENTS.SQL
--
--  Removes everything migrations/seed_150_students.sql created.
--
--    mysql -u root registrar_ai < migrations/unseed_150_students.sql
--
--  HOW ROWS ARE IDENTIFIED
--  -----------------------
--  Two independent markers, and a row must carry BOTH to be deleted:
--
--    student_number LIKE 'T9150%'    the number marker
--    email LIKE '%@seed150.test'      the address marker
--
--  One marker alone is not enough. student_number is assigned later by the
--  enrolment office and is NOT unique - the table has only a non-unique index
--  on it - so two unrelated records can share one, and a real student could
--  eventually be issued a T9150xxxx number. Requiring both means this file can
--  only ever remove what the seed actually wrote.
--
--  Nothing outside those markers is touched. The two pre-existing students and
--  the real DOC-2026-0001 request survive.
--
--  ORDER: children before parents. document_request_events and
--  academic_grades have no declared foreign keys today, which is exactly why
--  this order is not left to chance - the deletes still resolve through the
--  student_id chain rather than assuming a cascade exists.
-- ============================================================================

START TRANSACTION;

-- ── Confirm what is about to go, before deleting anything ──────────────
SELECT 'students to remove' AS what, COUNT(*) AS n
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
UNION ALL SELECT 'requests to remove', COUNT(*)
    FROM document_requests r JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'history rows to remove', COUNT(*)
    FROM academic_history h JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

-- ── Warn about rows carrying one marker but not the other ─────────────
--
-- This seed did not write any such row, so one appearing means something else
-- did - or that a real student has been issued a T9150xxxx number. The deletes
-- below still require BOTH markers and so are safe either way; this exists to
-- surface the situation rather than to block on it.
SELECT 'MIXED MARKERS - not written by this seed, review:' AS warning,
       student_number, email
    FROM students
    WHERE (student_number LIKE 'T9150%') <> (email LIKE '%@seed150.test')
    LIMIT 10;

DELETE e FROM document_request_events e
    JOIN document_requests r ON r.id = e.request_id
    JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

DELETE r FROM document_requests r
    JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

DELETE g FROM academic_grades g
    JOIN academic_history h ON h.id = g.academic_history_id
    JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

DELETE h FROM academic_history h
    JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

DELETE e FROM enrollment_history e
    JOIN students s ON s.id = e.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

-- student_ids IS written by this seed. It must go BEFORE the students delete
-- below, or the IDs are orphaned - each one points at a students row that no
-- longer exists, holding a number the next real student to be issued that ID
-- would collide with.
DELETE i FROM student_ids i
    JOIN students s ON s.id = i.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

-- Not written by this seed: a safety net for a half-finished earlier attempt.
DELETE c FROM rfid_cards c
    JOIN students s ON s.id = c.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

DELETE FROM students
    WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test';

COMMIT;

-- ── Confirm, rather than assume, that it worked ─────────────────────────
-- 'rows remaining' must be 0 for both. The other two counts must be unchanged
-- from before: this script does not touch them, and a drop would mean it did.
SELECT 'seeded students remaining' AS check_name, COUNT(*) AS n
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
UNION ALL SELECT 'all students in table', COUNT(*) FROM students
UNION ALL SELECT 'all document_requests in table', COUNT(*) FROM document_requests;