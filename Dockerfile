# =============================================================================
#  Registrar AI System — deployment image
#
#  Multi-stage build:
#    base    → PHP 8.2 + Apache with the PHP extensions the app needs
#    vendor  → installs Composer production dependencies (TCPDF, PHPMailer,
#              chillerlan/php-qrcode) against the SAME runtime PHP
#    runtime → final slim image: app source + ready-to-run `vendor/`
#
#  Build:
#    docker build -t registrar-ai:latest .
# =============================================================================

# ── Stage 1: base (PHP 8.2 + Apache + extensions) ────────────────────────
FROM php:8.2-apache AS base

# System libraries + PHP extensions used by the app and its Composer deps.
#   pdo_mysql/mysqli → database            mbstring   → multibyte strings
#   gd/fileinfo      → QRs, images, TCPDF   intl       → TCPDF i18n
#   curl             → AI gateway / payments exif / zip → uploads handling
#   opcache          → production performance
# Robust install:
#   - set -eux            → fail fast with a clear line id
#   - apt retries/timeout → survive flaky mirrors on small/cloud build hosts
#   - capped -j<N>        → never compile every extension in parallel on a
#                           many-core/small-RAM host (gd + intl/ICU are heavy,
#                           parallel builds OOM and the build dies with a
#                           cryptic "file already closed").
RUN set -eux \
    && apt-get update -o Acquire::Retries=5 -o Acquire::http::Timeout=30 \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libicu-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$( [ "$(nproc)" -gt 2 ] && echo 2 || echo "$(nproc)" )" \
        pdo_mysql \
        mbstring \
        exif \
        zip \
        gd \
        intl \
        curl \
        fileinfo \
        opcache \
    && a2enmod rewrite headers remoteip \
    && rm -rf /var/lib/apt/lists/*

# ── Stage 2: vendor (Composer production dependencies) ────────────────────
FROM base AS vendor

# Official Composer image just needs to supply the binary; the install below
# runs in THIS image so extension/platform checks match the runtime exactly.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# ── Stage 3: runtime (final image) ────────────────────────────────────────
FROM base AS runtime

ENV APP_ENV=production

# Apache vhost: serve the app from the web root and honor .htaccess
# (the app depends on mod_rewrite for /verify/<hash> pretty URLs).
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Guard template re-seeded into (possibly empty) upload/log volumes at boot.
COPY docker/deny-php.htaccess /usr/local/share/registrar-templates/deny-php.htaccess
COPY docker/deny-all.htaccess /usr/local/share/registrar-templates/deny-all.htaccess
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Composer (only exercised when RUN_COMPOSER_ON_BOOT=1 in dev bind-mount mode).
COPY --from=vendor /usr/local/bin/composer /usr/local/bin/composer

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