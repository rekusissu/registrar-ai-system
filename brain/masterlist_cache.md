---
tags: [table]
---

# 🗄️ `masterlist_cache`

Cached masterlist query results (Subsystem 10).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `user_id` | int, FK → [[users]].id | cache is per-user |
| `query_hash` | varchar(64) **UNIQUE** | |
| `query_text` | text | |
| `result_data` | longtext | JSON, `CHECK (json_valid(...))` |
| `generated_at` | timestamp | |
| `expires_at` | timestamp | |

## Indexes

`PK(id)`, `UNIQUE(query_hash)`, `idx_user_id`, `idx_query_hash`.

## Related

- [[Masterlist Generation]] · [[users]] · [[config.php]]
