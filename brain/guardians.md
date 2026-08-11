---
tags: [table]
---

# 🗄️ `guardians`

Family/guardian contacts for a student.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `full_name` | varchar(100) | |
| `relationship` | enum `father/mother/guardian/spouse/sibling` | |
| `contact_number` | varchar(15) | |
| `email` | varchar(100) | |
| `address` | text | |
| `is_primary` | tinyint | |
| `is_emergency` | tinyint | |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `idx_student_id`, `idx_contact`.

## Note

Phase 1 introduces a separate [[emergency_contacts]] table — guardians stay focused on family/guardians.

## Related

- [[Guardian & Emergency Contact]] · [[emergency_contacts]] · [[students]]
