---
tags: [api]
---

# 🎫 `api/queue-public.php`

Public (no login) queue endpoints for the kiosk + monitor.

## Actions

| Action | Behavior |
|---|---|
| `join` (POST) | kiosk tap-in; strict order: 2s global anti-bounce → card validation (exists, linked, active) → 5-min per-student cooldown → join from the back |
| `board` (GET) | full line-up feed for the monitor / kiosk / portal (now-serving, next, waiting count, recent feed incl. cancelled) |
| `my_ticket` (GET) | standing lookup by ticket number (portal-ready) |

## Timezone note

`queue_date` and `joined_at` are written from PHP (`date('Y-m-d H:i:s')`), never compared against MySQL `NOW()` (see [[mysql-timezone-skew]]).

## Related

- [[Queue Management]] · [[queue_tickets]] · `api/queue.php` · [[api/student-queue.php]] · [[RFID Access]]