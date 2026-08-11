---
tags: [page]
---

# 🔐 `shared/auth_actions.php`

POST-only action handler for login/logout/session-check (email-based auth).

## Actions

| Action | Behavior |
|---|---|
| `login` | looks up `users.email`, checks `is_active`, `password_verify()`, sets session |
| `logout` | `session_unset()` + `session_destroy()` |
| `check_session` | validates the session is still live |

Responses follow the app convention `{ success, message, data }`.

## Related

- [[Auth System]] · [[login.php]] · [[session_config.php]] · [[users]]
