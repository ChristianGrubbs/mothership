# =============================================================================
# Mothership Nginx Reverse Proxy
# =============================================================================
# Lightweight Nginx container that proxies PHP requests to the FPM container
# and serves static files (CSS, JS, images) directly.
# =============================================================================

FROM nginx:1.27-alpine

LABEL maintainer="ChristianGrubbs"
LABEL org.opencontainers.image.title="The Mothership Nginx"
LABEL org.opencontainers.image.description="Nginx reverse proxy for The Mothership"
LABEL org.opencontainers.image.source="https://github.com/ChristianGrubbs/mothership"

# Remove default config
RUN rm /etc/nginx/conf.d/default.conf

# Copy Mothership nginx config
COPY dockerfiles/nginx.conf /etc/nginx/conf.d/mothership.conf

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -sf http://localhost/ -o /dev/null -w '%{http_code}' | grep -qE '^(200|302|502)$' || exit 1
