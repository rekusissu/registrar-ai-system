---
tags: [table]
---

# 🗄️ `document_requests`

Document request lifecycle (Subsystem 7).

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `student_id` | int, FK → [[students]].id (CASCADE) | |
| `document_type` | enum `form137/good_moral/transcript/certificate/clearance` | |
| `purpose` | varchar(255) | |
| `recipient` | varchar(255) | |
| `status` | enum `pending/processing/approved/denied/completed/released` | default `pending` |
| `processed_by` | int, FK → [[users]].id | |
| `denial_reason` | text | |
| `file_path` | varchar(255) | |
| `request_date` | timestamp | |
| `processed_date` | datetime | |
| `completed_date` | datetime | |
| `fee_amount` | decimal(10,2) | *(Phase 1)* default 0.00 |
| `official_receipt` | varchar(40) | *(Phase 1)* |
| `release_date` | datetime | *(Phase 1)* |

## Indexes

`PK(id)`, FKs `processed_by` / `idx_student_id`, `idx_status`, `idx_document_type`.

## Seed data

3 requests (form137 transfer, good_moral job, transcript scholarship) — see [[registrar_ai.sql]].

## Related

- [[Document Requests]] · [[Digital File Storage]] · [[students]] · [[users]]
