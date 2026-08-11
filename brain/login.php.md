---
tags: [page]
---

# 🔐 `login.php`

The login screen — root of the app. Dev credentials pre-filled.

## Flow

1. User submits → `POST` to `shared/auth_actions.php` (`action=login`)
2. On success → `dashboard.php`
3. Timeout/access issues redirect back with `?timeout=1` / `?error=access_denied`

See [[Auth System]] for the full session/role machinery.

## Related

- [[Auth System]] · [[dashboard.php]] · [[shared/auth_actions.php]] · [[users]]
