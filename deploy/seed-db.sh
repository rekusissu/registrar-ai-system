#!/usr/bin/env bash
# =============================================================================
#  Import registrar_ai.sql into a REMOTE / managed MySQL or MariaDB.
#
#  Use this to seed a platform's managed DB add-on (Hostinger, Railway, etc.)
#  where the app runs as a single container without a local database.
#
#  Usage:
#    DB_HOST=db.example.com DB_PORT=3306 DB_USER=app DB_PASSWORD=secret \
#      bash deploy/seed-db.sh
#
#  ⚠  registrar_ai.sql DROPs and recreates every table — it REPLACES the
#     target database's contents. Never point it at a database with data
#     you want to keep.
#
#  NOTE: the dump was produced by MariaDB 10.4. It imports cleanly into
#  MariaDB; if your platform offers both MySQL and MariaDB, prefer MariaDB.
# =============================================================================
set -euo pipefail

: "${DB_HOST:?set DB_HOST}"
: "${DB_USER:?set DB_USER}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-registrar_ai}"
DB_PASSWORD="${DB_PASSWORD:-}"

SQL="${1:-$(cd "$(dirname "$0")/.." && pwd)/registrar_ai.sql}"
[ -f "$SQL" ] || { echo "✘ schema file not found: $SQL"; exit 1; }

command -v mysql >/dev/null 2>&1 \
  || { echo "✘ mysql client not found on this machine."; exit 1; }

echo "[seed] Importing $SQL"
echo "[seed]   → ${DB_HOST}:${DB_PORT} / ${DB_NAME} as ${DB_USER}"
MYSQL_PWD="$DB_PASSWORD" mysql \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
  --default-character-set=utf8mb4 \
  "$DB_NAME" < "$SQL"

echo "✔ Seeded. Tables are ready for the app."