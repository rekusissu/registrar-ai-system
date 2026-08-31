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

## Docker Deployment

The project ships with a full Docker setup (`Dockerfile`, `docker-compose.yml`, `docker-compose.dev.yml`) so you can deploy it anywhere Docker runs — no XAMPP required. The app container includes Apache + PHP 8.2 with the exact extensions the app needs (PDO/MySQL, mbstring, curl, gd, intl, …) and the Composer dependencies baked in. A MariaDB container is auto-seeded with `registrar_ai.sql` on first start.

### Prerequisites

- [Docker](https://www.docker.com/) (Docker Desktop on Windows/macOS) with the Compose plugin (`docker compose`).

### Production / default stack

```bash
# 1. Configure secrets (edit the values)
cp .env.example .env

# 2. Build & start (first run imports + seeds the database)
docker compose up -d --build

# 3. Open the app
#    http://localhost:8080   (change APP_PORT in .env if 8080 is taken)
```

- Login with the seeded dev user (see *Default login* above): **`registrar@bestlink.edu.ph` / `password123`**.
- Logs: `docker compose logs -f app`.
- Stop: `docker compose down` (keeps the database/upload volumes).
- **Re-seed the database from scratch:** `docker compose down -v && docker compose up -d --build` (⚠️ this deletes all DB + upload data).

### Local development (live source)

Overlays the repo as a bind mount so your edits are reflected immediately, and auto-installs Composer deps into the host `vendor/` on first boot. The database and runtime data (uploads/logs) stay in the same Docker volumes as the default stack:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

`APP_ENV=development` shows errors on screen.

### Optional integrations

All are configured via `.env` and are **opt-in** — the app keeps working when they're unset:

| Component     | Variables                                  | Behaviour when unset                  |
|---------------|--------------------------------------------|---------------------------------------|
| AI gateway    | `NINEROUTER_URL`, `AI_API_KEY`             | AI features degrade (no crash)        |
| Payments      | `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET` | Built-in mock/COD path |
| Email         | `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME` | Senders no-op           |

> **Remote server note:** point `NINEROUTER_URL` at your gateway or a tunnel (e.g. `https://my-gateway.example.com`) — the app appends `/v1/chat/completions`. The default `http://localhost:20128` only matches the old XAMPP 9Router setup on the same machine.

### How the container starts

`docker/entrypoint.sh` creates the writable runtime dirs (`uploads/…`, `assets/uploads/students`, `logs/`), re-seeds their "no script execution" `.htaccess` guards if they were shadowed by empty volumes, runs `composer install` only in dev bind-mount mode, then starts Apache. The Apache vhost in `docker/apache/000-default.conf` serves the app from the web root with `AllowOverride All` so the repo's `.htaccess` (pretty `/verify/<hash>` URLs, caching headers) works.

### Deploy to your VPS behind your domain

Ready to go live on a server you control (DigitalOcean, Vultr, AWS, …). No Docker needed on your laptop, and — thanks to the CI-prebuilt image — **no PHP compilation on your server either**.

**1. Push to GitHub — CI builds the image once and uploads it to GHCR:**

```bash
git add Dockerfile docker-compose*.yml docker/ deploy/ .github/ .env.example .dockerignore README.md
git commit -m "chore(docker): add deployment setup"
git push origin main
```

> This triggers `.github/workflows/docker-image.yml`, which builds the PHP + Composer image on GitHub's runner and pushes `ghcr.io/rekusissu/registrar-ai:latest` (+ `:main`, `:git-<sha>`). Watch it succeed under the **Actions** tab before proceeding.

**2. In your DNS provider — point the domain at the server:** add an `A` record `your.domain → <VPS IP>`.

**3. SSH into the VPS and run (Ubuntu/Debian):**

```bash
git clone https://github.com/rekusissu/registrar-ai-system.git
cd registrar-ai-system
bash deploy/server-setup.sh   # installs Docker + Compose; then RELOG
bash deploy/deploy.sh         # clones/pulls, creates .env, then stops
nano .env                     # set DOMAIN, strong DB passwords, JWT_SECRET, …
bash deploy/deploy.sh         # pulls the prebuilt image + starts behind HTTPS
```

- The deploy script **pulls the prebuilt image** from GHCR (no `docker compose --build`), auto-seeds the database from `registrar_ai.sql` on first run, and provisions a **Let's Encrypt** certificate for your `DOMAIN` via Caddy (ports 80/443 are the only public entry; the app binds to `127.0.0.1:8080`).
- The GHCR image is **public by default**; if you make it private, set `GHCR_USER` + `GHCR_TOKEN` (a fine-grained `read:packages` PAT) in `.env`.
- Login with the seeded dev user, then **change the password** before going live.

### How updates flow (CI → GHCR → VPS)

1. You `git push` to `main`.
2. **GitHub Actions** builds the multi-stage image and pushes to GHCR.
3. On the VPS, re-run `bash deploy/deploy.sh` — it pulls `:latest` and recreates containers (your DB/upload volumes persist).

You can also build locally anytime with `docker compose up -d --build` (the `build:` block is still present in `docker-compose.yml`).

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
