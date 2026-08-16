---
tags: [table]
---

# 🗄️ `otp_codes`

One-time codes for the Phase 5 login / password-reset flow. Added by [[security_upgrade.sql]].

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `user_id` | int | FK → [[users]].id `ON DELETE CASCADE` |
| `otp_hash` | varchar(255) | `password_hash()` of the code — never plaintext |
| `purpose` | enum `login/reset` | default `login` |
| `expires_at` | datetime NOT NULL | **PHP wall-clock** `date('Y-m-d H:i:s', time()+300)` — never MySQL `NOW()` (see [[mysql-timezone-skew]]) |
| `used_at` | datetime NULL | set when consumed (one-time use) |
| `created_at` | timestamp | auto |

## Lifecycle (`shared/auth_security.php`)

- `issueOtp()` — cleans expired/unused rows for the user, generates a 6-digit code, stores the hash, tries `mail()`; on bare XAMPP the code is returned to the UI as the **on-screen dev fallback** (`OTP_SHOW_ONSCREEN`)
- `verifyOtpCode()` — looks up the newest unused, unexpired row for `(user_id, purpose)`, `password_verify()`s the hash, marks `used_at`
- 5-minute TTL, single-use; wrong codes just fail verification (re-login prompts a fresh code)

## Related

- [[users]] · [[Auth System]] · [[shared/auth_security.php]] · [[Database MOC]] · [[security_upgrade.sql]]