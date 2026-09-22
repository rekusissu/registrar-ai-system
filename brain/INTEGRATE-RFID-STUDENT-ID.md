# 🔗 Integrate RFID Cards + Student IDs

> Created: 2026-09-22 | Status: **PLANNING**

## Goal

Merge Student IDs + RFID Cards so assigning an RFID card **auto-creates a Student ID** with QR. Both tables stay. Student number stays blank. QR resolves to `registrar.bcpsms2.com` verification page.

## Current State

| Module | Page | API | Table |
|--------|------|-----|-------|
| Student IDs | `registrar/student-ids.php` | `api/student-ids.php` | `student_ids` |
| RFID Cards | `registrar/rfid-cards.php` | `api/rfid.php` | `rfid_cards` |

Both separate sidebar entries, separate pages, separate APIs. QR currently outputs raw JSON payload.

---

## ✅ Decided

| # | Decision | Answer |
|---|----------|--------|
| 1 | QR URL | `https://registrar.bcpsms2.com/verify-student.php?id_number=XXX` |
| 2 | Sidebar | **RFID Cards only** — Student IDs page merges INTO RFID page |
| 3 | Legacy backfill | Yes, auto-create student_ids for existing RFID cards |
| 4 | Student number | Blank until other department fills it |
| 5 | Verification page | Logo + ✅ badge + "Verified Student of Bestlink College of the Philippines" — **zero PII** |

---

## 📋 Task Plan

### Phase 1 — QR Verification Page (public, zero PII)

| # | Task | Files | Status |
|---|------|-------|--------|
| 1.1 | Create `verify-student.php` — public, no login. Logo + ✅ badge + "Verified Student of Bestlink College of the Philippines". No student info. | `verify-student.php` | ✅ |
| 1.2 | Handle not-found: show "No record found" | `verify-student.php` | ✅ |

### Phase 2 — API: Assign RFID → Auto-create Student ID

| # | Task | Files | Status |
|---|------|-------|--------|
| 2.1 | Extract QR generation into `shared/qr_generator.php` | `shared/qr_generator.php` | ✅ |
| 2.2 | Modify `api/rfid.php` POST — after rfid_cards INSERT, auto-create `student_ids` with generated `id_number`, shared QR (URL format), type=`school_id` | `api/rfid.php` | ✅ |
| 2.3 | Modify `api/rfid.php` DELETE — soft-delete linked student_ids | `api/rfid.php` | ✅ |
| 2.4 | Update `api/student-ids.php` `generateQrFile()` → use shared generator, QR = URL | `api/student-ids.php` | ✅ |

### Phase 3 — Merge into RFID Page + Sidebar

| # | Task | Files | Status |
|---|------|-------|--------|
| 3.1 | RFID table — add `id_number` column to query + display | `registrar/rfid-cards.php` | ✅ |
| 3.2 | Assign modal — add info: "A Student ID will also be issued automatically." | `registrar/rfid-cards.php` | ✅ |
| 3.3 | Remove Student IDs from sidebar | `includes/sidebar.php` | ✅ |
| 3.4 | Student portal `student/ids.php` — show RFID + student_ids together (already does) | `student/ids.php` | ✅ (no change) |

### Phase 4 — Migration / Backfill

| # | Task | Files | Status |
|---|------|-------|--------|
| 4.1 | Add `rfid_card_id` column to `student_ids` (nullable) | SQL migration | ✅ |
| 4.2 | Backfill script: create student_ids for RFID cards missing one | `database/migrate_rfid_to_student_ids.php` | ✅ (ran, 0 cards to backfill) |

---

## 🔄 Integration Flow

```
Registrar assigns RFID card (registrar/rfid-cards.php)
        │
        ▼
api/rfid.php POST
        │
        ├─► INSERT rfid_cards (card_uid, student_id, ...)
        ├─► Auto-generate id_number (2026-0001)
        ├─► Generate QR → URL = registrar.bcpsms2.com/verify-student.php?id_number=2026-0001
        └─► INSERT student_ids (student_id, id_number, qr_code_path, rfid_card_id, id_type=school_id)
```

## ⏭️ Next: Start building Phase 1
