---
tags: [table]
---

# 🗄️ `rfid_scan_logs`

Every card scan/tap event (Subsystem 5).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `card_uid` | varchar(50) | |
| `student_id` | int | FK → [[students]].id (no CASCADE constraint) |
| `location` | varchar(100) | default `Main Gate` |
| `event_type` | enum `entry/exit/library/cafeteria/other` | default `entry` |
| `status` | enum `success/denied/unknown` | default `success` |
| `scanner_id` | varchar(50) | default `scanner-01` |
| `scanned_at` | timestamp | |
| `ip_address` | varchar(45) | |
| `user_agent` | text | |

## Indexes

`PK(id)`, `idx_card_uid`, `idx_student_id`, `idx_scanned_at`, `idx_location`.

## Related

- [[RFID Access]] · [[rfid_cards]] · [[students]]
