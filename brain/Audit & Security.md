---
tags: [subsystem]
---

# 🛡️ Audit & Security

Cross-cutting security + audit logging.

## Audit logging

- [[audit_logs]] records every action with old/new values as JSON (validated via `CHECK (json_valid(...))`)
- Captures user, action, table, record id, IP address, user agent, timestamp

## Security headers (`shared/security_headers.php`)

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `X-XSS-Protection` | `1; mode=block` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Content-Security-Policy` | `default-src 'self'`; allowlist CDN + inline |
| `Permissions-Policy` | geolocation/mic/camera denied |
| `Strict-Transport-Security` | on HTTPS (production) |
| `Cache-Control` | `no-store` on dashboard/registrar routes |

## Session hardening (`shared/session_config.php`)

- Session cookie: httponly, SameSite=Strict, Secure on HTTPS
- Idle timeout (default 20 min, sliding `last_activity`)
- `requireRole()` role gate — admin bypasses
- `requireStudent()` gate + `getCurrentStudentId()` for the `student/*` portal
- Timeout redirects use `app_url()` (root-relative) so they never 404 from nested `student/` pages

## Phase 5 login security

- **ID/username + password → OTP → session**: password verified before any OTP is issued; session only after `verify_otp`
- **10-minute lockout** after 5 failed attempts (`users.login_attempts` / `users.locked_until`, see [[shared/auth_security.php]])
- OTP codes stored as `password_hash` in [[otp_codes]] (never plaintext), 5-min TTL, single-use
- Email field only in the forgot-password flow (username/ID is the login identifier)

## Error containment

- `json_error()` in [[config.php]] sends a generic message to the browser; real exception details go to `error_log` only
- `shared/database.php` never echoes the PDO error to the client

## Related

- [[Subsystems MOC]] · [[Auth System]] · [[audit_logs]] · [[security_headers.php]]
