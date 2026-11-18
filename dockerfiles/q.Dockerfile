# =============================================================================
# Mothership Queue Worker (PHP 8.4 CLI)
# =============================================================================
# Lightweight CLI image for processing background jobs.
# Processes webhooks, system, completeness, and default queues.
#
# Multi-stage build:
#   Stage 1 (composer) — install PHP dependencies
#   Stage 2 (app)      — production CLI image
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
FROM php:8.4.27-cli-trixie@sha256:a4ae1f57e22b89a0a69c5e085b3010ee727d094e1542d249e48a01bec3c44810

LABEL maintainer="ChristianGrubbs"
LABEL org.opencontainers.image.title="The Mothership Queue Worker"
LABEL org.opencontainers.image.description="Background job processor for The Mothership"
LABEL org.opencontainers.image.source="https://github.com/ChristianGrubbs/mothership"

# System dependencies + PHP extensions.
# procps provides pgrep, used by the HEALTHCHECK below.
# zstd streams Blender 3.0+ .blend files, which are zstd-compressed, for their embedded thumbnail.
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    unzip \
    gosu \
    procps \
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
    python3 \
    python3-numpy \
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

# Verify the Python 3 + NumPy install this queue worker needs to run the
# panorama stitch CLI (Task 6, built on the pinned PanoForge core vendored
# under tools/media/panoforge/) — fail the build loudly here rather than
# discovering a missing interpreter or NumPy only when a real 360 conversion
# job runs inside mothership-q (see Task 8's real-OSV verification).
RUN python3 --version \
    && python3 -c "import numpy; print('numpy', numpy.__version__)"

# Align www-data with the host user so bind-mounted storage stays
# writable on both sides (defaults keep the stock image behavior).
ARG HOST_UID=33
ARG HOST_GID=33
RUN groupmod -o -g "${HOST_GID}" www-data \
    && usermod -o -u "${HOST_UID}" -g "${HOST_GID}" www-data

# PHP production configuration
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY dockerfiles/php.ini "$PHP_INI_DIR/conf.d/mothership.ini"

# Application code + Composer vendor from stage 1
# DNG fallback decoder; embedded JPEG extraction still uses ExifTool.
RUN apt-get update && apt-get install -y --no-install-recommends libraw-bin \
    && command -v dcraw_emu \
    && apt-get clean

WORKDIR /var/www/html
COPY . .
COPY --from=composer /app/vendor ./vendor

# Set permissions. The entrypoints drop to www-data via gosu, so bootstrap/cache
# has to be owned by it before the package manifest is written on first boot.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Healthcheck logic:
#   1. If the install lock file does not exist yet, the worker is still
#      waiting for the web/fpm container to finish first-time setup
#      (q-entrypoint.sh blocks on this for up to SETUP_WAIT_TIMEOUT
#      seconds, which is operator-overridable). Report healthy so the
#      orchestrator does not restart-loop while setup runs.
#   2. Once the lock exists, require an active queue:work or
#      schedule:work process. The bracket trick `[a]rtisan` prevents
#      pgrep from matching its own command line.
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD [ ! -f /var/www/html/storage/mothership.lock ] \
        || pgrep -f "[a]rtisan (queue|schedule):work" >/dev/null \
        || exit 1

# The commit this image was built from (docs/ops/studio-runtime.md#image-builds passes
# `--build-arg MOTHERSHIP_BUILD_COMMIT="$(git rev-parse HEAD)"`). config('app.build_commit')
# reads it and the layouts publish it in the mothership-build-commit meta tag, so a deploy
# check can prove which commit is serving. Declared last so it never invalidates the
# cached layers above.
ARG MOTHERSHIP_BUILD_COMMIT=unknown
ENV MOTHERSHIP_BUILD_COMMIT=${MOTHERSHIP_BUILD_COMMIT}

ENTRYPOINT ["/var/www/html/dockerfiles/q-entrypoint.sh"]
