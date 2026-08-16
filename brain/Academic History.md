---
tags: [subsystem]
---

# 📜 Academic History

Subsystem 3 (Phase 1) — Form 137 schooling record with per-subject grades.

## What it does

- Records each student's academic history: school, school year, grade level, GWA, subjects completed, remarks
- Phase 1 adds per-subject grades in [[academic_grades]] and `semester` / `credits` columns for Form 137 rendering
- **Phase 5** extends [[academic_grades]] with subject metadata + midterm/final grades, and adds a student-facing read-only view

## Tables

- [[academic_history]] — one row per school record
- [[academic_grades]] — per-subject grades *(Phase 1)*; += `subject_code`, `subject_type`, `prerequisite`, `instructor`, `schedule`, `room`, `semester_taken`, `midterm_grade`, `final_grade`, `final_rating`, `grade_status` *(Phase 5)*

## Pages

- `registrar/academic-history.php` — academic history management
- `student/academic-records.php` — student view (basic academic info + per-term subject/grades table) *(Phase 5)*

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[academic_history]] · [[academic_grades]]
