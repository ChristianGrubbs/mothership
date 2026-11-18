#!/usr/bin/env python3
"""Private native Clop optimize sidecar.

Implements the fixed host <-> app contract documented in
docs/ops/studio-clop.md and the spec's "Host <-> app contract" section:
GET /health and POST /optimize, stdlib only, one Clop process at a time.

This file is deployed standalone into the service state directory by
tools/studio/clop_service.py and run with the system python3 (3.9+
compatible); it must not import anything outside the standard library.
"""

from __future__ import annotations

import json
import mimetypes
import os
import pathlib
import signal
import subprocess
import tempfile
import threading
import time
import uuid
from datetime import datetime, timezone
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Mapping, Optional, Sequence
from urllib.parse import parse_qs, urlparse

DEFAULT_CLOP_BIN = str(pathlib.Path.home() / ".local/bin/clop")
DEFAULT_MAX_BYTES = 2 * 1024 * 1024 * 1024  # 2 GiB
DEFAULT_TIMEOUT = 900

IMAGE_SCALES = ("1", "0.75", "0.5")
VIDEO_SCALES = IMAGE_SCALES
VIDEO_COMPRESSIONS = ("25", "50", "75")
VIDEO_ENCODERS = ("hardware", "software")
BOOL_FLAGS = ("0", "1")

# Cheap host-side first line of defence: the app enforces its own per-family
# extension allowlist and MIME sniff, but the host never even hands back an
# output whose extension falls outside what the product actually supports.
ALLOWED_OUTPUT_EXTENSIONS = {
    "image": {"jpg", "jpeg", "png"},
    "video": {"mp4", "mov"},
    "pdf": {"pdf"},
}


class InvalidOption(ValueError):
    pass


class ServerConfig:
    def __init__(self) -> None:
        self.clop_bin = pathlib.Path(os.environ.get("CLOP_BIN", DEFAULT_CLOP_BIN))
        self.host = os.environ.get("CLOP_HOST", "127.0.0.1")
        self.port = int(os.environ.get("CLOP_PORT", "18766"))
        self.max_bytes = int(os.environ.get("CLOP_MAX_BYTES", str(DEFAULT_MAX_BYTES)))
        self.timeout = int(os.environ.get("CLOP_TIMEOUT", str(DEFAULT_TIMEOUT)))
        self.app_path = pathlib.Path(os.environ.get("CLOP_APP_PATH", "/Applications/Clop.app"))


class EventLog:
    def __init__(self) -> None:
        self.enabled = os.environ.get("CLOP_SERVICE_LOG", "").lower() in {"1", "true", "yes", "on"}
        default_path = pathlib.Path(__file__).resolve().parent / "events.jsonl"
        self.path = pathlib.Path(os.environ.get("CLOP_SERVICE_LOG_PATH", str(default_path)))

    def write(self, event: str, ctx: Optional[Mapping[str, object]] = None, level: str = "info") -> None:
        if not self.enabled:
            return
        self.path.parent.mkdir(parents=True, exist_ok=True)
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "run_id": (ctx or {}).get("run_id", ""),
            "level": level,
            "event": event,
            "loc": "studio.clop_service.server",
            "ctx": dict(ctx or {}),
        }
        with self.path.open("a", encoding="utf-8") as sink:
            sink.write(json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n")


def _clop_version(app_path: pathlib.Path) -> Optional[str]:
    info_plist = app_path / "Contents" / "Info.plist"
    if not info_plist.is_file():
        return None
    try:
        result = subprocess.run(
            ["/usr/bin/plutil", "-extract", "CFBundleShortVersionString", "raw", "-o", "-", str(info_plist)],
            check=False,
            capture_output=True,
            text=True,
            timeout=5,
        )
    except (OSError, subprocess.SubprocessError):
        return None
    if result.returncode != 0:
        return None
    version = result.stdout.strip()
    return version or None


def _build_args(type_: str, options: Mapping[str, str]) -> list:
    if type_ == "image":
        scale = options.get("scale", "1")
        if scale not in IMAGE_SCALES:
            raise InvalidOption("scale")
        adaptive = options.get("adaptive", "0")
        if adaptive not in BOOL_FLAGS:
            raise InvalidOption("adaptive")
        args = ["--downscale-factor", scale]
        if adaptive == "1":
            args += ["--compression", "adaptive"]
        return args
    if type_ == "video":
        compression = options.get("compression", "50")
        if compression not in VIDEO_COMPRESSIONS:
            raise InvalidOption("compression")
        encoder = options.get("encoder", "hardware")
        if encoder not in VIDEO_ENCODERS:
            raise InvalidOption("encoder")
        scale = options.get("scale", "1")
        if scale not in VIDEO_SCALES:
            raise InvalidOption("scale")
        remove_audio = options.get("remove_audio", "0")
        if remove_audio not in BOOL_FLAGS:
            raise InvalidOption("remove_audio")
        args = ["--compression", compression, "--encoder", encoder, "--downscale-factor", scale]
        if remove_audio == "1":
            args.append("--remove-audio")
        return args
    if type_ == "pdf":
        return ["--dpi", "adaptive"]
    raise InvalidOption("type")


class ClopTimeout(RuntimeError):
    pass


def _run_clop(command: Sequence[str], timeout: int) -> "subprocess.CompletedProcess[str]":
    process = subprocess.Popen(
        list(command),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        start_new_session=True,
    )
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError):
            pass
        process.communicate()
        raise ClopTimeout(f"clop timed out after {timeout}s")
    return subprocess.CompletedProcess(command, process.returncode, stdout, stderr)


class Handler(BaseHTTPRequestHandler):
    server_version = "MothershipClop/1.0"
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {self.address_string()} {fmt % args}")

    def _json(self, status: int, data: object, extra_headers: Optional[Mapping[str, str]] = None) -> None:
        body = json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        for key, value in (extra_headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(body)

    def _binary(self, status: int, body: bytes, content_type: str, extra_headers: Mapping[str, str]) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        for key, value in extra_headers.items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path != "/health":
            return self._json(HTTPStatus.NOT_FOUND, {"error": "not_found"})
        config: ServerConfig = self.server.config  # type: ignore[attr-defined]
        if not (config.clop_bin.is_file() and os.access(config.clop_bin, os.X_OK)):
            return self._json(HTTPStatus.SERVICE_UNAVAILABLE, {"ok": False, "error": "clop_missing"})
        version = _clop_version(config.app_path)
        return self._json(HTTPStatus.OK, {"ok": True, "clop": str(config.clop_bin), "version": version})

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path != "/optimize":
            return self._json(HTTPStatus.NOT_FOUND, {"error": "not_found"})
        self._optimize(parsed)

    def _optimize(self, parsed) -> None:
        config: ServerConfig = self.server.config  # type: ignore[attr-defined]
        event_log: EventLog = self.server.event_log  # type: ignore[attr-defined]

        # Structural checks first (no request body has been read yet): once we know
        # Content-Length we can always drain exactly that many bytes before an error
        # response, so a kept-alive connection is never left desynchronized by an
        # unread body. Only when Content-Length itself is missing/invalid do we not
        # know how much to drain, so those paths close the connection instead.
        length_header = self.headers.get("Content-Length")
        if length_header is None or not length_header.isdigit():
            self.close_connection = True
            return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "invalid_option"})
        content_length = int(length_header)
        if content_length > config.max_bytes:
            self.close_connection = True
            return self._json(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, {"error": "payload_too_large"})

        query = {key: values[0] for key, values in parse_qs(parsed.query).items()}
        type_ = query.get("type")
        filename = self.headers.get("X-Clop-Filename")
        try:
            args = _build_args(type_, query) if type_ in ("image", "video", "pdf") else None
        except InvalidOption:
            args = None
        if args is None or not filename:
            self.rfile.read(content_length)
            return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "invalid_option"})

        body = self.rfile.read(content_length)
        ext = pathlib.Path(filename).suffix.lstrip(".").lower() or "bin"
        run_id = uuid.uuid4().hex
        workdir = tempfile.mkdtemp(prefix="clop-")
        try:
            in_path = pathlib.Path(workdir) / f"in.{ext}"
            in_path.write_bytes(body)
            out_prefix = pathlib.Path(workdir) / "out"
            command = [str(config.clop_bin), "optimise", type_, *args, "-n", "-j", "-o", str(out_prefix), str(in_path)]
            old_bytes = len(body)
            event_log.write("optimize.start", {"run_id": run_id, "type": type_})
            try:
                with self.server.lock:  # type: ignore[attr-defined]
                    result = _run_clop(command, timeout=config.timeout)
            except ClopTimeout:
                event_log.write("optimize.timeout", {"run_id": run_id}, level="error")
                return self._json(HTTPStatus.GATEWAY_TIMEOUT, {"error": "timeout"})

            try:
                payload = json.loads(result.stdout)
            except json.JSONDecodeError:
                event_log.write(
                    "optimize.parse_error",
                    {"run_id": run_id, "returncode": result.returncode, "stderr": result.stderr[-4000:]},
                    level="error",
                )
                return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "optimize_failed"})

            failed = payload.get("failed") or []
            if failed:
                event_log.write(
                    "optimize.failed",
                    {"run_id": run_id, "errors": [entry.get("error") for entry in failed]},
                    level="error",
                )
                return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "optimize_failed"})

            done = payload.get("done") or []
            if not done:
                event_log.write("optimize.no_reduction", {"run_id": run_id, "reason": "empty_done"})
                return self._json(
                    HTTPStatus.OK,
                    {"outcome": "no_reduction", "old_bytes": old_bytes},
                    extra_headers={"X-Clop-Outcome": "no_reduction"},
                )

            entry = done[0]
            raw_path = entry.get("path")
            out_path = pathlib.Path(raw_path) if raw_path else None
            if out_path is None or not out_path.is_file():
                event_log.write("optimize.missing_output", {"run_id": run_id}, level="error")
                return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "optimize_failed"})

            out_bytes = out_path.read_bytes()
            # Clop can report success and leave a 0-byte file (a 6K VideoToolbox encode it could not start,
            # observed 2026-08-05); an empty result is a failure, never a smaller copy.
            if not out_bytes:
                event_log.write("optimize.empty_output", {"run_id": run_id}, level="error")
                return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "optimize_failed"})

            out_ext = out_path.suffix.lstrip(".").lower()
            if out_ext not in ALLOWED_OUTPUT_EXTENSIONS.get(type_, set()):
                event_log.write(
                    "optimize.disallowed_extension", {"run_id": run_id, "extension": out_ext}, level="error"
                )
                return self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "optimize_failed"})

            # Decide "not smaller" from the bytes actually on disk, never from Clop's
            # self-reported oldBytes/newBytes: those are Clop's own accounting and are
            # not trusted for this comparison.
            new_bytes = len(out_bytes)
            if new_bytes >= old_bytes:
                event_log.write("optimize.no_reduction", {"run_id": run_id, "old_bytes": old_bytes, "new_bytes": new_bytes})
                return self._json(
                    HTTPStatus.OK,
                    {"outcome": "no_reduction", "old_bytes": old_bytes},
                    extra_headers={"X-Clop-Outcome": "no_reduction"},
                )

            content_type = mimetypes.guess_type(out_path.name)[0] or "application/octet-stream"
            headers = {
                "X-Clop-Old-Bytes": str(old_bytes),
                "X-Clop-New-Bytes": str(new_bytes),
                "X-Clop-Extension": out_ext,
            }
            width_height = entry.get("newWidthHeight")
            if isinstance(width_height, (list, tuple)) and len(width_height) == 2:
                headers["X-Clop-Width"] = str(width_height[0])
                headers["X-Clop-Height"] = str(width_height[1])
            event_log.write("optimize.success", {"run_id": run_id, "old_bytes": old_bytes, "new_bytes": new_bytes})
            return self._binary(HTTPStatus.OK, out_bytes, content_type, headers)
        finally:
            import shutil

            shutil.rmtree(workdir, ignore_errors=True)


class ClopHTTPServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, handler, config: ServerConfig, event_log: EventLog) -> None:
        super().__init__(address, handler)
        self.config = config
        self.event_log = event_log
        self.lock = threading.Lock()


def main() -> int:
    config = ServerConfig()
    event_log = EventLog()
    server = ClopHTTPServer((config.host, config.port), Handler, config, event_log)
    print(f"clop service listening on http://{config.host}:{config.port} (clop={config.clop_bin})")
    server.serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
