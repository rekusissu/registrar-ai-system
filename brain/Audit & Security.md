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

## Error containment

- `json_error()` in [[config.php]] sends a generic message to the browser; real exception details go to `error_log` only
- `shared/database.php` never echoes the PDO error to the client

## Related

- [[Subsystems MOC]] · [[Auth System]] · [[audit_logs]] · [[security_headers.php]]
