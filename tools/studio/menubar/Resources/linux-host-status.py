"""Reads The Mothership's stack on a Linux host for the menu bar app, and changes nothing there.

The app runs it over SSH as `python3 - --home <dir> [--stats]`, with this file on standard input.
It prints one JSON object. From `app.env` it reads only the three bridge address keys, and only
their health answers leave the host. Every field it cannot read is null, so the app can tell a
part that is down from a part it could not read. The reads run side by side and the report is
printed within DEADLINE seconds, whatever is still hanging, so a stalled Docker reads as
unreadable containers and not as an unreachable host.
"""

import json
import os
import re
import socket
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime

STACK_PREFIX = "mothership-private-"
BRIDGE_KEYS = (
    ("MOTHERSHIP_OPTIMIZER_API", "Optimizer"),
    ("MOTHERSHIP_AI_API", "AI"),
    ("MOTHERSHIP_HOME_VIDEO_API", "Video"),
)
ORIGIN_URL = "http://127.0.0.1:18090/up"
TUNNEL_READY_URL = "http://127.0.0.1:20241/ready"
OLLAMA_PORT = 11434
BODY_LIMIT = 4096
# The app's SSH call allows 30 seconds, and reaching the host through the jump host takes a few.
DEADLINE = 18
COMMAND_TIMEOUT = 15
PROJECT = "mothership"
# Compose commands that change the stack's containers.
CHANGING_ACTIONS = {"up", "down", "stop", "start", "restart", "rm", "kill", "create"}
# Compose's global options that take a value as the next word.
COMPOSE_VALUE_OPTIONS = {
    "-f", "--file", "--env-file", "--profile", "--project-directory", "--ansi", "--progress", "--parallel",
}

opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def http(url, timeout=4):
    """Returns (status, body text), or (None, None) when nothing answered."""
    try:
        with opener.open(url, timeout=timeout) as response:
            return response.status, response.read(BODY_LIMIT).decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read(BODY_LIMIT).decode("utf-8", "replace")
    except Exception:
        return None, None


def run(arguments, timeout=COMMAND_TIMEOUT, env=None):
    """Returns standard output, or None when the command failed."""
    try:
        done = subprocess.run(arguments, capture_output=True, text=True, timeout=timeout, check=False, env=env)
    except Exception:
        return None
    return done.stdout if done.returncode == 0 else None


def compose_rows(stack, env):
    """Every container of the project, from `stack.sh ps --all`, or None."""
    text = run([stack, "ps", "--all", "--format", "json"], env=env)
    if text is None:
        return None
    text = text.strip()
    try:
        if text.startswith("["):
            return json.loads(text)
        return [json.loads(line) for line in text.splitlines() if line.strip()]
    except ValueError:
        return None


def stack_services(stack, env):
    """The services whose container is one of the stack's own, from the compose config, or None.

    Membership comes from each service's `container_name`, so a missing tunnel or bridge container
    is never counted, and a missing container of the stack's own counts as stopped.
    """
    text = run([stack, "config", "--format", "json"], env=env)
    try:
        services = json.loads(text)["services"]
    except (TypeError, ValueError, KeyError):
        return None
    if not isinstance(services, dict):
        return None
    return sorted(
        name for name, service in services.items()
        if isinstance(service, dict) and str(service.get("container_name", "")).startswith(STACK_PREFIX)
    )


def containers(services, rows):
    """The stack's own containers. The tunnel and the bridges have rows of their own on the board."""
    if services is None or rows is None:
        return None
    running = {row.get("Service") for row in rows if row.get("State") == "running"}
    return [{"name": service.replace("-", "_"), "running": service in running} for service in services]


def tunnel_connections():
    status, body = http(TUNNEL_READY_URL)
    if not body:
        return None
    try:
        return int(json.loads(body).get("readyConnections"))
    except (ValueError, TypeError, AttributeError):
        return None


def tunnel(rows, connections):
    """With the container list unreadable, a readiness answer is the sign the connector runs."""
    if rows is None:
        running = connections is not None
    else:
        running = any(row.get("Service") == "tunnel" and row.get("State") == "running" for row in rows)
    return {"running": running, "readyConnections": connections}


def bridge_addresses(app_env):
    """The three bridge keys from app.env, and nothing else from that file."""
    wanted = dict(BRIDGE_KEYS)
    values = {}
    with open(app_env, encoding="utf-8") as handle:
        for raw in handle:
            line = raw.strip()
            if line.startswith("export "):
                line = line[7:].strip()
            key, equals, value = line.partition("=")
            key = key.strip()
            if not equals or key not in wanted:
                continue
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
                value = value[1:-1]
            values[key] = value
    return [(name, values[key]) for key, name in BRIDGE_KEYS if values.get(key, "").startswith("http://")]


def bridges(app_env):
    try:
        addresses = bridge_addresses(app_env)
    except OSError:
        return None
    found = []
    for name, address in addresses:
        status, body = http(address.rstrip("/") + "/health")
        found.append({"name": name, "status": status, "body": body})
    return found


def default_gateway():
    try:
        with open("/proc/net/route", encoding="ascii") as handle:
            for line in handle.readlines()[1:]:
                fields = line.split()
                if len(fields) > 2 and fields[1] == "00000000":
                    return socket.inet_ntoa(struct.pack("<L", int(fields[2], 16)))
    except (OSError, ValueError):
        return None
    return None


def ollama():
    """The AI bridge reaches Ollama at this host's default gateway, so the check does too."""
    gateway = default_gateway()
    if gateway is None:
        return {"version": None, "loaded": 0}
    base = f"http://{gateway}:{OLLAMA_PORT}"
    version = loaded = None
    status, body = http(base + "/api/version")
    if status == 200 and body:
        try:
            version = json.loads(body).get("version")
        except (ValueError, AttributeError):
            version = None
    status, body = http(base + "/api/ps")
    if status == 200 and body:
        try:
            loaded = len(json.loads(body).get("models") or [])
        except (ValueError, AttributeError, TypeError):
            loaded = None
    return {"version": version, "loaded": loaded or 0}


def changes_stack(argv):
    """Whether a process's arguments are the wrapper's `up` or `stop`, or compose changing this project."""
    names = [os.path.basename(arg) for arg in argv]
    if "stack.sh" in names:
        at = names.index("stack.sh") + 1
        return at < len(argv) and argv[at] in ("up", "stop")
    start = next((i for i, name in enumerate(names) if name in ("docker", "docker-compose")), None)
    if start is None:
        return False
    words = argv[start + 1:]
    if words and words[0] == "compose":
        words = words[1:]
    elif names[start] == "docker":
        return False
    project = None
    index = 0
    while index < len(words):
        word = words[index]
        if word in ("-p", "--project-name"):
            project = words[index + 1] if index + 1 < len(words) else None
            index += 2
        elif word.startswith("--project-name="):
            project = word.split("=", 1)[1]
            index += 1
        elif word.startswith("-p") and not word.startswith("--"):
            project = word[2:]
            index += 1
        elif word in COMPOSE_VALUE_OPTIONS:
            index += 2
        elif word.startswith("-"):
            index += 1
        else:
            return project == PROJECT and word in CHANGING_ACTIONS
    return False


def changing():
    own = str(os.getpid())
    for pid in os.listdir("/proc"):
        if not pid.isdigit() or pid == own:
            continue
        try:
            with open(f"/proc/{pid}/cmdline", "rb") as handle:
                argv = handle.read().decode("utf-8", "replace").split("\0")
        except OSError:
            continue
        if changes_stack([arg for arg in argv if arg]):
            return True
    return False


UNITS = {"b": 1, "kb": 1e3, "mb": 1e6, "gb": 1e9, "tb": 1e12, "kib": 1024, "mib": 1024**2, "gib": 1024**3, "tib": 1024**4}


def size(text):
    match = re.match(r"\s*([0-9.]+)\s*([A-Za-z]+)", text or "")
    if not match or match.group(2).lower() not in UNITS:
        return None
    return int(float(match.group(1)) * UNITS[match.group(2).lower()])


def memory():
    text = run(["docker", "stats", "--no-stream", "--format", "{{json .}}"])
    try:
        rows = [json.loads(line) for line in (text or "").splitlines() if line.strip()]
    except ValueError:
        return None
    used = [size(row.get("MemUsage", "").split("/")[0]) for row in rows if row.get("Name", "").startswith(STACK_PREFIX)]
    return sum(used) if used and None not in used else None


def uptime_from_inspect(text, now):
    """Seconds since nginx started, from `{{.State.Running}} {{.State.StartedAt}}`. None while it is stopped."""
    running, _, started = (text or "").strip().partition(" ")
    if running != "true" or not started:
        return None
    stamp = re.sub(r"\.[0-9]+", "", started).replace("Z", "+00:00")
    try:
        return max(0, int(now - datetime.fromisoformat(stamp).timestamp()))
    except ValueError:
        return None


def uptime():
    text = run(["docker", "inspect", "--format", "{{.State.Running}} {{.State.StartedAt}}", STACK_PREFIX + "nginx"])
    return uptime_from_inspect(text, time.time())


def disk_free(home):
    try:
        disk = os.statvfs(home)
    except OSError:
        return None
    return disk.f_bavail * disk.f_frsize


def main(arguments):
    home = "/srv/mothership"
    if "--home" in arguments and arguments.index("--home") + 1 < len(arguments):
        home = arguments[arguments.index("--home") + 1]
    stack = os.path.join(home, "source/deploy/linux-host/stack.sh")
    env = dict(os.environ, MOTHERSHIP_HOME=home)
    pool = ThreadPoolExecutor(max_workers=9)
    jobs = {
        "rows": pool.submit(compose_rows, stack, env),
        "services": pool.submit(stack_services, stack, env),
        "connections": pool.submit(tunnel_connections),
        "bridges": pool.submit(bridges, os.path.join(home, "state/app.env")),
        "ollama": pool.submit(ollama),
        "origin": pool.submit(lambda: http(ORIGIN_URL)[0]),
        "busy": pool.submit(changing),
    }
    if "--stats" in arguments:
        jobs["memory"] = pool.submit(memory)
        jobs["uptime"] = pool.submit(uptime)
    finish_by = time.monotonic() + DEADLINE

    def result(name, fallback=None):
        try:
            return jobs[name].result(timeout=max(0.0, finish_by - time.monotonic()))
        except Exception:
            return fallback

    rows = result("rows")
    report = {
        "containers": containers(result("services"), rows),
        "tunnel": tunnel(rows, result("connections")),
        "bridges": result("bridges"),
        "ollama": result("ollama", {"version": None, "loaded": 0}),
        "originStatus": result("origin"),
        "busy": result("busy", False),
        "stats": {
            "memoryBytes": result("memory"), "uptimeSeconds": result("uptime"), "diskFreeBytes": disk_free(home),
        } if "--stats" in arguments else None,
    }
    sys.stdout.write(json.dumps(report, separators=(",", ":")) + "\n")
    sys.stdout.flush()
    # A read still hanging past the deadline must not hold the answer back.
    os._exit(0)


if __name__ == "__main__":
    main(sys.argv[1:])
