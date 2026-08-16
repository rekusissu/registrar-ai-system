---
tags: [page]
---

# 🎡 `includes/student-chat.php`

Self-contained floating AI chat widget for the student portal (Phase 5). Included from `includes/footer.php` when `$_SESSION['role'] === 'student'`, so it appears on **every** student page (dashboard, queue, records, …) and nowhere else.

## What it renders

- Fixed-position launcher button (bottom-right) + collapsible panel (message list, input, send)
- Own inline `<style>` + `<script>` — no external chat library (CSP `script-src 'self' 'unsafe-inline'` allows the inline script)
- Sends `POST { message }` to [[api/student-ai-chat.php]]; renders the reply (or the registrar-referral fallback verbatim); Enter-to-send; input disabled while pending; scroll-to-bottom

Prints nothing unless the session role is `student`.

## Related

- [[api/student-ai-chat.php]] · [[Student Portal]] · [[AI Client]] · `includes/footer.php`