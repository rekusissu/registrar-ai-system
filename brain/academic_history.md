---
tags: [table]
---

# 🗄️ `academic_history`

Schooling record per student (Form 137 basis), Subsystem 3.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `school_name` | varchar(100) | NOT NULL |
| `school_year` | varchar(20) | |
| `grade_level` | varchar(20) | |
| `gwa` | decimal(5,2) | general weighted average |
| `subjects_completed` | int | |
| `remarks` | text | |
| `semester` | varchar(20) | *(Phase 1)* |
| `credits` | decimal(6,2) | *(Phase 1)* |
| `created_at` | timestamp | |

## Children

- [[academic_grades]] — per-subject grades (FK → `academic_history.id`, CASCADE)

## Indexes

`PK(id)`, `idx_student_id`.

## Related

- [[Academic History]] · [[academic_grades]] · [[students]]
