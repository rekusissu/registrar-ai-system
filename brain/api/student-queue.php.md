---
tags: [page]
---

# 🎫 `api/student-queue.php`

Portal-facing queue endpoint (Phase 5). Student-gated; GET shows my ticket + live standing, POST `action=cancel` self-cancels the caller's own waiting ticket.

## Actions

| Action | Method | Behavior |
|---|---|---|
| (default) | GET | ticket lookup (incl. terminal tickets) + joined/served date-time split, position, now-serving, waiting count |
| `cancel` | POST | own waiting ticket → `status='cancelled'`, `served_at` = PHP `date('Y-m-d H:i:s')`, `logActivity('queue_cancel')` |

## Related

- [[Queue Management]] · [[queue_tickets]] · `student/queue.php` · [[Student Portal]]