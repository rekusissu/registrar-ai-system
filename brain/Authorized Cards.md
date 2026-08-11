---
tags: [subsystem]
---

# 💳 Authorized Cards

Staff cards allowed to operate scanning stations (part of [[RFID Access]]).

## What it does

- Stores card UID → operator mapping with a role: `admin`, `registrar`, `superadmin`
- `can_change_station` flag lets a card switch the active reader/station
- Consumed by `api/check-authorized-card.php` at the kiosk

## Tables

- [[authorized_cards]] — UID unique, name, role, can_change_station

## Pages

- `registrar/rfid-authorized-cards.php` — manage the list

## Related

- [[RFID Access]] · [[authorized_cards]] · [[rfid_cards]]
