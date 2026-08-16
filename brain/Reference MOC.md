---
tags: [moc]
---

# 📚 Reference MOC

Operational reference for working on this codebase.

## Configuration

- [[config.php]] — DB, session timeout, AI gateway, JWT secret, timezone
- [[config.local.php]] — per-station scanner mapping (gitignored)
- [[Setup & Verification]] — prerequisites, DB import, first login
- [[Database Setup]] — schema + migration application

## Workflow & process

- [[Team Workflow]] — branch/PR rules, daily sync, "unrelated histories" recovery
- [[Source Map]] — where every file lives
- [[Gitignore Notes]] — what is never committed

## Domain reference

- [[Glossary]] — statuses, document types, roles, acronyms
- [[Subsystems MOC]] — feature overview
- [[Database MOC]] — full schema

## AI plumbing

- [[AI Client]] — `aiGenerate()` / `aiGenerateJson()` / vision
- [[9Router Gateway]] — local OpenAI-compatible model gateway
- [[api/student-ai-chat.php]] — student portal chat backend (LLM + registrar-referral fallback)
- [[Document Reader]] — PDF/DOCX/TXT extraction
- [[Student Template]] — .docx form builder

Related: [[Home]] · [[Architecture MOC]]
