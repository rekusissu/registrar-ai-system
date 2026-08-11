---
tags: [table]
---

# 🗄️ `student_ids`

School / library / cafeteria IDs (Subsystem 6).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `id_number` | varchar(20) **UNIQUE** | |
| `id_type` | enum `school_id/library/cafeteria` | default `school_id` |
| `issue_date` / `expiry_date` | date | |
| `status` | enum `active/inactive/lost` | default `active` |
| `photo_path` | varchar(255) | |
| `qr_code_path` | varchar(255) | |
| `qr_payload` | varchar(255) | *(Phase 1)* |
| `school_year` | varchar(20) | *(Phase 1)* |
| `card_color` | varchar(20) | *(Phase 1)* default `blue` |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `UNIQUE(id_number)`, `student_id`, `idx_id_number`, `idx_status`.

## Related

- [[Student IDs]] · [[RFID Access]] · [[students]]
