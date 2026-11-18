#!/usr/bin/env bash
# Job service containers started as steps, after the nightly window guard (operator decision 2026-10-14).
#
# A job-level `services:` block starts its containers before the first step, so a nightly leg that a
# runner picks up after 06:00 would already have a database or Elasticsearch running on the Windows
# desktop before the guard could defer it. These steps start the same containers only once the guard
# says the leg runs, and remove them at the end of the job.
#
#   ci_service.sh start <NAME> <container-port> [docker run options...] <image>
#       Runs the container detached with the container port published on a Docker-chosen host port
#       (as `services:` did), waits for its health check to pass (when the image or options define
#       one), and writes SVC_<NAME>_PORT (host port) and SVC_<NAME>_ID (full container id) to
#       $GITHUB_ENV for later steps.
#   With CI_SVC_DEADLINE (Unix seconds; the guard's cut_epoch output, empty outside a nightly) set,
#   nothing starts at or past it: the image is pulled first and admission is checked again just
#   before the container starts; every docker call is bounded by the deadline; and anything this job
#   created that is not healthy by the deadline is removed and the step fails, so a slow reap, pull or
#   start never runs a database into the day.
#   ci_service.sh stop
#       Removes every container this job started (`if: always()`, so a cancelled or failed job
#       cleans up too). Given the same CI_SVC_DEADLINE, a stop after the cut keeps sweeping until
#       05:59 (see sweep below).
#
# Containers carry the label ci-svc-job=$CI_SVC_TAG (run id, attempt, job and matrix index), so two
# jobs on one host never touch each other's containers. `start` also removes ci-svc containers older
# than 12 hours, left behind by a runner that died before its stop step.
set -euo pipefail

: "${CI_SVC_TAG:?CI_SVC_TAG must name this job (run id, attempt, job, matrix index)}"
label="ci-svc-job=$CI_SVC_TAG"

log() {
  printf '{"ts":"%s","level":"%s","event":"%s","loc":"ci_service.sh","ctx":%s}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" "$3"
}

reap_stale() {
  local id created
  docker ps -aq --filter label=ci-svc=1 | while read -r id; do
    created="$(docker inspect --format '{{.Created}}' "$id" 2>/dev/null)" || continue
    if python3 -c 'import datetime as d, sys
t = d.datetime.fromisoformat(sys.argv[1][:19]).replace(tzinfo=d.timezone.utc)
sys.exit(0 if d.datetime.now(d.timezone.utc) - t > d.timedelta(hours=12) else 1)' "$created"; then
      docker rm -f "$id" >/dev/null 2>&1 && log info service.reaped "{\"id\":\"$id\",\"created\":\"$created\"}"
    fi
  done || true
}

cut="${CI_SVC_DEADLINE:-}"
past_cut() { [ -n "$cut" ] && [ "$(date +%s)" -ge "$cut" ]; }

# Runs a docker command bounded by the cut (unbounded outside a nightly); fails if the cut has passed.
before_cut() {
  local left
  [ -n "$cut" ] || { "$@"; return; }
  left=$((cut - $(date +%s)))
  [ "$left" -gt 0 ] || return 124
  timeout "$left" "$@"
}

# A docker client killed by the cut can leave the daemon still creating or starting a container, and
# the daemon finishes that work regardless. So once the cut has passed, cleanup keeps removing this
# job's labelled containers until CI_SVC_SWEEP_SECONDS (default 240) after the cut, 05:59, inside the
# window, and then until three listings in a row come back empty. A late create or start may run for
# moments after 05:55, but it is gone before the window closes at 06:00. Before the cut (or outside a
# nightly) cleanup settles on three empty listings.
#
# Only a listing that succeeds and comes back empty counts as quiet; a failed or timed-out listing or
# removal is retried. Every docker call is bounded by what is left of the budget: the rest of the
# sweep plus CI_SVC_CLEANUP_SECONDS (default 60), so a stop that starts late, even past 05:59, still
# lists and removes. Returns non-zero, and the step fails, if the budget runs out before this job's
# containers are confirmed gone. `removed` counts the containers seen and then confirmed gone.
seconds_or() { case "$1" in ''|*[!0-9]*) echo "$2" ;; *) echo $((10#$1)) ;; esac; }  # 10#: never octal
sweep() {
  local ids seen="" quiet=0 until=0 left cap budget
  if past_cut; then until=$((cut + $(seconds_or "${CI_SVC_SWEEP_SECONDS:-}" 240))); fi
  left=$((until - $(date +%s)))
  [ "$left" -gt 0 ] || left=0
  cap=$((SECONDS + left + $(seconds_or "${CI_SVC_CLEANUP_SECONDS:-}" 60)))
  removed=0
  while [ "$quiet" -lt 3 ] || [ "$(date +%s)" -lt "$until" ]; do
    budget=$((cap - SECONDS))
    if [ "$budget" -le 0 ]; then
      log error service.sweep_incomplete "{\"label\":\"$label\",\"remaining\":\"$(printf '%s' "$ids" | tr '\n' ' ')\"}"
      return 1
    fi
    if ids="$(timeout "$budget" docker ps -aq --filter "label=$label")"; then
      if [ -z "$ids" ]; then
        quiet=$((quiet + 1))
        removed=$(printf '%s' "$seen" | wc -w | tr -d ' ')
      else
        quiet=0
        seen="$(printf '%s\n%s\n' "$seen" "$ids" | grep . | sort -u)"
        budget=$((cap - SECONDS))  # the listing may have used some of it
        if [ "$budget" -gt 0 ]; then
          # shellcheck disable=SC2086 # one id per word; the next listing confirms the removal
          timeout "$budget" docker rm -f $ids >/dev/null 2>&1 || true
        fi
      fi
    else
      quiet=0
      ids="(listing failed)"
    fi
    budget=$((cap - SECONDS))
    if [ "$budget" -gt 0 ] && { [ "$quiet" -lt 3 ] || [ "$(date +%s)" -lt "$until" ]; }; then
      sleep "$((budget < 2 ? budget : 2))"
    fi
  done
}

# Fails the start once the cut has passed, removing whatever it got as far as creating.
refuse() {
  sweep || true
  log error service.past_cut "{\"name\":\"$name\",\"cut\":$cut,\"removed\":$removed}"
  echo "::error::$1" >&2
  exit 1
}

case "${1:-}" in
  start)
    name="$2" port="$3"
    shift 3
    image="${*: -1}"
    reap_stale
    # Pull first so the admission check below is the last thing before the container starts. A failed
    # pull is left to `docker run`, which reports it.
    before_cut docker pull -q "$image" >/dev/null || true
    past_cut && refuse "Not starting $name: the nightly window's 05:55 cut has passed."
    # Created and started as two bounded calls, so nothing is started once the cut has passed; refuse
    # removes a container the daemon finishes creating after its client was killed.
    if ! id="$(before_cut docker create --label ci-svc=1 --label "$label" -p "$port" "$@")"; then
      past_cut && refuse "$name was not created before the nightly window's 05:55 cut; removed it."
      log error service.create_failed "{\"name\":\"$name\"}"
      exit 1
    fi
    past_cut && refuse "Not starting $name: the nightly window's 05:55 cut has passed; removed it."
    if ! before_cut docker start "$id" >/dev/null; then
      past_cut && refuse "$name did not start before the nightly window's 05:55 cut; removed it."
      log error service.start_failed "{\"name\":\"$name\",\"id\":\"$id\"}"
      exit 1
    fi
    log info service.started "{\"name\":\"$name\",\"id\":\"$id\"}"

    # Same contract as `services:`: the job does not go on until the container is healthy.
    deadline=$((SECONDS + 600))
    while :; do
      past_cut && refuse "$name was not healthy by the nightly window's 05:55 cut; removed it."
      if ! state="$(before_cut docker inspect --format '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id")"; then
        past_cut && refuse "$name was not healthy by the nightly window's 05:55 cut; removed it."
        log error service.inspect_failed "{\"name\":\"$name\"}"
        exit 1
      fi
      case "$state" in
        "running healthy"|"running none") break ;;
        *unhealthy|exited*|dead*)
          docker logs --tail 50 "$id" >&2 || true
          log error service.unhealthy "{\"name\":\"$name\",\"state\":\"$state\"}"
          exit 1 ;;
      esac
      if [ "$SECONDS" -ge "$deadline" ]; then
        docker logs --tail 50 "$id" >&2 || true
        log error service.timeout "{\"name\":\"$name\",\"state\":\"$state\"}"
        exit 1
      fi
      sleep 2
    done

    # `docker port` lists one line per host address (0.0.0.0:49153, [::]:49154, in any order). The steps
    # connect to 127.0.0.1, so only an IPv4 wildcard or loopback mapping will do.
    host_port="$(docker port "$id" "$port/tcp" | sed -En 's/^(0\.0\.0\.0|127\.0\.0\.1):([0-9]+)$/\2/p' | head -n 1)"
    if [ -z "$host_port" ]; then
      log error service.no_ipv4_port "{\"name\":\"$name\",\"port\":$port}"
      exit 1
    fi
    # Readiness only counts before the cut: a slow inspect or port lookup may have crossed it.
    past_cut && refuse "$name became ready only after the nightly window's 05:55 cut; removed it."
    {
      echo "SVC_${name}_PORT=$host_port"
      echo "SVC_${name}_ID=$id"
    } >> "$GITHUB_ENV"
    log info service.ready "{\"name\":\"$name\",\"port\":$port,\"host_port\":$host_port}"
    ;;
  stop)
    if ! sweep; then
      echo "::error::Could not confirm this job's service containers were removed." >&2
      exit 1
    fi
    log info service.stopped "{\"count\":$removed}"
    ;;
  *)
    echo "usage: ci_service.sh start <NAME> <container-port> [docker run options...] <image> | stop" >&2
    exit 64
    ;;
esac
