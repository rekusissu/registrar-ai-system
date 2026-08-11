---
tags: [moc]
---

# ⚙️ API MOC

All endpoints are in `api/`, return JSON, and start with `shared/config.php` + `shared/database.php`. Most require login and check roles.

## Auth & session

- [[api/auth.php]] — `POST` login/logout/check_session (also via `shared/auth_actions.php`)

## Students & documents

- [[api/students.php]] — student CRUD + search
- [[api/student-ids.php]] — ID generation / QR
- [[api/documents.php]] — document file operations
- [[api/masterlist.php]] — masterlist generation (cached)
- [[api/student-template.php]] — fillable .docx enrolment form

## RFID

- [[api/rfid.php]] — card scanning / registration
- [[api/rfid-scan.php]] — scan event ingestion
- [[api/rfid-ai-search.php]] — AI natural-language card search
- [[api/card-readers.php]] — reader management
- [[api/check-authorized-card.php]] — kiosk card authorization check

## Intelligence

- [[api/ai-assist.php]] — AI assistant endpoint
- [[api/ai-tools.php]] — AI helper tools
- [[api/notifications.php]] — notification feed

## Conventions

- Request bodies are JSON via `php://input`
- Responses: `{ success: bool, message: string, data?: any }`
- Errors via `json_error()` in [[config.php]] — generic message to client, real detail to `error_log`
- CORS headers are set per-endpoint (`*` on the AI search endpoint)

Related: [[Home]] · [[Architecture MOC]]
