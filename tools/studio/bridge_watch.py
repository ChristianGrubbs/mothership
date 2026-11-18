#!/usr/bin/env python3
"""Restart a crashed optimizer or AI bridge on the Studio (docs/ops/studio-runtime.md, "Bridge watch").

The bridges are started once by ``runtime.py up`` (at login and on every deploy) and nothing else restarts
them, so a crash used to last until the next reboot or deploy. launchd runs this every five minutes
(app.themothership.bridge-watch). It only looks: a bridge whose /health fails on two checks in a row is
handed to its own manager's idempotent ``up`` through Terminal, the way the autostart starts the stack,
so the restarted process gets the same Local Network standing as one started at login.

It never restarts a bridge while the stack is down. A down stack is its own case: when the public URL also
fails on two checks in a row, it kickstarts the login autostart (app.themothership.autostart), which
reruns the full recovery in Terminal -- never while an autostart run is still in progress, never within the
cooldown, and at most three times an hour. One JSONL line per decision goes to the log.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import pathlib
import shlex
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Callable, Dict, List, Optional, Tuple

# label, app.env key, manager script, state directory under the native services root (runtime.MANAGED_BRIDGES)
BRIDGES = (
    ("optimizer", "MOTHERSHIP_OPTIMIZER_API", "clop_service.py", "clop"),
    ("ai", "MOTHERSHIP_AI_API", "ai_service.py", "ai"),
    ("video", "MOTHERSHIP_HOME_VIDEO_API", "video_service.py", "video"),
)
FAILURES_BEFORE_RESTART = 2
COOLDOWN_SECONDS = 15 * 60
FPM_CONTAINER = "mothership-private-fpm"
PUBLIC_URL = "https://mothership.example.com/admin/login"
AUTOSTART_LABEL = "app.themothership.autostart"
AUTOSTART_SCRIPT = "mothership-autostart.command"
AUTOSTART_DONE_EVENTS = ("tunnel.ready", "autostart.failed")
AUTOSTART_MAX_RUN_SECONDS = 20 * 60  # a run that logged no end event this long ago is treated as dead
HEAL_MAX_PER_HOUR = 3
RUN_ID = uuid.uuid4().hex[:12]


def log(path: pathlib.Path, level: str, event: str, **ctx: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    line = {
        "ts": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
        "run_id": RUN_ID,
        "level": level,
        "event": event,
        "loc": "bridge_watch",
        "ctx": ctx,
    }
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(line) + "\n")


def read_env(path: pathlib.Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def endpoint(url: str) -> Optional[Tuple[str, int]]:
    parsed = urllib.parse.urlparse(url)
    if not parsed.hostname or not parsed.port:
        return None
    return parsed.hostname, parsed.port


def healthy(host: str, port: int, timeout: float = 4.0) -> bool:
    try:
        with urllib.request.urlopen("http://{}:{}/health".format(host, port), timeout=timeout) as response:
            body = json.loads(response.read().decode("utf-8") or "{}")
            return response.status == 200 and body.get("ok") is not False
    except (OSError, ValueError, urllib.error.URLError):
        return False


def stack_running(container_bin: str) -> bool:
    try:
        result = subprocess.run([container_bin, "ls"], check=False, capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.SubprocessError):
        return False
    return result.returncode == 0 and any(line.split()[:1] == [FPM_CONTAINER] for line in result.stdout.splitlines())


def public_up(url: str = PUBLIC_URL, timeout: float = 10.0) -> bool:
    """True when the public URL answers 2xx/3xx (redirects are not followed)."""

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *_args, **_kwargs):  # type: ignore[override]
            return None

    try:
        with urllib.request.build_opener(NoRedirect).open(url, timeout=timeout) as response:
            return 200 <= response.status < 400
    except urllib.error.HTTPError as error:
        return 300 <= error.code < 400
    except (OSError, ValueError, urllib.error.URLError):
        return False


def autostart_process_alive() -> bool:
    try:
        result = subprocess.run(["/usr/bin/pgrep", "-f", AUTOSTART_SCRIPT], check=False, capture_output=True, timeout=10)
    except (OSError, subprocess.SubprocessError):
        return False
    return result.returncode == 0


def autostart_in_progress(log_path: pathlib.Path, now: float, process_alive: Callable[[], bool]) -> Tuple[bool, str]:
    """Whether the newest autostart run (by its JSONL log) has begun and not yet ended."""
    try:
        lines = log_path.read_text(encoding="utf-8").splitlines()[-200:]
    except OSError:
        return process_alive(), "no_log"
    events = []
    for raw in lines:
        try:
            events.append(json.loads(raw))
        except ValueError:
            continue
    if not events:
        return process_alive(), "empty_log"
    latest = events[-1].get("run_id")
    run_events = [item for item in events if item.get("run_id") == latest]
    if any(item.get("event") in AUTOSTART_DONE_EVENTS for item in run_events):
        return False, "last_run_ended"
    try:
        began = datetime.datetime.strptime(run_events[0]["ts"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc).timestamp()
    except (KeyError, ValueError):
        return process_alive(), "unparsed_ts"
    if now - began < AUTOSTART_MAX_RUN_SECONDS:
        return True, "last_run_open"
    return process_alive(), "last_run_stale"


def heal_decision(state: Dict[str, dict], public_ok: bool, running: bool, now: float) -> str:
    """'public_ok', 'wait', 'autostart_running', 'cooldown', 'hourly_cap' or 'kickstart'; updates state in place."""
    entry = state.setdefault("_stack", {"failures": 0, "kicks": []})
    entry["kicks"] = [float(t) for t in entry.get("kicks", []) if now - float(t) < 3600]
    if public_ok:
        entry["failures"] = 0
        return "public_ok"
    entry["failures"] = int(entry.get("failures", 0)) + 1
    if entry["failures"] < FAILURES_BEFORE_RESTART:
        return "wait"
    if running:
        return "autostart_running"
    if entry["kicks"] and now - max(entry["kicks"]) < COOLDOWN_SECONDS:
        return "cooldown"
    if len(entry["kicks"]) >= HEAL_MAX_PER_HOUR:
        return "hourly_cap"
    entry["kicks"].append(now)
    return "kickstart"


def heal_stack(args: argparse.Namespace, state: Dict[str, dict], log_path: pathlib.Path, public_probe: Callable[[str], bool],
               launch: Callable[[List[str]], int], now: float, process_alive: Callable[[], bool]) -> None:
    public_ok = public_probe(args.public_url)
    running, why = autostart_in_progress(args.autostart_log.expanduser(), now, process_alive)
    verdict = heal_decision(state, public_ok, running, now)
    entry = state["_stack"]
    level = "info" if verdict == "public_ok" else "warning"
    log(log_path, level, "stack.heal_decision", reason="stack_not_running", public_ok=public_ok, failures=entry["failures"],
        autostart_running=running, autostart_state=why, kicks_last_hour=len(entry["kicks"]), action=verdict, dry_run=args.dry_run)
    if verdict != "kickstart":
        return
    argv = ["/bin/launchctl", "kickstart", "gui/{}/{}".format(args.uid, AUTOSTART_LABEL)]
    if args.dry_run:
        entry["kicks"].pop()
        log(log_path, "info", "stack.kickstart_skipped", reason="dry_run", argv=argv)
        return
    code = launch(argv)
    log(log_path, "error" if code else "info", "stack.kickstart_requested", argv=argv, returncode=code)


def restart_command(python: str, source: pathlib.Path, native: pathlib.Path, manager: str, state_name: str, host: str, port: int) -> str:
    argv = [
        python, str(source / "tools" / "studio" / manager), "up",
        "--source", str(source), "--state", str(native / state_name), "--host", host, "--port", str(port),
    ]
    return "#!/bin/zsh\n# Written by bridge_watch.py; safe to delete.\n" + " ".join(shlex.quote(part) for part in argv) + "\n"


def decide(state: Dict[str, dict], label: str, is_healthy: bool, now: float) -> str:
    """'ok', 'wait' (failing, not yet twice or in cooldown) or 'restart'; updates state in place."""
    entry = state.setdefault(label, {"failures": 0, "restarted_at": 0.0})
    if is_healthy:
        entry["failures"] = 0
        return "ok"
    entry["failures"] = int(entry.get("failures", 0)) + 1
    if entry["failures"] < FAILURES_BEFORE_RESTART or now - float(entry.get("restarted_at", 0.0)) < COOLDOWN_SECONDS:
        return "wait"
    entry["restarted_at"] = now
    return "restart"


def run(
    args: argparse.Namespace,
    probe: Callable[[str, int], bool] = healthy,
    stack_up: Callable[[str], bool] = stack_running,
    launch: Callable[[List[str]], int] = lambda argv: subprocess.run(argv, check=False, timeout=60).returncode,
    now: Callable[[], float] = time.time,
    public_probe: Callable[[str], bool] = public_up,
    process_alive: Callable[[], bool] = autostart_process_alive,
) -> int:
    log_path = args.log.expanduser()
    state_path = args.state_file.expanduser()
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        state = {}
    if args.simulate_down or not stack_up(args.container_bin):
        if args.simulate_down:
            log(log_path, "info", "watch.simulated_down", dry_run=args.dry_run)
        heal_stack(args, state, log_path, public_probe, launch, now(), process_alive)
        state_path.parent.mkdir(parents=True, exist_ok=True)
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 0
    state.setdefault("_stack", {"failures": 0, "kicks": []})["failures"] = 0
    try:
        environment = read_env(args.app_env.expanduser())
    except OSError as error:
        log(log_path, "error", "watch.env_unreadable", path=str(args.app_env), error=type(error).__name__)
        return 1

    for label, key, manager, state_name in BRIDGES:
        target = endpoint(environment.get(key, ""))
        if target is None:
            log(log_path, "debug" if key not in environment else "warning", "bridge.unconfigured", bridge=label)
            continue
        host, port = target
        verdict = decide(state, label, probe(host, port), now())
        if verdict == "ok":
            continue
        log(log_path, "warning", "bridge.unhealthy", bridge=label, port=port, failures=state[label]["failures"], action=verdict)
        if verdict != "restart":
            continue
        script = args.work_dir.expanduser() / "restart-{}.command".format(label)
        script.parent.mkdir(parents=True, exist_ok=True)
        script.write_text(restart_command(args.python, args.source, args.native, manager, state_name, host, port), encoding="utf-8")
        script.chmod(0o755)
        code = launch(["/usr/bin/open", "-a", "Terminal", str(script)])
        log(log_path, "error" if code else "info", "bridge.restart_requested", bridge=label, port=port, returncode=code)

    state_path.parent.mkdir(parents=True, exist_ok=True)
    state_path.write_text(json.dumps(state), encoding="utf-8")
    return 0


def parse_args(argv: Optional[List[str]] = None) -> argparse.Namespace:
    home = pathlib.Path.home()
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--source", type=pathlib.Path, default=home / "Studio Workspaces/Mothership/source")
    parser.add_argument("--native", type=pathlib.Path, default=home / "Studio Data/Mothership Native Services")
    parser.add_argument("--app-env", type=pathlib.Path, default=home / "Studio Data/Mothership Private/app.env")
    parser.add_argument("--work-dir", type=pathlib.Path, default=home / "Library/Application Support/The Mothership Bridge Watch")
    parser.add_argument("--state-file", type=pathlib.Path, default=home / "Library/Application Support/The Mothership Bridge Watch/state.json")
    parser.add_argument("--log", type=pathlib.Path, default=home / "Library/Logs/mothership-bridge-watch.jsonl")
    parser.add_argument("--container-bin", default="/opt/homebrew/bin/container")
    parser.add_argument("--python", default=sys.executable)
    parser.add_argument("--public-url", default=PUBLIC_URL)
    parser.add_argument("--autostart-log", type=pathlib.Path, default=home / "Library/Logs/mothership-autostart.jsonl")
    parser.add_argument("--uid", type=int, default=os.getuid())
    parser.add_argument("--dry-run", action="store_true", help="log the stack-heal decision but never kickstart")
    parser.add_argument("--simulate-down", action="store_true", help="treat the stack as down (pair with --dry-run)")
    return parser.parse_args(argv)


if __name__ == "__main__":
    sys.exit(run(parse_args()))
