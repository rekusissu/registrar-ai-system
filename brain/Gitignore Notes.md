---
tags: [reference]
---

# 🚫 Gitignore Notes

What `.gitignore` excludes — and why.

## Ignored

- `shared/ai_key.local` — local AI API key (never commit)
- `.env`, `*.local` — local secrets/config
- `/vendor/` — Composer deps (regen with `composer install`)
- `/composer.phar*` — local build tool
- `uploads/ai_docs/`, `uploads/students/*`, `uploads/ids/*` — uploaded files (`.htaccess` kept)
- `/shared/config.local.php` — per-station scanner mapping
- OS/editor noise: `.DS_Store`, `Thumbs.db`, `*.log`

## Not yet ignored (consider)

- `.obsidian/` — the vault config + plugins live here (untracked; neither ignored nor committed). Decide whether to track shared config or ignore per-machine files.

## Related

- [[Team Workflow]] · [[config.local.php]] · [[Source Map]]
