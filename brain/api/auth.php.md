---
tags: [api]
---

# ⚙️ `api/auth.php`

Authentication endpoint (JSON). Mirrors the actions in [[shared/auth_actions.php]] (Phase 5 flow).

- `login` — ID/username + password → OTP step (no session yet)
- `verify_otp` — confirm code → grant session → role redirect
- `forgot` / `reset_password` — email → reset OTP → new password
- `resend_otp` — re-issue a fresh code
- `logout` — destroy session
- `check_session` — validate live session

See [[Auth System]].

## Related

- [[API MOC]] · [[shared/auth_actions.php]] · [[session_config.php]]
