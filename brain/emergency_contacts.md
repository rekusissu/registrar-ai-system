---
tags: [table]
---

# 🗄️ `emergency_contacts`

Dedicated emergency contact list — **added by Phase 1** (`registrar_upgrade.sql`).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `full_name` | varchar(100) | |
| `relationship` | varchar(50) | free text |
| `contact_number` | varchar(20) | |
| `address` | text | |
| `is_primary` | tinyint | |
| `created_at` / `updated_at` | timestamp | |

## Indexes

`PK(id)`, `idx_student_id`, FK `fk_emergency_student`.

## Related

- [[Guardian & Emergency Contact]] · [[guardians]] · [[students]]
