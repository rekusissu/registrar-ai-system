---
tags: [subsystem]
---

# 🏥 Clinic Portal — Health Record Log

The **clinic (nurse) owns all health data creation**; the registrar portal is **view-only**.

## What it does

- **RFID tap-in kiosk** (`nurse/kiosk.php`): nurse taps a student ID card → the system
  identifies the student and displays **Student ID, Name, Program, Year Level, Section**
  plus the **on-file health summary** (blood type, last vitals, allergies).
- **Identity verification**: the nurse verifies the student, then **Verify & Start Assessment**
  proceeds to the visit form.
- **Visit logging** (`nurse/clinic-visit.php`) — the nurse captures **everything**, stored
  denormalized into `health_visits`:
  - **Medical Profile** (blood type, height, weight, allergies, pre-existing conditions,
    immunizations) — **pre-filled from the student's latest visit** (the newest visit is the
    current profile); update on save.
  - **Vitals** (temperature, blood pressure).
  - **Visit** (Reason, Assessment, Action Taken — drop-downs with Other→free text — and
    Nurse's Notes). Date & Time auto.
- **Save Health Record** → one `health_visits` row holding profile + vitals + visit data,
  visible on both portals.
- **Registrar** (`registrar/health-records.php`): **view-only, filterable Health Record Log**
  (search, date range, reason, status). **No add/edit/delete** — adding records is the
  clinic's job.

## Role

- New `nurse` role added to `users.role` enum (migration `database/clinic_portal.sql`).
- Login redirects nurse → `nurse/dashboard.php`.
- **Account creation is admin-only** (`registrar/users.php` → `requireRole('admin')`,
  `api/users.php` admin-only) and can create **registrar** and **nurse** accounts.

## Tables

- `health_visits` — the single store for all health data. Columns:
  visit identity (`date_time`, `record_status`, `recorded_by`), the visit itself
  (`reason_for_visit`, `assessment`, `action_taken`, `nurse_notes`), vitals
  (`temperature`, `blood_pressure`), and the denormalized medical profile
  (`blood_type`, `allergies`, `height`, `weight`, `pre_existing_conditions`,
  `immunization_records`).
- The legacy registrar-owned `health_records` profile table is **no longer used** by the
  health module (data left in place, concept dropped).

## API

- `api/clinic.php` — `identify` (card→student + latest profile), `visits` (student history),
  `save-visit` (nurse/admin), `log` (registrar view-only feed).

## Pages

- `nurse/dashboard.php` — clinic landing page (today's visits, stats, recent records).
- `nurse/kiosk.php` — full-screen RFID tap-in with on-file health summary.
- `nurse/clinic-visit.php` — profile + vitals + visit form with history.
- `registrar/health-records.php` — view-only filterable log (registrar sync).

## Related

- [[health_visits]] · [[RFID Access]]