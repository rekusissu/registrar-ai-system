---
tags: [reference]
---

# 🚀 9Router Gateway

Local OpenAI-compatible model gateway the app fronts for AI features.

## What it is

- A local service at `http://localhost:20128` exposing `/v1/chat/completions` (OpenAI format)
- Fronts several models behind a single Bearer key: `ollama/minimax-m3`, `ollama/kimi-k2.5`, `ollama/gpt-oss:120b`, `ollama/glm-4.7-flash` (see [[config.php]])
- Endpoint verified live via `/v1/models`

## How the app uses it

- [[AI Client]] sends standard `chat/completions` payloads
- Handles transient overloads via model failover list
- Response may be plain JSON or OpenAI-SSE streaming (the client tolerates both)

## Setup

The API key is read from env `AI_API_KEY`, falling back to `shared/ai_key.local` (gitignored — never commit a real key).

`/v1/models` is public, but chat/completions requires the Bearer key: set `AI_API_KEY` (or `NINEROUTER_KEY`) or create `shared/ai_key.local`.

## Related

- [[AI Client]] · [[AI Search & Insights]] · [[config.php]]
