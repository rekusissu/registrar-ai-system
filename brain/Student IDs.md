---
tags: [subsystem]
---

# 🪪 Student IDs

Subsystem 6 (Phase 1) — school, library, and cafeteria ID generation with QR.

## What it does

- Issues IDs per student with type (`school_id`, `library`, `cafeteria`)
- Tracks issue/expiry dates and status (active / inactive / lost)
- Stores photo and QR code paths
- Phase 1 adds `qr_payload`, `school_year`, `card_color` for QR issuance and card layout

## Tables

- [[student_ids]] — issued ID records

## Pages & endpoints

- `registrar/student-ids.php` — ID management
- `api/student-ids.php` — ID generation / QR JSON

## Related

- [[Subsystems MOC]] · [[RFID Access]] · [[Student Management]] · [[student_ids]]
