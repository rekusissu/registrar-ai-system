# Major Update — Setup & Verify Checklist

Everything below must run once before the new registrar modules work end-to-end.
Estimated time: ~5 minutes.

## 1. Install PHP dependencies (QR code library)

The Student ID module generates QR codes via `chillerlan/php-qrcode`. The
`composer.lock` exists but `vendor/` is not installed.

```bash
cd C:/xampp/htdocs/registrar-ai-system
composer install
```

Skip this only if `vendor/autoload.php` is already present. Without it, ID cards
still issue, but no QR file is generated (the API no longer crashes — it returns
a clear log entry).

## 2. Apply the database migration

```bash
cd C:/xampp/htdocs/registrar-ai-system
mysql -u root registrar_ai < database/registrar_upgrade.sql
```

`registrar_upgrade.sql` is idempotent — safe to re-run. It adds:

- `students`: `lrn`, `name_suffix`, `mother_name`, `father_name`, `birth_country`
- New tables: `emergency_contacts`, `academic_grades`, `health_visits`
- `rfid_cards`: `qr_code_path`, `issued_at`
- `student_ids`: `qr_payload`, `school_year`, `card_color`
- `document_requests`: `fee_amount`, `official_receipt`, `release_date`
- `status_tracker`: `effective_date`, `end_date`
- `documents`: `category`, `is_locked`
- `health_records`: `blood_pressure`, `dietary_restrictions`

Verify: `SHOW TABLES LIKE 'health_visits';` should return one row.

## 3. Syntax-check the edited files

```bash
cd C:/xampp/htdocs/registrar-ai-system
for f in \
  api/students.php api/documents.php api/rfid-scan.php api/student-ids.php \
  registrar/guardians.php registrar/file-storage.php registrar/status-tracker.php \
  registrar/academic-history.php registrar/health-records.php registrar/student-ids.php \
  registrar/documents.php registrar/documents-add.php registrar/masterlist.php \
  registrar/students.php includes/sidebar.php includes/header.php ; do
  php -l "$f" || echo "FAILED: $f"
done
```

## 4. Smoke test (browser)

Log in at `http://localhost/registrar-ai-system/` (admin@bestlink.edu.ph).

New pages to check (left sidebar):
1. **Guardians & Contacts** — pick a student, add a guardian + emergency contact, Save. Reload, confirm persisted.
2. **File Storage** — Upload a PDF for a student; preview it; download; delete.
3. **Status Tracker** — confirm the status-change timeline renders from existing `status_tracker` rows.
4. **Students** → Add Student — confirm the new *Name Suffix / LRN / Father / Mother* fields save on an existing student; open the profile view and confirm the new fields display.
5. **Health Records** → open the *clinic visits* (notes icon) modal on a row — log a visit with temp/BP, confirm timeline appears.
6. **Academic History** — add a record, add per-subject grades via *Add Subject*, save, re-edit to confirm grades load.
7. **Student IDs** → View → **Print ID** — confirm the credit-card-size print sheet with school logo opens.
8. **Documents** → New Request — confirm Fee/OR fields; Process a request and confirm Fee/OR save.
9. **Masterlist** → Print — confirm the official letterhead (logo + school name + SY + signature blocks) appears.
10. **RFID Kiosk / status** — after issuing an ID, scan the QR payload (JSON) through the kiosk to confirm subsystem-5 fallback resolves the student.

## Rollback

The migration is additive-only (no drops, no data changes). To back out, drop the
three new tables (`emergency_contacts`, `academic_grades`, `health_visits`) and
`ALTER TABLE ... DROP COLUMN` the added columns.