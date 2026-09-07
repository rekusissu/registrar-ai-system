#!/usr/bin/env bash
# =============================================================================
#  Registrar AI System — deploy / update on the VPS.
#
#  Prereqs:
#    - Docker Engine + Compose installed (run server-setup.sh once, then relog)
#    - Your domain's DNS already points at this server (A record)
#    - Your Git repo (with the Docker files) is pushed to GitHub
#
#  Usage:
#    bash deploy.sh
#  First run creates .env from .env.example, then STOPS so you can edit it:
#    nano .env        # set DOMAIN, strong DB passwords, JWT_SECRET, etc.
#    bash deploy.sh   # then run again to actually build + start
# =============================================================================
set -euo pipefail

# ── Config (override via env if needed) ─────────────────────────────────────
REPO_URL="${REPO_URL:-https://github.com/rekusissu/registrar-ai-system.git}"
APP_DIR="${APP_DIR:-$HOME/registrar-ai-system}"
BRANCH="${BRANCH:-main}"

mkdir -p "$APP_DIR"
cd "$APP_DIR"

# 1. Cloning / pulling the code ------------------------------------------------
if [ ! -d .git ]; then
  echo "[deploy] Cloning ${REPO_URL} (branch ${BRANCH})…"
  git clone --branch "$BRANCH" "$REPO_URL" .
else
  echo "[deploy] Pulling latest on ${BRANCH}…"
  git fetch origin
  git checkout "$BRANCH" 2>/dev/null || true
  git pull --ff-only origin "$BRANCH"
fi

# 2. .env -----------------------------------------------------------------------
if [ ! -f .env ]; then
  cp .env.example .env
  echo
  echo "──────────────────────────────────────────────────────────────"
  echo "  Created .env from .env.example."
  echo "  EDIT IT NOW — at minimum set:"
  echo "    DOMAIN=your.domain"
  echo "    DB_PASSWORD , DB_ROOT_PASSWORD"
  echo "    JWT_SECRET , KIOSK_ACCESS_TOKEN"
  echo "  (optional) NINEROUTER_URL / AI_API_KEY for AI features."
  echo "  Then re-run:  bash deploy.sh"
  echo "──────────────────────────────────────────────────────────────"
  exit 1
fi

# 3. Validate required secrets ----------------------------------------------
# Rejects: missing lines, empty values (VAR=),and leftover placeholder values
# (both the current .env.example values and older "change-me"/"replace-with" examples).
# This kills the classic foot-gun: a fresh .env leaves JWT_SECRET as the shipping
# placeholder (or empty), Compose starts fine,,and the app only fails-closed on the
# first browser hit with "JWT_SECRET is not set". We abort the deploy here instead.with a
# clear message, before anything is started.

# require_set_var <VARNAME> [known-bad-value...]  —  requires a real (non-empty,
# non-placeholder) value.
 require_set_var() {
  local var="$1"; shift
  local line value ph
  line="$(grep -E "^${var}=" .env | tail -n1)" || {
    echo "✘ ${var}  is missing from .env — set it before deploying."
    exit 1
  }
  value="${line#*=}"
  value="$(printf '%s' "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  if [ -z "$value" ]; then
    echo "✘ ${var}  is empty ins .env — set it to a strong random value (e.g. openssl rand -hex 32)."
    exit 1
  fi
  for ph in "$@"; do
    if [ "$value" = "$ph" ]; then
      echo "✘ ${var}  still uses a shipping placeholder ('${value}') — change it to a unique strong value ins .env."
      exit 1
    fi
  done
  echo "  ✓ ${var}: set."
}

require_set_var DOMAIN                    "your.domain"
require_set_var DB_PASSWORD               "your-strong-db-password-here" "change-me"
require_set_var DB_ROOT_PASSWORD          "your-strong-db-root-password-here" "change-me"
require_set_var JWT_SECRET                "your-very-strong-jwt-secret-min-64-chars-openssl-rand-hex-32" "replace-with"
require_set_var KIOSK_ACCESS_TOKEN       "your-strong-kiosk-token-min-32-chars" "kiosk-tap-2024"


# 4. Auth to GHCR (for pushing + pulling the prebuilt image) ------------------
# The image is PUBLIC by default so a pull usually needs no login. If you made
# the package private, set GHCR_USER + GHCR_TOKEN in .env (a fine-grained PAT
# with read:packages).
GHCR_USER="$(grep -E '^GHCR_USER=' .env | cut -d= -f2-)"
GHCR_TOKEN="$(grep -E '^GHCR_TOKEN=' .env | cut -d= -f2-)"
if [ -n "$GHCR_USER" ] && [ -n "$GHCR_TOKEN" ]; then
  echo "[deploy] Logging in to GitHub Container Registry…"
  echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin
fi

# 5. Pull the prebuilt image (no local compile) + start ------------------------
echo "[deploy] Pulling latest image and starting the production stack…"
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull app
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-build

echo
echo "✔ Deployed. Give it ~30s on first run to seed the database."
echo "  https://$(grep -E '^DOMAIN=' .env | cut -d= -f2)"
echo "  Logs:    docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f app"
echo "  Update:  re-run `bash deploy.sh` (it pulls the new image + recreates)."
