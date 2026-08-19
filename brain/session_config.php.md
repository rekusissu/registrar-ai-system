---
tags: [architecture]
---

# 🔐 `shared/session_config.php`

Session bootstrap + guard helpers. Loads `config.php` first so it works standalone.

## Session lifecycle

- Session name: `BCP_REGISTRAR_SESSION`
- Sliding idle timeout: `SESSION_IDLE_TIMEOUT` (default 20 min) — destroys and redirects to `login.php?timeout=1`; `0` disables idle logout
- `last_activity` touched on every request

## Helper functions

| Function | Purpose |
|---|---|
| `isLoggedIn()` | session has `user_id` |
| `getCurrentUserId()` / `getCurrentUserRole()` / `getCurrentUserName()` | session accessors |
| `requireLogin()` | redirect if not logged in |
| `requireRole($role)` | admin or matching role, else `dashboard.php?error=access_denied` |

## Related

- [[Auth System]] · [[shared/auth_actions.php]] · [[config.php]] · [[Audit & Security]]
