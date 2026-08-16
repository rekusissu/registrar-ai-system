---
tags: [table]
---

# 🗄️ `users`

Login accounts.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `email` | varchar(100) **UNIQUE** | used for OTP delivery + forgot-password |
| `username` | varchar(60) **UNIQUE** (NULL) | login identifier for staff (added Phase 5, [[security_upgrade.sql]]) |
| `password_hash` | varchar(255) | bcrypt via `password_hash` |
| `full_name` | varchar(100) | |
| `role` | enum `admin/registrar/staff/teacher/student` | default `staff` |
| `student_id` | int | FK → `students.id` (links a student account to its record) |
| `login_attempts` | int | failed-password counter (Phase 5) |
| `locked_until` | datetime NULL | lockout deadline — **PHP wall-clock**, never `NOW()` (see [[mysql-timezone-skew]]) |
| `rfid_uid` | varchar(20) | optional card link |
| `is_active` | tinyint(1) | |
| `created_at` / `updated_at` | timestamp | |

## Indexes

`PK(id)`, `UNIQUE(email)`, `UNIQUE(username)`, `idx_email`.

## Login lookup

`resolveLoginUser()` (in [[shared/auth_security.php]]) resolves a submitted credential to a user row by `users.username` OR `users.email` OR a `students.student_number` match through `users.student_id`.

## Seed data

- `admin@bestlink.edu.ph` / admin
- `registrar@bestlink.edu.ph` / registrar (dev default password `password123`)
- Student accounts: username = `students.student_number` (e.g. `2026-0001`), linked via `student_id`

## Related

- [[Auth System]] · [[audit_logs]] · [[document_requests]] · [[status_tracker]] · [[masterlist_cache]]
