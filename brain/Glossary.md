---
tags: [reference]
---

# 🗂️ Glossary

Domain terminology used across the system.

## Student statuses (`students.status`)

| Status | Meaning |
|---|---|
| `active` | currently enrolled |
| `probation` | on academic probation |
| `at-risk` | flagged for risk (used by AI search) |
| `loa` | leave of absence |
| `graduated` | completed |
| `transferred` | moved to another school |
| `dropped` | withdrawn |

## Document types (`document_requests.document_type`)

`form137` · `good_moral` · `transcript` · `certificate` · `clearance`

## Request statuses

`pending → processing → approved / denied → completed → released`

## File doc types (`documents.doc_type`)

`enrollment` · `transcript` · `health` · `photo` · `clearance` · `other`

## Roles

- **`users.role`:** `admin` · `registrar` · `staff` · `teacher`
- **`authorized_cards.role`:** `admin` · `registrar` · `superadmin`

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
