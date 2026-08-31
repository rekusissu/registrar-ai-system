#!/usr/bin/env sh
# =============================================================================
#  Registrar AI System — container entrypoint
#
#  Responsibilities (runs as root, then hands off to Apache as root so the
#  master can bind :80; workers drop to www-data automatically):
#    1. Create the writable runtime dirs (uploads/, logs/) and re-seed their
#       `.htaccess` "deny script execution" guards whenever they were hidden
#       behind a fresh, empty Docker volume.
#    2. Make those dirs writable by the www-data Apache worker.
#    3. Optional: run `composer install` on first boot (used by the DEV
#       bind-mount override where the host's gitignored vendor/ is absent).
#    4. exec Apache so it stays PID 1 and receives signals/stop cleanly.
# =============================================================================
set -e

WWW=/var/www/html
TEMPLATES=/usr/local/share/registrar-templates
DENY_PHP=.htaccess

# Directory layout relative to $WWW that must exist and be writable.
RUNTIME_DIRS="
uploads
uploads/students
uploads/ids
uploads/document_requirements
uploads/document_pdfs
uploads/ai_docs
assets/uploads/students
logs
"

echo "[entrypoint] preparing runtime directories…"
for d in $RUNTIME_DIRS; do
  target="$WWW/$d"
  mkdir -p "$target"
  # Re-seed the script-execution guard if the volume shadowed the image copy.
  if [ ! -f "$target/$DENY_PHP" ]; then
    cp "$TEMPLATES/deny-php.htaccess" "$target/$DENY_PHP"
  fi
  chown -R www-data:www-data "$target" 2>/dev/null || true
  chmod -R u+rwX,g+rwX "$target" 2>/dev/null || true
done

# The logs volume must never be served over the web.
if [ ! -f "$WWW/logs/$DENY_PHP" ]; then
  cp "$TEMPLATES/deny-all.htaccess" "$WWW/logs/$DENY_PHP"
fi
chown www-data:www-data "$WWW/logs/$DENY_PHP" 2>/dev/null || true

# Dev-only: install Composer deps when the source is bind-mounted and the
# host's vendor/ (which is gitignored) isn't present yet.
if [ "${RUN_COMPOSER_ON_BOOT:-0}" = "1" ] && [ ! -f "$WWW/vendor/autoload.php" ]; then
  echo "[entrypoint] vendor/ missing — running composer install…"
  ( cd "$WWW" && composer install --no-interaction --no-progress --prefer-dist ) \
    || echo "[entrypoint] warning: composer install failed, continuing anyway."
fi

echo "[entrypoint] starting Apache…"
exec apache2-foreground