# Registrar AI-Powered System

AI-powered registrar management system for **Bestlink College of the Philippines (BCP)** — student records, document requests, academic history, health records, and RFID card access management.

## Features

- **Student Management** — masterlist with course, year level, and status tracking (active / at-risk / probation / graduated)
- **Document Requests** — form 137, good moral, transcript, certificate, and clearance tracking through their full lifecycle
- **AI-Powered Search** — natural-language queries ("show me at-risk students", "find expired RFID cards") backed by keyword/intent recognition
- **RFID Access Control** — card registration, reader management, scan logging, and tap-to-check kiosk
- **RFID Insights Dashboard** — real-time analytics and automated recommendations
- **Audit Logging** — full action history with old/new value tracking
- **Security Headers** — CSP, HSTS, X-Frame-Options, session hardening

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
```

Then visit **http://localhost/registrar-ai-system**

### Default login

The login form is pre-filled with the dev credentials:

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

The "AI" features currently use **rule-based keyword and intent detection** implemented in PHP/JS — they parse natural-language queries and map them to filters (status, course, student name, card UID). There is no external LLM call at this time.

The scaffolding for real model integration exists (`ai_cache` table in the schema) but is not wired up. If you want to plug in Ollama, Gemini, or DeepSeek later, the search endpoints are the place to hook it in.

## Team Workflow

**Important:** Read [`TEAM-WORKFLOW.md`](TEAM-WORKFLOW.md) before contributing.

Quick rules:
- **Never commit directly to `main`** — always create a feature branch
- Always pull before starting work each day
- Open a Pull Request for review
- Keep secrets out of git (`.gitignore` handles `vendor/`, `uploads/`, and `*.log`)

## License

Internal — BCP use only.
