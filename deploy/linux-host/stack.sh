#!/usr/bin/env bash
# Operate The Mothership on a Linux host (docs/ops/linux-host.md).
#   stack.sh build    build the four images from the checkout's HEAD and record their tags
#   stack.sh up       render generated files, then create/start every service
#   stack.sh verify   the launcher's verify checks against this stack
#   stack.sh stop [SERVICE...]  stop every service, or the named ones (containers, volumes and state stay)
#   stack.sh ps|logs  pass through to docker compose
#   stack.sh backup create|stream|restore-test   back up the data (backup.sh)
# MOTHERSHIP_HOME (default /srv/mothership) holds source/ (the checkout) and state/.
set -euo pipefail

HOME_DIR="${MOTHERSHIP_HOME:-/srv/mothership}"
SOURCE="$HOME_DIR/source"
STATE="$HOME_DIR/state"
STACK_ENV="$STATE/stack.env"
COMPOSE_FILE="$SOURCE/deploy/linux-host/compose.yaml"

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >&2; }

stack_value() { sed -n "s/^$1=//p" "$STACK_ENV" | tail -1; }

set_stack_value() {
  local key="$1" value="$2" tmp
  touch "$STACK_ENV"
  tmp="$(mktemp "$STATE/.stack.env.XXXXXX")"
  grep -v "^$key=" "$STACK_ENV" > "$tmp" || true
  printf '%s=%s\n' "$key" "$value" >> "$tmp"
  chmod 0644 "$tmp"
  mv "$tmp" "$STACK_ENV"
}

compose() {
  local profiles=""
  [ -f "$STATE/tunnel/credentials.json" ] && [ -n "$(stack_value TUNNEL_ID)" ] && profiles="tunnel"
  COMPOSE_PROFILES="$profiles" docker compose --project-name mothership \
    --file "$COMPOSE_FILE" --env-file "$STACK_ENV" "$@"
}

ensure_state() {
  install -d -m 0755 "$STATE" "$STATE/storage" "$STATE/looper" "$STATE/video" "$STATE/generated"
  install -d -m 0700 "$STATE/higgsfield-session" "$STATE/tunnel"
  [ -f "$STATE/app.env" ] || { log "missing $STATE/app.env"; exit 1; }
  chmod 0600 "$STATE/app.env"
  set_stack_value MOTHERSHIP_SOURCE "$SOURCE"
  set_stack_value MOTHERSHIP_STATE "$STATE"
  set_stack_value MOTHERSHIP_UID "$(id -u)"
  set_stack_value MOTHERSHIP_GID "$(id -g)"
}

build() {
  ensure_state
  local commit tag uid gid
  commit="$(git -C "$SOURCE" rev-parse HEAD)"
  tag="${commit:0:12}"
  uid="$(id -u)"; gid="$(id -g)"
  for role in fpm q; do
    log "building mothership-$role:$tag"
    docker build --build-arg HOST_UID="$uid" --build-arg HOST_GID="$gid" \
      --build-arg MOTHERSHIP_BUILD_COMMIT="$commit" \
      --file "$SOURCE/dockerfiles/$role.Dockerfile" --tag "mothership-$role:$tag" "$SOURCE"
  done
  log "building mothership-looper:$tag"
  docker build --build-arg HOST_UID="$uid" --build-arg HOST_GID="$gid" \
    --file "$SOURCE/tools/studio/looper.Dockerfile" --tag "mothership-looper:$tag" "$SOURCE"
  log "building mothership-bridges:$tag"
  docker build --build-arg HOST_UID="$uid" --build-arg HOST_GID="$gid" \
    --file "$SOURCE/deploy/linux-host/bridges.Dockerfile" --tag "mothership-bridges:$tag" "$SOURCE"
  set_stack_value FPM_IMAGE "mothership-fpm:$tag"
  set_stack_value QUEUE_IMAGE "mothership-q:$tag"
  set_stack_value LOOPER_IMAGE "mothership-looper:$tag"
  set_stack_value BRIDGES_IMAGE "mothership-bridges:$tag"
  log "built $tag; run: stack.sh up"
}

render() {
  ensure_state
  python3 "$SOURCE/deploy/linux-host/host.py" render --state "$STATE"
}

case "${1:-}" in
  build) build ;;
  render) render ;;
  up) render; shift; compose up --detach --remove-orphans "$@" ;;
  verify) python3 "$SOURCE/deploy/linux-host/host.py" verify --state "$STATE" --fpm-image "$(stack_value FPM_IMAGE)" ;;
  stop) shift; compose stop "$@" ;;
  ps|logs|config|exec) cmd="$1"; shift; compose "$cmd" "$@" ;;
  backup) shift; exec "$SOURCE/deploy/linux-host/backup.sh" "$@" ;;
  *) sed -n '2,9p' "$0"; exit 2 ;;
esac
