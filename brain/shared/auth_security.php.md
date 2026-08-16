---
tags: [page]
---

# 🔐 `shared/auth_security.php`

Shared login-hardening helpers for the Phase 5 auth flow. Consumed by [[shared/auth_actions.php]] and [[api/auth.php]].

## Functions

| Function | Purpose |
|---|---|
| `resolveLoginUser($db, $credential)` | resolve an ID/username/email to a `users` row: `users.username` → `users.email` → `students.student_number` via `users.student_id` |
| `lockoutRemainingSeconds($db, $userId)` | seconds left in the lockout window, 0 if none |
| `handleFailedAttempt($db, $userId)` | increments `login_attempts`; at `LOCKOUT_THRESHOLD (5)` writes `locked_until` = PHP `now + LOCKOUT_DURATION_SEC (600)` and resets the counter |
| `resetLoginLockout($db, $userId)` | clears `login_attempts` + `locked_until` on successful password |
| `issueOtp($db, $userId, $purpose, $email)` | cleans old codes, generates 6-digit code, stores hash in [[otp_codes]], tries `mail()`; returns `{ masked_email, delivered, otp }` (on-screen dev fallback) |
| `verifyOtpCode($db, $userId, $purpose, $code)` | check newest unused/unexpired code, `password_verify`, mark `used_at` |
| `maskEmail($email)` | `u***@example.com` masking |
| `signInSession($user)` | grant the session + role-based redirect (`student → student/dashboard.php`, else `dashboard.php`) |

## Key constants

- `LOCKOUT_THRESHOLD = 5`, `LOCKOUT_DURATION_SEC = 600` (10-minute lockout)
- `OTP_SHOW_ONSCREEN` — when true (dev), the code is returned to the UI because `mail()` fails on bare XAMPP

## ⚠️ Timezone rule

All `locked_until` / `expires_at` values are written and compared with **PHP wall-clock strings**, never MySQL `NOW()`/`DATE_ADD(now(),…)` — MySQL session timezone is UTC and a stored UTC deadline read as Manila is 8h in the past, which silently bypasses lockout (see [[mysql-timezone-skew]]).

## Related

- [[Auth System]] · [[shared/auth_actions.php]] · [[otp_codes]] · [[users]] · [[Audit & Security]]