---
tags: [reference]
---

# 🗂️ Glossary

Domain terminology used across the system.

## Student statuses (`students.status`)

| Status | Meaning | Portal label |
|---|---|---|
| `enrolled` | currently enrolled *(Phase 5, default)* | **Enrolled** |
| `active` | currently enrolled | **Active** |
| `probation` | on academic probation | Active |
| `at-risk` | flagged for risk (used by AI search) | Active |
| `loa` | leave of absence | Active |
| `graduated` | completed | **Graduated** |
| `transferred` | moved to another school | **Transferred** |
| `dropped` | withdrawn | **Dropped** |

The 5 **canonical** portal labels (Enrolled / Active / Graduated / Transferred / Dropped) come from `getStudentStatusLabel()` in [[functions.php]]; legacy `probation / at-risk / loa` map to **Active**.

## Document types (`document_requests.document_type`)

`form137` · `good_moral` · `transcript` · `certificate` · `clearance`

## Request statuses

`pending → processing → approved / denied → completed → released`

## File doc types (`documents.doc_type`)

`enrollment` · `transcript` · `health` · `photo` · `clearance` · `other`

## Roles

- **`users.role`:** `admin` · `registrar` · `staff` · `teacher` · `student` *(Phase 5)*
- **`authorized_cards.role`:** `admin` · `registrar` · `superadmin`

## Queue statuses (`queue_tickets.status`)

`waiting` · `serving` · `completed` · `no-show` · `removed` · `cancelled` *(cancelled = Phase 5 student self-cancel)*

## OTP (`otp_codes.purpose`)

`login` · `reset` — 6-digit, 5-min TTL, single-use; delivered via email or on-screen dev fallback.

## RFID

- **Card status:** `active` · `inactive` · `lost` · `expired`
- **Event types:** `entry` · `exit` · `library` · `cafeteria` · `other`
- **Scan status:** `success` · `denied` · `unknown`

## Relationships (guardians)

`father` · `mother` · `guardian` · `spouse` · `sibling`

## ID types (`student_ids.id_type`)

`school_id` · `library` · `cafeteria`

## Section code format

`[year][semester][number]` — e.g. `11001` = year 1, sem 1, section 1. Max `MAX_STUDENTS_PER_SECTION` = 50.

## Acronyms

| Acronym | Meaning |
|---|---|
| BCP | Bestlink College of the Philippines |
| LRN | Learner Reference Number (DepEd) |
| GWA | General Weighted Average |
| LOA | Leave of Absence |
| Form 137 | Permanent school record (DepEd) |
| UID | card unique identifier |

Related: [[Home]] · [[Reference MOC]]
