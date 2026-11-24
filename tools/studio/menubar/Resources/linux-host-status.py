"""Reads The Mothership's stack on a Linux host for the menu bar app, and changes nothing there.

The app runs it over SSH as `python3 - --home <dir> [--stats]`, with this file on standard input.
It prints one JSON object. From `app.env` it reads only the three bridge address keys, and only
their health answers leave the host. Every field it cannot read is null, so the app can tell a
part that is down from a part it could not read.
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
# A deploy or another session changing the stack: the wrapper's `up` or `stop`, or compose run by hand.
CHANGING = (
    re.compile(r"(^|/)stack\.sh (up|stop)( |$)"),
    re.compile(r"docker[- ]compose .*--project-name mothership .* (up|down|stop|restart|rm)( |$)"),
)

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


def run(arguments, timeout=20):
    """Returns standard output, or None when the command failed."""
    try:
        done = subprocess.run(arguments, capture_output=True, text=True, timeout=timeout, check=False)
    except Exception:
        return None
    return done.stdout if done.returncode == 0 else None


def compose_rows(stack):
    """Every container of the project, from `stack.sh ps --all`, or None."""
    text = run([stack, "ps", "--all", "--format", "json"])
    if text is None:
        return None
    text = text.strip()
    try:
        if text.startswith("["):
            return json.loads(text)
        return [json.loads(line) for line in text.splitlines() if line.strip()]
    except ValueError:
        return None


def containers(stack, rows):
    """The stack's own containers. The tunnel and the bridges have rows of their own on the board."""
    services = run([stack, "config", "--services"])
    if services is None or rows is None:
        return None
    by_service = {}
    for row in rows:
        by_service.setdefault(row.get("Service"), []).append(row)
    found = []
    for service in sorted(services.split()):
        mine = by_service.get(service, [])
        if mine and not any(row.get("Name", "").startswith(STACK_PREFIX) for row in mine):
            continue
        running = any(row.get("State") == "running" for row in mine)
        found.append({"name": service.replace("-", "_"), "running": running})
    return found


def tunnel(rows):
    running = any(row.get("Service") == "tunnel" and row.get("State") == "running" for row in rows or [])
    status, body = http(TUNNEL_READY_URL)
    connections = None
    if body:
        try:
            connections = int(json.loads(body).get("readyConnections"))
        except (ValueError, TypeError, AttributeError):
            connections = None
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


def changing():
    own = str(os.getpid())
    for pid in os.listdir("/proc"):
        if not pid.isdigit() or pid == own:
            continue
        try:
            with open(f"/proc/{pid}/cmdline", "rb") as handle:
                command = handle.read().replace(b"\0", b" ").decode("utf-8", "replace").strip()
        except OSError:
            continue
        if any(pattern.search(command) for pattern in CHANGING):
            return True
    return False


UNITS = {"b": 1, "kb": 1e3, "mb": 1e6, "gb": 1e9, "tb": 1e12, "kib": 1024, "mib": 1024**2, "gib": 1024**3, "tib": 1024**4}


def size(text):
    match = re.match(r"\s*([0-9.]+)\s*([A-Za-z]+)", text or "")
    if not match or match.group(2).lower() not in UNITS:
        return None
    return int(float(match.group(1)) * UNITS[match.group(2).lower()])


def stats(home):
    memory = None
    text = run(["docker", "stats", "--no-stream", "--format", "{{json .}}"], timeout=20)
    try:
        rows = [json.loads(line) for line in (text or "").splitlines() if line.strip()]
    except ValueError:
        rows = []
    used = [size(row.get("MemUsage", "").split("/")[0]) for row in rows if row.get("Name", "").startswith(STACK_PREFIX)]
    if used and None not in used:
        memory = sum(used)
    uptime = None
    started = run(["docker", "inspect", "--format", "{{.State.StartedAt}}", STACK_PREFIX + "nginx"], timeout=10)
    if started:
        stamp = re.sub(r"\.[0-9]+", "", started.strip()).replace("Z", "+00:00")
        try:
            uptime = max(0, int(time.time() - datetime.fromisoformat(stamp).timestamp()))
        except ValueError:
            uptime = None
    try:
        disk = os.statvfs(home)
        free = disk.f_bavail * disk.f_frsize
    except OSError:
        free = None
    return {"memoryBytes": memory, "uptimeSeconds": uptime, "diskFreeBytes": free}


def main(arguments):
    home = "/srv/mothership"
    if "--home" in arguments and arguments.index("--home") + 1 < len(arguments):
        home = arguments[arguments.index("--home") + 1]
    stack = os.path.join(home, "source/deploy/linux-host/stack.sh")
    rows = compose_rows(stack)
    origin, _ = http(ORIGIN_URL)
    report = {
        "containers": containers(stack, rows),
        "tunnel": tunnel(rows),
        "bridges": bridges(os.path.join(home, "state/app.env")),
        "ollama": ollama(),
        "originStatus": origin,
        "busy": changing(),
        "stats": stats(home) if "--stats" in arguments else None,
    }
    print(json.dumps(report, separators=(",", ":")))


if __name__ == "__main__":
    main(sys.argv[1:])
