---
tags: [table]
---

# 🗄️ `health_records`

Medical profile, 1:1 with a student (Subsystem 4).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `blood_type` | varchar(5) | |
| `allergies` | text | |
| `pre_existing_conditions` | text | |
| `immunization_records` | text | |
| `height` / `weight` | decimal(5,2) | |
| `clinic_visits` | int | count |
| `notes` | text | |
| `blood_pressure` | varchar(12) | *(Phase 1)* |
| `dietary_restrictions` | text | *(Phase 1)* |
| `created_at` / `updated_at` | timestamp | |

## Children

- [[health_visits]] — clinic visit timeline (FK → `students.id`)

## Indexes

`PK(id)`, `idx_student_id`.

## Related

- [[Health Records]] · [[health_visits]] · [[students]]
