---
tags: [table]
---

# 🗄️ `users`

Login accounts.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `email` | varchar(100) **UNIQUE** | login identifier |
| `password_hash` | varchar(255) | bcrypt via `password_hash` |
| `full_name` | varchar(100) | |
| `role` | enum `admin/registrar/staff/teacher` | default `staff` |
| `rfid_uid` | varchar(20) | optional card link |
| `is_active` | tinyint(1) | |
| `created_at` / `updated_at` | timestamp | |

## Indexes

`PK(id)`, `UNIQUE(email)`, `idx_email`.

## Seed data

- `admin@bestlink.edu.ph` / admin
- `registrar@bestlink.edu.ph` / registrar (dev default password `password123`)

## Related

- [[Auth System]] · [[audit_logs]] · [[document_requests]] · [[status_tracker]] · [[masterlist_cache]]
