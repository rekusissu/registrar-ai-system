---
tags: [subsystem]
---

# 🎓 Student Portal

The logged-in student surface (Phase 5). Built on top of the shared header/sidebar shell; every `student/*` page is guarded by `requireStudent()`.

## Pages (all under `student/`)

| Page | Purpose |
|---|---|
| `dashboard.php` | welcome hero, status strip, **Current Status** panel, **Announcements**, **My Queue** |
| `profile.php` | full personal profile + status history |
| `queue.php` | my queue ticket, live position, self-cancel |
| `documents.php` | document request tracking |
| `academic-records.php` | basic academic info + per-term subject/grades table |
| `health-records.php` | medical profile: blood type, height/weight/**BMI**, medical/surgical/current conditions, emergency contacts (empty state when none on file) |
| `announcements.php` | bulletin feed (linked from the dashboard panel) |
| `ids.php` / `grades.php` | legacy pages (some pre-date Phase 5) |

## Guard header (`_guard.php`)

Shared bootstrap: `requireStudent()`, loads `shared/functions.php`, resolves the caller's linked `students.id` via `getCurrentStudentId()`, renders header + sidebar, and exposes the `$student` row. Renders a friendly "no record linked" notice when no student is attached.

## AI chat widget

Every portal page includes [[includes/student-chat.php]] (via `includes/footer.php`, role-gated). Backend: [[api/student-ai-chat.php]] — **pure LLM** answering with the student's own context; low-confidence → the exact registrar-referral fallback sentence.

## Navigation

Sidebar (student branch): **Dashboard · Student Profile · Queue Management · Document Requests · Academic Records · Health Records · Logout**. Announcements moved to the dashboard panel (not a sidebar entry). Quick Actions tiles were removed from the dashboard.

Related: [[Home]] · [[Auth System]] · [[Queue Management]] · [[Document Requests]] · [[Academic History]] · [[Health Records]]