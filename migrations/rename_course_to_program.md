# Rename `course` to `program` (schema migration — NOT yet applied)

Status: **not started, and deliberately deferred.** The UI and the database
both currently use "Course". A label-only change to "Program" was tried and
reverted: the Enrollment team has been asked whether their side uses
`program` or `course`, and the answer is pending. Until it arrives, "Course"
is the consistent choice everywhere.

**Blocked pending external confirmation.** Do not begin Step 1 until the
Enrollment team replies — see Step 5, which is the only part of this rename
we cannot do alone.

## Why the rename was split

`course` is a column in four tables:

| Table                | Column   | Notes                                  |
|----------------------|----------|----------------------------------------|
| `students`           | `course` | Primary record; the value users see    |
| `enrollments`        | `course` | Mirrors the source Enrollment System   |
| `enrollment_history` | `course` | Audit trail of previous values         |
| `queue_tickets`      | `course` | Copied from the student at issue time |

Renaming touches roughly 157 code references across PHP, JS, and SQL, plus
the external Enrollment System handoff. That is why the UI label and the
schema change are treated as separate decisions: the label is a cheap,
reversible cosmetic change, while the schema rename is a one-way operation
on stored data and should be done once, on its own, with a tested migration.

## Naming collision warning

`course` is already used in this codebase to mean *a subject offering*
(e.g. "CS101"). The `document_catalog` table has a row literally named
`Course Description`. Do **not** blindly rename every occurrence of the word
"course" — only the column/field that stores the student's degree program.

Safe to rename:
- SQL columns: `course` in the four tables above
- PHP array keys: `$student['course']`, `$input['course']`, `$payload['course']`
- JSON keys sent over the wire: `course`
- Form field names / element IDs: `addCourse`, `editCourse`, `filterCourse`,
  `csCourse`, `esCourse`, `genCourse`, `nsCourse`, `vCourse`

Leave alone:
- `document_catalog.name = 'Course Description'` (a requestable document)
- `courseStandardize()`, `courseAliases()`, `courseNameSimilarity()` — these
  standardize the *program* value, but the names are internal and harmless
- `.ah-course`, `courseChart` element IDs, `rfid-distribution` CSS classes
  unless you also update the HTML that targets them
- Any comment describing a subject rather than a program

## Step 1 — Add the new columns (non-destructive)

```sql
ALTER TABLE students           ADD COLUMN program VARCHAR(100) NULL AFTER major;
ALTER TABLE enrollments        ADD COLUMN program VARCHAR(100) NULL AFTER major;
ALTER TABLE enrollment_history ADD COLUMN program VARCHAR(100) NULL AFTER major;
ALTER TABLE queue_tickets      ADD COLUMN program VARCHAR(100) NULL;
```

## Step 2 — Backfill and verify before dropping anything

```sql
UPDATE students           SET program = course WHERE program IS NULL AND course IS NOT NULL;
UPDATE enrollments        SET program = course WHERE program IS NULL AND course IS NOT NULL;
UPDATE enrollment_history SET program = course WHERE program IS NULL AND course IS NOT NULL;
UPDATE queue_tickets      SET program = course WHERE program IS NULL AND course IS NOT NULL;

-- Must return 0 mismatches before you drop the old column.
SELECT COUNT(*) FROM students WHERE NOT (program <=> course);
```

## Step 3 — Dual-write in the application

Update every write path to set both columns for one release, so a rollback
never leaves the new column empty. The write paths are:

- `shared/functions.php` → `createStudentFromInput()` (used by both
  `api/students.php` and `api/enrollments.php`)
- `api/students.php` → `PUT` branch (`$allowedFields`)
- `api/masterlist.php` → `bulk_assign_section`, `edit_section`, `assign_sections`
- `api/masterlist.php` → `bulk_assign` / `next_section` filter parameters

Also update every read path: `SELECT *` is safe (it will return both), but
explicit column lists and `array_key_exists()` guards need `program` added.

## Step 4 — Switch reads, then drop

After one release with dual-writes and zero mismatches:

```sql
ALTER TABLE students           DROP COLUMN course;
ALTER TABLE enrollments        DROP COLUMN course;
ALTER TABLE enrollment_history DROP COLUMN course;
ALTER TABLE queue_tickets      DROP COLUMN course;
```

Then remove the `course` entries from the `$allowedFields` lists and the
`in_array()` allowlists in `api/students.php` and `api/enrollments.php`.

## Step 5 — External coordination (BLOCKED — waiting on the Enrollment team)

`api/enrollments.php` reads applicant records from the `enrollments` table,
which is populated by the **Enrollment System** (a separate team). The
column there is currently named `course`, and `api/enrollments.php` maps it
into `$payload['course']` before calling `createStudentFromInput()`.

**Status: the Enrollment team has been asked to confirm whether their side
uses `program`. Do not start Step 1 until they reply.** Renaming our column
before they confirm would either break the intake pipeline or force a
temporary translation layer that we would then have to remove.

When they confirm, the safest order is:

1. **They rename their column / payload key to `program` first** (or confirm
   they will accept `course` indefinitely).
2. **Then** we run this migration.
3. If they cannot change yet, add a translation shim at the intake boundary
   and delete it once they migrate:

```php
// api/enrollments.php — temporary until the Enrollment System renames its key
$payload['course'] = $input['program'] ?? $input['course'] ?? '';
```

A silent key mismatch here is the highest-risk failure mode of this whole
rename: applicants would lose their program with no visible error, because
`createStudentFromInput()` would reject them on the now-required year level
rather than on a missing program. Test the accept-an-applicant flow
explicitly after the rename.

Note: `registrar/masterlist.php` already sends `program:` to
`api/masterlist-handoff.php`, which already reads `$input['program']`. That
handoff is an internal CMS stub, not the Enrollment System, so it needs no
coordination.

## Testing checklist for the rename

- `vendor\bin\phpunit tests` (currently 17 tests, 44 assertions)
- Add-student flow (create via `registrar/students.php`)
- Accept-an-applicant flow (create via `registrar/students.php` → Receive)
- Edit-student flow (`PUT api/students.php`)
- Masterlist: auto-assign, create section, assign students, edit section
- Queue ticket creation and display
- Student portal: dashboard, profile, academic records
- Nurse lookup
- CSV/Excel exports from students and masterlist
- Every PDF document in `document_catalog` that prints the program

## Rollback

Until Step 4 completes, rollback is `git revert` plus dropping the `program`
columns. After Step 4, the `course` values are gone, so take a backup first —
`backups/` is the established location in this repo.
