---
tags: [table, core]
---

# 🗄️ `students`

Master student record. The central table every other student sub-record joins to.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_number` | varchar(20) **UNIQUE** | e.g. `2026-0001` |
| `first_name` / `middle_name` / `last_name` | varchar | |
| `gender` | enum `Male/Female` | |
| `civil_status` | enum `Single/Married/Widowed/Separated` | |
| `birth_date` | date (NOT NULL) | |
| `place_of_birth` | varchar(100) | |
| `nationality` / `religion` | varchar | |
| `address` | text (NOT NULL) | |
| `contact_number` | varchar(15) | |
| `email` | varchar(100) | |
| `photo` | varchar(255) | path |
| `course` | varchar(100) | e.g. `BS Computer Science` |
| `major` | varchar(100) | |
| `year_level` | int | |
| `school_year` / `semester` | varchar | |
| `adviser_id` | int | → users (not a FK constraint) |
| `section` | varchar(20) | |
| `status` | enum `active/probation/at-risk/loa/graduated/transferred/dropped` | default `active` |
| `created_at` / `updated_at` | timestamp | auto |

### Phase 1 additions ([[registrar_upgrade.sql]])

`lrn` varchar(12), `name_suffix` varchar(10), `mother_name` varchar(100), `father_name` varchar(100), `birth_country` varchar(60) — DepEd/Form 137 fields.

## Indexes

`PK(id)`, `UNIQUE(student_number)`, `idx_student_number`, `idx_status`, `idx_course`.

## Children (FK → `students.id`, all `ON DELETE CASCADE`)

[[guardians]] · [[emergency_contacts]] · [[academic_history]] · [[health_records]] · [[document_requests]] · [[documents]] · [[rfid_cards]] · [[student_ids]] · [[status_tracker]] · [[health_visits]]

## Seed data

3 demo students (Juan Dela Cruz, Maria Santos, Ana Reyes) — see [[registrar_ai.sql]].

## Related

- [[Student Management]] · [[status_tracker]] · [[Database MOC]] · [[registrar_ai.sql]]
