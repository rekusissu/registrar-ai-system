---
tags: [reference]
---

# 🚀 9Router Gateway

Local OpenAI-compatible model gateway the app fronts for AI features.

## What it is

- A local service at `http://localhost:20128` exposing `/v1/chat/completions` (OpenAI format)
- Fronts several **free** Ollama/OpenRouter models behind a single Bearer key (see [[config.php]]): `ollama/minimax-m3`, `ollama/gpt-oss:120b`, `ollama/kimi-k2.5`. (Older `nvidia/*` entries were retired upstream — 404 "No active credentials".)
- Endpoint verified live via `/v1/models`; some models stream via SSE with text in `delta.reasoning_content`, handled by `aiParseStreamedBody()` in [[AI Client]]

## How the app uses it

- [[AI Client]] sends standard `chat/completions` payloads
- Handles transient overloads via model failover list
- Response may be plain JSON or OpenAI-SSE streaming (the client tolerates both)

## Setup

The API key is read from env `AI_API_KEY`, falling back to `shared/ai_key.local` (gitignored — never commit a real key).

## Related

- [[AI Client]] · [[AI Search & Insights]] · [[config.php]]
