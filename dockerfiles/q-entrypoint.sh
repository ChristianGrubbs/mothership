#!/bin/bash
set -e

ROOT="${MOTHERSHIP_ROOT:-/var/www/html}"

source "${ROOT}/dockerfiles/lib/ensure-app-key.sh"
source "${ROOT}/dockerfiles/lib/wait-for-setup.sh"

QUEUE_NAMES="${QUEUE_NAMES:-webhooks,system,completeness,default}"
QUEUE_TIMEOUT="${QUEUE_TIMEOUT:-90}"
QUEUE_TRIES="${QUEUE_TRIES:-3}"
QUEUE_MAX_JOBS="${QUEUE_MAX_JOBS:-1000}"
QUEUE_MAX_TIME="${QUEUE_MAX_TIME:-3600}"

wait_for_setup "$ROOT"
ensure_app_key

if [[ "${QUEUE_WORKER_CONNECTION:-}" == "panorama" ]]; then
    echo "→ Starting dedicated panorama queue worker"
    exec gosu www-data php artisan queue:work panorama --queue=panorama,video-preview --tries=1
fi

if [[ "${QUEUE_WORKER_CONNECTION:-}" == "vectorization" ]]; then
    echo "→ Starting dedicated vectorization queue worker"
    exec gosu www-data php artisan queue:work vectorization --queue=vectorization --timeout=480 --tries=1 --memory=512 --max-jobs=50
fi

if [[ "${QUEUE_WORKER_CONNECTION:-}" == "ai-index" ]]; then
    echo "→ Starting dedicated AI index queue worker"
    exec gosu www-data php artisan queue:work ai-index --queue=ai-index --timeout=300 --tries=4 --memory=512 --max-jobs=200
fi

echo "→ Starting queue worker: ${QUEUE_NAMES}"

exec gosu www-data php artisan queue:work \
    --queue="${QUEUE_NAMES}" \
    --timeout="${QUEUE_TIMEOUT}" \
    --tries="${QUEUE_TRIES}" \
    --max-jobs="${QUEUE_MAX_JOBS}" \
    --max-time="${QUEUE_MAX_TIME}" \
    --sleep=3
