---
tags: [subsystem]
---

# 🔍 AI Search & Insights

The natural-language search and analytics layer. Two personalities live here:

1. **Rule-based keyword parsing** — `ai/search.php` and `api/rfid-ai-search.php` parse natural-language queries ("show me at-risk students", "find expired RFID cards") and map them to filters (status, course, name, student number, card UID). Fast, offline, deterministic.
2. **Live model calls** — [[AI Client]] hits the local [[9Router Gateway]] for real LLM completions (vision, JSON extraction) where wired in.

3. **Student AI chat (Phase 5)** — a **pure-LLM** assistant on every portal page via [[api/student-ai-chat.php]] + [[includes/student-chat.php]]. It personalizes answers with the student's own context (status, queue, pending documents) and falls back to the exact registrar-referral sentence on low confidence / gateway failure. A small keyword fast-path runs first as an offline cheap tier.

## How the keyword parser works

`api/rfid-ai-search.php` (the reference implementation):

- Loads all cards joined to students
- Detects intent by substring matching against keyword lists:
  - status → `expired/active/lost/inactive`
  - expiring soon → `soon`, `expiring`, `about to expire`, `near expiry`
  - course → `nursing`, `computer`, `education`, ...
  - name → `/(student|name|find|search|show|for)\s+([a-z\s]+)/`
  - student number → `/\b\d{4}-\d{4}\b/`
  - card UID → `/\b\d{10}\b/`
- Applies array_filter in memory, then emits an `ai_interpretation` string + count + insights

## Tables

- [[ai_cache]] — cached completions (prompt_hash + TTL, 1 day)

## Pages & endpoints

- `ai/search.php` — AI search UI
- `ai/insights.php` — insights dashboard
- `api/ai-assist.php` · `api/ai-tools.php` — assistant endpoints
- `api/rfid-ai-search.php` — RFID natural-language search
- `api/student-ai-chat.php` — student portal chat (LLM + fallback, Phase 5)

## Related

- [[Subsystems MOC]] · [[RFID Access]] · [[AI Client]] · [[9Router Gateway]] · [[ai_cache]]
