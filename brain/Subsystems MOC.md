---
tags: [moc]
---

# 🏛️ Subsystems MOC

Map of the functional subsystems. Built from `database/registrar_upgrade.sql` (Phase 1, 10 subsystems) plus the management features already in `registrar_ai.sql`.

## Core recordkeeping

- [[Student Management]] — masterlist, personal data, status lifecycle
- [[Guardian & Emergency Contact]] — family + emergency contacts
- [[Academic History]] — Form 137 schooling record + per-subject grades
- [[Health Records]] — medical profile + clinic visit timeline
- [[Document Requests]] — form 137 / good moral / transcript / certificate / clearance workflow
- [[Digital File Storage]] — uploaded student documents
- [[Masterlist Generation]] — cached section/course listings

## Access & identity

- [[RFID Access]] — card registration, readers, scan logging, tap-to-check kiosk
- [[Student IDs]] — school / library / cafeteria IDs with QR
- [[Authorized Cards]] — staff cards allowed to operate stations

## Intelligence

- [[AI Search & Insights]] — natural-language query parsing + analytics dashboard
- [[Audit & Security]] — audit log, security headers, session hardening

## Cross-cutting

- [[Auth System]] — login/session/roles
- [[Database Layer]] — PDO wrapper + config
- [[AI Client]] — 9Router gateway integration
- [[Document Reader]] — PDF/DOCX/TXT text extraction
- [[Student Template]] — fillable .docx enrolment form

## Upgrade roadmap (Phase 1)

Each subsystem corresponds to a section of `database/registrar_upgrade.sql`:

| # | Subsystem | Added by upgrade |
|---|---|---|
| 1 | Personal info | `students.lrn`, `name_suffix`, `mother_name`, `father_name`, `birth_country` |
| 2 | Guardian/emergency | new `emergency_contacts` table |
| 3 | Academic history | `academic_grades` table; `academic_history.semester`, `.credits` |
| 4 | Health record | `health_visits` table; `health_records.blood_pressure`, `.dietary_restrictions` |
| 5 | RFID/QR | `rfid_cards.qr_code_path`, `.issued_at` |
| 6 | Student ID | `student_ids.qr_payload`, `.school_year`, `.card_color` |
| 7 | Document requests | `document_requests.fee_amount`, `.official_receipt`, `.release_date` |
| 8 | Status tracker | `status_tracker.effective_date`, `.end_date` |
| 9 | File storage | `documents.category`, `.is_locked` |
| 10 | Masterlist | `masterlist_cache` (no schema change) |

Related: [[Home]] · [[Database MOC]]
