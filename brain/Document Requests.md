---
tags: [subsystem]
---

# 📄 Document Requests

Subsystem 7 (Phase 1) — lifecycle tracking for student document requests.

## What it does

- Tracks requests for: `form137`, `good_moral`, `transcript`, `certificate`, `clearance`
- Full lifecycle: `pending → processing → approved / denied → completed → released`
- Phase 1 adds fee tracking (`fee_amount`, `official_receipt`) and `release_date`

## Tables

- [[document_requests]] — request records (student, type, purpose, recipient, status, processor, timestamps)

## Pages & endpoints

- `registrar/documents.php` — requests list
- `registrar/documents-add.php` — new request
- `registrar/documents-archive.php` — archived/processed requests
- `api/documents.php` — JSON operations
- `student/documents.php` + `api/student-documents.php` — student request tracking *(Phase 5)*

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[Digital File Storage]] · [[document_requests]]
