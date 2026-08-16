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
3. Apply migrations (all idempotent, in order):
   ```bash
   mysql -u root registrar_ai < database/registrar_upgrade.sql   # Phase 1
   mysql -u root registrar_ai < database/security_upgrade.sql    # Phase 5
   ```

## Config

Edit `shared/config.php` DB constants (XAMPP default is `root`/empty password). For AI, put the gateway Bearer key in `shared/ai_key.local` (gitignored) — see [[AI Client]].

## First login

- Registrar: **ID number** (e.g. `RGS-001`) + password (Phase 5 login: **ID → OTP**; in dev the OTP prints on screen)
- Student portal: **student number** (e.g. `2026-0001`) + password → OTP → `student/dashboard.php`
- ⚠️ Change credentials before going live.

## Verify

- Pages load without PHP errors (dev env shows errors; production hides them)
- `SHOW TABLES LIKE 'otp_codes';` confirms the Phase 5 migration applied
- RFID stations: copy `config.local.template.php` → `config.local.php`, set `SCANNER_READER_ID`

## Related

- [[Database Setup]] · [[config.php]] · [[Team Workflow]] · [[Source Map]]
