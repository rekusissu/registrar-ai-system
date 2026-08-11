---
tags: [table]
---

# 🗄️ `academic_grades`

Per-subject grades under an academic history record — **added by Phase 1**.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `academic_history_id` | int, FK → [[academic_history]].id (CASCADE) | |
| `subject` | varchar(120) | |
| `units` | decimal(4,2) | |
| `grade` | varchar(10) | |
| `remarks` | varchar(40) | |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `idx_academic_history_id`, FK `fk_grade_academy`.

## Related

- [[Academic History]] · [[academic_history]] · [[students]]
