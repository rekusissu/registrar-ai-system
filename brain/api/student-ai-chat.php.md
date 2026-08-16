---
tags: [page]
---

# 🤖 `api/student-ai-chat.php`

Backend for the student portal AI chat widget (Phase 5). JSON `POST` with `{ message }`, guarded by `requireStudent()` (admin allowed).

## Flow

1. `requireStudent()` guard; empty message rejected
2. Optional keyword fast-path intent map (cheap, offline)
3. **Primary: pure LLM** — personalizes the system prompt with the student's own context (name, status, active queue ticket, pending document requests) so answers like "Your document request #3 is in progress" come out naturally
4. Low-confidence / empty / gateway unreachable → the exact fallback sentence:
   > *"This requires registrar assistance. Please visit the registrar's office or contact staff directly."*
5. Every query is `logActivity()`'d for audit.

## Response

`{ success, message, data: { answer } }` — the widget renders the fallback verbatim.

## Related

- [[includes/student-chat.php]] · [[AI Client]] · [[Audit & Security]] · [[Student Portal]] · [[AI Search & Insights]]