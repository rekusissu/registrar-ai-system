---
tags: [architecture]
---

# 🤖 AI Client

`shared/ai_client.php` — OpenAI-compatible client for the local [[9Router Gateway]].

## Functions

| Function | Purpose |
|---|---|
| `aiGenerate($system, $user, $opts)` | text completion, cached, model failover |
| `aiGenerateJson($system, $user, $fallback, $opts)` | JSON-object completion → array; strips code fences; falls back on failure |
| `aiGenerateVision($system, $text, $imageBase64, $mime, $opts)` | vision request (prefers minimax/kimi models) |
| `aiHttpChat($payload)` | low-level curl POST |
| `aiParseStreamedBody($buffer)` | handles JSON object, JSON array, or OpenAI-SSE streaming |

## Key behaviors

- **Model failover** — tries `AI_MODELS` in order until one returns content (handles transient 529/5xx)
- **Caching** — every result lands in [[ai_cache]] keyed by `prompt_hash`, honoring `AI_CACHE_TTL`
- **Cache keys** — `ai:<model>:md5(system\0user)`; vision uses `vis:md5(...)`
- **Robust parsing** — tolerates the gateway appending a `data: [DONE]` sentinel, or streaming SSE lines
- **Resilience** — cache-write failures and model errors never break the caller

## Related

- [[9Router Gateway]] · [[AI Search & Insights]] · [[ai_cache]] · [[config.php]]
