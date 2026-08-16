---
tags: [moc]
---

# ⚙️ API MOC

All endpoints are in `api/`, return JSON, and start with `shared/config.php` + `shared/database.php`. Most require login and check roles.

## Auth & session

- [[api/auth.php]] — `POST` login (ID+password → OTP) / logout / check_session / forgot / reset_password (also via `shared/auth_actions.php`)

## Students & documents

- [[api/students.php]] — student CRUD + search
- [[api/student-ids.php]] — ID generation / QR
- [[api/documents.php]] — document file operations
- [[api/masterlist.php]] — masterlist generation (cached)
- [[api/student-template.php]] — fillable .docx enrolment form

## Student portal

- [[api/student-ai-chat.php]] — AI chat widget backend (student-gated, LLM + fallback · Phase 5)
- [[api/student-queue.php]] — portal queue view + self-cancel (`action=cancel`)
- [[api/student-documents.php]] — portal document requests
- [[api/announcements.php]] — announcements feed

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
- **CSRF** — every mutating endpoint validates `X-CSRF-Token` (or `csrf_token` form field) via [[csrf_guard.php]]; public kiosk endpoints are exempt
- **Login throttling** — 5 failed attempts / 15 min per email+IP locks out for 15 min (`login_attempts` table, see [[login_throttle.php]])

Related: [[Home]] · [[Architecture MOC]]
