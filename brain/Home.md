---
tags: [moc, hub]
---

# 🧠 BCP Registrar System — Brain

This is the living knowledge graph for the **Registrar AI-Powered System** — student records, document requests, academic history, health records, and RFID access management for **Bestlink College of the Philippines (BCP)**.

Use this as your entry point. Everything below is linked; navigate with Ctrl+Click to jump the graph.

## Quick facts

- **Stack:** PHP 8.x + PDO · MySQL/MariaDB · Vanilla JS + Chart.js + Tailwind
- **DB:** `registrar_ai` (see [[registrar_ai.sql]] for the canonical schema)
- **Auth:** session-based (`BCP_REGISTRAR_SESSION`) with **ID-number/username + password → OTP → session**; roles `admin / registrar / staff / teacher / student`
- **AI:** local **9Router** OpenAI-compatible gateway · Ollama/OpenRouter models (`minimax-m3`, `gpt-oss:120b`, `kimi-k2.5`), cached in `ai_cache`
- **Student portal:** logged-in student hub ([[Student Portal]]) — dashboard, profile, queue, documents, academic & health records, AI chat
- **Migrations:** [[registrar_ai.sql]] (base) → [[registrar_upgrade.sql]] (Phase 1) → [[security_upgrade.sql]] (Phase 5)

## Hubs (Maps of Content)

- 🏛️ [[Subsystems MOC]] — the 10+ functional subsystems
- 🗄️ [[Database MOC]] — every table, column, FK, and index
- ⚙️ [[API MOC]] — REST endpoints
- 🧱 [[Architecture MOC]] — how the pieces fit together
- 📚 [[Reference MOC]] — config, workflow, setup, glossary

## Subsystems at a glance

| Subsystem | Core tables | Pages |
|---|---|---|
| [[Student Management]] | [[students]], [[status_tracker]] | `registrar/students.php`, `masterlist.php` |
| [[Guardian & Emergency Contact]] | [[guardians]], [[emergency_contacts]] | `registrar/guardians.php` |
| [[Academic History]] | [[academic_history]], [[academic_grades]] | `registrar/academic-history.php` |
| [[Health Records]] | [[health_records]], [[health_visits]] | `registrar/health-records.php` |
| [[RFID Access]] | [[rfid_cards]], [[rfid_scan_logs]], [[authorized_cards]] | `registrar/rfid-*.php` |
| [[Student IDs]] | [[student_ids]] | `registrar/student-ids.php` |
| [[Document Requests]] | [[document_requests]] | `registrar/documents*.php` · `student/documents.php` |
| [[Digital File Storage]] | [[documents]] | `registrar/file-storage.php` |
| [[Masterlist Generation]] | [[masterlist_cache]] | `api/masterlist.php` |
| [[AI Search & Insights]] | [[ai_cache]] | `ai/search.php`, `ai/insights.php` |
| [[Student Portal]] | many | `student/*` pages + AI chat |
| [[Queue Management]] | [[queue_tickets]] | `registrar/queue.php` · `student/queue.php` |
| [[Audit & Security]] | [[audit_logs]] | `shared/security_headers.php` |

## Navigation

- **Front door:** [[login.php]] → [[dashboard.php]] → [[sidebar.php]]
- **Setup:** [[Setup & Verification]] · [[Database Setup]]
- **Team:** [[Team Workflow]] · [[Source Map]]
- **Last note:** [[2026-08-16]] (daily)

## 📇 Live index

Self-updating views over all 91 brain notes (tag-driven, no manual upkeep):

![[Brain Index.base]]

## 🗺️ Visual map

Browse the whole system as a canvas — every node opens its brain note:

![[Brain Map.canvas]]
