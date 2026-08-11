---
tags: [reference]
---

# 🗺️ Source Map

Where everything lives in the repo.

## Root

| File | Purpose |
|---|---|
| `index.php` | redirects to login |
| `login.php` / `logout.php` | auth entry/exit |
| `dashboard.php` | post-login landing |
| `settings.php` | settings page |
| `rootinfo.php` | server info probe |
| `registrar_ai.sql` | canonical schema |
| `composer.json` / `package.json` | deps |

## Directories

| Path | Contents |
|---|---|
| `registrar/` | all registrar-facing feature pages (students, documents, RFID, health, IDs, …) |
| `api/` | JSON endpoints (see [[API MOC]]) |
| `shared/` | bootstrap + utilities: `config.php`, `database.php`, `session_config.php`, `security_headers.php`, `auth_actions.php`, `ai_client.php`, `document_reader.php`, `student_template.php`, `functions.php`, `normalize.php` |
| `ai/` | AI search + insights pages |
| `includes/` | `header.php`, `sidebar.php`, `footer.php` |
| `database/` | `registrar_upgrade.sql` (Phase 1 migration) |
| `js/` `css/` | frontend assets (Tailwind, Chart.js, Font Awesome via CDN) |
| `uploads/` | uploaded files (gitignored; keep `.htaccess`) |
| `assets/` | images/icons |
| `vendor/` | Composer deps (gitignored) |
| `.kilo/` | kilo app config (snapshot off) |

## Related

- [[Home]] · [[Architecture MOC]] · [[Setup & Verification]]
