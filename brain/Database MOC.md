---
tags: [moc]
---

# 🗄️ Database MOC

Canonical schema is `registrar_ai.sql` (base dump) + `database/registrar_upgrade.sql` (Phase 1 migration, idempotent). Tables are all InnoDB, utf8mb4. Database name: `registrar_ai`.

## Student core

- [[students]] — master record (personal data, course, year, section, status)
- [[status_tracker]] — status change history (active / probation / at-risk / loa / graduated / transferred / dropped)

## Student sub-records (all FK → `students.id`)

- [[guardians]] — family/guardian contacts
- [[emergency_contacts]] — emergency contact list *(added by Phase 1)*
- [[academic_history]] — school records (Form 137)
- [[academic_grades]] — per-subject grades under a history record *(Phase 1)*
- [[health_records]] — medical profile (1:1)
- [[health_visits]] — clinic visit timeline *(Phase 1)*
- [[document_requests]] — document request lifecycle
- [[documents]] — uploaded files
- [[rfid_cards]] — issued RFID/QR cards
- [[student_ids]] — school/library/cafeteria IDs

## Access & cards

- [[authorized_cards]] — staff cards authorized to operate stations
- [[rfid_scan_logs]] — every tap/scan event

## Users & observability

- [[users]] — login accounts (roles: admin / registrar / staff / teacher)
- [[audit_logs]] — full action history with old/new JSON values
- [[masterlist_cache]] — cached masterlist query results
- [[ai_cache]] — cached AI completions (prompt_hash + TTL)

## ERD sketch (core)

```
students 1 ──── N guardians / emergency_contacts / academic_history / health_records / document_requests / documents / rfid_cards / student_ids / status_tracker
academic_history 1 ──── N academic_grades
health_records 1 ──── N health_visits
users 1 ──── N audit_logs / document_requests.processed_by / status_tracker.changed_by
students 1 ──── N rfid_scan_logs (via card UID)
```

## Migration status

[[registrar_upgrade.sql]] is safe to re-run (guards every column/table existence). After applying, verify with `SHOW TABLES LIKE 'health_visits';`.

Related: [[Home]] · [[Subsystems MOC]]
