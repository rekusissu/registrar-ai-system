---
tags: [page]
---

# 🔐 `shared/auth_actions.php`

POST-only action handler for the ID/username + password → OTP → session flow (Phase 5). Shared hardening lives in [[shared/auth_security.php]].

## Actions

| Action | Behavior |
|---|---|
| `login` | resolve credential (**ID/username/email**), lockout check, `password_verify()`; on failure `handleFailedAttempt()` (5 → 10-min lockout); on success issue OTP → `{ step: 'otp', user_id, masked_email, otp }` (no session yet) |
| `resend_otp` | re-issue a fresh code |
| `verify_otp` | confirm code → `signInSession()` → role-based redirect |
| `forgot` | **email only** → reset OTP `{ step: 'otp', purpose: 'reset' }` |
| `reset_password` | after reset OTP verifies → set new `password_hash` |
| `logout` | `session_unset()` + `session_destroy()` |
| `check_session` | validates the session is still live |

Responses follow the app convention `{ success, message, data }`.

## Related

- [[Auth System]] · [[shared/auth_security.php]] · [[login.php]] · [[session_config.php]] · [[users]] · [[otp_codes]]
