#!/usr/bin/env python3
"""Deploy and manage the private native Clop optimize sidecar.

Digest-deployed source, an owned-process PID receipt, idempotent
up/down/status/verify. No venv is needed (the deployed server.py is
stdlib-only and runs under the system python3).
"""

from __future__ import annotations

import argparse
import contextlib
import fcntl
import hashlib
import ipaddress
import json
import os
import pathlib
import re
import shutil
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Mapping, Optional, Sequence

DEFAULT_CLOP_BIN = str(pathlib.Path.home() / ".local/bin/clop")
DEFAULT_MAX_BYTES = 2 * 1024 * 1024 * 1024  # 2 GiB
DEFAULT_TIMEOUT = 900
DEFAULT_PORT = 18766


class ServiceFailure(RuntimeError):
    pass


@dataclass(frozen=True)
class ServiceConfig:
    source: pathlib.Path
    state: pathlib.Path
    host: str
    port: int
    clop_bin: pathlib.Path
    max_bytes: int
    timeout: int

    @property
    def runtime(self) -> pathlib.Path:
        return self.state / "runtime" / "clop-service"

    @property
    def pid_file(self) -> pathlib.Path:
        return self.state / "run" / "clop-service.json"

    @property
    def log_file(self) -> pathlib.Path:
        return self.state / "logs" / "clop-service.log"

    @property
    def lifecycle_log(self) -> pathlib.Path:
        return self.state / "logs" / "clop-service-events.jsonl"

    @property
    def url(self) -> str:
        return f"http://{self.host}:{self.port}"


class EventLog:
    def __init__(self, state: pathlib.Path):
        self.enabled = os.environ.get("CLOP_SERVICE_LOG", "").lower() in {"1", "true", "yes", "on"}
        self.path = state / "manager-events.jsonl"
        self.run_id = uuid.uuid4().hex

    def write(self, event: str, ctx: Optional[Mapping[str, object]] = None) -> None:
        if not self.enabled:
            return
        self.path.parent.mkdir(parents=True, exist_ok=True)
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "run_id": self.run_id,
            "level": "info",
            "event": event,
            "loc": "studio.clop_service",
            "ctx": dict(ctx or {}),
        }
        with self.path.open("a", encoding="utf-8") as sink:
            sink.write(json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n")


def _validate_private_bind(host: str) -> None:
    try:
        address = ipaddress.ip_address(host)
    except ValueError as error:
        raise ServiceFailure("clop service host must be an IP address") from error
    if address.is_unspecified or not (address.is_private or address.is_loopback):
        raise ServiceFailure("clop service host must be a private or loopback address")


def _atomic_json(path: pathlib.Path, payload: Mapping[str, object]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(dict(payload), indent=2, sort_keys=True) + "\n", encoding="utf-8")
    temporary.replace(path)


def _read_pid(config: ServiceConfig) -> Optional[dict]:
    try:
        payload = json.loads(config.pid_file.read_text(encoding="utf-8"))
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        return None
    return payload if isinstance(payload, dict) else None


def _process_command(pid: int) -> str:
    result = subprocess.run(
        ["/bin/ps", "-p", str(pid), "-o", "command="],
        check=False,
        capture_output=True,
        text=True,
        timeout=5,
    )
    return result.stdout.strip() if result.returncode == 0 else ""


def _python_executable() -> str:
    return shutil.which("python3") or "/usr/bin/python3"


_INTERPRETER = re.compile(r"^(?:.*/)?[Pp]ython[\w.]*((?:\s+-[A-Za-z]+)*)$")


def _is_python_command(command: str, script: str) -> bool:
    """True for `<python interpreter> [flags] <script>` with the script as its own final argument.

    `ps` joins argv with spaces and the state paths contain spaces, so match the script as the
    command's suffix behind a whitespace boundary and inspect only what precedes it: a python
    interpreter path (which may contain spaces) plus optional short flags. `-c`/`-m` run other
    code, and `python3 /tmp/other<script>` or `tail -f <script>` are not this service.
    """
    if not command.endswith(" " + script):
        return False
    match = _INTERPRETER.match(command[: -len(script) - 1])
    if not match:
        return False
    return not any(set(flag[1:]) & {"c", "m"} for flag in match.group(1).split())


def _owned_process(config: ServiceConfig, payload: Optional[dict] = None) -> Optional[int]:
    payload = payload or _read_pid(config)
    if not payload:
        return None
    try:
        pid = int(payload["pid"])
    except (KeyError, TypeError, ValueError):
        return None
    command = _process_command(pid)
    if not command:
        return None
    # Ownership is the deployed script path. The interpreter path is not compared:
    # on macOS `ps` shows the resolved .../Python.app/Contents/MacOS/Python, never
    # the recorded `python3` shim path.
    expected_script = str(config.runtime / "server.py")
    if not _is_python_command(command, expected_script):
        return None
    return pid


def _health(config: ServiceConfig, timeout: float = 3) -> Optional[dict]:
    try:
        with urllib.request.urlopen(f"{config.url}/health", timeout=timeout) as response:
            payload = json.loads(response.read())
    except (OSError, urllib.error.URLError, json.JSONDecodeError):
        return None
    except urllib.error.HTTPError as error:
        try:
            return json.loads(error.read())
        except (json.JSONDecodeError, OSError):
            return None
    return payload if isinstance(payload, dict) else None


def _source_digest(source: pathlib.Path) -> str:
    digest = hashlib.sha256()
    path = source / "server.py"
    digest.update(b"server.py")
    digest.update(path.read_bytes())
    return digest.hexdigest()


def deploy(config: ServiceConfig) -> str:
    source = config.source / "tools" / "studio" / "clop-service"
    server_source = source / "server.py"
    if not server_source.is_file():
        raise ServiceFailure(f"missing sidecar source: {server_source}")
    config.runtime.mkdir(parents=True, exist_ok=True)
    shutil.copy2(server_source, config.runtime / "server.py")
    return _source_digest(source)


def status(config: ServiceConfig) -> dict:
    payload = _read_pid(config)
    pid = _owned_process(config, payload)
    health = _health(config) if pid else None
    return {
        "running": pid is not None,
        "healthy": bool(health and health.get("ok") is True),
        "pid": pid,
        "host": config.host,
        "port": config.port,
        "clop": health.get("clop") if health else None,
        "version": health.get("version") if health else None,
        "source_digest": payload.get("source_digest") if payload else None,
    }


def stop(config: ServiceConfig, log: EventLog) -> dict:
    payload = _read_pid(config)
    pid = _owned_process(config, payload)
    if pid is None:
        return status(config)
    log.write("service.stopping", {"pid": pid})
    os.kill(pid, signal.SIGTERM)
    deadline = time.monotonic() + 20
    while _owned_process(config, payload) and time.monotonic() < deadline:
        time.sleep(0.25)
    if _owned_process(config, payload):
        os.kill(pid, signal.SIGKILL)
    config.pid_file.unlink(missing_ok=True)
    log.write("service.stopped", {"pid": pid})
    return status(config)


@contextlib.contextmanager
def _exclusive(config: ServiceConfig):
    """Serialize overlapping `up` calls so only one checks, spawns and writes the receipt."""
    lock_path = config.state / "run" / "clop-service.lock"
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    with lock_path.open("a") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def up(config: ServiceConfig, log: EventLog) -> dict:
    with _exclusive(config):
        return _up(config, log)


def _up(config: ServiceConfig, log: EventLog) -> dict:
    _validate_private_bind(config.host)
    source_digest = deploy(config)
    current = status(config)
    payload = _read_pid(config)
    if current["healthy"] and payload and payload.get("source_digest") == source_digest:
        log.write("service.already_running", {"pid": current["pid"], "host": config.host, "port": config.port})
        return current
    if current["running"]:
        stop(config, log)
    if _health(config) is not None:
        # Something we do not own already answers on this address; never spawn a
        # duplicate or overwrite the receipt.
        log.write("service.foreign_listener", {"host": config.host, "port": config.port})
        raise ServiceFailure(
            f"clop service already running at {config.url} but not owned by this receipt; "
            "stop that process or choose another --port"
        )

    if not config.clop_bin.is_file():
        raise ServiceFailure(f"missing native service dependency: {config.clop_bin}")
    config.log_file.parent.mkdir(parents=True, exist_ok=True)
    config.lifecycle_log.parent.mkdir(parents=True, exist_ok=True)

    python = _python_executable()
    environment = os.environ.copy()
    environment.update(
        {
            "CLOP_BIN": str(config.clop_bin),
            "CLOP_HOST": config.host,
            "CLOP_PORT": str(config.port),
            "CLOP_MAX_BYTES": str(config.max_bytes),
            "CLOP_TIMEOUT": str(config.timeout),
            "CLOP_SERVICE_LOG_PATH": str(config.lifecycle_log),
        }
    )
    command = [python, str(config.runtime / "server.py")]
    with config.log_file.open("ab", buffering=0) as output:
        process = subprocess.Popen(
            command,
            cwd=config.runtime,
            env=environment,
            stdin=subprocess.DEVNULL,
            stdout=output,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
    _atomic_json(
        config.pid_file,
        {
            "pid": process.pid,
            "python": python,
            "host": config.host,
            "port": config.port,
            "source_digest": source_digest,
            "started_at": datetime.now(timezone.utc).isoformat(),
        },
    )
    log.write("service.started", {"pid": process.pid, "host": config.host, "port": config.port})
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise ServiceFailure(f"clop service exited with status {process.returncode}; inspect {config.log_file}")
        health = _health(config)
        if health and health.get("ok") is True:
            return status(config)
        time.sleep(0.5)
    raise ServiceFailure(f"clop service did not become healthy; inspect {config.log_file}")


def verify(config: ServiceConfig, *, timeout: int) -> dict:
    deadline = time.monotonic() + timeout
    last = None
    while time.monotonic() < deadline:
        last = _health(config)
        if last and last.get("ok") is True:
            return {
                "ok": True,
                "clop": last.get("clop"),
                "version": last.get("version"),
                "host": config.host,
                "port": config.port,
            }
        time.sleep(1)
    detail = last or {"health": "unavailable"}
    raise ServiceFailure(f"clop service did not become healthy before timeout: {detail}")


def _parser() -> argparse.ArgumentParser:
    project = pathlib.Path(__file__).resolve().parents[2]
    default_state = pathlib.Path.home() / "Studio Data" / "Mothership Native Services" / "clop"
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("up", "down", "status", "verify"))
    parser.add_argument("--source", type=pathlib.Path, default=project)
    parser.add_argument("--state", type=pathlib.Path, default=default_state)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    parser.add_argument("--clop-bin", type=pathlib.Path, default=pathlib.Path(DEFAULT_CLOP_BIN))
    parser.add_argument("--max-bytes", type=int, default=DEFAULT_MAX_BYTES)
    parser.add_argument("--timeout", type=int, default=DEFAULT_TIMEOUT)
    return parser


def main(argv: Optional[Sequence[str]] = None) -> int:
    args = _parser().parse_args(argv)
    config = ServiceConfig(
        source=args.source.resolve(),
        state=args.state.resolve(),
        host=args.host,
        port=args.port,
        clop_bin=args.clop_bin,
        max_bytes=args.max_bytes,
        timeout=args.timeout,
    )
    log = EventLog(config.state)
    try:
        if args.command == "up":
            result = up(config, log)
        elif args.command == "down":
            with _exclusive(config):
                result = stop(config, log)
        elif args.command == "verify":
            result = verify(config, timeout=args.timeout)
        else:
            result = status(config)
    except (OSError, ServiceFailure, urllib.error.URLError) as error:
        print(f"clop service: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
