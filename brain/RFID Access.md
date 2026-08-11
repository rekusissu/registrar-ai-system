---
tags: [subsystem]
---

# 📡 RFID Access

Subsystem 5 (Phase 1) — card registration, readers, scan logging, and tap-to-check kiosk.

## What it does

- Issues RFID cards (and QR cards via [[Student IDs]]) to students, with expiry tracking
- Manages card readers across scanning stations (Main Gate, Library, Lab, Dormitory)
- Logs every entry/exit/library/cafeteria scan
- Kiosk mode: tap a card → `api/check-authorized-card.php` decides allow/deny

## Tables

- [[rfid_cards]] — issued cards (UID, type rfid/qrcode, status, expiry)
- [[rfid_scan_logs]] — every scan event (location, event_type, status, scanner_id, IP)
- [[authorized_cards]] — staff cards authorized to operate stations

## Phase 1 additions

`rfid_cards.qr_code_path`, `rfid_cards.issued_at` — cards can be QR-issued too.

## Pages & endpoints

- `registrar/rfid-cards.php` — card registration
- `registrar/rfid-readers.php` — reader management
- `registrar/rfid-scan-logs.php` — scan log browser
- `registrar/rfid-kiosk.php` — tap-to-check kiosk
- `registrar/rfid-authorized-cards.php` — authorized card list
- `registrar/rfid-test.php` — hardware test page
- `api/rfid.php` · `api/rfid-scan.php` · `api/card-readers.php` · `api/check-authorized-card.php` · `api/rfid-ai-search.php`

## Station config

Each scanning PC maps to a reader via [[config.local.php]] (`SCANNER_READER_ID`) — gitignored so every station keeps its own mapping.

## Related

- [[Subsystems MOC]] · [[Student IDs]] · [[Authorized Cards]] · [[AI Search & Insights]] · [[rfid_cards]] · [[rfid_scan_logs]]
