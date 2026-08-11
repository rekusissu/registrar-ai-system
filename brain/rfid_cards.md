---
tags: [table]
---

# 🗄️ `rfid_cards`

Issued RFID / QR cards (Subsystem 5).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `card_uid` | varchar(50) **UNIQUE** | e.g. `0006929950` |
| `card_type` | enum `rfid/qrcode` | default `rfid` |
| `status` | enum `active/inactive/lost/expired` | default `active` |
| `issued_date` | date | |
| `expiry_date` | date | |
| `notes` | text | |
| `qr_code_path` | varchar(255) | *(Phase 1)* |
| `issued_at` | timestamp | *(Phase 1)* |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `UNIQUE(card_uid)`, `student_id`, `idx_card_uid`, `idx_status`.

## Seed data

Card `0006929950` → student 1 — see [[registrar_ai.sql]].

## Related

- [[RFID Access]] · [[rfid_scan_logs]] · [[authorized_cards]] · [[students]] · [[AI Search & Insights]]
