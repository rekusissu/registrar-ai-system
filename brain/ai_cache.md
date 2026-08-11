---
tags: [table]
---

# 🗄️ `ai_cache`

Cache for AI completions (via [[AI Client]]).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `prompt_hash` | varchar(64) **UNIQUE** | `ai:<model>:md5(...)` or `vis:md5(...)` |
| `prompt` | text | truncated to 4000 chars |
| `response` | text | |
| `model` | varchar(50) | which model answered |
| `created_at` | timestamp | |
| `expires_at` | timestamp | `AI_CACHE_TTL` = 1 day |

## Indexes

`PK(id)`, `UNIQUE(prompt_hash)`, `idx_prompt_hash`.

## Related

- [[AI Search & Insights]] · [[AI Client]] · [[config.php]]
