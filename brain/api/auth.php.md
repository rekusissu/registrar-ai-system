---
tags: [api]
---

# ⚙️ `api/auth.php`

Authentication endpoint (JSON). Mirrors the actions in [[shared/auth_actions.php]].

- `login` — email + password → session
- `logout` — destroy session
- `check_session` — validate live session

See [[Auth System]].

## Related

- [[API MOC]] · [[shared/auth_actions.php]] · [[session_config.php]]
