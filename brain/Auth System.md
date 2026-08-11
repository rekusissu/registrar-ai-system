---
tags: [subsystem, cross-cutting]
---

# 🔐 Auth System

Session-based authentication. No JWT in the request path — a PHP session cookie is the bearer.

## Login flow

1. [[login.php]] posts to [[shared/auth_actions.php]] (`action=login`)
2. Looks up user by **email** in [[users]], checks `is_active`, verifies `password_verify()`
3. On success: `$_SESSION['user_id' | 'email' | 'full_name' | 'role' | 'login_time']`
4. Session cookie named `BCP_REGISTRAR_SESSION`

## Session guard ([[session_config.php]])

- `isLoggedIn()` — session has `user_id`
- `requireLogin()` — redirect to `login.php?timeout=1`
- `requireRole($role)` — admin or matching role, else `dashboard.php?error=access_denied`
- Idle timeout: `SESSION_IDLE_TIMEOUT` (20 min default), sliding `last_activity`

## Roles

Defined in `users.role` enum: `admin`, `registrar`, `staff`, `teacher`. Admin bypasses all `requireRole` checks. RFID ops are gated to `admin`/`registrar` in the API endpoints.

## Logout

`action=logout` → `session_unset()` + `session_destroy()`.

## Related

- [[users]] · [[shared/auth_actions.php]] · [[session_config.php]] · [[login.php]] · [[Audit & Security]]
