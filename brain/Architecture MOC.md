---
tags: [moc]
---

# 🧱 Architecture MOC

How the BCP Registrar System fits together. Plain PHP server-rendered app (no framework) with JSON API endpoints and vanilla JS frontend.

## Request flow

1. Browser hits a page (`.php` in root or `registrar/`)
2. [[includes/header.php]] pulls in session + security setup
3. Page queries [[Database Layer]] (PDO) and renders HTML + Tailwind
4. JS calls [[API MOC]] endpoints for dynamic data (search, RFID, AI)

## Layers

- **Entry points:** [[index.php]] → [[login.php]] → [[dashboard.php]] · [[sidebar.php]] navigation
- **Shared bootstrap:** [[config.php]] · [[database.php]] · [[session_config.php]] · [[security_headers.php]] · [[functions.php]]
- **Business logic:** `registrar/*` pages
- **API layer:** `api/*` endpoints
- **AI layer:** [[AI Client]] · [[Document Reader]] · [[Student Template]]
- **Frontend:** `js/*`, `css/`, Tailwind (via npm), Chart.js, Font Awesome

## Key design decisions

- **PDO everywhere** — prepared statements only; `EMULATE_PREPARES = false`
- **Role-based access** — `requireRole()` in [[session_config.php]]; admin bypasses all checks
- **Server-side error containment** — `json_error()` never leaks schema/connection details to the browser
- **AI is opt-in real models** — live OpenAI-compatible gateway with a rule-based fallback path for search
- **Idempotent migrations** — [[registrar_upgrade.sql]] + [[security_upgrade.sql]] re-run safely
- **Student portal is a first-class surface** — `student/*` pages render through a shared guard ([[_guard.php]]) with the AI chat widget on every page ([[includes/student-chat.php]])

## Security posture

See [[Audit & Security]] for the full list: CSP, HSTS, X-Frame-Options: DENY, nosniff, Referrer-Policy, `frame-ancestors 'none'`, httponly + SameSite=Strict cookies, no-store caching on sensitive routes.

## File map

- **Root:** login, logout, dashboard, settings, index
- **`registrar/`:** all registrar-facing feature pages
- **`student/`:** student portal pages (guarded by `student/_guard.php`)
- **`api/`:** JSON endpoints
- **`shared/`:** bootstrap + utilities (incl. `auth_security.php`)
- **`ai/`:** AI search + insights pages
- **`includes/`:** header / sidebar / footer / student-chat
- **`database/`:** migration SQL (registrar_upgrade + security_upgrade)

## Visual map

See the full architecture as a canvas: [[Brain Map.canvas]]

Related: [[Home]] · [[API MOC]] · [[Source Map]]
