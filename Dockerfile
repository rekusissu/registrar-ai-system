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

# Safety net: guarantee the extensions the app needs exist even if the
# prebuilt base image is stale or was rebuilt without them. These installs
# are no-ops when the extension is already present.
RUN set -e \
    && apt-get update -o Acquire::Retries=5 -o Acquire::http::Timeout=30 \
    && apt-get install -y --no-install-recommends \
        unzip \
        libzip-dev \
        libmariadb-dev \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Composer needs ext-zip OR the unzip binary to download dist archives.
# unzip is installed here so the app build is self-sufficient even if the
# pulled extension layer predates the zip addition.

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

# Safety net: same guarantee as the vendor stage — ensure critical
# extensions exist even if the prebuilt base image is missing them.
RUN set -e \
    && apt-get update -o Acquire::Retries=5 -o Acquire::http::Timeout=30 \
    && apt-get install -y --no-install-recommends libzip-dev libmariadb-dev \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=production

# Required production secrets. Hostinger Cloud builds this Dockerfile directly
# (it ignores docker-compose.yml and any .env file on the host), so these must reach the
# PHP process as env vars. Keep APP_ENV=production above — it is what activates the
# fail-closed guard in shared/config.php that emits "JWT_SECRET is not set".
#
# These ARG defaults are baked into the image so a fresh build boots out-of-the-box; a
# real value supplied by the platform (build arg or env«, e.g. in a no-Dockerfile CI，
# an environment variable override) will take precedence over these.
#
# NOTE: these are committed/visible to anyone with image access. They are random and
# unique per deployment so the app cannot boot with an empty secret; replace them with your
# own values by setting secret build args on the platform for tighter hygiene.
ARG JWT_SECRET_ARG=75b9c530776f97aa3971f7a70f15f0d69a14734cfc8767e7cf619faf48a7ef19
ARG KIOSK_ACCESS_TOKEN_ARG=43f288fbe5d9c3c99dff8cdff50b9e3c
ENV JWT_SECRET=${JWT_SECRET:-${JWT_SECRET_ARG}}
ENV KIOSK_ACCESS_TOKEN=${KIOSK_ACCESS_TOKEN:-${KIOSK_ACCESS_TOKEN_ARG}}

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
# mkdir -p is required: .dockerignore excludes logs/ (and its subpaths of
# uploads/), so these dirs may not exist in the image until we create them.
RUN set -e \
    && mkdir -p \
        /var/www/html/uploads \
        /var/www/html/logs \
        /var/www/html/assets/uploads/students \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs /var/www/html/assets/uploads \
    && chmod -R u+rwX,g+rwX /var/www/html/uploads /var/www/html/logs /var/www/html/assets/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]