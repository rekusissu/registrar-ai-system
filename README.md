# Registrar AI-Powered System

AI-powered registrar management system for **Bestlink College of the Philippines (BCP)** — student records, document requests, academic history, health records, and RFID card access management.

## Features

- **Student Management** — masterlist with course, year level, and status tracking (active / at-risk / probation / graduated)
- **Document Requests** — form 137, good moral, transcript, certificate, and clearance tracking through their full lifecycle
- **AI-Powered Search** — natural-language queries ("show me at-risk students", "find expired RFID cards") backed by keyword/intent recognition
- **RFID Access Control** — card registration, reader management, scan logging, and tap-to-check kiosk
- **RFID Insights Dashboard** — real-time analytics and automated recommendations
- **Audit Logging** — full action history with old/new value tracking
- **Security Headers** — CSP, HSTS, X-Frame-Options, session hardening, CSRF tokens, login throttling

## Tech Stack

- **Backend:** PHP 8.x with PDO prepared statements
- **Database:** MySQL / MariaDB
- **Frontend:** Vanilla JS, Chart.js, Font Awesome, custom CSS
- **RFID:** Web-based scanning API for USB/HID RFID readers

## Prerequisites

- [XAMPP](https://www.apachefriends.org/) (Apache + PHP 8.x + MySQL/MariaDB)
- [Composer](https://getcomposer.org/)

## Quick Start

```bash
# 1. Clone the repo into your XAMPP web root
git clone https://github.com/rekusissu/Registrar-AI-powered-system.git
cd Registrar-AI-powered-system

# 2. Install PHP dependencies
composer install

# 3. Create the database
#    Open phpMyAdmin → create a database named `registrar_ai`
#    Import `registrar_ai.sql` into it
#    Optional: login-security migration (throttling table):
#    mysql -u root registrar_ai < database/login_security.sql
```

Then visit **http://localhost/registrar-ai-system**

### Default login

Use the dev credentials below (the login form is no longer pre-filled):

| Email | Password |
|-------|----------|
| `registrar@bestlink.edu.ph` | `password123` |

> ⚠️ Change these before going live. Credentials are configured in `shared/config.php` (database) and the `users` table (login).

## Configuration

### Database

Edit `shared/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'registrar_ai');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
```

### RFID readers

Each scanning station maps to a reader in the `card_readers` table. Copy the template and set the reader ID:

```bash
cp shared/config.local.template.php shared/config.local.php
```

```php
// Main Gate PC   → define('SCANNER_READER_ID', 1);
// Library PC     → define('SCANNER_READER_ID', 2);
// Lab PC         → define('SCANNER_READER_ID', 3);
// Dormitory PC   → define('SCANNER_READER_ID', 4);
define('SCANNER_READER_ID', 1);
```

`shared/config.local.php` is **gitignored** — every station keeps its own reader mapping.

## Project Structure

```
registrar-ai-system/
├── ai/              # AI insights page & AI search UI
├── api/             # REST API endpoints (JSON)
├── assets/          # Images, icons
├── css/             # Stylesheets
├── includes/        # Shared header / sidebar / footer
├── js/              # Client-side scripts
├── registrar/       # Registrar-facing pages
├── shared/          # Config, database, auth, security utilities
├── uploads/         # Uploaded student documents & IDs (gitignored)
├── vendor/          # Composer dependencies (gitignored)
└── registrar_ai.sql # Database schema + seed data
```

## About the AI layer

AI features run through the local **9Router gateway** (`http://localhost:20128`, OpenAI-compatible) via `shared/ai_client.php` — `aiGenerate()`, `aiGenerateJson()`, and `aiGenerateVision()` with `ai_cache` caching and ordered model failover.

### AI setup

- Point the gateway with `NINEROUTER_URL` (default `http://localhost:20128`) and set the key via `AI_API_KEY` or `NINEROUTER_KEY`, or create `shared/ai_key.local` (gitignored).
- Verify: `curl http://localhost:20128/api/health` — returns `{"ok":true}`
- Model IDs in `shared/config.php` (`AI_MODEL`, `AI_MODELS`) must exist in the gateway's `v1/models` list.

## Team Workflow

**Important:** Read [`TEAM-WORKFLOW.md`](TEAM-WORKFLOW.md) before contributing.

Quick rules:
- **Never commit directly to `main`** — always create a feature branch
- Always pull before starting work each day
- Open a Pull Request for review
- Keep secrets out of git (`.gitignore` handles `vendor/`, `uploads/`, and `*.log`)

## License

Internal — BCP use only.
