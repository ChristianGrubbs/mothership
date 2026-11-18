#!/usr/bin/env python3
"""Stand-in for the stack wrapper, so the menu bar app can be tested without an outage.

Usage: stand-in-stack.py up|down|status|verify
Environment: STAND_IN_DIR (state folder), STAND_IN_PORT (default 18095), STAND_IN_STEP (seconds per container).
Create $STAND_IN_DIR/fail-up or fail-down to make that command fail.
`up` starts a detached daemon that behaves like a native bridge: it serves /up and /health and
writes a heartbeat file, so a test can prove it outlives the app.
"""
import http.server
import json
import os
import pathlib
import signal
import subprocess
import sys
import time

ROLES = (
    "postgres", "redis", "elasticsearch", "mailpit", "looper", "fpm", "queue", "panorama_queue",
    "stylepress_queue", "vectorization_queue", "ai_index_queue", "scheduler", "nginx",
)
ROOT = pathlib.Path(os.environ["STAND_IN_DIR"])
STATE = ROOT / "state.json"
DAEMON_PID = ROOT / "daemon.pid"
HEARTBEAT = ROOT / "daemon.heartbeat"
PORT = int(os.environ.get("STAND_IN_PORT", "18095"))
STEP = float(os.environ.get("STAND_IN_STEP", "0.4"))


def read_running():
    try:
        return set(json.loads(STATE.read_text()))
    except (OSError, ValueError):
        return set()


def write_running(running):
    STATE.write_text(json.dumps(sorted(running)))


def daemon_alive():
    try:
        os.kill(int(DAEMON_PID.read_text()), 0)
        return True
    except (OSError, ValueError):
        return False


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-Length", "2")
        self.end_headers()
        self.wfile.write(b"ok")

    def log_message(self, *args):
        pass


def serve():
    DAEMON_PID.write_text(str(os.getpid()))
    server = http.server.ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    server.timeout = 1
    while True:
        server.handle_request()
        HEARTBEAT.write_text(str(time.time()))


def up():
    if (ROOT / "fail-up").exists():
        print("fpm did not become ready within 300 seconds", file=sys.stderr)
        return 1
    running = read_running()
    for role in ROLES:
        running.add(role)
        write_running(running)
        print("{}: started".format(role), flush=True)
        time.sleep(STEP)
    if not daemon_alive():
        subprocess.Popen(
            [sys.executable, __file__, "serve"], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, start_new_session=True,
        )
    return 0


def down():
    if (ROOT / "fail-down").exists():
        print("queue did not stop", file=sys.stderr)
        return 1
    running = read_running()
    for role in reversed(ROLES):
        running.discard(role)
        write_running(running)
        time.sleep(STEP)
    if daemon_alive():
        os.kill(int(DAEMON_PID.read_text()), signal.SIGTERM)
    return 0


def status():
    running = read_running()
    print("web_transport: native-publish")
    print("web_relay: stopped")
    for role in ROLES:
        print("{}: {}".format(role, "running 192.0.2.1" if role in running else "missing"))
    return 0


def main():
    ROOT.mkdir(parents=True, exist_ok=True)
    command = sys.argv[1]
    if command == "serve":
        serve()
        return 0
    return {"up": up, "down": down, "status": status, "verify": status}[command]()


if __name__ == "__main__":
    sys.exit(main())
