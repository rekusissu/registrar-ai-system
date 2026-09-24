# =============================================================================
#  php-8.2-apache-ext — precompiled PHP extension layer
#
#  PURPOSE
#    Managed deploy platforms (Hostinger-style PaaS, etc.) build the image on
#    a weak single vCPU and kill long builds at their timeout. Compiling PHP
#    extensions from source takes ~20 minutes there — most of it just `mbstring`
#    (~15 min). The fix is to compile the extensions ONCE on GitHub's fast
#    runners, push this layer to GHCR, and let the platform pull it in seconds.
#
#  This image is the base for the registrar-app image (see ../Dockerfile).
#
#  Only the extensions the app actually uses are compiled (see composer.json:
#  ext-pdo, ext-mbstring, ext-curl; gd for PDF/QRCodes; zip for runtime
#  composer operations; fileinfo/opcache cheap):
#    pdo_mysql mysqli mbstring gd curl zip fileinfo opcache
#  intl / exif are intentionally NOT built (unused → faster + smaller).
#
#  Build (CI does this):  docker build -f docker/php-extensions.Dockerfile -t ghcr.io/rekusissu/php-8.2-apache-ext:php-8.2 .
# =============================================================================

FROM php:8.2-apache

# System libraries + PHP extensions for the app (compiled here, cached in CI).
# Build deps (-dev packages) are installed, used, then purged to keep the
# image lean.  Runtime libs (libfreetype6, libjpeg62-turbo, libpng16-16,
# libzip4, libonig5, libcurl4) are kept as auto-installed dependencies.
RUN set -e \
    && apt-get update -o Acquire::Retries=5 -o Acquire::http::Timeout=30 \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        mbstring \
        gd \
        curl \
        zip \
        fileinfo \
    && docker-php-ext-enable opcache \
    && a2enmod rewrite headers remoteip \
    # Remove build-time headers only.  Runtime .so libs (libfreetype6,
    # libjpeg62-turbo, libpng16-16, libzip4, libonig5, libcurl4) must stay —
    # apt cannot see that the compiled php extensions link to them.
    && apt-get purge -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Production defaults.
ENV APP_ENV=production