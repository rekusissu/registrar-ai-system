---
tags: [reference]
---

# 🗄️ `database/security_upgrade.sql`

Phase 5 migration — cybersecurity, student status, AI chat, portal enrichment, queue cancellation. **Idempotent** — guards every column/table via `information_schema` + `PREPARE/EXECUTE`, so re-running is safe.

## How to apply

```bash
mysql -u root registrar_ai < database/security_upgrade.sql
```

## What it adds (by area)

| # | Area | Changes |
|---|---|---|
| 1 | Cybersecurity | `users.username` UNIQUE, `users.login_attempts`, `users.locked_until`; new `otp_codes` table (`user_id`, `otp_hash`, `purpose login/reset`, `expires_at`, `used_at`) |
| 2 | Student status | `students.status` enum += **`enrolled`** (default), additive |
| 3 | Portal enrichment | `academic_grades` += `subject_code`, `subject_type`, `prerequisite`, `instructor`, `schedule`, `room`, `semester_taken`, `midterm_grade`, `final_grade`, `final_rating`, `grade_status`; `health_records` += `medical_history`, `surgical_history` |
| 4 | Queue | `queue_tickets.status` enum += **`cancelled`** |

## Notes

- MariaDB index pitfall handled: the `email` unique index is dropped/re-added around `users` enum/column changes (same pattern as `student_portal.sql`).
- **No data migration** — all additions are nullable/defaulted; legacy rows keep working.

See [[Subsystems MOC]] for phase 5 context and [[Auth System]] for the OTP/lockout flow.

## Related

- [[Database Setup]] · [[registrar_ai.sql]] · [[registrar_upgrade.sql]] · [[otp_codes]] · [[Subsystems MOC]]