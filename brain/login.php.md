---
tags: [page]
---

# 🔐 `login.php`

The login screen — root of the app. Dev credentials pre-filled.

## Flow (Phase 5)

1. User enters **ID number / username** (staff: `users.username`; student: `students.student_number`) + password → `POST` to [[shared/auth_actions.php]] (`action=login`)
2. Correct password → **OTP step** (6-digit code, emailed or shown on screen in dev) → `action=verify_otp`
3. Verified → role-based redirect: student → `student/dashboard.php`, else `dashboard.php`
4. **Email field appears only in the forgot-password flow** (`action=forgot` → reset OTP → `action=reset_password`)
5. 5 failed attempts → 10-minute lockout message
6. Timeout/access issues redirect back with `?timeout=1` (via `app_url()`, works from any depth) / `?error=access_denied`

See [[Auth System]] for the full session/OTP/lockout machinery.

## Related

- [[Auth System]] · [[dashboard.php]] · [[shared/auth_actions.php]] · [[users]]
