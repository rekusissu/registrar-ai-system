---
tags: [api]
---

# ⚙️ `api/rfid-ai-search.php`

AI-powered RFID card search — the reference implementation of the rule-based natural-language parser.

## What it parses

| Intent | Pattern |
|---|---|
| status | keyword lists: `expired`/`active`/`lost`/`inactive` |
| expiring soon | `soon`, `expiring`, `about to expire`, `near expiry` → ≤30 days |
| course | `nursing`, `computer`, `education`, `accountancy`, … |
| name | `/(student\|name\|find\|search\|show\|for)\s+([a-z\s]+)/` |
| student number | `/\b\d{4}-\d{4}\b/` |
| card UID | `/\b\d{10}\b/` |

Filters via `array_filter` in memory, returns `ai_interpretation` + count + insights.

## Access

`POST` only; requires `admin`/`registrar`. CORS open.

See [[AI Search & Insights]] for the full walkthrough.

## Related

- [[API MOC]] · [[AI Search & Insights]] · [[rfid_cards]] · [[students]]
