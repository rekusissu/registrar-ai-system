---
tags: [reference]
---

# 🚀 Setup & Verification

Getting the BCP Registrar System running locally and verifying it works. (Source: `README.md` + `SETUP-VERIFY.md`.)

## Prerequisites

- XAMPP (Apache + PHP 8.x + MySQL/MariaDB)
- Composer (optional: Node.js for Tailwind)

## Install

```bash
git clone https://github.com/rekusissu/Registrar-AI-powered-system.git
cd Registrar-AI-powered-system
composer install
npm install        # optional, Tailwind
```

## Database

1. phpMyAdmin → create DB `registrar_ai`
2. Import `registrar_ai.sql`
3. (Recommended) apply the Phase 1 migration:
   `mysql -u root registrar_ai < database/registrar_upgrade.sql`

## Config

Edit `shared/config.php` DB constants (XAMPP default is `root`/empty password).

## First login

Visit `http://localhost/registrar-ai-system` → `registrar@bestlink.edu.ph` / `password123`

⚠️ Change credentials before going live.

## Verify

- Pages load without PHP errors (dev env shows errors; production hides them)
- `SHOW TABLES LIKE 'health_visits';` confirms the Phase 1 migration applied
- RFID stations: copy `config.local.template.php` → `config.local.php`, set `SCANNER_READER_ID`

## Related

- [[Database Setup]] · [[config.php]] · [[Team Workflow]] · [[Source Map]]
