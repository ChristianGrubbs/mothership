#!/bin/zsh
# Runs one stack action, detached from the menu bar app, so quitting the app never interrupts it.
# Usage: stack-action.sh <id> <action> <wrapper> <action-dir> <container-binary> <python> <craft-tool> <coordinator> <deploy-lease> <site-url>
#        [<bridge-python> <bridge-source> <native-services> <bridge-label> <bridge-host> <bridge-port>]
# Actions: start, stop, restart, recover, login, craft-apps, craft-engines, craft-mcp, craft-mcp-force, bridge-restart, queue-read.
# queue-read only prints the site's queue counts as JSON. It changes nothing, takes no lock and writes no files.
# recover is the restart for after the stack's drive dropped off and came back. Containers whose runtime helpers
# held files on the old mount can't be stopped, so it clears them first: stop, then SIGKILL, then removing each
# stuck helper from launchd, and only if a stale record still shows running, a restart of the container service.
# Writes <action-dir>/<id>.progress.jsonl (one JSON object per step) and <id>.log (wrapper output).
# While it changes the stack it also keeps <action-dir>/stack-action.json, which deploys read:
#   {"action","pid","process_start","started_at"}. process_start is `LC_ALL=C ps -o lstart= -p <pid>`, trimmed.
#   The marker is live only while that pid exists with that start time. Anything else is stale.
set -u
umask 077
export PATH=/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin

id=$1 action=$2 wrapper=$3 dir=$4 container=$5 python=${6:-} craft_tool=${7:-} coordinator=${8:-} deploy_lease=${9:-} site_url=${10:-}
bridge_python=${11:-} bridge_source=${12:-} native_services=${13:-} bridge_label=${14:-} bridge_host=${15:-} bridge_port=${16:-}
progress="$dir/$id.progress.jsonl"
log="$dir/$id.log"
lock="$dir/runner.lock"
drive_probe_errors="$dir/$id.drive-probe"
drive_probe_pid=""
marker="$dir/stack-action.json"
marker_written=0
readonly EX_BUSY=75 EX_DRIVE_REFUSED=77 RUNTIME_WAIT_LOGIN=600 RUNTIME_WAIT_MANUAL=30 EXPECTED_CONTAINERS=13
readonly DRIVE_PROBE_TENTHS=50 EX_USAGE=64 EX_CRAFT_IN_USE=3 EX_CRAFT_STACK_RUNNING=65
readonly EX_DEPLOY_RUNNING=79 EX_QUEUES_BUSY=80 EX_BUILDER_RUNNING=81 EX_SITE_NOT_BACK=82 STACK_PREFIX=mothership-private-
readonly EX_BRIDGE_PORT_HELD=83 EX_STACK_NOT_RUNNING=84 BRIDGE_START_LIMIT=180
readonly SITE_WAIT=${MOTHERSHIP_SITE_WAIT:-180}
readonly EX_STUCK_CONTAINERS=85 EX_STACK_ALREADY_STOPPED=86 HELPER_LABEL_PREFIX=com.apple.container.container-runtime-linux.
# Tests point this at a stand-in, so a test run can never remove a real container helper.
readonly LAUNCHCTL=${MOTHERSHIP_BAR_LAUNCHCTL:-/bin/launchctl}
# TODO 2026-11-18: re-read these connection and queue names from tools/studio/runtime.py after each release.
readonly QUEUE_CHECK='$m=["redis"=>["webhooks","system","completeness","default","broadcastable"],"panorama"=>["panorama","video-preview"],"database"=>["stylepress"],"vectorization"=>["vectorization"],"ai-index"=>["ai-index"]]; $o=[]; foreach($m as $c=>$qs){foreach($qs as $q){$o[$c."/".$q]=Queue::connection($c)->size($q);}} $o["chunk_uploads_open"]=DB::table("dam_chunk_uploads")->whereNotIn("state",["completed","failed","cancelled","expired"])->count(); echo json_encode(["idle"=>array_sum($o)===0,"counts"=>$o]),PHP_EOL;'

# The panel asks this before Stop and Restart. It runs beside any other action, because it only reads.
if [[ $action == queue-read ]]; then
  gtimeout --preserve-status 40 "$container" exec "${STACK_PREFIX}fpm" php artisan tinker --execute="$QUEUE_CHECK" 2>/dev/null
  exit $?
fi

emit() { # step state [rc]
  local rc_field=""
  [[ $# -ge 3 ]] && rc_field=",\"rc\":$3"
  print -r -- "{\"ts\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"run_id\":\"$id\",\"action\":\"$action\",\"step\":\"$1\",\"state\":\"$2\"$rc_field}" >> "$progress"
}

finish() {
  [[ -n $drive_probe_pid ]] && kill -9 "$drive_probe_pid" 2>/dev/null
  rm -f "$drive_probe_errors"
  (( marker_written )) && rm -f "$marker"
  emit done end "$1"
  exit "$1"
}

# One action at a time. The kernel holds this lock for the life of this process and frees it if the
# process dies, so there is never a stale lock to clear. Child commands do not inherit it.
zmodload zsh/system
: >> "$lock"
zsystem flock -t 0 -f lock_fd "$lock" 2>/dev/null || finish $EX_BUSY

# A deploy or another session may be driving the stack by hand.
if pgrep -f '^[^ ]*[Pp]ython[^ ]* .*runtime\.py .* (up|down)$' >/dev/null 2>&1; then finish $EX_BUSY; fi

# Returns 0 when the wrapper is readable, 1 when macOS refused, 2 while macOS has not answered.
# A held read cannot be interrupted, so one probe stays outstanding and is checked again next pass.
drive_access() {
  local tenths=0
  if [[ -z $drive_probe_pid ]]; then
    /bin/cat "$wrapper" >/dev/null 2>"$drive_probe_errors" &
    drive_probe_pid=$!
  fi
  while kill -0 "$drive_probe_pid" 2>/dev/null; do
    (( tenths++ >= DRIVE_PROBE_TENTHS )) && return 2
    sleep 0.1
  done
  wait "$drive_probe_pid" 2>/dev/null
  drive_probe_pid=""
  [[ $(<"$drive_probe_errors") == *"Operation not permitted"* ]] && return 1
  return 0
}

stop_for_drive() { # log message
  print -r -- "$1" >> "$log"
  emit waiting end $EX_DRIVE_REFUSED
  finish $EX_DRIVE_REFUSED
}

wait_for_runtime() { # seconds
  local deadline=$(( SECONDS + $1 )) access
  emit waiting start
  while true; do
    drive_access
    access=$?
    # A refusal is final until the operator allows access, so don't wait it out.
    (( access == 1 )) && stop_for_drive "macOS refused access to the stack's drive"
    (( access == 0 )) && [[ -x $wrapper ]] && "$container" system status >/dev/null 2>&1 && break
    if (( SECONDS >= deadline )); then
      (( access == 2 )) && stop_for_drive "macOS did not answer the request for the stack's drive within $1 seconds"
      print -r -- "container runtime not ready after $1 seconds" >> "$log"
      emit waiting end 1
      finish 1
    fi
    sleep 3
  done
  emit waiting end 0
}

run_step() { # step
  emit "$1" start
  "$wrapper" "$1" >> "$log" 2>&1
  local rc=$?
  emit "$1" end $rc
  (( rc == 0 )) || finish $rc
}

stack_already_running() {
  local listing total running
  listing=$("$wrapper" status 2>>"$log" | grep -Ev '^(web_transport|web_relay|collector):')
  total=$(print -r -- "$listing" | grep -c ':')
  running=$(print -r -- "$listing" | grep -c ': running')
  (( total >= EXPECTED_CONTAINERS && running == total ))
}

craft_step() { # step tool-arguments...
  local step=$1 rc
  shift
  emit "$step" start
  "$python" "$craft_tool" "$@" >> "$log" 2>&1
  rc=$?
  emit "$step" end $rc
  return $rc
}

wrapper_step() { # step, without stopping the action when it fails
  emit "$1" start
  "$wrapper" "$1" >> "$log" 2>&1
  local rc=$?
  emit "$1" end $rc
  return $rc
}

# True only when the coordinator answers and shows no active write lease on the deploy checkout.
# The coordinator can time out under load, so it gets three tries. No answer counts as held.
deploy_lease_is_free() {
  local output attempt
  for attempt in 1 2 3; do
    output=$(gtimeout --preserve-status 90 "$coordinator" peers --resource "$deploy_lease" --json 2>>"$log")
    if print -r -- "$output" | DEPLOY_LEASE=$deploy_lease /usr/bin/python3 -I -c '
import json, os, sys
lease = os.environ["DEPLOY_LEASE"]
body = json.load(sys.stdin)
if "leases" not in body:
    sys.exit(2)
held = any(
    item.get("state") == "active" and item.get("mode") == "write"
    and any(name == lease or name.startswith(lease + "/") for name in item.get("resources", []))
    for item in body["leases"]
)
sys.exit(1 if held else 0)' 2>/dev/null; then
      return 0
    else
      (( $? == 1 )) && return 1
    fi
    sleep 5
  done
  return 1
}

# True only when the site reports every queue empty. A failed or unreadable check counts as busy.
queues_are_idle() {
  local output
  output=$(gtimeout --preserve-status 120 "$container" exec "${STACK_PREFIX}fpm" php artisan tinker --execute="$QUEUE_CHECK" 2>>"$log") || return 1
  print -r -- "$output" | /usr/bin/python3 -I -c '
import json, sys
lines = [line for line in sys.stdin.read().splitlines() if line.strip()]
sys.exit(0 if lines and json.loads(lines[-1]).get("idle") is True else 1)' 2>/dev/null
}

# True only when the runtime answers and lists none of the stack's containers as running.
stack_containers_are_stopped() {
  local listing
  listing=$(gtimeout --preserve-status 60 "$container" list --quiet 2>>"$log") || return 1
  ! print -r -- "$listing" | grep -q "^$STACK_PREFIX"
}

# True only when the builder is reported stopped, or no builder exists. Anything else counts as running.
builder_is_stopped() {
  local output
  output=$(gtimeout --preserve-status 60 "$container" builder status --format json 2>>"$log") || return 1
  print -r -- "$output" | /usr/bin/python3 -I -c '
import json, sys
body = json.load(sys.stdin)
sys.exit(0 if body == [] or body[0]["status"]["state"] == "stopped" else 1)' 2>/dev/null
}

process_start() { # pid
  LC_ALL=C /bin/ps -o lstart= -p "$1" 2>/dev/null | /usr/bin/sed -e 's/^ *//' -e 's/ *$//'
}

# True when the marker names a process that is still running. An unreadable marker is stale.
marker_is_live() {
  [[ -f $marker ]] || return 1
  local fields pid started
  fields=$(/usr/bin/python3 -I -c '
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
print(int(body["pid"]))
print(body["process_start"])' "$marker" 2>/dev/null) || return 1
  pid=${fields%%$'\n'*}
  started=${fields#*$'\n'}
  [[ -n $started && $(process_start "$pid") == "$started" ]]
}

# Replaces a stale marker in one step. A live one belongs to someone else and is never touched.
write_marker() {
  if marker_is_live; then
    print -r -- "another stack action holds the marker" >> "$log"
    emit preflight end $EX_BUSY
    finish $EX_BUSY
  fi
  local temporary="$marker.$$.tmp"
  print -r -- "{\"action\":\"$action\",\"pid\":$$,\"process_start\":\"$(process_start $$)\",\"started_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" > "$temporary" \
    && /bin/mv -f "$temporary" "$marker"
  if (( $? != 0 )); then
    rm -f "$temporary"
    print -r -- "the stack action marker could not be written" >> "$log"
    emit preflight end 1
    finish 1
  fi
  marker_written=1
}

# Runs before anything changes the stack. The marker goes first, so a deploy that starts after this
# check sees the marker, and one that started before it holds the lease this check reads.
guard_stack_action() {
  emit preflight start
  write_marker
  if ! deploy_lease_is_free; then
    print -r -- "a deploy holds $deploy_lease, or the coordinator did not answer" >> "$log"
    emit preflight end $EX_DEPLOY_RUNNING
    finish $EX_DEPLOY_RUNNING
  fi
  emit preflight end 0
}

# The checks before an engine update's outage. A refusal here leaves the site running.
check_safe_to_stop() {
  guard_stack_action
  emit preflight start
  if ! queues_are_idle; then
    print -r -- "the site's queues are busy or could not be read" >> "$log"
    emit preflight end $EX_QUEUES_BUSY
    finish $EX_QUEUES_BUSY
  fi
  emit preflight end 0
}

# Waits for the public site to answer 200. A site that doesn't answer ends the action here.
wait_for_site() {
  local code deadline=$(( SECONDS + SITE_WAIT ))
  emit site start
  while true; do
    code=$(curl -sS -o /dev/null -m 10 -w '%{http_code}' "$site_url" 2>>"$log")
    [[ $code == 200 ]] && break
    if (( SECONDS >= deadline )); then
      print -r -- "the site answered $code, not 200, $SITE_WAIT seconds after the stack was started" >> "$log"
      emit site end $EX_SITE_NOT_BACK
      finish $EX_SITE_NOT_BACK
    fi
    sleep 5
  done
  emit site end 0
}

# Starts the stack once, then waits for the site. It never starts anything a second time.
# A failed start ends the action even if the site answers.
bring_site_back() {
  local up_rc
  wrapper_step up
  up_rc=$?
  wait_for_site
  (( up_rc == 0 )) || finish $up_rc
}

# The stack's containers the runtime lists as running, one per line. Three tries; fails when the runtime never answers.
running_stack_containers() {
  local listing attempt
  for attempt in 1 2 3; do
    if listing=$(gtimeout --preserve-status 60 "$container" list --quiet 2>>"$log"); then
      print -r -- "$listing" | grep "^$STACK_PREFIX"
      return 0
    fi
    sleep 5
  done
  return 1
}

# Ends the recovery when the runtime can't list containers. Without a listing nothing escalates, so an unreadable
# runtime never leads to the container service restart, which would stop unrelated containers.
stop_unlisted() {
  print -r -- "the container runtime didn't list its containers, so nothing more was done" >> "$log"
  emit clear end 1
  finish 1
}

# Brings every running stack container down, escalating only as far as it has to. After the stack's drive drops,
# the helpers that held files on the old mount ignore stop and even SIGKILL, so the last two rungs remove them
# from launchd and, if a stale record still says running, restart the container service. That restart also
# stops any other container on this Mac, which is why it runs only when nothing gentler worked.
clear_stuck_containers() {
  local name names
  emit clear start
  names=$(running_stack_containers) || stop_unlisted
  # A drop leaves containers listed as running, stuck on the old mount. With none running, the stack was stopped
  # cleanly, maybe on purpose and maybe while the app was closed, so it stays down until someone starts it.
  if [[ -z $names ]]; then
    print -r -- "no stack container was running, so the stack was left down" >> "$log"
    emit clear end $EX_STACK_ALREADY_STOPPED
    finish $EX_STACK_ALREADY_STOPPED
  fi
  for name in ${(f)names}; do
    print -r -- "stopping $name" >> "$log"
    gtimeout --preserve-status 40 "$container" stop --time 10 "$name" >> "$log" 2>&1 && continue
    print -r -- "$name didn't stop; sending SIGKILL" >> "$log"
    gtimeout --preserve-status 30 "$container" kill --signal KILL "$name" >> "$log" 2>&1
  done
  sleep 3
  names=$(running_stack_containers) || stop_unlisted
  for name in ${(f)names}; do
    print -r -- "$name ignored SIGKILL; removing its runtime helper from launchd" >> "$log"
    gtimeout --preserve-status 30 "$LAUNCHCTL" bootout "gui/$(id -u)/$HELPER_LABEL_PREFIX$name" >> "$log" 2>&1
  done
  [[ -n $names ]] && sleep 5
  names=$(running_stack_containers) || stop_unlisted
  if [[ -n $names ]]; then
    print -r -- "still listed as running after their helpers were removed: ${(f)names}; restarting the container service" >> "$log"
    if ! gtimeout --preserve-status 300 "$container" system stop >> "$log" 2>&1; then
      print -r -- "the container service didn't stop; removing its API server from launchd" >> "$log"
      gtimeout --preserve-status 30 "$LAUNCHCTL" bootout "gui/$(id -u)/com.apple.container.apiserver" >> "$log" 2>&1
      sleep 2
    fi
    gtimeout --preserve-status 120 "$container" system start >> "$log" 2>&1
  fi
  names=$(running_stack_containers) || stop_unlisted
  if [[ -n $names ]]; then
    print -r -- "stack containers still running after every way of stopping them: ${(f)names}" >> "$log"
    emit clear end $EX_STUCK_CONTAINERS
    finish $EX_STUCK_CONTAINERS
  fi
  emit clear end 0
}

# True only when the runtime lists the site's PHP container as running. A restart copies the bridge's server
# from the deployed checkout, and that checkout moves while a deploy has the stack down.
site_container_is_running() {
  local listing
  listing=$(gtimeout --preserve-status 60 "$container" list --quiet 2>>"$log") || return 1
  print -r -- "$listing" | grep -qx "${STACK_PREFIX}fpm"
}

# Runs the bridge's own manager and nothing else. The manager's `up` does nothing to a healthy bridge, and
# it replaces one that runs but doesn't answer. This never stops a process and never edits the manager's files.
# Output goes to a file, because a server the manager leaves running could hold a pipe open.
restart_bridge() {
  local manager state_name rc output_file="$dir/$id.bridge-output"
  case $bridge_label in
    optimizer) manager=clop_service.py state_name=clop ;;
    ai) manager=ai_service.py state_name=ai ;;
    video) manager=video_service.py state_name=video ;;
    *) print -r -- "unknown bridge: $bridge_label" >> "$log"; finish $EX_USAGE ;;
  esac
  if [[ -z $bridge_python || -z $bridge_source || -z $native_services || -z ${HOME:-} ]] \
    || [[ ! $bridge_host =~ '^[A-Za-z0-9.:-]+$' || ! $bridge_port =~ '^[0-9]+$' ]] \
    || (( bridge_port < 1 || bridge_port > 65535 )); then
    print -r -- "the bridge restart is not configured" >> "$log"
    finish $EX_USAGE
  fi
  wait_for_runtime $RUNTIME_WAIT_MANUAL
  guard_stack_action
  emit preflight start
  if ! site_container_is_running; then
    print -r -- "the site's PHP container isn't running, so the bridge was left alone" >> "$log"
    emit preflight end $EX_STACK_NOT_RUNNING
    finish $EX_STACK_NOT_RUNNING
  fi
  emit preflight end 0
  emit bridge start
  gtimeout $BRIDGE_START_LIMIT "$bridge_python" "$bridge_source/tools/studio/$manager" up \
    --source "$bridge_source" --state "$native_services/$state_name" --host "$bridge_host" --port "$bridge_port" \
    > "$output_file" 2>&1
  rc=$?
  /bin/cat "$output_file" >> "$log"
  if (( rc != 0 )) && grep -q "not owned by this receipt" "$output_file"; then rc=$EX_BRIDGE_PORT_HELD; fi
  rm -f "$output_file"
  emit bridge end $rc
  (( rc == 0 )) || finish $rc
}

# The site goes down only after the downloads succeed and the checks pass. The builder runs only while every
# stack container is stopped, and `up` runs only once the builder has stopped: apple/container issue 2051.
update_engines() {
  local rc build_rc
  trap '' HUP INT TERM
  wait_for_runtime $RUNTIME_WAIT_MANUAL
  craft_step prepare engine-prepare || finish $?
  check_safe_to_stop
  wrapper_step down
  rc=$?
  if (( rc != 0 )); then
    bring_site_back
    finish $rc
  fi
  emit stopped start
  if ! stack_containers_are_stopped; then
    print -r -- "stack containers were still running after down, so nothing was built" >> "$log"
    emit stopped end $EX_CRAFT_STACK_RUNNING
    bring_site_back
    finish $EX_CRAFT_STACK_RUNNING
  fi
  emit stopped end 0
  craft_step build engine-build --wrapper "$wrapper"
  build_rc=$?
  emit builder start
  if ! builder_is_stopped; then
    # Starting the stack beside a live builder collapses its network, so the site stays down for the operator.
    print -r -- "the builder did not report stopped, so the site was left down" >> "$log"
    emit builder end $EX_BUILDER_RUNNING
    finish $EX_BUILDER_RUNNING
  fi
  emit builder end 0
  bring_site_back
  # A failed build is reported and never retried. The site is back on the images it already had.
  (( build_rc == 0 )) || finish $build_rc
  craft_step activate engine-activate
  rc=$?
  (( rc == 0 )) || finish $rc
  # A server in use keeps running. The panel then offers the restart.
  craft_step mcp restart-mcp
  rc=$?
  (( rc == 0 || rc == EX_CRAFT_IN_USE )) || finish $rc
}

if [[ $action == craft-* && ( -z $python || -z $craft_tool ) ]]; then
  print -r -- "the ArtCraft tool is not configured" >> "$log"
  finish $EX_USAGE
fi
if [[ $action != craft-(apps|mcp|mcp-force) && ( -z $coordinator || -z $deploy_lease ) ]]; then
  print -r -- "the deploy lease check is not configured" >> "$log"
  finish $EX_USAGE
fi
if [[ $action == (craft-engines|start|restart|recover) && -z $site_url ]]; then
  print -r -- "the site address is not configured, so this action could not confirm the site came back" >> "$log"
  finish $EX_USAGE
fi

case $action in
  craft-apps) craft_step desktop update-desktop || finish $? ;;
  craft-engines) update_engines ;;
  craft-mcp) craft_step mcp restart-mcp || finish $? ;;
  craft-mcp-force) craft_step mcp restart-mcp --force || finish $? ;;
  login)
    wait_for_runtime $RUNTIME_WAIT_LOGIN
    if stack_already_running; then
      print -r -- "stack already running at login; nothing to start" >> "$log"
    else
      guard_stack_action
      run_step up
    fi
    ;;
  start) wait_for_runtime $RUNTIME_WAIT_MANUAL; guard_stack_action; run_step up; wait_for_site ;;
  stop) wait_for_runtime $RUNTIME_WAIT_MANUAL; guard_stack_action; run_step down ;;
  restart) wait_for_runtime $RUNTIME_WAIT_MANUAL; guard_stack_action; run_step down; run_step up; wait_for_site ;;
  # The drive has only just come back, so the runtime gets the login wait.
  recover)
    wait_for_runtime $RUNTIME_WAIT_LOGIN; guard_stack_action; clear_stuck_containers
    run_step down; run_step up; wait_for_site
    ;;
  bridge-restart) restart_bridge ;;
  *) print -r -- "unknown action: $action" >> "$log"; finish 64 ;;
esac
finish 0
