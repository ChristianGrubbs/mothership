# =============================================================================
# Mothership Web Server (Apache + PHP 8.4)
# =============================================================================
# Alternative to Nginx + PHP-FPM. Use via:
#   docker compose -f compose.dev.yaml -f compose.dev.apache.yaml up -d
#
# Multi-stage build:
#   Stage 1 (composer) — install PHP dependencies
#   Stage 2 (app)      — production Apache image
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1: Composer dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
COPY packages/ packages/
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: Production image
# ---------------------------------------------------------------------------
# PHP2Shell fixes (CVE-2026-109618 / CVE-2026-109619); retain Debian trixie.
FROM php:8.4.27-apache-trixie@sha256:a0b75cee7023ba2c127138dcad2ecc6ec7e09435534645e70450f29f24ea2a5a

LABEL maintainer="ChristianGrubbs"
LABEL org.opencontainers.image.title="The Mothership"
LABEL org.opencontainers.image.description="Digital asset management for The Mothership"
LABEL org.opencontainers.image.source="https://github.com/ChristianGrubbs/mothership"
LABEL org.opencontainers.image.vendor="The Mothership"
LABEL org.opencontainers.image.licenses="MIT"

# System dependencies + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    gosu \
    ffmpeg \
    poppler-utils \
    libimage-exiftool-perl \
    libvips-tools \
    zstd \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    libicu-dev \
    libgmp-dev \
    libpq-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        calendar \
        exif \
        gd \
        gmp \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# `dam:update` / `dam:update:restore` (DamUpdater) shell out to pg_dump and psql on
# PostgreSQL. Keep the client on
# the server's major (postgres:16 on the Studio and in CI): pg_dump refuses to
# dump a newer server, and a newer pg_dump's plain SQL does not load into an
# older one (17 writes `SET transaction_timeout`, which 16 rejects under
# ON_ERROR_STOP). Debian trixie only ships client 17, so take it from PGDG. A
# separate layer keeps libpq-dev, which pdo_pgsql builds against, on Debian's.
ARG PG_CLIENT_MAJOR=16
RUN install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
        https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && . /etc/os-release \
    && printf 'Types: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: %s-pgdg\nArchitectures: %s\nComponents: main\nSigned-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc\n' \
        "$VERSION_CODENAME" "$(dpkg --print-architecture)" > /etc/apt/sources.list.d/pgdg.sources \
    && apt-get update \
    && apt-get install -y --no-install-recommends "postgresql-client-${PG_CLIENT_MAJOR}" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && pg_dump --version \
    && psql --version \
    && [ "$(pg_dump --version | sed -E 's/.* ([0-9]+)[.].*/\1/')" = "$PG_CLIENT_MAJOR" ]

# PHP production configuration
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY dockerfiles/php.ini "$PHP_INI_DIR/conf.d/mothership.ini"

# Apache: enable modules and set document root to public/
RUN a2enmod rewrite headers deflate \
    && sed -i 's|/var/www/html|/var/www/html/public|g' \
        /etc/apache2/sites-available/000-default.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install Composer (for runtime use in entrypoint)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Application code + Composer vendor from stage 1
# DNG fallback decoder; embedded JPEG extraction still uses ExifTool.
RUN apt-get update && apt-get install -y --no-install-recommends libraw-bin \
    && command -v dcraw_emu \
    && apt-get clean

WORKDIR /var/www/html
COPY . .
COPY --from=composer /app/vendor ./vendor

RUN touch vendor/composer/installed.json

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=180s --retries=3 \
    CMD curl -fsS http://localhost/up > /dev/null || exit 1

ENTRYPOINT ["/var/www/html/dockerfiles/web-entrypoint.sh"]
