---
tags: [reference]
---

# 🗄️ Database Setup

## Schema files

- [[registrar_ai.sql]] — base schema + seed data (phpMyAdmin dump, MariaDB 10.4)
- [[registrar_upgrade.sql]] — Phase 1 migration (idempotent, safe to re-run)

## Apply the migration

```bash
mysql -u root registrar_ai < database/registrar_upgrade.sql
```

Every statement guards on column/table existence, so re-running won't error. Verify with `SHOW TABLES LIKE 'health_visits';`.

## What the migration adds

- New tables: `emergency_contacts`, `academic_grades`, `health_visits`
- New columns across: `students`, `academic_history`, `health_records`, `rfid_cards`, `student_ids`, `document_requests`, `status_tracker`, `documents`

Full breakdown: [[Subsystems MOC]] → "Upgrade roadmap (Phase 1)".

## Related

- [[Setup & Verification]] · [[Database MOC]] · [[Database Layer]]
