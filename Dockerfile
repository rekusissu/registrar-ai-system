# =============================================================================
#  Registrar AI System — deployment image
#
#  The PHP extensions are NOT compiled here. They come prebuilt in
#  ghcr.io/rekusissu/php-8.2-apache-ext (built by CI from
#  docker/php-extensions.Dockerfile). That keeps the platform build fast —
#  just COPY steps — which is essential on managed Docker hosts that kill
#  long builds at their timeout.
#
#  Build:
#    docker build -t ghcr.io/rekusissu/registrar-ai:latest .
# =============================================================================

# Tag of the prebuilt extension layer (bumped only when the extension set
# changes). Push ghcr.io/rekusissu/php-8.2-apache-ext:<tag> first (CI does).
ARG PHP_EXT_TAG=php-8.2
FROM ghcr.io/rekusissu/php-8.2-apache-ext:${PHP_EXT_TAG} AS vendor

# Composer needs ext-zip OR the unzip binary to download dist archives.
# unzip is installed here so the app build is self-sufficient even if the
# pulled extension layer predates the zip addition.
RUN set -e \
    && apt-get update -o Acquire::Retries=5 -o Acquire::http::Timeout=30 \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Composer production dependencies. The vendor stage uses the SAME prebuilt
# runtime so Composer's platform checks match exactly.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# ── Runtime (final image) ──────────────────────────────────────────────────
FROM ghcr.io/rekusissu/php-8.2-apache-ext:${PHP_EXT_TAG}

ENV APP_ENV=production

# Apache vhost: serve the app from the web root and honor .htaccess
# (the app depends on mod_rewrite for /verify/<hash> pretty URLs).
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Guard templates re-seeded into (possibly empty) upload/log volumes at boot.
COPY docker/deny-php.htaccess /usr/local/share/registrar-templates/deny-php.htaccess
COPY docker/deny-all.htaccess /usr/local/share/registrar-templates/deny-all.htaccess
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Composer (only exercised when RUN_COMPOSER_ON_BOOT=1 in dev bind-mount mode).
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Application source + ready-to-run vendor.
COPY --from=vendor /build/vendor /var/www/html/vendor
COPY . /var/www/html/

# The app writes to uploads/ and logs/ at runtime. Apache's master runs as
# root (binds :80) and its workers run as www-data, so make those paths
# www-data-writable. The entrypoint keeps them correct inside volumes.
RUN chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs /var/www/html/assets/uploads \
    && chmod -R u+rwX,g+rwX /var/www/html/uploads /var/www/html/logs /var/www/html/assets/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]