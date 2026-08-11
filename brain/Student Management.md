---
tags: [subsystem, core]
---

# 🎓 Student Management

Subsystem 1 (Phase 1) — personal info database + masterlist. The heart of the system.

## What it does

- Maintains the student masterlist with personal data, DepEd/Form 137 fields, course, year level, section, and status
- Statuses: `active`, `probation`, `at-risk`, `loa`, `graduated`, `transferred`, `dropped`
- Status changes are journaled in [[status_tracker]]

## Tables

- [[students]] — master record
- [[status_tracker]] — status change history with effective dates

## Phase 1 additions

`registrar_upgrade.sql` added: `students.lrn`, `name_suffix`, `mother_name`, `father_name`, `birth_country` (DepEd/Form 137 fields).

## Pages & endpoints

- `registrar/students.php` — list + CRUD
- `registrar/masterlist.php` — masterlist view
- `api/students.php` — CRUD/search JSON
- `api/masterlist.php` — masterlist generation
- `ai/search.php` — natural-language student search

## Related

- [[Subsystems MOC]] · [[Academic History]] · [[Status Tracker]] · [[students]] · [[status_tracker]]
