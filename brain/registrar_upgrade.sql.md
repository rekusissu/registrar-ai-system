---
tags: [reference]
---

# 🗄️ `database/registrar_upgrade.sql`

Phase 1 migration for the 10 subsystems. **Idempotent** — every statement guards on column/table existence via `information_schema`, so re-running is safe.

## How to apply

```bash
mysql -u root registrar_ai < database/registrar_upgrade.sql
```

## What it adds (by subsystem)

| # | Table | Changes |
|---|---|---|
| 1 | `students` | + `lrn`, `name_suffix`, `mother_name`, `father_name`, `birth_country` |
| 2 | `emergency_contacts` | new table |
| 3 | `academic_grades` | new table; `academic_history` + `semester`, `credits` |
| 4 | `health_visits` | new table; `health_records` + `blood_pressure`, `dietary_restrictions` |
| 5 | `rfid_cards` | + `qr_code_path`, `issued_at` |
| 6 | `student_ids` | + `qr_payload`, `school_year`, `card_color` |
| 7 | `document_requests` | + `fee_amount`, `official_receipt`, `release_date` |
| 8 | `status_tracker` | + `effective_date`, `end_date` |
| 9 | `documents` | + `category`, `is_locked` |
| 10 | `masterlist_cache` | no schema change |

See [[Subsystems MOC]] for the full subsystem descriptions.

## Related

- [[Database Setup]] · [[registrar_ai.sql]] · [[Subsystems MOC]]
