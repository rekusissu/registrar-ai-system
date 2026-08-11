---
tags: [architecture]
---

# 🛡️ `shared/security_headers.php`

Sets security headers on every page. Details in [[Audit & Security]].

## Headers set

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy` — allowlists CDNs + inline, `frame-ancestors 'none'`
- `Permissions-Policy` — geolocation/mic/camera off
- `Strict-Transport-Security` — only when HTTPS
- `Cache-Control: no-store` on dashboard/registrar routes
- Cookie hardening: httponly, SameSite=Strict, Secure on HTTPS

## Related

- [[Audit & Security]] · [[session_config.php]] · [[Architecture MOC]]
