---
tags: [table]
---

# 🗄️ `status_tracker`

Journal of every student status change (Subsystem 8).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `previous_status` | varchar(50) | |
| `current_status` | varchar(50) | NOT NULL |
| `reason` | text | |
| `changed_by` | int, FK → [[users]].id | |
| `effective_date` | date | *(Phase 1)* |
| `end_date` | date | *(Phase 1)* |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `FK changed_by`, `idx_student_id`, `idx_current_status`.

## Related

- [[Status Tracker]] · [[Student Management]] · [[students]] · [[users]]
