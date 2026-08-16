---
tags: [subsystem, cross-cutting]
---

# 🔐 Auth System

Session-based authentication. No JWT in the request path — a PHP session cookie is the bearer.

## Login flow (Phase 5 — hardened)

1. [[login.php]] posts to [[shared/auth_actions.php]] / [[api/auth.php]] (`action=login`)
2. Credential = **ID number / username** — resolved via `resolveLoginUser()`: staff use `users.username`, students use `students.student_number` → linked `users.student_id`. (Email is accepted during transition, but the primary login field is the ID. **Email appears only in the forgot-password flow.**)
3. Lockout check → `password_verify()` → on success a **one-time OTP** is issued ([[otp_codes]], 5-min TTL) and a `step: otp` response returns. **No session yet.**
4. `action=verify_otp` confirms the code → `signInSession()` grants `user_id / email / full_name / role / login_time`
5. Session cookie named `BCP_REGISTRAR_SESSION`

Shared hardening lives in [[shared/auth_security.php]] (note not yet written — TODO).

## Lockout

- Threshold `LOCKOUT_THRESHOLD = 5` failed attempts → `users.locked_until` set to PHP wall-clock `now + LOCKOUT_DURATION_SEC (600)`; `login_attempts` reset.
- `lockoutRemainingSeconds()` reads `locked_until` — must be a **PHP-written** timestamp ([[mysql-timezone-skew]]: MySQL `NOW()` is UTC and breaks the comparison).

## Session guard ([[session_config.php]])

- `isLoggedIn()` — session has `user_id`
- `requireLogin()` — redirect via `app_url('login.php?timeout=1')` (root-relative, works from any depth)
- `requireRole($role)` — admin or matching role, else `dashboard.php?error=access_denied`
- `requireStudent()` — student OR admin, guards every `student/*` page
- `getCurrentStudentId()` — resolves the caller's linked `students.id` from `users.student_id`
- Idle timeout: `SESSION_IDLE_TIMEOUT` (20 min default), sliding `last_activity`

## Roles

Defined in `users.role` enum: `admin`, `registrar`, `staff`, `teacher`, `student`. Admin bypasses all `requireRole` checks. Student-format accounts have a `users.username` for login; student accounts log in with `students.student_number`.

## Forgot password

`action=forgot` (email only) → reset OTP → `action=verify_otp` (purpose `reset`) → `action=reset_password`.

## Logout

`action=logout` → `session_unset()` + `session_destroy()`.

## Related

- [[users]] · [[otp_codes]] · [[shared/auth_actions.php]] · [[shared/auth_security.php]] · [[session_config.php]] · [[login.php]] · [[Audit & Security]] · [[Student Portal]]
