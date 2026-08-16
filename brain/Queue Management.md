---
tags: [subsystem]
---

# 🎫 Queue Management

Daily queue numbers for the registrar counter — kiosk join, live monitor, serving console, and student self-cancel. Backed by [[queue_tickets]].

## How it works

1. **Kiosk join** — a student taps their card at the RFID kiosk (`registrar/rfid-kiosk.php`); `api/queue-public.php?action=join` validates (2s anti-bounce, active card, 5-min cooldown) and appends a ticket from the back.
2. **Live monitor / board** — `api/queue-public.php?action=board` feeds now-serving / next / waiting count for the monitor screens.
3. **Serving console** — `registrar/queue.php` (registrar/admin) calls next, completes, skips.
4. **Student view** — `student/queue.php` + `api/student-queue.php` show my ticket, position, and now-serving; a waiting student can **self-cancel** (`action=cancel`, Phase 5).

## Statuses

`waiting → serving → completed | no-show | removed` · `waiting → cancelled` (Phase 5 self-cancel)

## API endpoints

- [[api/queue-public.php]] — public join/board/my_ticket (no login)
- `api/queue.php` — registrar console stats + actions
- [[api/student-queue.php]] — portal view + self-cancel (student-gated)

## Timezone note

`queue_date` and all `*_at` columns are written from PHP (`date('Y-m-d H:i:s')`) and compared against PHP-written strings — never MySQL `NOW()` (see [[mysql-timezone-skew]]).

Related: [[Home]] · [[queue_tickets]] · [[RFID Access]] · [[Student Portal]]