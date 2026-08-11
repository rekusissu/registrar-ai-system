---
tags: [reference]
---

# ⚙️ `shared/config.php`

Central configuration. Loaded by every page/endpoint.

## Database

`DB_HOST` / `DB_NAME` (`registrar_ai`) / `DB_USER` / `DB_PASSWORD` / `DB_CHARSET=utf8mb4`

## App

- `APP_NAME` = BCP Registrar System, `APP_VERSION` = 1.0.0
- `APP_ENV` = `development` (set `production` to hide errors)
- `APP_ROOT` = project root
- `SESSION_IDLE_TIMEOUT` = 20 min
- `MAX_STUDENTS_PER_SECTION` = 50 (section codes `[year][sem][num]`, e.g. 11001)
- `JWT_SECRET` — dev placeholder, change in production

## AI (9Router gateway)

- `AI_API_URL` = `http://localhost:20128/v1/chat/completions`
- `AI_MODEL` = `nvidia/deepseek-ai/deepseek-v4-flash`
- `AI_MODELS` = ordered fallback list (deepseek → minimax-m3 → glm-5.2)
- `AI_API_KEY` = env `AI_API_KEY`, else `shared/ai_key.local` (gitignored)
- `AI_CACHE_TTL` = 86400s (1 day)

## Timezone

`Asia/Manila`

## Error helper

`json_error(Throwable, $prefix)` — logs real exception server-side, returns generic JSON to client.

## Related

- [[Database Layer]] · [[AI Client]] · [[Setup & Verification]] · [[config.local.php]]
