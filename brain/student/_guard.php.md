---
tags: [page]
---

# 🛡️ `student/_guard.php`

Shared bootstrap for every `student/*` portal page (Phase 5).

## What it does

1. `requireStudent()` — only a logged-in student (or admin) proceeds
2. Loads `shared/functions.php` (so `getStudentStatusLabel()` / `logActivity()` are available)
3. Resolves the caller's linked record: `getCurrentStudentId()` → `SELECT * FROM students WHERE id = ?`
4. Renders the shared header + sidebar, then exposes the `$student` row to the page
5. When no student record is linked, prints a friendly "No Student Record Linked" notice instead of a fatal

Pages include it after session config + database, then read `$student` for the rest of the render.

Related: [[Student Portal]] · [[session_config.php]] · [[functions.php]] · [[Auth System]]