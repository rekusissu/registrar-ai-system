---
tags: [architecture]
---

# 🧰 `shared/functions.php`

Global helper functions used across the app.

## Helpers

- `truncate($string, $length, $suffix)` — string truncation
- `randomString($length)` — random hex (crypto-secure)
- `generateStrongPassword($length)` — human-readable letters+digits
- `getStudentStatusLabel($status)` — maps a `students.status` to one of the 5 canonical portal labels (**Enrolled / Active / Graduated / Transferred / Dropped**); legacy `probation / at-risk / loa` → Active *(Phase 5)*
- `logActivity($userId, $action, ...)` — writes to [[audit_logs]] (used by auth/OTP/chat/queue-cancel events)
- plus more shared utilities (see `shared/functions.php`)

## Related

- [[Architecture MOC]] · [[config.php]] · [[Database Layer]]
