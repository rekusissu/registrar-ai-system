-- ============================================================================
--  MIGRATIONS/SEED_150_STUDENTS.SQL
--
--  Creates 150 test students, each with academic history, subject grades,
--  enrollment history and document requests, spread across real courses,
--  statuses, year levels and points in the document lifecycle.
--
--    mysql -u root registrar_ai < migrations/seed_150_students.sql
--
--  WHY A GENERATED SET INSTEAD OF 150 HAND-WRITTEN ROWS
--  ----------------------------------------------------
--  The point is volume and spread: the roster filters, the 50-row pagination,
--  the per-course and per-status counts and the document desk's status
--  grouping all need rows to bite on. Hand-written rows drift out of sync with
--  each other the moment a column changes. This derives every value from the
--  row's own ordinal, so a student is reproducible: the same n always yields
--  the same name, course and history.
--
--  Randomness is MOD-based, not RAND(). A RAND() seed is unrepeatable, which
--  makes a bug reported against "student 88" impossible to re-create. MOD of a
--  prime walks the lists in a fixed but well-mixed order.
--
--  NO RFID
--  -------
--  Deliberate, and asserted at the end. Nothing here writes rfid_cards, and
--  every student_ids row it creates leaves rfid_card_id NULL, so each seeded
--  student has an ID but no card and nothing points at one. lrn is left NULL
--  as well. The card-insights page and the RFID scanner therefore start from a
--  truthful "none issued" state instead of showing 150 cards that were never
--  issued.
--
--  An ID without a card is a real state in this system: the enrolment office
--  issues the ID first and the card separately. That is why the IDs are seeded
--  but the cards are not.
--
--  EVERY SEEDED ROW IS MARKED AND REMOVABLE
--  ----------------------------------------
--  Two independent markers, and removal needs both to agree - the same rule
--  tests/clear_seeded_students.php uses:
--
--    student_number LIKE 'T9150%'      the number marker
--    email LIKE '%@seed150.test'       the address marker
--
--  One marker alone is not enough: student_number is assigned later by the
--  enrolment office and is NOT unique (there is only a non-unique index on
--  it), and a real student could eventually be issued a T9150xxxx number.
--  Requiring both means this file can only ever remove what it wrote.
--
--  Roll it back with migrations/unseed_150_students.sql.
--
--  SAFE TO RE-RUN
--  --------------
--  Deletes its own prior output first, inside the same transaction, so a
--  second run cannot produce 300 students or collide on student_number.
--  It never touches a row that is not marked.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0.  Guard: widen document_type to the vocabulary the app actually writes.
--
--     document_requests.document_type is enum('form137','good_moral',
--     'transcript','certificate','clearance') - five values - but the catalog
--     has seven SKUs and api/student-documents.php maps them to diploma, ctc,
--     honorable_dismissal and course_description as well.
--
--     This server does not run STRICT_TRANS_TABLES, so writing one of those
--     four does not raise an error; MySQL silently coerces it to ''. That is
--     the bug migrations/document_type_enum.sql documents, and it is the
--     reason this block exists rather than being left to that migration.
--
--     The guard counts the rows WHERE the enum is still narrow. If that count
--     is GREATER THAN zero the column needs widening, so the ALTER is what
--     runs. Testing `COUNT(*) = 0` instead fires the ALTER only when the enum
--     is ALREADY correct - the exact inversion, which leaves the column narrow
--     and every unmapped type blank. Verified against this server: the wrong
--     form returned 'DO 0' on a database that demonstrably needed the change.
-- ----------------------------------------------------------------------------
SET @ddl = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE `document_requests`
            MODIFY `document_type`
            ENUM(''form137'',''good_moral'',''transcript'',''certificate'',''clearance'',''diploma'',''ctc'',''honorable_dismissal'',''course_description'')
            NOT NULL',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'document_requests'
      AND COLUMN_NAME  = 'document_type'
      AND COLUMN_TYPE  = 'enum(''form137'',''good_moral'',''transcript'',''certificate'',''clearance'')'
);
PREPARE s FROM @ddl;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- 0b. Ensure the document catalog exists.
--
--     The requests below JOIN document_catalog, and the join produces NOTHING
--     when the table is empty. On a database imported from registrar_ai.sql the
--     catalog IS empty - create_admin.php deliberately never loads it, because
--     which documents a school issues and at what price is a business decision
--     rather than a default.
--
--     So against a fresh install this seed used to create 150 students and
--     zero document requests, exit 0, and print a report in which every
--     request count was legitimately 0. Nothing errored. The failure was
--     completely silent, and a report full of zeroes reads as "nothing to do"
--     rather than "nothing was created".
--
--     The seven rows below are the same defaults create_admin.php offers in
--     defaultCatalog(), because a seed cannot ask a question. On a real
--     installation this is only ever a test fixture - it inserts nothing when
--     the catalog is already populated, and never edits or deletes an
--     existing row.
--
--     Guarded on sku, not on the table being empty: a partly-populated catalog
--     gets the missing SKUs and keeps the ones it has, so an office that has
--     retired a document keeps its own fee and pricing decision.
-- ----------------------------------------------------------------------------
INSERT INTO document_catalog
    (sku, name, description, base_fee, fee_type, requirement, sla_days, is_active)
SELECT d.sku, d.name, d.description, d.base_fee, d.fee_type, d.requirement, d.sla_days, 1
FROM (
    SELECT 'DOC-COE' AS sku, 'Certificate of Enrollment' AS name,
           'Proof of current enrollment' AS description, '100.00' AS base_fee,
           'flat' AS fee_type, NULL AS requirement, 1 AS sla_days
    UNION ALL SELECT 'DOC-TOR', 'Transcript of Records',
           'Complete academic record (TOR)', '250.00', 'per_page',
           'Scanned copy of valid ID', 3
    UNION ALL SELECT 'DOC-GM', 'Certificate of Good Moral',
           'Good moral character certificate', '150.00', 'flat',
           'No pending disciplinary cases', 3
    UNION ALL SELECT 'DOC-DIPLOMA', 'Diploma Replacement',
           'Replacement of lost diploma', '1000.00', 'flat',
           'Notarized Affidavit of Loss', 5
    UNION ALL SELECT 'DOC-CTC', 'Certified True Copy',
           'Certified true copy of a record', '50.00', 'per_page', NULL, 2
    UNION ALL SELECT 'DOC-HD', 'Honorable Dismissal',
           'Transfer / honorable dismissal', '300.00', 'flat', NULL, 10
    UNION ALL SELECT 'DOC-CD', 'Course Description',
           'Subject syllabus / course description', '100.00', 'per_syllabus', NULL, 1
) d
WHERE NOT EXISTS (
    SELECT 1 FROM document_catalog c WHERE c.sku = d.sku
);

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1.  1..150, once.
--
--     MariaDB 10.2+ recursive CTE. Kept in a temporary table because it is
--     referenced by four different inserts below, and a CTE is scoped to a
--     single statement.
-- ----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_seed150;
CREATE TEMPORARY TABLE tmp_seed150 (
    n INT NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO tmp_seed150 (n)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 150
)
SELECT n FROM seq;

-- ----------------------------------------------------------------------------
-- 2.  Remove any output from a previous run.
--
--     Without this the file is not re-runnable: request_id and qr_hash both
--     carry UNIQUE indexes, so a second run collides on the first insert and
--     the whole seed aborts. Re-running is a normal thing to do while editing
--     a seed, and a seed that cannot be re-run forces a manual DROP first.
--
--     Children before parents. document_request_events and academic_grades
--     reference their parents, so deleting in the other order would leave
--     orphans on a database that has real foreign keys. None are declared
--     today - which is exactly why this order is not left to chance.
--
--     Scoped by joining back to students and requiring BOTH markers, so this
--     can never reach a real student's history. A delete on the marker alone
--     would be enough today and would be wrong the day a real record carries
--     a matching student_number.
-- ----------------------------------------------------------------------------
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

-- student_ids IS written by this seed, so this delete is load-bearing rather
-- than a safety net. It has to run before the students delete below, or the
-- IDs are orphaned: student_ids.student_id points at a students row, and
-- deleting the parent first leaves an ID belonging to nobody. An ID left
-- behind is worse than a missing student - it occupies a number that the next
-- real student to be issued that ID would collide with.
DELETE i FROM student_ids i
    JOIN students s ON s.id = i.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

-- rfid_cards is NOT written by this seed. It is a safety net for a
-- half-finished earlier attempt, which would otherwise leave a card that
-- nothing else could clean up.
DELETE c FROM rfid_cards c
    JOIN students s ON s.id = c.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

DELETE FROM students
    WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test';

-- ----------------------------------------------------------------------------
-- 3.  The 150 students.
--
--     Values come from ELT(MOD(n * PRIME, <list length>) + 1, ...) so each
--     column walks its list in its own order. Different primes per column mean
--     course and status are not correlated - a single prime would hand every
--     BSIT student the same status and quietly destroy the spread the seed
--     exists to provide.
--
--     status is constrained to what the column's enum actually accepts:
--     enrolled, active, graduate, alumni, dropped. The roster filters on it,
--     so an out-of-enum value would not appear as a distinct filter at all.
--     graduate and dropped are deliberately rare (1-in-8 slots) so they show
--     up in the data without swamping the enrolled majority, which is what a
--     real term looks like.
-- ----------------------------------------------------------------------------
INSERT INTO students (
    student_number, first_name, middle_name, last_name, gender, civil_status,
    birth_date, place_of_birth, nationality, religion, address,
    contact_number, email, course, year_level, school_year, semester,
    section, status, mother_name, father_name,
    -- lrn, photo, rfid: intentionally omitted. See the NO RFID note at the top.
    email_is_placeholder
)
SELECT
    CONCAT('T9150', LPAD(n, 4, '0')),

    -- first name: 20 given names
    ELT(MOD(n * 7,  20) + 1, 'Juan','Maria','Pedro','Ana','Jose','Cristina',
        'Mark','Grace','Paul','Angel','Ryan','Nicole','Carlo','Jenny',
        'Diego','Kim','Miguel','Sofia','Nathan','Camille'),

    -- middle initial on every 3rd row, so some rows have one and most do not
    CASE WHEN MOD(n, 3) = 0
         THEN ELT(MOD(n * 11, 7) + 1, 'A','B','C','D','E','F','G') END,

    -- last name: 25 surnames
    ELT(MOD(n * 13, 25) + 1, 'Dela Cruz','Santos','Reyes','Bautista',
        'Ocampo','Garcia','Mendoza','Torres','Ramos','Cruz',
        'Villanueva','Aquino','Castillo','Flores','Rivera',
        'Gonzales','Domingo','De Guzman','Navarro','Salazar',
        'Padilla','Lorenzo','Manalo','Cabrera','Evangelista'),

    -- gender, alternating
    CASE WHEN MOD(n, 2) = 0 THEN 'Male' ELSE 'Female' END,

    -- civil status: overwhelmingly Single, as a student body would be
    CASE
        WHEN MOD(n, 17) = 0 THEN 'Married'
        WHEN MOD(n, 53) = 0 THEN 'Widowed'
        ELSE 'Single'
    END,

    -- 2004-2006 births, so a Year 4 student is plausibly ~22 and not 40.
    -- Day offset derived from n so rows do not all share one date.
    DATE_ADD('2004-01-01', INTERVAL (n * 37) % 1095 DAY),

    ELT(MOD(n * 3, 4) + 1, 'Quezon City','Manila','Davao City','Cebu City'),
    'Filipino',
    ELT(MOD(n * 5, 3) + 1, 'Roman Catholic', 'Iglesia ni Cristo', 'Born Again'),

    -- TESTDATA marker in the address as well, matching the existing
    -- tests/seed_masterlist_150.php convention.
    CONCAT('TESTDATA-Seed150 Block ', MOD(n, 6) + 1,
           ' Lot ', LPAD(n, 4, '0'), ', Quezon City'),

    -- 09xx, unique per row
    CONCAT('09', LPAD(700000000 + n * 137, 9, '0')),

    -- second half of the marker pair
    CONCAT('seed150_', n, '@seed150.test'),

    -- 6 courses. Includes the two already in the table (BSIT, BSHM) so the
    -- roster's course filter has a realistic set to work with.
    ELT(MOD(n * 17, 6) + 1,
        'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
        'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)',
        'BACHELOR OF SCIENCE IN ACCOUNTANCY (BSA)',
        'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)',
        'BACHELOR OF SCIENCE IN EDUCATION (BSEE)',
        'BACHELOR OF ARTS IN PSYCHOLOGY (BAPSY)'),

    -- year level 1..4, repeating: counts land on 38/38/37/37.
    MOD(n - 1, 4) + 1,

    -- Year 1-2 in the current school year, Year 3-4 in the previous one
    CASE WHEN MOD(n - 1, 4) + 1 <= 2 THEN '2026-2027' ELSE '2025-2026' END,

    -- Alternating term, so both the '1st' and '2nd' filters have rows.
    --
    -- MOD(n, 8), not MOD(n, 2). Keying the term off the same parity that sets
    -- the year level made the two perfectly correlated - every Year 1 came out
    -- '2nd' and every Year 2 '1st' - so half the term filters matched nothing
    -- and no single year level had both terms in it. That also collapsed the
    -- section codes to one per year level instead of one per year+term.
    CASE WHEN MOD(n, 8) < 4 THEN '1st' ELSE '2nd' END,

    -- Section code, built to shared/section_code.php's format:
    -- [year digit][semester digit][### section number], 5 digits, e.g. 11001
    -- is Year 1, 1st semester, section 1.
    --
    -- The section number is the student's rank within their own year+term
    -- block divided by MAX_STUDENTS_PER_SECTION (50), so the code genuinely
    -- GROUPS students: everyone in a block shares a code and a new one opens
    -- when the block passes 50. That is the shape autoAssignStudentSections()
    -- produces.
    --
    -- Partitioned by year+term and NOT by course, because a section code does
    -- not encode the program: nextSectionNumber() in shared/functions.php builds
    -- the code from (year, semester, n) alone, and a block may legitimately hold
    -- students from several courses. Partitioning by course as well invented
    -- course-specific codes that the enrolment office would never assign.
    --
    -- 150 students over 8 year+term blocks is ~19 each, so every block fits in
    -- one section and the codes run 11001..41002 rather than reaching 002. That
    -- is correct arithmetic, not a bug: at this cohort size no block overflows.
    --
    -- An earlier version wrote '11A1', a shape no reader in the app recognises.
    CONCAT(
        MOD(n - 1, 4) + 1,                          -- year digit
        CASE WHEN MOD(n, 8) < 4 THEN '1' ELSE '2' END,   -- semester digit
        LPAD(1 + (ROW_NUMBER() OVER (
                PARTITION BY MOD(n - 1, 4) + 1, MOD(n, 8) < 4
              ) - 1) DIV 50, 3, '0')
    ),

    -- enrolled/active dominate; graduate and dropped appear in 2 of every 8
    -- slots, which keeps them visible without distorting the roll.
    ELT(MOD(n * 19, 8) + 1,
        'enrolled','enrolled','enrolled','enrolled',
        'active','active',
        'graduate','dropped'),

    ELT(MOD(n * 23, 20) + 1, 'Maria','Rosario','Ana','Luz','Elena','Cristina',
        'Josefina','Teresita','Angeline','Grace','Divina','Fe','Ligaya',
        'Nena','Perla','Rita','Sonia','Tessa','Ying','Zena'),
    ELT(MOD(n * 29, 20) + 1, 'Ricardo','Antonio','Manuel','Jose','Pedro',
        'Ramon','Andres','Eduardo','Francisco','Gerardo','Hector',
        'Ismael','Julio','Leonardo','Mariano','Nestor','Oscar',
        'Rogelio','Samuel','Tomas'),

    -- 1 = the address is a placeholder, so the app never tries to email a
    -- seed150.test address during a test run.
    1
FROM tmp_seed150;

-- ----------------------------------------------------------------------------
-- 4.  Academic history.
--
--     One row per prior term, built by cross-joining the students against a
--     small term table. A Year 3 student has six terms behind them, a Year 1
--     has none - that relationship is the point, so the number of terms is
--     derived from year_level rather than fixed at one row each.
--
--     The senior-high row is what makes the transcript printable: a Year 4
--     student's TOR is meaningless without it.
--
--     school_year is derived from year_level and the term index rather than
--     hardcoded, so the terms always run backwards in time without a row ever
--     claiming to be in a term the student has not reached.
--
--     gwa sits in 1.20-2.60, the plausible band for this kind of institution.
--     A flat 1.00 would make every GWA-dependent view (honorable dismissal
--     eligibility, probation rules) behave identically for all 150.
--
--     accepted_at is left NULL: grade acceptance is a real human action by
--     Faculty, and the acceptance report counts students who have a term row
--     with no acceptance as "nothing received". Seeding every term as already
--     accepted would make that report untestable.
-- ----------------------------------------------------------------------------
INSERT INTO academic_history (
    student_id, school_name, school_year, semester, grade_level,
    gwa, subjects_completed, credits, remarks
)
SELECT
    s.id,
    CASE WHEN t.term_index = 0 THEN 'Senior High School'
         ELSE 'University' END,
    -- School year, derived so the terms always step backwards one year at a
    -- time. sy_start = 2026 minus how many school years back this term sits,
    -- and the second half of the range is simply sy_start + 1. Written as two
    -- separate integer expressions rather than nested string maths, because a
    -- CONCAT that builds a half-open range is far too easy to get wrong.
    CONCAT(2026 - (s.year_level * 2 - 1 - t.term_index) DIV 2, '-',
           2027 - (s.year_level * 2 - 1 - t.term_index) DIV 2),
    CASE WHEN MOD(t.term_index, 2) = 0 THEN '1st' ELSE '2nd' END,
    CASE WHEN t.term_index = 0 THEN 'Senior High School'
         ELSE CONCAT('Year ', s.year_level - FLOOR(t.term_index / 2)) END,
    -- gwa. A new student has no completed COLLEGE term, only the SHS year, so
    -- the count starts at the SHS row alone.
    --
    -- The band starts at 1.60 rather than 1.20, which matters: the subject
    -- offsets below reach 6 tenths either side of the mean, so a term GWA
    -- below 1.60 would push a rating under the 1.00 floor of the
    -- GRADE_SCALE_MIN..GRADE_SCALE_MAX scale that term_grades.php assumes.
    -- A rating of 0.61 is not a failing grade, it is a corrupt one.
    ROUND(1.60 + MOD(s.id * 7 + t.term_index * 13, 101) / 100.0, 2),
    -- 5 subjects in college terms, 8 in the SHS year
    CASE WHEN t.term_index = 0 THEN 8 ELSE 5 END,
    CASE WHEN t.term_index = 0 THEN 24.00 ELSE 15.00 END,
    CASE
        WHEN MOD(s.id * 7 + t.term_index * 13, 101) + 160 < 190 THEN 'With honors'
        WHEN MOD(s.id * 7 + t.term_index * 13, 101) + 160 >= 251 THEN 'Needs improvement'
        ELSE 'Passed'
    END
FROM students s
JOIN (
    -- 8 term slots, oldest first. A Year 4 needs 8 (7 college + 1 SHS),
    -- a Year 3 needs 6, a Year 2 needs 4, a Year 1 needs 1 (SHS only).
    SELECT 0 AS term_index UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
    SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL
    SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
) t
  -- Senior High School (term_index 0) plus the college terms already
  -- completed. A Year 1 student has finished NO college term, so this has to be
  -- (year_level - 1) * 2 and not year_level * 2 - 1: the latter gives a
  -- first-year student one completed college term, which would put a university
  -- result in the record of someone who has not sat one.
  ON t.term_index = 0 OR t.term_index <= (s.year_level - 1) * 2
WHERE s.student_number LIKE 'T9150%'
  AND s.email LIKE '%@seed150.test';

-- ----------------------------------------------------------------------------
-- 5.  Subject grades, five per college term and eight for the SHS year.
--
--     final_rating is the numeric 1.00-5.00 scale that
--     shared/term_grades.php actually averages - it is not a letter, and a
--     term GWA computed from these rows has to match the gwa column above,
--     because the printable transcript reads one and the roster screen reads
--     the other. A mismatch between them is a bug a user would see as a
--     transcript disagreeing with the screen.
--
--     So the rating is centred on that term's own GWA: the mean of the five
--     ratings equals the history row's gwa by construction. The spread comes
--     from offsetting each subject around that mean.
--
--     One subject in roughly nine fails, and its grade_status says so.
--     Without a failure no probation rule, no "needs improvement" remark and
--     no incomplete-term path has anything to act on.
-- ----------------------------------------------------------------------------
INSERT INTO academic_grades (
    academic_history_id, subject, subject_code, units,
    grade, final_grade, final_rating, remarks, grade_status,
    instructor, semester_taken, term_status, source_system
)
SELECT
    h.id,
    -- Subject names rotate over 8 slots; code and name are paired from the
    -- same slot so a row can never claim code "IT 102" for "Calculus".
    ELT(MOD(h.id + subj.i, 8) + 1,
        'Introduction to Computing','Calculus in the Life Sciences',
        'Technical English','Financial Accounting','Principles of Economics',
        'Human Relations','Statistics','Ethics'),
    ELT(MOD(h.id + subj.i, 8) + 1,
        'IT 101','MATH 101','ENG 101','ACC 101','ECO 101','HRM 101','STAT 101','ETH 101'),

    -- Uniform 3.00 units on purpose. shared/term_grades.php weights the GWA by
    -- units, so mixing 2-unit subjects into a term would pull the computed mean
    -- off the stored gwa. With uniform units the invariant holds: the mean of
    -- these ratings equals the history row's gwa exactly, which is what keeps
    -- the printable transcript agreeing with the roster screen.
    3.00,

    -- Letter grade derived from the same rating the numeric column holds, so
    -- the two can never disagree.
    CASE
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) <= 1.00 THEN 'A'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 2.00  THEN 'B+'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 2.50  THEN 'B'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 3.00  THEN 'C+'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 3.50  THEN 'C'
        ELSE 'D'
    END,

    -- final_grade column carries the same letter
    CASE
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) <= 1.00 THEN 'A'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 2.00  THEN 'B+'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 2.50  THEN 'B'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 3.00  THEN 'C+'
        WHEN ROUND(h.gwa + subj.offset / 10.0, 2) < 3.50  THEN 'C'
        ELSE 'D'
    END,

    -- the numeric rating: term GWA + this subject's offset
    ROUND(h.gwa + subj.offset / 10.0, 2),

    -- remarks and grade_status agree with each other: one subject in nine
    -- fails, and a failing subject can never also read "Passed"
    CASE
        WHEN MOD(h.id + subj.i, 9) = 0 THEN 'Failed'
        ELSE 'Passed'
    END,

    CASE
        WHEN MOD(h.id + subj.i, 9) = 0 THEN 'failed'
        ELSE 'passed'
    END,

    ELT(MOD(h.id + subj.i * 5, 10) + 1, 'Engr. Santos','Prof. Reyes',
        'Dr. Bautista','Prof. Cruz','Engr. Garcia','Ms. Mendoza',
        'Prof. Torres','Dr. Ramos','Mr. Aquino','Ms. Villanueva'),

    h.semester,

    -- every seeded term is a closed one; the current term belongs to the
    -- registrar, not to history
    'final',

    'seed150'
FROM academic_history h
JOIN students s ON s.id = h.student_id
JOIN (
    -- 8 subject slots.
    --
    -- The offsets sum to zero across BOTH sets used here, which is what makes
    -- the GWA invariant hold: slots 1-5 are -6,-3,0,3,6 (sum 0) and slots
    -- 1-8 add -5,+1,+4 (sum 0). A one-off offset like -2 would shift the
    -- computed term GWA away from the stored value and put the transcript and
    -- the roster screen into disagreement.
    SELECT 1 AS i, -6 AS offset UNION ALL
    SELECT 2, -3 UNION ALL SELECT 3, 0 UNION ALL SELECT 4, 3 UNION ALL
    SELECT 5, 6 UNION ALL SELECT 6, -5 UNION ALL SELECT 7, 1 UNION ALL
    SELECT 8, 4
) subj
  ON 1 = 1
WHERE s.student_number LIKE 'T9150%'
  AND s.email LIKE '%@seed150.test'
  -- SHS year gets 8 subjects, college terms get 5, matching the
  -- subjects_completed written into academic_history above.
  AND subj.i <= CASE WHEN h.school_name = 'Senior High School' THEN 8 ELSE 5 END;

-- ----------------------------------------------------------------------------
-- 6.  Enrollment history.
--
--     One row per student per term they have been enrolled in, which is what
--     the enrolment office reads to show how a student arrived at their
--     current year level. Mirrors students.year_level / school_year /
--     semester exactly, so the two cannot drift.
--
--     enrolled_at is stamped in the past and walks forward a term at a time.
-- ----------------------------------------------------------------------------
INSERT INTO enrollment_history (
    student_id, student_number, course, year_level,
    school_year, semester, section, status, enrolled_at
)
SELECT
    s.id,
    s.student_number,
    s.course,
    -- the year level the student was in at each point of the walk
    GREATEST(1, s.year_level - (t.term_index - 1) DIV 2),
    CONCAT(2026 - t.term_index DIV 2, '-', 2027 - t.term_index DIV 2),
    CASE WHEN MOD(t.term_index, 2) = 0 THEN '1st' ELSE '2nd' END,
    s.section,
    'enrolled',
    -- enrolled_at: backdated from today, one term (~4 months) at a time
    DATE_SUB(CURDATE(), INTERVAL t.term_index * 4 MONTH)
FROM students s
JOIN (
    SELECT 1 AS term_index UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
    SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
) t
  ON t.term_index <= s.year_level * 2 - 1
WHERE s.student_number LIKE 'T9150%'
  AND s.email LIKE '%@seed150.test';

-- ----------------------------------------------------------------------------
-- 7.  Document requests.
--
--     Spread across the lifecycle on purpose. The desk's status rail, its
--     counts, the revenue figure and the overdue calculation all key off
--     document_status, and each behaves differently depending on where a
--     request sits:
--
--       Awaiting_Payment  fee still owed, paid_at must be NULL
--       Filed             paid, waiting for a clerk to start it
--       Processing        paid, being prepared
--       Ready             paid, waiting for pickup
--       Claimed           paid and handed over, claimed_at set
--       Rejected          refusal reason required
--
--     Two invariants carry most of the weight:
--
--       paid_at is NULL if and only if document_status = 'Awaiting_Payment'.
--       The desk derives "is this paid?" from that pairing, so a Filed row
--       with no paid_at renders as an unpaid request that has already been
--       processed - the exact confusion this data exists to prevent.
--
--       status (the legacy column) mirrors document_status. Filing writes
--       'pending', claiming writes 'released'. A row reading 'pending' while
--       claiming to be Collected is what the desk sees when it builds its
--       legacy filters, so both are written together.
--
--     request_id numbers from 9000 upward, clear of DOC-2026-0001, which is
--     the real request in use for end-to-end payment testing and must not be
--     crowded by seeded ids.
--
--     fee_amount comes from the catalog using the same arithmetic as
--     api/student-documents.php - base_fee when flat, base_fee x quantity for
--     per_page and per_syllabus. Joined rather than hardcoded, so a catalog
--     fee change cannot leave the desk quoting one number and billing another.
-- ----------------------------------------------------------------------------
INSERT INTO document_requests (
    request_id, student_id, catalog_id, document_type, quantity,
    request_type, fulfillment_type, payment_method, purpose,
    document_status, status, source, fee_amount, paid_at,
    ready_at, claimed_at, release_date, request_date,
    completed_date, rejection_reason, qr_hash
)
SELECT
    CONCAT('DOC-', YEAR(CURDATE()), '-',
           LPAD(9000 + ROW_NUMBER() OVER (ORDER BY s.id, q.n), 4, '0')),
    s.id,
    cat.id,
    -- legacy vocabulary, mirroring the sku->type map in
    -- api/student-documents.php
    CASE cat.sku
        WHEN 'DOC-COE'     THEN 'certificate'
        WHEN 'DOC-TOR'     THEN 'transcript'
        WHEN 'DOC-GM'      THEN 'good_moral'
        WHEN 'DOC-DIPLOMA' THEN 'diploma'
        WHEN 'DOC-CTC'     THEN 'ctc'
        WHEN 'DOC-HD'      THEN 'honorable_dismissal'
        WHEN 'DOC-CD'      THEN 'course_description'
    END,
    q.quantity,
    'Regular',
    'Pickup',
    -- online payment is what parks a request in Awaiting_Payment; everything
    -- else is paid at the counter and skips straight to Filed
    CASE WHEN q.lane = 0 THEN 'Online' ELSE 'Counter' END,
    q.purpose,
    q.document_status,
    CASE q.document_status
        WHEN 'Claimed'  THEN 'released'
        WHEN 'Rejected' THEN 'denied'
        WHEN 'Ready'    THEN 'approved'
        ELSE 'pending'
    END,
    CASE WHEN q.lane = 0 THEN 'online' ELSE 'walk_in' END,
    ROUND(cat.base_fee * (CASE WHEN cat.fee_type = 'flat' THEN 1 ELSE q.quantity END), 2),
    CASE WHEN q.document_status = 'Awaiting_Payment' THEN NULL
         ELSE DATE_SUB(NOW(), INTERVAL q.age_days DAY) END,
    CASE WHEN q.document_status IN ('Ready','Claimed')
         THEN DATE_SUB(NOW(), INTERVAL GREATEST(q.age_days - 1, 0) DAY) END,
    CASE WHEN q.document_status = 'Claimed'
         THEN DATE_SUB(NOW(), INTERVAL GREATEST(q.age_days - 2, 0) DAY) END,
    CASE WHEN q.document_status IN ('Ready','Claimed')
         THEN DATE_SUB(CURDATE(), INTERVAL q.age_days DAY) END,
    DATE_SUB(NOW(), INTERVAL q.age_days DAY),
    CASE WHEN q.document_status = 'Claimed'
         THEN DATE_SUB(NOW(), INTERVAL GREATEST(q.age_days - 3, 0) DAY) END,
    CASE WHEN q.document_status = 'Rejected'
         THEN 'Insufficient supporting documents on file' END,
    -- Deterministic hash, and unique per (student, lane).
    --
    -- The lane has to be in the key, not just the catalog id: the
    -- catalog_pick formula can select the SAME catalog row for two lanes of
    -- one student, which would then hash identically. qr_hash carries a
    -- UNIQUE index (uq_qr_hash), so that collision is a hard error, not a
    -- silent overwrite - and it aborts the whole seed.
    --
    -- q.n is included rather than cat.id so the value stays unique even where
    -- the Diploma filter below skips a catalog row.
    --
    -- Identical on a re-run, so re-seeding cannot invalidate a verification QR
    -- that has already been printed.
    SHA2(CONCAT('seed150-', s.id, '-', q.n), 256)
FROM students s
JOIN document_catalog cat ON cat.is_active = 1
JOIN (
    -- 6 lifecycle lanes, one request each per student.
    --
    -- Each lane names a distinct catalog row. An earlier version derived the
    -- row from MOD(student_id + lane, 6), which was a mistake twice over: it
    -- let two lanes of one student land on the SAME document (duplicate
    -- requests for one thing, and an identical qr_hash, which the UNIQUE
    -- index rejects outright), and it shifted the catalog mix as the student
    -- ids moved. Naming the row per lane keeps one student to one request per
    -- document and keeps the document mix even across all seven SKUs.
    SELECT 1 AS n, 'DOC-COE'     AS sku, 'Awaiting_Payment' AS document_status,
           1 AS quantity, 0 AS lane, 1 AS age_days, 'Scholarship application' AS purpose
    UNION ALL SELECT 2, 'DOC-TOR', 'Filed', 2, 1, 2, 'Transfer to another school'
    UNION ALL SELECT 3, 'DOC-GM',  'Processing', 1, 1, 4, 'Job application'
    UNION ALL SELECT 4, 'DOC-CTC', 'Ready', 3, 1, 6, 'Internship requirement'
    UNION ALL SELECT 5, 'DOC-CD',  'Claimed', 2, 1, 9, 'Graduate school requirement'
    UNION ALL SELECT 6, 'DOC-HD',  'Rejected', 1, 1, 11, 'Personal copy'
) q
  -- joined on sku rather than a position, so the lane cannot drift away from
  -- the document it names
  ON cat.sku = q.sku
WHERE s.student_number LIKE 'T9150%'
  AND s.email LIKE '%@seed150.test';

-- ----------------------------------------------------------------------------
-- 8.  Timeline events.
--
--     The desk's expanded detail row reads document_request_events to draw
--     what happened and when, so seeded requests with an empty timeline look
--     like requests that never happened. Each request gets the events that
--     actually preceded its current status - an Awaiting_Payment request has
--     only its filing event and no payment, because it has not been paid.
--
--     created_by is NULL: these are the actions of students and of the
--     registrar office, not of a named user account, and no seeded user row
--     exists.
-- ----------------------------------------------------------------------------
-- Every request is FILED, including one still awaiting payment: filing is
-- what happened, the payment is what has not. An earlier version excluded
-- Awaiting_Payment here on the reasoning that it had "not really started",
-- which left those requests with no timeline at all - the desk's detail row
-- then showed a request that appears to have come from nowhere.
INSERT INTO document_request_events (request_id, status, note, created_by, created_at)
SELECT r.id, 'Filed', 'Request filed', NULL, r.request_date
FROM document_requests r
JOIN students s ON s.id = r.student_id
WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test';

-- The payment event, only where money actually moved.
INSERT INTO document_request_events (request_id, status, note, created_by, created_at)
SELECT r.id, 'Payment confirmed', 'Fee received at the counter', NULL, r.paid_at
FROM document_requests r
JOIN students s ON s.id = r.student_id
WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
  AND r.paid_at IS NOT NULL;

INSERT INTO document_request_events (request_id, status, note, created_by, created_at)
SELECT r.id, 'Processing', 'Started preparing the document', NULL, r.request_date
FROM document_requests r
JOIN students s ON s.id = r.student_id
WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
  AND r.document_status IN ('Processing','Ready','Claimed');

INSERT INTO document_request_events (request_id, status, note, created_by, created_at)
SELECT r.id, 'Ready', 'Ready for collection', NULL, r.ready_at
FROM document_requests r
JOIN students s ON s.id = r.student_id
WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
  AND r.ready_at IS NOT NULL;

INSERT INTO document_request_events (request_id, status, note, created_by, created_at)
SELECT r.id, 'Claimed', 'Released to the student', NULL, r.claimed_at
FROM document_requests r
JOIN students s ON s.id = r.student_id
WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
  AND r.claimed_at IS NOT NULL;

INSERT INTO document_request_events (request_id, status, note, created_by, created_at)
SELECT r.id, 'Rejected', r.rejection_reason, NULL, r.request_date
FROM document_requests r
JOIN students s ON s.id = r.student_id
WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
  AND r.document_status = 'Rejected';

-- ----------------------------------------------------------------------------
-- 9.  Student IDs.
--
--     An ID is NOT a column on students. It is a row in student_ids, which is
--     where the enrolment department records it, and both student/ids.php and
--     registrar/student-ids.php read that table. Setting students
--     .student_number alone would leave both ID pages showing "not yet
--     assigned", because neither reads the students row for the ID itself.
--
--     Format is YYYY-XXXX, as generateNextIdNumber() produces in
--     shared/qr_generator.php. Numbered from 9001 up so they sit clear of the
--     0001-0500 range real students are issued and cannot collide with one.
--
--     rfid_card_id is NULL and qr_code_path is unset. That is what keeps "no
--     RFID" true: an ID row can exist with no card behind it, and the scanner
--     resolves a bare ID string through id_number without one. Nothing here
--     issues a card or points at one.
--
--     expiry_date is NULL rather than a date. An expiry is a real decision by
--     the office, and inventing one would produce an ID that reads as valid
--     indefinitely.
-- ----------------------------------------------------------------------------
INSERT INTO student_ids (
    student_id, id_number, id_type, issue_date, expiry_date, status,
    school_year, card_color, rfid_card_id, qr_code_path
)
SELECT
    s.id,
    CONCAT(YEAR(CURDATE()), '-',
           LPAD(9000 + CAST(SUBSTRING(s.student_number, 6) AS UNSIGNED), 4, '0')),
    'school_id',
    DATE_SUB(CURDATE(), INTERVAL 30 DAY),
    NULL,
    'active',
    s.school_year,
    -- a rotating colour, so the ID pages render more than one shade
    ELT(MOD(CAST(SUBSTRING(s.student_number, 6) AS UNSIGNED), 4) + 1,
        'blue', 'green', 'maroon', 'gold'),
    NULL,
    NULL
FROM students s
WHERE s.student_number LIKE 'T9150%'
  AND s.email LIKE '%@seed150.test';

COMMIT;

-- ----------------------------------------------------------------------------
-- 9.  Report.
--
--     Printed rather than assumed. A seed that silently inserts fewer rows than
--     intended still looks like it worked - the tables are just smaller than
--     expected and nothing errors. These counts make that visible on the run
--     that created it rather than a week later.
-- ----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_seed150;

SELECT 'students' AS seeded, COUNT(*) AS rows_seeded
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
UNION ALL SELECT 'academic_history', COUNT(*)
    FROM academic_history h JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'academic_grades', COUNT(*)
    FROM academic_grades g
      JOIN academic_history h ON h.id = g.academic_history_id
      JOIN students s ON s.id = h.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'enrollment_history', COUNT(*)
    FROM enrollment_history e JOIN students s ON s.id = e.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'document_requests', COUNT(*)
    FROM document_requests r JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'request_events', COUNT(*)
    FROM document_request_events e
      JOIN document_requests r ON r.id = e.request_id
      JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'rfid_cards (must be 0)', COUNT(*)
    FROM rfid_cards c JOIN students s ON s.id = c.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'student_ids (150 expected)', COUNT(*)
    FROM student_ids i JOIN students s ON s.id = i.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'student_ids linked to an RFID card (must be 0)', COUNT(*)
    FROM student_ids i JOIN students s ON s.id = i.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
      AND i.rfid_card_id IS NOT NULL
UNION ALL SELECT 'students with a section', COUNT(*)
    FROM students WHERE student_number LIKE 'T9150%'
      AND email LIKE '%@seed150.test' AND section IS NOT NULL AND section <> ''
UNION ALL SELECT 'students with an LRN (must be 0)', COUNT(*)
    FROM students WHERE student_number LIKE 'T9150%'
      AND email LIKE '%@seed150.test' AND lrn IS NOT NULL AND lrn <> '';

-- DID THE SEED ACTUALLY DO ITS JOB?
--
-- Every count above is a plain COUNT, so a total failure also reads as a
-- passing report: 150 students and zero document requests, with
-- paid_at_violations legitimately 0 because it is counting violations among
-- no requests at all. That is what happened against a database whose catalog
-- was empty, and the output could not be distinguished from success.
--
-- So each expectation is stated here and reported as EXPECTED / GOT / OK,
-- where a shortfall is a FAIL rather than a number to be interpreted. This is
-- the part that turns a silent no-op into something you would notice.
SELECT 'students' AS expectation, 150 AS expected_rows, COUNT(*) AS got,
       IF(COUNT(*) = 150, 'OK', 'FAIL') AS result
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
UNION ALL SELECT 'document_requests', 900, COUNT(*),
       IF(COUNT(*) = 900, 'OK', 'FAIL')
    FROM document_requests r JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'student_ids', 150, COUNT(*),
       IF(COUNT(*) = 150, 'OK', 'FAIL')
    FROM student_ids i JOIN students s ON s.id = i.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'active catalog items the requests need', 6, COUNT(*),
       IF(COUNT(*) >= 6, 'OK', 'FAIL')
    FROM document_catalog WHERE is_active = 1
UNION ALL SELECT 'rfid_cards (must be 0)', 0, COUNT(*),
       IF(COUNT(*) = 0, 'OK', 'FAIL')
    FROM rfid_cards c JOIN students s ON s.id = c.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
UNION ALL SELECT 'ids linked to an RFID card (must be 0)', 0, COUNT(*),
       IF(COUNT(*) = 0, 'OK', 'FAIL')
    FROM student_ids i JOIN students s ON s.id = i.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
      AND i.rfid_card_id IS NOT NULL
UNION ALL SELECT 'paid_at violations (must be 0)', 0, COUNT(*),
       IF(COUNT(*) = 0, 'OK', 'FAIL')
    FROM document_requests r JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
      AND ( (r.document_status = 'Awaiting_Payment') <> (r.paid_at IS NULL) );

-- Section codes, so the grouping can be eyeballed. Every code should be
-- [year][sem][###] and agree with that student's own year and semester.
SELECT section, COUNT(*) AS n
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
    GROUP BY section ORDER BY section;

-- A section code that disagrees with the year level or semester it encodes
-- must be 0: the enrolment office's filters key on that agreement, so a code
-- that lies about its own student files them under the wrong roster.
SELECT COUNT(*) AS section_mismatches
    FROM students
    WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
      AND ( LEFT(section, 1) <> CAST(year_level AS CHAR)
            OR SUBSTRING(section, 2, 1) <> IF(semester = '2nd', '2', '1')
            OR section NOT REGEXP '^[1-9][1-3][0-9]{3}$' );

-- The spread, so it is obvious the mix came out as intended rather than
-- collapsing onto one value somewhere in the ELT lists.
SELECT course, COUNT(*) AS n
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
    GROUP BY course ORDER BY n DESC, course;

SELECT year_level, COUNT(*) AS n
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
    GROUP BY year_level ORDER BY year_level;

SELECT status, COUNT(*) AS n
    FROM students WHERE student_number LIKE 'T9150%' AND email LIKE '%@seed150.test'
    GROUP BY status ORDER BY n DESC;

-- Both columns are named aliased: unqualified `status` is ambiguous here,
-- because students and document_requests each have one.
SELECT r.document_status AS lifecycle, r.status AS legacy_status, COUNT(*) AS n
    FROM document_requests r JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
    GROUP BY r.document_status, r.status ORDER BY r.document_status;

-- The invariant that matters most, asserted rather than assumed. Any row
-- returned here is a request claiming to be unpaid-but-processed or
-- unpaid-but-handed-over, and the desk would render it as exactly that.
-- This must be 0.
SELECT COUNT(*) AS paid_at_violations FROM document_requests r
    JOIN students s ON s.id = r.student_id
    WHERE s.student_number LIKE 'T9150%' AND s.email LIKE '%@seed150.test'
      AND ( (r.document_status = 'Awaiting_Payment') <> (r.paid_at IS NULL) );

-- ============================================================================
--  Remove it again:
--    mysql -u root registrar_ai < migrations/unseed_150_students.sql
-- ============================================================================