---
tags: [table]
---

# 🗄️ `audit_logs`

Full action history with before/after values.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `user_id` | int, FK → [[users]].id | |
| `action` | varchar(100) | |
| `table_name` | varchar(50) | |
| `record_id` | int | |
| `old_values` / `new_values` | longtext | JSON, `CHECK (json_valid(...))` |
| `ip_address` | varchar(45) | |
| `user_agent` | text | |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `idx_user_id`, `idx_action`, `idx_created_at`.

## Related

- [[Audit & Security]] · [[users]] · [[Auth System]]
