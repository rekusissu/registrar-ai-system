---
tags: [table]
---

# 🗄️ `health_visits`

Clinic visit timeline — **added by Phase 1**. FK points at `students.id`, not `health_records.id`.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `visit_date` | date | |
| `complaint` | varchar(255) | |
| `diagnosis` | varchar(255) | |
| `temperature` | decimal(4,1) | |
| `blood_pressure` | varchar(12) | |
| `treatment` | varchar(255) | |
| `medication` | text | |
| `physician` | varchar(100) | |
| `notes` | text | |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `idx_student_id`, FK `fk_visit_student`.

## Related

- [[Health Records]] · [[health_records]] · [[students]]
