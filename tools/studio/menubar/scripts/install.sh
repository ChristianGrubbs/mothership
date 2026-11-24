#!/bin/zsh
# Builds The Mothership menu bar app, installs it for this user and starts it at login.
# Usage:
#   install.sh --wrapper <stack-wrapper> --app-env <app.env> --site-url <https://host/up> --disk <path>
#              --coordinator <lease-tool> --deploy-lease <worktree:/path>
#              [--umbrel-ssh <user@host> --umbrel-key <private-key> [--umbrel-capacity-file <json>]]
#              [--craft-tool <craft_updates.py>]
#              [--bridge-source <deployed-checkout> --bridge-native <native-services-folder>]
#   install.sh --site-url <https://host/up> --linux-host <user@host> --linux-key <private-key>
#              [--linux-jump <ssh-config-alias>] [--linux-home <stack-folder>] [--linux-name <what the panel calls it>]
#              [--umbrel-ssh <user@host> --umbrel-key <private-key>] [--craft-tool <craft_updates.py>]
#   install.sh --uninstall
# The --linux-host form watches a stack on a Linux host over SSH (docs/ops/linux-host.md). The app then reads that
# host only, and offers no Start, Stop or Restart. The first connection records the host's key through the jump host.
# Installing never starts or stops the stack. Everything is staged and checked first. If the switch fails, the previous version is put back.
set -euo pipefail

label=app.themothership.menubar
applications="$HOME/Applications"
app="$applications/The Mothership.app"
support="$HOME/Library/Application Support/The Mothership Menu Bar"
config="$support/config.json"
agent="$HOME/Library/LaunchAgents/$label.plist"
domain="gui/$(id -u)"
package=${0:A:h:h}

if [[ ${1:-} == --uninstall ]]; then
  launchctl bootout "$domain/$label" 2>/dev/null || true
  rm -f "$agent"
  rm -rf "$app"
  print -r -- "Removed the app and its login item. The site was not touched."
  exit 0
fi

wrapper="" app_env="" site_url="" disk="" umbrel_ssh="" umbrel_key="" umbrel_capacity_file=""
craft_tool="" coordinator="" deploy_lease="" bridge_source="" bridge_native=""
linux_host="" linux_key="" linux_jump="" linux_home=/srv/mothership linux_name="Windows PC"
while (( $# )); do
  case $1 in
    --wrapper) wrapper=${2:a} ;;
    --app-env) app_env=${2:a} ;;
    --site-url) site_url=$2 ;;
    --disk) disk=${2:a} ;;
    --umbrel-ssh) umbrel_ssh=$2 ;;
    --umbrel-key) umbrel_key=${2:a} ;;
    --umbrel-capacity-file) umbrel_capacity_file=${2:a} ;;
    --craft-tool) craft_tool=${2:a} ;;
    --coordinator) coordinator=${2:a} ;;
    --deploy-lease) deploy_lease=$2 ;;
    --bridge-source) bridge_source=${2:a} ;;
    --bridge-native) bridge_native=${2:a} ;;
    --linux-host) linux_host=$2 ;;
    --linux-key) linux_key=${2:a} ;;
    --linux-jump) linux_jump=$2 ;;
    --linux-home) linux_home=$2 ;;
    --linux-name) linux_name=$2 ;;
    *) print -r -- "unknown option: $1" >&2; exit 64 ;;
  esac
  shift 2
done
if [[ -n $linux_host ]]; then
  [[ -n $site_url && $linux_host =~ '^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$' && -f $linux_key
     && $linux_home =~ '^/[A-Za-z0-9._/-]*$' && ( -z $linux_jump || $linux_jump =~ '^[A-Za-z0-9._@-]+$' )
     && $linux_name =~ '^[A-Za-z0-9 ._-]{1,24}$' ]] || {
    print -r -- "usage: install.sh --site-url <url> --linux-host <user@host> --linux-key <key> [--linux-jump <alias>] [--linux-home <path>] [--linux-name <name>]" >&2
    exit 64
  }
  if [[ -n $wrapper$app_env$disk$coordinator$deploy_lease$bridge_source$bridge_native$umbrel_capacity_file ]]; then
    print -r -- "--linux-host watches another host, so it takes none of the options for a stack on this Mac" >&2
    exit 64
  fi
else
  [[ -x $wrapper && -f $app_env && -n $site_url && -d $disk ]] || {
    print -r -- "usage: install.sh --wrapper <path> --app-env <path> --site-url <url> --disk <path>" >&2
    exit 64
  }
fi
if [[ -n $umbrel_ssh || -n $umbrel_key ]]; then
  [[ $umbrel_ssh =~ '^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$' && -f $umbrel_key ]] || {
    print -r -- "--umbrel-ssh needs user@host and --umbrel-key needs an existing key file" >&2
    exit 64
  }
fi
if [[ -n $umbrel_capacity_file && ( -z $umbrel_ssh || ! -d ${umbrel_capacity_file:h} ) ]]; then
  print -r -- "--umbrel-capacity-file needs --umbrel-ssh and an existing folder" >&2
  exit 64
fi
if [[ -n $craft_tool && ! -f $craft_tool ]]; then
  print -r -- "--craft-tool needs an existing file" >&2
  exit 64
fi
if [[ -n $bridge_source || -n $bridge_native ]] && [[ ! -d $bridge_source/tools/studio || ! -d $bridge_native ]]; then
  print -r -- "--bridge-source needs the deployed checkout and --bridge-native needs an existing folder" >&2
  exit 64
fi
if [[ -z $linux_host ]] && [[ ! -x $coordinator || $deploy_lease != worktree:/* ]]; then
  print -r -- "--coordinator needs an executable and --deploy-lease needs worktree:/path" >&2
  exit 64
fi

known_hosts="$support/linux-host-known-hosts"
if [[ -n $linux_host ]]; then
  # The same connection the app makes, checked before anything is replaced. The first one records the host's
  # key through the jump host. Every later one, the app's included, requires that same key.
  mkdir -p "$support"
  ssh_args=(-i "$linux_key" -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10
    -o "UserKnownHostsFile=\"$known_hosts\"" -o LogLevel=ERROR)
  [[ -n $linux_jump ]] && ssh_args+=(-J "$linux_jump")
  ssh -o StrictHostKeyChecking=accept-new "${ssh_args[@]}" -T "$linux_host" true || {
    print -r -- "Could not reach $linux_host over SSH. Nothing was changed." >&2
    exit 69
  }
  ssh -o StrictHostKeyChecking=yes "${ssh_args[@]}" -T "$linux_host" python3 - --home "$linux_home" \
    < "$package/Resources/linux-host-status.py" | /usr/bin/python3 -c '
import json, sys
report = json.load(sys.stdin)
rows = report["containers"]
if rows is None:
    sys.exit("the Linux host answered, but its containers could not be read")
running = sum(1 for row in rows if row["running"])
print("Linux host read: %d of %d stack containers running" % (running, len(rows)))
' || {
    print -r -- "The status read on $linux_host failed. Nothing was changed." >&2
    exit 69
  }
fi

identity=$(security find-identity -p codesigning -v | awk '/Apple Development/ { print $2; exit }')
[[ -n $identity ]] || identity=-

mkdir -p "$applications" "$support" "${agent:h}"
staged_app="$app.new" staged_config="$config.new" staged_agent="$agent.new"
previous_app="$app.previous" previous_config="$config.previous" previous_agent="$agent.previous"
build=$(mktemp -d "${TMPDIR:-/tmp}/mothership-bar.XXXXXX")
trap 'rm -rf "$build" "$staged_app" "$staged_config" "$staged_agent"' EXIT

zsh "$package/scripts/build-app.sh" "$build" "$identity"
rm -rf "$staged_app"
ditto "$build/The Mothership.app" "$staged_app"

LINUX_HOST=$linux_host LINUX_KEY=$linux_key LINUX_JUMP=$linux_jump LINUX_HOME=$linux_home LINUX_NAME=$linux_name \
  KNOWN_HOSTS=$known_hosts \
  WRAPPER=$wrapper APP_ENV=$app_env SITE_URL=$site_url DISK=$disk CONFIG=$staged_config \
  UMBREL_SSH=$umbrel_ssh UMBREL_KEY=$umbrel_key UMBREL_CAPACITY_FILE=$umbrel_capacity_file \
  CRAFT_TOOL=$craft_tool COORDINATOR=$coordinator DEPLOY_LEASE=$deploy_lease \
  BRIDGE_SOURCE=$bridge_source BRIDGE_NATIVE=$bridge_native \
  /usr/bin/python3 - <<'PY'
import json, os
config = {"siteURL": os.environ["SITE_URL"]}
if os.environ["LINUX_HOST"]:
    config["linuxHost"] = {
        "sshTarget": os.environ["LINUX_HOST"],
        "jumpHost": os.environ["LINUX_JUMP"] or None,
        "keyPath": os.environ["LINUX_KEY"],
        "knownHostsPath": os.environ["KNOWN_HOSTS"],
        "home": os.environ["LINUX_HOME"],
        "displayName": os.environ["LINUX_NAME"],
    }
else:
    config.update({
        "wrapperPath": os.environ["WRAPPER"],
        "appEnvPath": os.environ["APP_ENV"],
        "originURL": "http://127.0.0.1:18090/up",
        "containerBinary": "/opt/homebrew/bin/container",
        "tunnelLabel": "app.themothership.tunnel",
        "tunnelReadyURL": "http://127.0.0.1:20241/ready",
        "ollamaURL": "http://127.0.0.1:11434",
        "diskPath": os.environ["DISK"],
        "containerPrefix": "mothership-private-",
        "uptimeService": "com.apple.container.container-runtime-linux.mothership-private-nginx",
        "deployGuard": {"coordinator": os.environ["COORDINATOR"], "lease": os.environ["DEPLOY_LEASE"]},
    })
if os.environ["UMBREL_SSH"]:
    config["umbrelStore"] = {
        "sshTarget": os.environ["UMBREL_SSH"],
        "keyPath": os.environ["UMBREL_KEY"],
        "dataPath": "/data",
        "container": "mothership-s3",
        "bucket": "mothership",
    }
if os.environ["UMBREL_CAPACITY_FILE"]:
    config["umbrelCapacityFile"] = os.environ["UMBREL_CAPACITY_FILE"]
if os.environ["CRAFT_TOOL"]:
    config["craftTool"] = {"python": "/opt/homebrew/bin/python3", "script": os.environ["CRAFT_TOOL"]}
if os.environ["BRIDGE_SOURCE"]:
    config["bridgeWatch"] = {
        "python": "/opt/homebrew/bin/python3",
        "source": os.environ["BRIDGE_SOURCE"],
        "nativeServices": os.environ["BRIDGE_NATIVE"],
    }
with open(os.environ["CONFIG"], "w", encoding="utf-8") as handle:
    json.dump(config, handle, indent=2)
PY
chmod 600 "$staged_config"

APP_BINARY="$app/Contents/MacOS/MothershipBar" LABEL=$label AGENT=$staged_agent /usr/bin/python3 - <<'PY'
import os, plistlib
agent = {
    "Label": os.environ["LABEL"],
    "ProgramArguments": [os.environ["APP_BINARY"], "--login"],
    "RunAtLoad": True,
    "KeepAlive": {"SuccessfulExit": False},
    "ThrottleInterval": 30,
    "AbandonProcessGroup": True,
    "LimitLoadToSessionType": "Aqua",
    "ProcessType": "Interactive",
}
with open(os.environ["AGENT"], "wb") as handle:
    plistlib.dump(agent, handle)
PY
plutil -lint "$staged_agent" >/dev/null

# Loading the login item launches the app now. This login is marked as handled first, so installing
# never starts the stack, even if it is down on purpose.
"$staged_app/Contents/MacOS/MothershipBar" --mark-login-handled

typeset -A replaced

# Each step returns on failure itself: errexit is off inside a function called from `||`.
put_in_place() { # name staged live previous
  if [[ -e $3 ]]; then mv "$3" "$4" || return 1; fi
  mv "$2" "$3" || return 1
  replaced[$1]=1
}

# Removes only what this run installed, and restores only what this run moved aside.
undo_one() { # name live previous
  if [[ -n ${replaced[$1]:-} ]]; then rm -rf "$2"; fi
  if [[ -e $3 ]]; then mv "$3" "$2" || print -r -- "Could not restore $2. A copy is at $3." >&2; fi
}

restore_previous() {
  print -r -- "Install failed. Putting the previous version back." >&2
  launchctl bootout "$domain/$label" 2>/dev/null || true
  undo_one app "$app" "$previous_app"
  undo_one config "$config" "$previous_config"
  undo_one agent "$agent" "$previous_agent"
  if [[ -e $agent ]]; then
    launchctl bootstrap "$domain" "$agent" || print -r -- "The previous version could not be reloaded." >&2
  fi
  exit 1
}

switch_to_staged() {
  put_in_place app "$staged_app" "$app" "$previous_app" || return 1
  put_in_place config "$staged_config" "$config" "$previous_config" || return 1
  put_in_place agent "$staged_agent" "$agent" "$previous_agent" || return 1
  launchctl bootstrap "$domain" "$agent" || return 1
}

rm -rf "$previous_app"
rm -f "$previous_config" "$previous_agent"
launchctl bootout "$domain/$label" 2>/dev/null || true
switch_to_staged || restore_previous

rm -rf "$previous_app"
rm -f "$previous_config" "$previous_agent"
print -r -- "Installed $app and started it. Label: $label"
