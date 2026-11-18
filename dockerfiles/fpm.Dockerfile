# =============================================================================
# Mothership PHP-FPM Application Server
# =============================================================================
# Multi-stage build:
#   Stage 1 (composer) — install PHP dependencies
#   Stage 2 (app)      — production PHP-FPM image
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
FROM php:8.4.27-fpm-trixie@sha256:e2d8e787dc775bdbd9d7e0c5e8f2f9ddb41ce5977c8bbaf99a2ba2e7d90fd8ae

LABEL maintainer="ChristianGrubbs"
LABEL org.opencontainers.image.title="The Mothership FPM"
LABEL org.opencontainers.image.description="PHP-FPM application server for The Mothership"
LABEL org.opencontainers.image.source="https://github.com/ChristianGrubbs/mothership"

# System dependencies + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
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

# Verify the Python 3 + NumPy install the panorama stitch CLI (Task 6, built
# on the pinned PanoForge core vendored under tools/media/panoforge/) needs
# at runtime — fail the build loudly here rather than discovering a missing
# interpreter or NumPy only when a 360 conversion job runs in production.
RUN python3 --version \
    && python3 -c "import numpy; print('numpy', numpy.__version__)"

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

# Coverage driver for Pest's Test Impact Analysis. Off by default so production
# images stay free of it; enable with --build-arg INSTALL_COVERAGE_EXTENSION=true.
# Even when installed it stays dormant (pcov.enabled=0) until a test run turns it on.
ARG INSTALL_COVERAGE_EXTENSION=false
RUN if [ "$INSTALL_COVERAGE_EXTENSION" = "true" ]; then \
        pecl install pcov-1.0.12 \
        && docker-php-ext-enable pcov \
        && echo 'pcov.enabled=0' >> "$PHP_INI_DIR/conf.d/docker-php-ext-pcov.ini"; \
    fi

# PHP production configuration
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY dockerfiles/php.ini "$PHP_INI_DIR/conf.d/mothership.ini"

# PHP-FPM pool configuration
COPY dockerfiles/www.conf /usr/local/etc/php-fpm.d/www.conf

# Install Composer (for runtime use in entrypoint)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Application code + Composer vendor from stage 1
# DNG fallback decoder; embedded JPEG extraction still uses ExifTool.
RUN apt-get update && apt-get install -y --no-install-recommends libraw-bin \
    && command -v dcraw_emu \
    && apt-get clean

WORKDIR /var/www/html
COPY . .
RUN sh dockerfiles/lib/install-higgsfield.sh
COPY --from=composer /app/vendor ./vendor

RUN touch vendor/composer/installed.json

# Align www-data with the host user so bind-mounted storage stays
# writable on both sides (defaults keep the stock image behavior).
ARG HOST_UID=33
ARG HOST_GID=33
RUN groupmod -o -g "${HOST_GID}" www-data \
    && usermod -o -u "${HOST_UID}" -g "${HOST_GID}" www-data

# Set permissions. /var/www is www-data's home, so it has to be writable for
# tooling that caches there (Pest's Test Impact Analysis graph, Composer).
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown www-data:www-data /var/www

# The bind-mounted tree is owned by the host user, which Git refuses to read
# from another uid. Test Impact Analysis shells out to Git to find changed files.
RUN git config --system --add safe.directory /var/www/html

# The commit this image was built from (docs/ops/studio-runtime.md#image-builds passes
# `--build-arg MOTHERSHIP_BUILD_COMMIT="$(git rev-parse HEAD)"`). config('app.build_commit')
# reads it and the layouts publish it in the mothership-build-commit meta tag, so a deploy
# check can prove which commit is serving. Declared last so it never invalidates the
# cached layers above.
ARG MOTHERSHIP_BUILD_COMMIT=unknown
ENV MOTHERSHIP_BUILD_COMMIT=${MOTHERSHIP_BUILD_COMMIT}

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=10s --start-period=180s --retries=3 \
    CMD test -f /var/www/html/storage/mothership.lock \
        && timeout 5 bash -c 'exec 3<>/dev/tcp/127.0.0.1/9000'

ENTRYPOINT ["/var/www/html/dockerfiles/fpm-entrypoint.sh"]
