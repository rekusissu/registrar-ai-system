---
tags: [reference, pitfall]
---

# ⏰ MySQL↔PHP timezone skew

MariaDB session timezone on this XAMPP box is **UTC**; PHP runs **Asia/Manila (UTC+8)** via `date_default_timezone_set` in [[config.php]].

## The trap

`MySQL NOW()` returns UTC — a `locked_until`/`expires_at` written with `DATE_ADD(NOW(), INTERVAL …)` is stored as UTC, then `strtotime()` in PHP reads that bare string as Manila ⇒ **8 hours in the past**. A lockout/OTP-expiry check then sees an already-past deadline and silently bypasses.

This exactly broke the Phase 5 login lockout (attempt 6 was not rejected) before the fix.

## The rule

For any wall-clock timestamp (`users.locked_until`, `otp_codes.expires_at`, `queue_tickets.*`, `document_requests.*`):

- **Write** from PHP: `date('Y-m-d H:i:s', time() + X)`
- **Compare** against a bound PHP string: `date('Y-m-d H:i:s')`

Never `NOW()` in SQL against a PHP-written value (and vice versa). See the fixed helpers in [[shared/auth_security.php]], `issueOtp()`/`verifyOtpCode()` in same, and `api/queue-public.php`.

Related: [[Auth System]] · [[shared/auth_security.php]] · [[otp_codes]] · [[queue_tickets]] · [[config.php]]