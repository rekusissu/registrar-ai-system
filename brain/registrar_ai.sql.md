---
tags: [reference]
---

# 🗄️ `registrar_ai.sql`

The canonical schema dump — phpMyAdmin export (MariaDB 10.4, PHP 8.2).

## Contents

- 17 tables: `students`, `status_tracker`, `guardians`, `academic_history`, `health_records`, `document_requests`, `documents`, `rfid_cards`, `rfid_scan_logs`, `student_ids`, `authorized_cards`, `users`, `audit_logs`, `masterlist_cache`, `ai_cache` (+ 2 more)
- Seed data: 3 students, 3 guardians, 2 users, 3 document requests, 1 RFID card, status_tracker entries
- All FKs defined; child tables cascade on student delete
- Unique keys on `student_number`, `card_uid`, `email`, `id_number`, `prompt_hash`, `query_hash`

> Note: the base dump predates Phase 1. Run [[registrar_upgrade.sql]] to add the 10-subsystem tables/columns.

## Related

- [[Database MOC]] · [[Database Setup]] · [[Database Layer]]
