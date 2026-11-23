#!/usr/bin/env bash
# Back up the Linux-host stack's data (docs/ops/linux-host.md, "Backups").
#   backup.sh create             write a backup set to $MOTHERSHIP_HOME/backups/<UTC stamp>/ and print its path
#   backup.sh stream             create a set, then write it to stdout as a tar (the off-host puller's forced command)
#   backup.sh restore-test [SET] restore SET (default: the newest) into a scratch database and check it against its manifest
# A set holds postgres.dump (pg_dump custom format), redis.rdb, state.tar.gz (app.env, stack.env, storage, looper, video,
# higgsfield-session, tunnel) and manifest.json (bytes and sha256 per file, row counts per table, the Redis keyspace).
# Elasticsearch is not backed up: its indices are rebuilt from Postgres. Asset bytes live in the S3 bucket, not here.
# Every run appends JSONL events to $MOTHERSHIP_BACKUPS/backup.jsonl.
set -euo pipefail
# Keep errexit inside $(...): stream calls create through a command substitution.
shopt -s inherit_errexit

HOME_DIR="${MOTHERSHIP_HOME:-/srv/mothership}"
STATE="$HOME_DIR/state"
BACKUPS="${MOTHERSHIP_BACKUPS:-$HOME_DIR/backups}"
KEEP="${MOTHERSHIP_BACKUP_KEEP:-4}"
PG=mothership-private-postgres
REDIS=mothership-private-redis
SCRATCH_DB=mothership_restore_check
RUN_ID="$(cat /proc/sys/kernel/random/uuid)"

# Per-table exact row counts as "table<TAB>rows", one per line, sorted by table name.
COUNT_SQL="select table_name || E'\t' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name;"

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >&2; }

event() {
  # event LEVEL NAME [KEY=VALUE ...]; values are strings. Never pass secrets.
  local level="$1" name="$2"; shift 2
  python3 - "$BACKUPS/backup.jsonl" "$RUN_ID" "$level" "$name" "$@" <<'PY' || true
import datetime, json, sys
path, run_id, level, name, *pairs = sys.argv[1:]
ctx = dict(pair.split("=", 1) for pair in pairs)
record = {"ts": datetime.datetime.now(datetime.timezone.utc).isoformat(), "run_id": run_id, "level": level,
          "event": name, "loc": "deploy/linux-host/backup.sh", "ctx": ctx}
with open(path, "a", encoding="utf-8") as handle:
    handle.write(json.dumps(record) + "\n")
PY
}

fail() { log "error: $*"; event error backup.failed "message=$*"; exit 1; }

# psql against database $1 (default: the app database) as the container's superuser, over the local socket.
psql_in() { docker exec -i "$PG" sh -c 'exec psql -X -q -A -t -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "${1:-$POSTGRES_DB}"' sh "${1:-}"; }

redis() { docker exec "$REDIS" redis-cli "$@"; }

newest_set() { find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d -name '20*Z' | sort | tail -1; }

save_redis() {
  local dest="$1" before after dir file
  dir="$(redis CONFIG GET dir | sed -n 2p)"
  file="$(redis CONFIG GET dbfilename | sed -n 2p)"
  before="$(redis LASTSAVE)"
  redis BGSAVE >/dev/null
  for _ in $(seq 1 120); do
    after="$(redis LASTSAVE)"
    [ "$after" != "$before" ] && break
    sleep 1
  done
  [ "$after" != "$before" ] || fail "redis BGSAVE did not finish in 120s"
  redis INFO persistence | grep -q '^rdb_last_bgsave_status:ok' || fail "redis BGSAVE reported an error"
  docker cp "$REDIS:$dir/$file" "$dest" || fail "copying the RDB out of redis failed"
}

write_manifest() {
  # write_manifest SET_DIR COUNTS_FILE KEYSPACE_FILE
  python3 - "$@" "$(git -C "$HOME_DIR/source" rev-parse HEAD 2>/dev/null || echo unknown)" <<'PY'
import datetime, hashlib, json, os, sys
set_dir, counts_file, keyspace_file, commit = sys.argv[1:]
files = []
for name in ("postgres.dump", "redis.rdb", "state.tar.gz"):
    path = os.path.join(set_dir, name)
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    files.append({"name": name, "bytes": os.path.getsize(path), "sha256": digest.hexdigest()})
tables = {}
for line in open(counts_file, encoding="utf-8"):
    if "\t" in line:
        table, rows = line.rstrip("\n").split("\t", 1)
        tables[table] = int(rows)
manifest = {
    "set": os.path.basename(set_dir.rstrip("/")).removeprefix(".incoming-"),
    "created_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "source_commit": commit,
    "files": files,
    "postgres": {"tables": len(tables), "rows": sum(tables.values()), "row_counts": tables,
                 "note": "counted just before pg_dump, outside its snapshot"},
    "redis_keyspace": open(keyspace_file, encoding="utf-8").read().strip().splitlines(),
}
with open(os.path.join(set_dir, "manifest.json"), "w", encoding="utf-8") as handle:
    json.dump(manifest, handle, indent=2)
    handle.write("\n")
PY
}

create() {
  install -d -m 0700 "$BACKUPS"
  exec 9>"$BACKUPS/.lock"
  flock -n 9 || fail "another backup is running"
  local stamp work final status attempt
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  work="$BACKUPS/.incoming-$stamp"
  final="$BACKUPS/$stamp"
  install -d -m 0700 "$work"
  event info backup.started "set=$stamp"

  psql_in <<<"$COUNT_SQL" > "$work/.counts" || fail "row counts failed"
  docker exec "$PG" sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > "$work/postgres.dump" \
    || fail "pg_dump failed"
  save_redis "$work/redis.rdb"
  redis INFO keyspace | tr -d '\r' | grep '^db' > "$work/.keyspace" || true

  # Logs and framework caches are not needed to restore and change constantly, so they are left out. GNU tar exits 1
  # when a file changed (or shrank, padded with zeros) while it was read: retry, and publish only a clean pass.
  for attempt in 1 2 3; do
    status=0
    tar -C "$STATE" --exclude=storage/logs --exclude=storage/framework/views --exclude=storage/framework/cache \
      --exclude=storage/framework/sessions -czf "$work/state.tar.gz" \
      app.env stack.env storage looper video higgsfield-session tunnel || status=$?
    [ "$status" -eq 0 ] && break
    [ "$status" -eq 1 ] || fail "state archive failed (tar exit $status)"
    log "a state file changed while it was archived (attempt $attempt); retrying"
    sleep 5
  done
  [ "$status" -eq 0 ] || fail "state files kept changing during 3 archive attempts"

  write_manifest "$work" "$work/.counts" "$work/.keyspace"
  rm -f "$work/.counts" "$work/.keyspace"
  chmod 0600 "$work"/*
  mv "$work" "$final"

  # Keep the newest $KEEP sets here; the off-host copies are the history.
  find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d -name '20*Z' -print0 | sort -z | head -z -n "-$KEEP" | xargs -0 -r rm -rf --
  find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d -name '.incoming-*' -mmin +240 -exec rm -rf -- {} +

  event info backup.created "set=$stamp" "bytes=$(du -sb "$final" | cut -f1)"
  log "backup set $final"
  printf '%s\n' "$final"
}

stream() {
  local set_dir
  set_dir="$(create)"
  tar -C "$set_dir" -cf - manifest.json postgres.dump redis.rdb state.tar.gz
  event info backup.streamed "set=$(basename "$set_dir")"
}

restore_test() {
  local set_dir="${1:-$(newest_set)}" redis_image tables_ok listing
  [ -n "$set_dir" ] && [ -f "$set_dir/manifest.json" ] || fail "no backup set at '${set_dir}'"
  log "checking $set_dir"
  python3 - "$set_dir" <<'PY' || fail "a file does not match its manifest"
import hashlib, json, os, sys
set_dir = sys.argv[1]
manifest = json.load(open(os.path.join(set_dir, "manifest.json"), encoding="utf-8"))
for entry in manifest["files"]:
    digest = hashlib.sha256(open(os.path.join(set_dir, entry["name"]), "rb").read()).hexdigest()
    if digest != entry["sha256"]:
        sys.exit(f"sha256 mismatch: {entry['name']}")
print(f"sha256: {len(manifest['files'])} files match the manifest", file=sys.stderr)
PY

  psql_in <<<"drop database if exists $SCRATCH_DB;"
  psql_in <<<"create database $SCRATCH_DB;"
  docker exec -i "$PG" sh -c 'exec pg_restore --no-owner -U "$POSTGRES_USER" -d "$1"' sh "$SCRATCH_DB" \
    < "$set_dir/postgres.dump" || { psql_in <<<"drop database if exists $SCRATCH_DB;"; fail "pg_restore reported errors"; }
  psql_in "$SCRATCH_DB" <<<"$COUNT_SQL" > "$BACKUPS/.restore-counts"
  psql_in <<<"drop database $SCRATCH_DB;"
  tables_ok=0
  python3 - "$set_dir/manifest.json" "$BACKUPS/.restore-counts" <<'PY' || tables_ok=$?
import json, sys
manifest = json.load(open(sys.argv[1], encoding="utf-8"))["postgres"]["row_counts"]
restored = {}
for line in open(sys.argv[2], encoding="utf-8"):
    if "\t" in line:
        table, rows = line.rstrip("\n").split("\t", 1)
        restored[table] = int(rows)
diffs = {t: (manifest.get(t), restored.get(t)) for t in sorted(set(manifest) | set(restored)) if manifest.get(t) != restored.get(t)}
print(f"postgres: restored {len(restored)} tables, {sum(restored.values())} rows; "
      f"manifest {len(manifest)} tables, {sum(manifest.values())} rows", file=sys.stderr)
for table, (before, after) in diffs.items():
    print(f"  differs: {table} manifest={before} restored={after}", file=sys.stderr)
sys.exit(1 if diffs else 0)
PY
  rm -f "$BACKUPS/.restore-counts"

  redis_image="$(docker inspect -f '{{.Config.Image}}' "$REDIS")"
  docker run --rm --network none -v "$set_dir/redis.rdb:/check/dump.rdb:ro" --entrypoint redis-check-rdb "$redis_image" \
    /check/dump.rdb >&2 || fail "redis-check-rdb rejected redis.rdb"
  # List first: `tar | grep -q` would kill tar with SIGPIPE and fail under pipefail.
  listing="$(tar -tzf "$set_dir/state.tar.gz")" || fail "state.tar.gz is unreadable"
  grep -qx 'app.env' <<<"$listing" || fail "state.tar.gz has no app.env"
  log "state: $(wc -l <<<"$listing") entries, app.env present"

  if [ "$tables_ok" -ne 0 ]; then
    event warning restore_test.differs "set=$(basename "$set_dir")"
    log "restore test: the dump restores, but row counts differ from the manifest (rows written between count and dump?)"
    exit 3
  fi
  event info restore_test.passed "set=$(basename "$set_dir")"
  log "restore test passed for $(basename "$set_dir")"
}

case "${1:-}" in
  create) create ;;
  stream) stream ;;
  restore-test) shift; restore_test "${1:-}" ;;
  *) sed -n '2,9p' "$0"; exit 2 ;;
esac
