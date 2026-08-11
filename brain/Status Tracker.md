---
tags: [subsystem]
---

# 🔄 Status Tracker

Subsystem 8 (Phase 1) — journal of student status changes.

## What it does

- Records every status transition: `previous_status → current_status` with a reason and who changed it
- Statuses: `active`, `probation`, `at-risk`, `loa`, `graduated`, `transferred`, `dropped`
- Phase 1 adds `effective_date` and `end_date` to support LOA / transfer windows

## Tables

- [[status_tracker]] — one row per change (FK `student_id`, `changed_by` → [[users]])

## Pages

- `registrar/status-tracker.php` — status history UI

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[status_tracker]]
