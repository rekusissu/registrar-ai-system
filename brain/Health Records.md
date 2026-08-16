---
tags: [subsystem]
---

# 🏥 Health Records

Subsystem 4 (Phase 1) — medical profile plus a clinic visit timeline.

## What it does

- Stores blood type, allergies, pre-existing conditions, immunizations, height/weight, clinic visit count
- Phase 1 adds the [[health_visits]] timeline (complaint, diagnosis, temperature, blood pressure, treatment, medication, physician) and `health_records.blood_pressure` / `.dietary_restrictions`
- **Phase 5** adds `health_records.medical_history` / `.surgical_history` and a student-facing read-only view

## Tables

- [[health_records]] — 1:1 medical profile per student
- [[health_visits]] — visit timeline *(Phase 1)*

## Pages

- `registrar/health-records.php` — health records management
- `student/health-records.php` — student view (blood type, height/weight/BMI, medical/surgical/current conditions, emergency contacts; empty state when no record on file) *(Phase 5)*

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[health_records]] · [[health_visits]]
