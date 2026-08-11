---
tags: [subsystem]
---

# 🏥 Health Records

Subsystem 4 (Phase 1) — medical profile plus a clinic visit timeline.

## What it does

- Stores blood type, allergies, pre-existing conditions, immunizations, height/weight, clinic visit count
- Phase 1 adds the [[health_visits]] timeline (complaint, diagnosis, temperature, blood pressure, treatment, medication, physician) and `health_records.blood_pressure` / `.dietary_restrictions`

## Tables

- [[health_records]] — 1:1 medical profile per student
- [[health_visits]] — visit timeline *(Phase 1)*

## Pages

- `registrar/health-records.php` — health records management

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[health_records]] · [[health_visits]]
