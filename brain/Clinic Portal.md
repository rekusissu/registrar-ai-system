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
- `api/clinic-ai-recommend.php` — POST visit fields → AI first-aid recommendation (nurse/admin).

## Pages

- `nurse/dashboard.php` — clinic landing page with AI Aid Recommendation (today's visits, KPIs, tap-in, visit form, recent records).
- `nurse/kiosk.php` — full-screen RFID tap-in with on-file health summary.
- `nurse/clinic-visit.php` — profile + vitals + visit form with history.
- `registrar/health-records.php` — view-only filterable log (registrar sync).

## AI Aid Recommendation

After the nurse fills vitals + reason, the **AI Aid Recommendation** panel (amber-tinted, collapsed by default) expands in the visit form:

1. Nurse clicks **"Get Aid Recommendation"**.
2. Frontend POSTs all form fields to `api/clinic-ai-recommend.php`.
3. Backend builds a prompt from the visit data (reason, assessment, vitals, allergies, conditions, BMI) and calls the LLM via `aiGenerateJson()`.
4. LLM returns structured JSON: `recommendation`, `urgency` (low/medium/high), `key_warnings[]`, `referral_needed`, `confidence`, `summary`.
5. Panel renders the result with urgency-colored badge, recommendation text, warnings, and referral flag.
6. Nurse can **Re-analyze** if they update fields.
7. Panel collapses on workspace reset.

The system prompt explicitly instructs the LLM: "You are NOT diagnosing" — it only suggests first-aid steps appropriate for a school clinic.

## Related

- [[health_visits]] · [[RFID Access]]