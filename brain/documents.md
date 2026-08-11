---
tags: [table]
---

# 🗄️ `documents`

Uploaded student document files (Subsystem 9).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `doc_type` | enum `enrollment/transcript/health/photo/clearance/other` | |
| `filename` | varchar(255) | |
| `file_path` | varchar(500) | under `uploads/` |
| `file_size` | bigint | |
| `file_type` | varchar(50) | MIME |
| `description` | text | |
| `uploaded_by` | int, FK → [[users]].id | |
| `category` | varchar(40) | *(Phase 1)* |
| `is_locked` | tinyint(1) | *(Phase 1)* |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, FKs `uploaded_by` / `idx_student_id`, `idx_doc_type`.

## Related

- [[Digital File Storage]] · [[Document Requests]] · [[students]] · [[users]]
