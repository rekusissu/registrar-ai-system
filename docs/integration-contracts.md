# Registrar integration contracts

All five providers in `config/integrations.example.json` remain disabled. No credentials, live connectors, scheduled pulls, or automatic forwarding are installed. Existing locally received data remains readable. Copying an example file must never enable a provider.

## Common envelope

Future authenticated adapters must validate `schema_version` (`1.0`), `provider`, `event_id` (stable provider event identifier), `occurred_at` (ISO 8601 with timezone), `source_record_id`, `source_version` (monotonically increasing integer), and `payload`. Deduplicate by provider/event ID, reject stale versions, resolve student numbers to existing records, and record source provenance plus the local receipt timestamp. Never trust caller-provided roles, program assignments, local database IDs, or certification decisions. Authenticate and authorize the provider before accepting data; reject unknown fields and invalid academic placements. Apply each event atomically and retain failure history for explicit retry.

## Provider ownership and payloads

| Provider | Event | Required payload | Registrar behavior |
| --- | --- | --- | --- |
| Enrollment | `enrollment.updated` | `student_number`, `program_code`, `school_year`, `period`, `year_level`, `status` | Validate program/grade placement; retain original source record and version. |
| Curriculum | `academic_record.submitted` | `student_number`, `school_year`, `period`, `grading_scale`, `subjects` | Read-only source grades; each subject supplies code/name, source reference, units when applicable, final rating or explicit missing status. `grading_scale` distinguishes `college_1_5` from `shs_percentage`. Never infer missing grades as zero. |
| Clinic | `clearance.updated` | `student_number`, `school_year`, `clearance_status`, `effective_at` | Store clearance status only. Do not ingest diagnoses or clinical notes. |
| Class Scheduling | `placement.assigned` | `student_number`, `program_code`, `school_year`, `period`, `year_level`, `section`, `strand_or_cluster` for SHS | Validate using the existing SHS placement/section checks. Keep campus, grade, strand/cluster, period and academic year together. College allocation must not allocate SHS students. |
| Alumni | `alumni.updated` | `student_number`, `graduation_year`, `program_code`, `alumni_reference` | Link the existing student. Graduation/status changes require an authorized Registrar review. |

SHS campus catalogs contain published offerings, not a guarantee of availability for every school year or grade. A future Curriculum catalog must supply academic-year availability. No academic calendar end date or SHS passing/averaging policy is invented here.

## Correction routing

Academic Officers record `needs_correction` or `incomplete` reviews with mandatory remarks. The Academic Validation page is the local correction queue for authorized Registrar staff; review history is append-only. A future Curriculum correction event must include its own event ID, academic source reference, local validation reference, remarks, requesting actor and timestamp. The receiving adapter acknowledges receipt separately from resolution. Until connected, the UI explicitly states that external forwarding is unavailable; saving remarks never claims delivery.

## AI and deployment boundaries

Academic summaries use aggregate counts from the authorized roster. The configured AI provider is invoked only by the Generate AI summary action; failure returns labeled local analysis. AI output is advisory and never writes grades, acceptance, status, or corrections. No model training or student-data upload pipeline is enabled. A future training workflow needs an approved purpose, de-identified dataset, retention policy and provider agreement. Local/cloud hosting remains a deployment choice covered by the existing deployment guide, not an automatic replication of student records.
