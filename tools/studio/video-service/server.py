#!/usr/bin/env python3
"""Private native video download sidecar for the Home "Video Downloader" tool.

Runs yt-dlp (the engine the Studio's `yoinks` terminal app drives) with ffmpeg
and keeps a durable, per-person queue, so a download asked for on one device
can be collected on another. Contract (docs/ops/studio-video.md):

    GET    /health
    POST   /probe                 {"url"}
    GET    /jobs?owner=ID
    POST   /jobs                  {"owner", "url", "choice", "title"?}
    GET    /jobs/<id>?owner=ID
    DELETE /jobs/<id>?owner=ID
    GET    /files/<id>?owner=ID   (Range supported)

The format list and the yt-dlp arguments follow yoinks' recipe
(https://github.com/pablostanley/yoinks, MIT). yoinks itself is a full-screen
terminal app with no scriptable mode, so it cannot be driven from here.

Deployed standalone by tools/studio/video_service.py and run with the system
python3 (3.9+ compatible); standard library only.
"""

from __future__ import annotations

import ipaddress
import http.client
import json
import os
import pathlib
import re
import select
import shutil
import signal
import socket
import socketserver
import subprocess
import sys
import threading
import time
import uuid
from datetime import datetime, timezone
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Dict, List, Mapping, Optional, Tuple
from urllib.parse import parse_qs, quote, urlparse

DEFAULT_YTDLP = "/opt/homebrew/bin/yt-dlp"
DEFAULT_FFMPEG_DIR = "/opt/homebrew/bin"
DEFAULT_MAX_BYTES = 2 * 1024 * 1024 * 1024  # 2 GiB per file
DEFAULT_KEEP_SECONDS = 24 * 60 * 60
DEFAULT_WORKERS = 4
DEFAULT_USER_BACKLOG = 25
DEFAULT_JOB_TIMEOUT = 2 * 60 * 60
DEFAULT_MIN_FREE_BYTES = 10 * 1024 * 1024 * 1024
PROBE_TIMEOUT = 75
MAX_PROBES = 4
MAX_VIDEO_CHOICES = 4
COMMON_HEIGHTS = {1080, 720, 480}
MAX_BODY = 8192
PROGRESS_PREFIX = "MSVID|"
PROGRESS_TEMPLATE = (
    "download:" + PROGRESS_PREFIX
    + "%(progress.downloaded_bytes)s|%(progress.total_bytes)s|%(progress.total_bytes_estimate)s"
    + "|%(progress.speed)s|%(progress.eta)s|%(info.format_id)s"  # the format id names the stream in hand
)
# Printed once before the first byte: the size of every stream yt-dlp is about to fetch (a video and its sound
# are usually two), so progress can be measured against the whole file instead of the stream in hand. One record
# per stream: yt-dlp drops a missing value from a plain list, which would lose which stream a size belongs to and
# how many streams there are; the format id is always there, so every stream keeps its place.
PLAN_PREFIX = "MSPLAN|"
PLAN_TEMPLATE = (
    "before_dl:" + PLAN_PREFIX
    + "%(requested_formats.:.{format_id,filesize,filesize_approx})j|%(filesize,filesize_approx)s"
)
ACTIVE = ("queued", "running", "processing", "optimizing")
DEFAULT_OPTIMIZER_PORT = 18766  # the Studio's Clop bridge (docs/ops/studio-clop.md), on this service's own address
OPTIMIZE_TIMEOUT = 60 * 60  # Clop runs one file at a time, so a request can wait behind others
# Clop's Strong setting with the slower "smaller file" encoder. Measured on real downloads: the Balanced/Faster
# default could not shrink a 1080p YouTube file at all; this made it 41% smaller (275 MB to 164 MB) in 3.5 minutes.
OPTIMIZE_QUERY = "type=video&compression=75&encoder=software"
OPTIMIZABLE = (".mp4", ".mov")  # what Clop's video mode takes and returns
# Only videos up to this size are made smaller; a larger one is kept as downloaded (operator limit, 2026-11-11).
# Clop has no size target, takes one file at a time for the whole Studio and is stopped after 15 minutes, so a
# large file would hold everyone's queue and then fail.
DEFAULT_OPTIMIZE_MAX_BYTES = 250 * 1024 * 1024
JOB_ID = re.compile(r"^[a-f0-9]{32}$")
OWNER = re.compile(r"^[0-9]{1,18}$")
CHOICE = re.compile(r"^(best|mp3|v[0-9]{2,5})$")


class OptimizeFailed(RuntimeError):
    pass


class BadRequest(ValueError):
    def __init__(self, message: str, status: int = 422):
        super().__init__(message)
        self.status = status


class ServerConfig:
    def __init__(self, environ: Optional[Mapping[str, str]] = None) -> None:
        env = os.environ if environ is None else environ
        self.host = env.get("VIDEO_HOST", "127.0.0.1")
        self.port = int(env.get("VIDEO_PORT", "18769"))
        default_data = pathlib.Path(__file__).resolve().parent / "data"
        self.data = pathlib.Path(env.get("VIDEO_DATA", str(default_data)))
        self.ytdlp = env.get("VIDEO_YTDLP", DEFAULT_YTDLP)
        self.ffmpeg_dir = env.get("VIDEO_FFMPEG_DIR", DEFAULT_FFMPEG_DIR)
        self.max_bytes = int(env.get("VIDEO_MAX_BYTES", str(DEFAULT_MAX_BYTES)))
        self.keep_seconds = int(env.get("VIDEO_KEEP_SECONDS", str(DEFAULT_KEEP_SECONDS)))
        self.workers = max(1, int(env.get("VIDEO_WORKERS", str(DEFAULT_WORKERS))))
        self.user_backlog = max(1, int(env.get("VIDEO_USER_BACKLOG", str(DEFAULT_USER_BACKLOG))))
        self.job_timeout = int(env.get("VIDEO_JOB_TIMEOUT", str(DEFAULT_JOB_TIMEOUT)))
        self.min_free_bytes = int(env.get("VIDEO_MIN_FREE_BYTES", str(DEFAULT_MIN_FREE_BYTES)))
        self.allow_private = env.get("VIDEO_ALLOW_PRIVATE", "").lower() in {"1", "true", "yes", "on"}
        self.log_enabled = env.get("VIDEO_SERVICE_LOG", "").lower() in {"1", "true", "yes", "on"}
        self.log_path = pathlib.Path(env.get("VIDEO_SERVICE_LOG_PATH", str(self.data / "events.jsonl")))
        optimizer = env.get("VIDEO_OPTIMIZER_API", "http://{}:{}".format(self.host, DEFAULT_OPTIMIZER_PORT)).strip()
        self.optimizer = "" if optimizer.lower() in {"", "0", "off", "none"} else optimizer.rstrip("/")
        self.optimizer_ready = False  # kept current by optimizer_watch()
        self.optimize_max_bytes = max(0, int(env.get("VIDEO_OPTIMIZE_MAX_BYTES", str(DEFAULT_OPTIMIZE_MAX_BYTES))))  # 0: no limit
        self.proxy: Optional[str] = None  # set by serve(): the egress proxy every download goes through
        self.ffmpeg_tools: Optional[str] = None  # set by serve(): the folder of local-files-only ffmpeg stand-ins

    @property
    def files(self) -> pathlib.Path:
        return self.data / "files"

    @property
    def jobs_file(self) -> pathlib.Path:
        return self.data / "jobs.json"

    def too_large_to_optimize(self, size: object) -> bool:
        return bool(self.optimize_max_bytes) and isinstance(size, (int, float)) and size > self.optimize_max_bytes


class EventLog:
    def __init__(self, config: ServerConfig) -> None:
        self.enabled = config.log_enabled
        self.path = config.log_path
        self.run_id = uuid.uuid4().hex
        self._lock = threading.Lock()

    def write(self, event: str, ctx: Optional[Mapping[str, object]] = None, level: str = "info") -> None:
        if not self.enabled:
            return
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "run_id": self.run_id,
            "level": level,
            "event": event,
            "loc": "studio.video_service.server",
            "ctx": dict(ctx or {}),
        }
        with self._lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            with self.path.open("a", encoding="utf-8") as sink:
                sink.write(json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n")


def url_host(url: str) -> str:
    """The lower-cased host of an http(s) URL; the path and query never reach a log."""
    return (urlparse(url).hostname or "").lower()


SITE_NAMES = {"youtube": "YouTube", "tiktok": "TikTok", "twitter": "X", "linkedin": "LinkedIn", "soundcloud": "SoundCloud"}


def site_name(extractor: object, url: str) -> str:
    """yt-dlp's extractor key as people write the site's name; the host for sites it has no name for."""
    key = str(extractor or "")
    if not key or key.lower() == "generic":
        return url_host(url)[:60]
    return SITE_NAMES.get(key.lower(), key)[:60]


def validate_url(url: object, *, allow_private: bool = False, resolver=socket.getaddrinfo) -> str:
    if not isinstance(url, str) or not url.strip():
        raise BadRequest("Paste the link to a video.")
    url = url.strip()
    if len(url) > 2048 or re.search(r"\s", url):
        raise BadRequest("That link is not a web address.")
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        raise BadRequest("The link has to start with http:// or https://.")
    if parsed.username or parsed.password:
        raise BadRequest("Links with a sign-in name in them are not supported.")
    if allow_private:
        return url
    try:
        public_addresses(parsed.hostname, parsed.port or (443 if parsed.scheme == "https" else 80), resolver=resolver)
    except PermissionError:
        raise BadRequest("That link points at a private address, which is not supported.")
    except (OSError, UnicodeError):
        raise BadRequest("That site could not be found. Check the link.")
    return url


def public_addresses(host: str, port: int, *, allow_private: bool = False, resolver=socket.getaddrinfo) -> List[tuple]:
    """Every address a host resolves to, as getaddrinfo entries; PermissionError unless all of them are public."""
    found = resolver(host, port, type=socket.SOCK_STREAM)
    if not allow_private:
        for entry in found:
            if not ipaddress.ip_address(entry[4][0].split("%")[0]).is_global:
                raise PermissionError("{} is not a public address".format(host))
    return found


class EgressProxy(socketserver.ThreadingTCPServer):
    """The only way out for yt-dlp and ffmpeg: a loopback forward proxy that refuses private destinations.

    Checking the pasted link is not enough: a public page can redirect to a private address, name one as its
    media or manifest URL, or change its DNS answer between the check and the download. Every connection the
    downloader makes comes through here instead. The proxy resolves the destination itself, refuses it unless
    every address is public, and connects to the address it checked.
    """

    allow_reuse_address = True
    daemon_threads = True
    IDLE_SECONDS = 300
    MAX_HEAD = 65536

    def __init__(self, config: ServerConfig, log: EventLog, resolver=socket.getaddrinfo) -> None:
        self.config, self.log, self.resolver = config, log, resolver
        super().__init__(("127.0.0.1", 0), EgressHandler)

    @property
    def url(self) -> str:
        return "http://127.0.0.1:{}".format(self.server_address[1])

    def connect(self, host: str, port: int) -> socket.socket:
        found = public_addresses(host, port, allow_private=self.config.allow_private, resolver=self.resolver)
        error: Optional[OSError] = None
        for family, kind, protocol, _name, address in found:
            upstream = socket.socket(family, kind, protocol)
            upstream.settimeout(30)
            try:
                upstream.connect(address)
                return upstream
            except OSError as failure:
                upstream.close()
                error = failure
        raise error or OSError("no address for {}".format(host))


class EgressHandler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        client: socket.socket = self.request
        client.settimeout(30)
        head = b""
        while b"\r\n\r\n" not in head:
            chunk = client.recv(8192)
            if not chunk or len(head) + len(chunk) > EgressProxy.MAX_HEAD:
                return
            head += chunk
        head, rest = head.split(b"\r\n\r\n", 1)
        lines = head.decode("latin-1").split("\r\n")
        try:
            method, target, _version = lines[0].split(" ", 2)
            if method.upper() == "CONNECT":
                host, _, port_text = target.rpartition(":")
                host, port, forward = host.strip("[]"), int(port_text), b""
            else:
                parsed = urlparse(target)
                if parsed.scheme != "http" or not parsed.hostname:
                    raise ValueError("unsupported target")
                host, port = parsed.hostname, parsed.port or 80
                path = (parsed.path or "/") + ("?" + parsed.query if parsed.query else "")
                kept = [line for line in lines[1:] if line.split(":", 1)[0].strip().lower() not in ("proxy-connection", "proxy-authorization", "connection")]
                forward = "\r\n".join(["{} {} HTTP/1.1".format(method, path), *kept, "Connection: close", "", ""]).encode("latin-1") + rest
        except ValueError:
            self.answer(400, "Bad Request")
            return
        try:
            upstream = self.server.connect(host, port)
        except PermissionError:
            self.server.log.write("egress.refused", {"host": host, "port": port}, level="warning")
            self.answer(403, "Forbidden")
            return
        except (OSError, UnicodeError):
            self.answer(502, "Bad Gateway")
            return
        with upstream:
            if method.upper() == "CONNECT":
                client.sendall(b"HTTP/1.1 200 Connection established\r\n\r\n")
                forward = rest
            if forward:
                upstream.sendall(forward)
            self.relay(client, upstream)

    def answer(self, status: int, reason: str) -> None:
        try:
            self.request.sendall("HTTP/1.1 {} {}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".format(status, reason).encode("ascii"))
        except OSError:
            pass

    @staticmethod
    def relay(client: socket.socket, upstream: socket.socket) -> None:
        pair = {client: upstream, upstream: client}
        try:
            while True:
                ready, _, _ = select.select(list(pair), [], [], EgressProxy.IDLE_SECONDS)
                if not ready:
                    return
                for source in ready:
                    data = source.recv(65536)
                    if not data:
                        return
                    pair[source].sendall(data)
        except OSError:
            return


LOCAL_PROTOCOLS = "file,pipe,fd,crypto,data"
SANDBOX_EXEC = "/usr/bin/sandbox-exec"  # macOS; where it is missing the stand-ins rely on the whitelist alone
FFMPEG_WRAPPER = """#!{python}
# Written by the Mothership video service; rewritten on every start.
# ffmpeg is here to join and convert files yt-dlp has already downloaded. It is never allowed to open a network
# address: a manifest can name key or segment addresses (httpproxy://, tcp://, ...) that ffmpeg would open
# directly, around the egress proxy. Every input is limited to local protocols.
import os
import sys

REAL = {real!r}
LOCAL = {local!r}
SANDBOX = {sandbox!r}
arguments, skip = [], False
for argument in sys.argv[1:]:
    if skip:
        skip = False
        continue
    if argument == "-protocol_whitelist":
        skip = True
        continue
    if argument == "-i":
        arguments += ["-protocol_whitelist", LOCAL]
    arguments.append(argument)
if {probe!r} and any(not argument.startswith("-") for argument in arguments):
    arguments = ["-protocol_whitelist", LOCAL] + arguments  # ffprobe takes its input as a bare argument
if os.path.exists(SANDBOX):
    # The whitelist is an ffmpeg option, and a playlist or concat file can set its own. The macOS sandbox is
    # not an option: with it the process cannot open a network connection whatever it is told to read.
    os.execv(SANDBOX, [SANDBOX, "-p", "(version 1)(allow default)(deny network*)", REAL] + arguments)
os.execv(REAL, [REAL] + arguments)
"""


def install_ffmpeg_wrappers(config: ServerConfig) -> str:
    """Write ffmpeg/ffprobe stand-ins that only open local files; returns their folder for --ffmpeg-location."""
    folder = config.data / "bin"
    folder.mkdir(parents=True, exist_ok=True)
    for name in ("ffmpeg", "ffprobe"):
        real = pathlib.Path(config.ffmpeg_dir, name)
        target = folder / name
        if not real.is_file():
            target.unlink(missing_ok=True)
            continue
        target.write_text(FFMPEG_WRAPPER.format(python=sys.executable, real=str(real), local=LOCAL_PROTOCOLS, probe=name == "ffprobe",
                                                 sandbox=SANDBOX_EXEC), encoding="utf-8")
        target.chmod(0o755)
    return str(folder)


def proxy_arguments(config: ServerConfig) -> List[str]:
    return ["--proxy", config.proxy] if config.proxy else []


def downloader_environment(config: ServerConfig) -> Dict[str, str]:
    """The environment for yt-dlp and the ffmpeg it starts: both leave through the egress proxy."""
    environment = dict(os.environ)
    if config.proxy:
        for name in ("http_proxy", "https_proxy", "all_proxy", "HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY"):
            environment[name] = config.proxy
        for name in ("no_proxy", "NO_PROXY"):
            environment[name] = ""
    return environment


def optimizer_endpoint(config: ServerConfig) -> Optional[Tuple[str, int]]:
    parsed = urlparse(config.optimizer)
    if parsed.scheme != "http" or not parsed.hostname:
        return None
    return parsed.hostname, parsed.port or 80


def optimizer_health(config: ServerConfig) -> bool:
    endpoint = optimizer_endpoint(config)
    if endpoint is None:
        return False
    connection = http.client.HTTPConnection(endpoint[0], endpoint[1], timeout=3)
    try:
        connection.request("GET", "/health")
        response = connection.getresponse()
        payload = json.loads(response.read(65536))
        return response.status == 200 and isinstance(payload, dict) and payload.get("ok") is True
    except (OSError, http.client.HTTPException, ValueError):
        return False
    finally:
        connection.close()


def optimizer_watch(config: ServerConfig, store: "JobStore", interval: int = 60) -> None:
    while not store.stopping:
        time.sleep(interval)
        config.optimizer_ready = optimizer_health(config)


def optimize_file(config: ServerConfig, path: pathlib.Path) -> Tuple[str, Optional[pathlib.Path], str]:
    """Send one finished video to the Clop bridge.

    ("smaller", temporary file, extension) hands back a complete smaller file for the caller to put in place;
    ("none", None, "") means Clop could not make it smaller. The original is never touched here.
    """
    endpoint = optimizer_endpoint(config)
    if endpoint is None:
        raise OptimizeFailed("optimizer not configured")
    old = path.stat().st_size
    temporary = path.with_name(".optimizing")
    kept = False
    connection = http.client.HTTPConnection(endpoint[0], endpoint[1], timeout=OPTIMIZE_TIMEOUT)
    try:
        with path.open("rb") as source:
            connection.request(
                "POST", "/optimize?" + OPTIMIZE_QUERY, body=source,
                headers={"Content-Length": str(old), "Content-Type": "application/octet-stream",
                         "X-Clop-Filename": "video" + path.suffix.lower()},
            )
        response = connection.getresponse()
        if response.status != 200:
            raise OptimizeFailed("optimizer answered {}".format(response.status))
        if response.getheader("X-Clop-Outcome") == "no_reduction":
            return "none", None, ""
        extension = "." + (response.getheader("X-Clop-Extension") or "").lower()
        if extension not in OPTIMIZABLE:
            raise OptimizeFailed("unexpected output type")
        try:
            expected = int(response.getheader("Content-Length") or "")
        except ValueError:
            raise OptimizeFailed("optimizer gave no length")
        if expected <= 0 or expected >= old:
            return "none", None, ""
        written = 0
        with temporary.open("wb") as sink:
            while written < expected:
                chunk = response.read(min(1024 * 1024, expected - written))
                if not chunk:
                    break
                written += len(chunk)
                sink.write(chunk)
            sink.flush()
            os.fsync(sink.fileno())
        if written != expected:
            # A dropped connection ends the body early without an error; a short file must never replace the original.
            raise OptimizeFailed("optimizer sent {} of {} bytes".format(written, expected))
        kept = True
        return "smaller", temporary, extension
    except (OSError, http.client.HTTPException) as error:
        raise OptimizeFailed(repr(error)[:200])
    finally:
        connection.close()
        if not kept:
            try:
                temporary.unlink()
            except OSError:
                pass


def format_bytes(size: float) -> str:
    for unit in ("B", "KB", "MB"):
        if size < 1024:
            return "{:.0f} {}".format(size, unit)
        size /= 1024
    return "{:.1f} GB".format(size)


def build_choices(info: Mapping[str, object]) -> List[dict]:
    """A short list of sizes (highest first) with estimated sizes, then audio-only MP3; one is marked default."""
    formats = [f for f in (info.get("formats") or []) if isinstance(f, dict)]

    def has(value: object) -> bool:
        return bool(value) and value != "none"

    audio = [f for f in formats if has(f.get("acodec")) and not has(f.get("vcodec"))]
    best_audio = max(audio, key=lambda f: f.get("abr") or f.get("tbr") or 0, default=None)
    audio_size = (best_audio or {}).get("filesize") or (best_audio or {}).get("filesize_approx") or 0
    videos = [f for f in formats if has(f.get("vcodec")) and isinstance(f.get("height"), int) and f["height"] > 0]
    choices: List[dict] = []
    heights = offered_heights({f["height"] for f in videos})
    for height in heights:
        def score(f: dict) -> float:
            return (f.get("tbr") or 0) + (10000 if f.get("ext") == "mp4" else 0) + (5000 if str(f.get("vcodec", "")).startswith("avc") else 0)

        best = max((f for f in videos if f["height"] == height), key=score)
        size = best.get("filesize") or best.get("filesize_approx") or 0
        if size and not has(best.get("acodec")):
            size += audio_size
        choices.append({"id": "v{}".format(height), "kind": "video", "label": "{}p video (MP4)".format(height), "size": int(size) or None})
    if not choices:
        choices.append({"id": "best", "kind": "video", "label": "Best available video (MP4)", "size": None})
    # Full HD is the size most people want; a 4K original stays one tap away without being the default.
    preferred = next((height for height in heights if height <= 1080), None)
    for index, choice in enumerate(choices):
        choice["default"] = choice["id"] == "v{}".format(preferred) if preferred else index == 0
    choices.append({"id": "mp3", "kind": "audio", "label": "Audio only (MP3)", "size": int(audio_size) or None, "default": False})
    return choices


def offered_heights(available: set) -> List[int]:
    """The original's top size plus the common ones, highest first: a short list, not every rung of the ladder."""
    ordered = sorted(available, reverse=True)
    picked = set(ordered[:1]) | (available & COMMON_HEIGHTS)
    for height in ordered:
        if len(picked) >= 3:
            break
        picked.add(height)
    return sorted(picked, reverse=True)[:MAX_VIDEO_CHOICES]


def choice_args(choice: str) -> List[str]:
    if not CHOICE.match(choice or ""):
        raise BadRequest("Choose one of the listed formats.")
    if choice == "mp3":
        return ["-f", "ba/b", "-x", "--audio-format", "mp3", "--audio-quality", "0"]
    prefer = ["-S", "res,vcodec:h264,acodec:aac", "--merge-output-format", "mp4"]
    if choice == "best":
        return ["-f", "bv*+ba/b"] + prefer
    height = int(choice[1:])
    return ["-f", "bv*[height={h}]+ba/b[height={h}]/bv*[height<={h}]+ba/b".format(h=height)] + prefer


def choice_label(choice: str) -> str:
    if choice == "mp3":
        return "Audio only (MP3)"
    return "Best available video (MP4)" if choice == "best" else "{}p video (MP4)".format(choice[1:])


def clean_error(stderr: str) -> str:
    lines = [line.strip() for line in stderr.splitlines() if line.strip()]
    errors = [line for line in lines if line.startswith("ERROR:")]
    text = (errors[-1] if errors else (lines[-1] if lines else "")).replace("ERROR:", "").strip()
    text = re.sub(r"^\[[^\]]+\]\s*[^:]{0,40}:\s*", "", text)
    text = re.sub(r"https?://\S+", "the link", text)
    return text[:300] or "The video could not be downloaded."


def safe_filename(name: str) -> str:
    name = re.sub(r"[\x00-\x1f\x7f/\\:*?\"<>|]+", " ", name).strip(" .")
    name = re.sub(r"\s+", " ", name)
    return name[:150] or "video"


def now() -> float:
    return time.time()


def sync_folder(folder: pathlib.Path) -> None:
    """Flush a folder's own entries (a rename or delete inside it) to disk."""
    try:
        handle = os.open(str(folder), os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(handle)
    except OSError:
        pass
    finally:
        os.close(handle)


class JobStore:
    """The queue. One lock guards every job; jobs.json is rewritten on each state change."""

    def __init__(self, config: ServerConfig, log: EventLog) -> None:
        self.config = config
        self.log = log
        self.lock = threading.Condition()
        self.jobs: Dict[str, dict] = {}
        self.processes: Dict[str, subprocess.Popen] = {}
        self.workers: List[threading.Thread] = []
        self.optimize_turn = threading.Lock()  # Clop takes one file at a time, so downloads take turns (run_optimize)
        self.stopping = False
        config.files.mkdir(parents=True, exist_ok=True)
        self._load()

    # --- persistence -----------------------------------------------------------------------------------------
    def _load(self) -> None:
        try:
            stored = json.loads(self.config.jobs_file.read_text(encoding="utf-8"))
        except (FileNotFoundError, json.JSONDecodeError, OSError):
            stored = []
        requeued = 0
        for job in stored if isinstance(stored, list) else []:
            if not isinstance(job, dict) or not JOB_ID.match(str(job.get("id", ""))):
                continue
            if job.get("status") in ("running", "processing"):
                # The service stopped mid-download: start that job again rather than lose it.
                shutil.rmtree(self.config.files / job["id"], ignore_errors=True)
                job.update(status="queued", percent=0, downloaded=0, total=None, speed=None, eta=None, started_at=None,
                           part=None, parts=None)
                requeued += 1
            elif job.get("status") == "optimizing":
                # The original is replaced only by a complete smaller file, so whatever is stored is whole. If the
                # service stopped just after the swap, the file is already the smaller one: record its real size.
                job.update(status="done")
                stored = self.config.files / job["id"] / str(job.get("stored") or "")
                if stored.is_file() and stored.stat().st_size != job.get("size"):
                    size = stored.stat().st_size
                    job.update(saved=int(job.get("size") or size) - size, size=size, downloaded=size, total=size, optimized="smaller")
            if job.get("status") == "done" and job.get("stored"):
                # A finished download keeps exactly one file. Anything else in its folder is what a stop left
                # behind: a part-written smaller copy, or the file on the other side of a swap.
                stored = self.config.files / job["id"] / str(job["stored"])
                if stored.is_file():
                    for other in stored.parent.iterdir():
                        if other != stored and other.is_file():
                            try:
                                other.unlink()
                            except OSError:
                                pass
            self.jobs[job["id"]] = job
        self.log.write("queue.loaded", {"jobs": len(self.jobs), "requeued": requeued})
        self._save()

    def _save(self) -> None:
        # Flushed to disk, file and folder entry both: a download's file is only let go once the queue that names
        # its replacement would survive a power cut.
        temporary = self.config.jobs_file.with_suffix(".json.tmp")
        with temporary.open("w", encoding="utf-8") as sink:
            sink.write(json.dumps(list(self.jobs.values()), separators=(",", ":")))
            sink.flush()
            os.fsync(sink.fileno())
        temporary.replace(self.config.jobs_file)
        sync_folder(self.config.jobs_file.parent)

    # --- views -----------------------------------------------------------------------------------------------
    def _position(self, job: dict) -> Optional[int]:
        if job["status"] != "queued":
            return None
        order = self._queue_order()
        return order.index(job["id"]) + 1 if job["id"] in order else None

    def _queue_order(self) -> List[str]:
        """The order queued jobs will start in: people take turns, each person's own jobs oldest first."""
        running: Dict[str, int] = {}
        for job in self.jobs.values():
            if job["status"] in ("running", "processing"):
                running[job["owner"]] = running.get(job["owner"], 0) + 1
        queued = sorted((j for j in self.jobs.values() if j["status"] == "queued"), key=lambda j: j["created_at"])
        order: List[str] = []
        while queued:
            pick = min(queued, key=lambda j: (running.get(j["owner"], 0), j["created_at"]))
            running[pick["owner"]] = running.get(pick["owner"], 0) + 1
            queued.remove(pick)
            order.append(pick["id"])
        return order

    def view(self, job: dict) -> dict:
        keys = ("id", "url", "title", "choice", "label", "status", "percent", "downloaded", "total", "speed", "eta",
                "filename", "size", "error", "created_at", "started_at", "finished_at", "optimize", "optimized", "saved",
                "part", "parts", "optimize_started_at")
        result = {key: job.get(key) for key in keys}
        result["can_optimize"] = bool(
            self.config.optimizer_ready and job["status"] == "done" and job.get("optimized") in (None, "failed")
            and pathlib.Path(str(job.get("stored") or "")).suffix.lower() in OPTIMIZABLE
            and not self.config.too_large_to_optimize(job.get("size"))
        )
        result["position"] = self._position(job)
        result["expires_at"] = job["finished_at"] + self.config.keep_seconds if job.get("finished_at") else None
        return result

    def stats(self) -> dict:
        with self.lock:
            statuses = [job["status"] for job in self.jobs.values()]
        return {"queued": statuses.count("queued"), "running": statuses.count("running") + statuses.count("processing")}

    def list(self, owner: str) -> List[dict]:
        with self.lock:
            mine = sorted((j for j in self.jobs.values() if j["owner"] == owner), key=lambda j: j["created_at"], reverse=True)
            return [self.view(job) for job in mine]

    def get(self, job_id: str, owner: str) -> dict:
        with self.lock:
            return self.view(self._owned(job_id, owner))

    def _owned(self, job_id: str, owner: str) -> dict:
        job = self.jobs.get(job_id)
        if job is None or job["owner"] != owner:
            raise BadRequest("That download is not in your list.", 404)
        return job

    # --- changes ---------------------------------------------------------------------------------------------
    def add(self, owner: str, url: str, choice: str, title: Optional[str], optimize: bool = False) -> Tuple[dict, bool]:
        with self.lock:
            active = [j for j in self.jobs.values() if j["owner"] == owner and j["status"] in ACTIVE]
            for job in active:
                if job["url"] == url and job["choice"] == choice:
                    return self.view(job), False
            if len(active) >= self.config.user_backlog:
                raise BadRequest(
                    "You already have {} downloads waiting. Let some finish, then add more.".format(len(active)), 429
                )
            if shutil.disk_usage(self.config.files).free < self.config.min_free_bytes:
                raise BadRequest("The studio is low on disk space. Try again in a little while.", 507)
            job = {
                "id": uuid.uuid4().hex, "owner": owner, "url": url, "choice": choice, "label": choice_label(choice),
                "title": (title or "").strip()[:200] or url_host(url), "status": "queued", "percent": 0,
                "downloaded": 0, "total": None, "speed": None, "eta": None, "filename": None, "size": None,
                "error": None, "created_at": now(), "started_at": None, "finished_at": None,
                "optimize": bool(optimize) and choice != "mp3", "optimized": None, "saved": None,
            }
            self.jobs[job["id"]] = job
            self._save()
            self.lock.notify_all()
            self.log.write("job.queued", {"job": job["id"], "owner": owner, "host": url_host(url), "choice": choice})
            return self.view(job), True

    def remove(self, job_id: str, owner: str) -> None:
        with self.lock:
            job = self._owned(job_id, owner)
            process = self.processes.get(job_id)
            del self.jobs[job_id]
            self._save()
            self.lock.notify_all()
        if process is not None:
            self._kill(process)
        shutil.rmtree(self.config.files / job_id, ignore_errors=True)
        self.log.write("job.removed", {"job": job_id, "owner": owner, "status": job["status"]})

    def begin_optimize(self, job_id: str, owner: str) -> Tuple[dict, bool]:
        """Mark a finished video as being made smaller. True means the caller must start run_optimize(); False
        means one is already running for this download, so a second request starts nothing."""
        with self.lock:
            job = self._owned(job_id, owner)
            if job["status"] == "optimizing":
                return self.view(job), False
            if job["status"] != "done" or not job.get("stored"):
                raise BadRequest("That download is not ready yet.", 409)
            if pathlib.Path(job["stored"]).suffix.lower() not in OPTIMIZABLE:
                raise BadRequest("Only MP4 video can be made smaller.", 422)
            if job.get("optimized") in ("smaller", "none"):
                raise BadRequest("That file is already as small as it gets.", 409)
            if self.config.too_large_to_optimize(job.get("size")):
                raise BadRequest("That video is over {}, so it is kept as it is. Only smaller videos can be made smaller.".format(
                    format_bytes(self.config.optimize_max_bytes)), 422)
            if not self.config.optimizer_ready:
                raise BadRequest("Making files smaller is not available right now. Try again in a minute.", 503)
            job.update(status="optimizing", optimize_started_at=None)
            self._save()
            self.log.write("job.optimizing", {"job": job_id, "owner": owner, "size": job.get("size")})
            return self.view(job), True

    def file(self, job_id: str, owner: str) -> Tuple[pathlib.Path, str]:
        with self.lock:
            job = self._owned(job_id, owner)
            if job["status"] == "optimizing":
                raise BadRequest("That file is being made smaller. It will be ready shortly.", 409)
            if job["status"] != "done" or not job.get("filename"):
                raise BadRequest("That download is not ready yet.", 409)
            path = self.config.files / job_id / job["stored"]
            name = job["filename"]
        if not path.is_file():
            raise BadRequest("That file is no longer kept. Download it again.", 410)
        return path, name

    def purge_expired(self) -> int:
        cutoff = now() - self.config.keep_seconds
        with self.lock:
            expired = [j["id"] for j in self.jobs.values() if j["status"] not in ACTIVE and (j.get("finished_at") or 0) < cutoff]
            for job_id in expired:
                del self.jobs[job_id]
            if expired:
                self._save()
        for job_id in expired:
            shutil.rmtree(self.config.files / job_id, ignore_errors=True)
        for entry in list(self.config.files.iterdir()) if self.config.files.is_dir() else []:
            # Checked under the lock at the moment of deletion: a job is in the queue before its folder exists,
            # so a download that started since the scan began is never mistaken for a leftover.
            with self.lock:
                if entry.name not in self.jobs:
                    shutil.rmtree(entry, ignore_errors=True)
        if expired:
            self.log.write("queue.purged", {"jobs": len(expired)})
        return len(expired)

    # --- workers ---------------------------------------------------------------------------------------------
    def next_job(self) -> Optional[dict]:
        with self.lock:
            while not self.stopping:
                order = self._queue_order()
                if order:
                    job = self.jobs[order[0]]
                    job.update(status="running", started_at=now())
                    self._save()
                    return job
                self.lock.wait(timeout=5)
            return None

    def progress(self, job_id: str, **fields: object) -> bool:
        """Record progress; False when the job was removed and its process should stop."""
        with self.lock:
            job = self.jobs.get(job_id)
            if job is None:
                return False
            job.update(fields)
            return True

    def finish(self, job_id: str, **fields: object) -> None:
        with self.lock:
            self.processes.pop(job_id, None)
            job = self.jobs.get(job_id)
            if job is None:
                return
            job.update(fields, finished_at=now(), speed=None, eta=None)
            self._save()
            self.lock.notify_all()
        self.log.write("job.finished", {"job": job_id, "status": fields.get("status"), "size": fields.get("size")},
                       level="info" if fields.get("status") in ("done", "optimizing") else "warning")

    @staticmethod
    def _kill(process: subprocess.Popen) -> None:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except (ProcessLookupError, PermissionError):
            return
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except (ProcessLookupError, PermissionError):
                pass

    def stop(self) -> None:
        with self.lock:
            self.stopping = True
            processes = list(self.processes.values())
            self.lock.notify_all()
        for process in processes:
            self._kill(process)
        # A worker that was starting a downloader when the snapshot was taken stops it itself (run_job); wait for
        # every worker so no downloader outlives the service and writes into a folder the next start reuses.
        deadline = time.monotonic() + 30
        for thread in self.workers:
            if thread is not threading.current_thread():
                thread.join(timeout=max(0.0, deadline - time.monotonic()))


def run_optimize(store: JobStore, job_id: str) -> None:
    """Make one finished video smaller through the Clop bridge; the job ends "done" whatever the outcome.

    Clop takes one file at a time, so downloads take turns here. A file waiting for its turn has no
    optimize_started_at; the clock a person sees starts when the file is handed over."""
    with store.optimize_turn:
        _optimize_in_turn(store, job_id)


def _optimize_in_turn(store: JobStore, job_id: str) -> None:
    with store.lock:
        job = store.jobs.get(job_id)
        if job is None or job["status"] != "optimizing":
            return
        path = store.config.files / job_id / job["stored"]
        job.update(optimize_started_at=now())
        store._save()
    try:
        outcome, produced, extension = optimize_file(store.config, path)
        detail = None
    except (OptimizeFailed, OSError) as error:
        outcome, produced, extension, detail = "failed", None, "", str(error)[:200]
    with store.lock:
        job = store.jobs.get(job_id)
        if job is None:
            return  # removed while it was being made smaller; its folder is already gone
        if outcome == "smaller":
            # Put the smaller file in place, record it, and only then let the old one go. A stop between the steps
            # leaves either the old name with the old file, or the new name with the new file (see _load).
            target = path.with_suffix(extension)
            try:
                size = produced.stat().st_size
                produced.replace(target)
                sync_folder(target.parent)
            except OSError as error:
                outcome, detail = "failed", str(error)[:200]
            else:
                job.update(stored=target.name, filename=safe_filename(target.name), saved=int(job["size"]) - size,
                           size=size, downloaded=size, total=size)
        job.update(status="done", optimized=outcome)
        store._save()
        if outcome == "smaller" and target != path:
            try:
                path.unlink()
            except OSError:
                pass
        store.lock.notify_all()
    store.log.write("job.optimized", {"job": job_id, "outcome": outcome, "saved": job.get("saved"), "error": detail},
                    level="warning" if outcome == "failed" else "info")


def start_optimize(store: JobStore, job_id: str) -> None:
    threading.Thread(target=run_optimize, args=(store, job_id), name="video-optimize", daemon=True).start()


def download_command(config: ServerConfig, job: Mapping[str, object], folder: pathlib.Path) -> List[str]:
    return [
        config.ytdlp, "--ignore-config", *choice_args(str(job["choice"])),
        "--no-playlist", "--no-warnings", "--newline", "--no-quiet", "--progress",
        "--progress-template", PROGRESS_TEMPLATE, "--print", PLAN_TEMPLATE, "--print", "after_move:filepath", "--no-simulate",
        "--max-filesize", str(config.max_bytes), "--socket-timeout", "30", "--retries", "5",
        "--ffmpeg-location", config.ffmpeg_tools or config.ffmpeg_dir, *proxy_arguments(config),
        "-o", str(folder / "%(title).100s.%(ext)s"), "--", str(job["url"]),
    ]


def _number(value: str) -> Optional[float]:
    try:
        return float(value)
    except ValueError:
        return None


def _size(value: object) -> Optional[int]:
    return int(value) if isinstance(value, (int, float)) and not isinstance(value, bool) and value > 0 else None


def parse_plan(line: str) -> List[Optional[int]]:
    """The size of each stream yt-dlp is about to fetch, in order; None for a stream the site gave no size for.

    An empty list means the plan said nothing: one stream of unknown size."""
    # The last field is a bare number or NA, so the split is safe whatever a format id holds.
    streams, _, whole = line[len(PLAN_PREFIX):].rpartition("|")
    try:
        records = json.loads(streams)
    except ValueError:
        records = None
    if isinstance(records, list) and records:
        return [
            (_size(record.get("filesize")) or _size(record.get("filesize_approx"))) if isinstance(record, dict) else None
            for record in records
        ]
    size = _number(whole)
    return [int(size)] if size and size > 0 else []


def run_job(store: JobStore, job: dict) -> None:
    """Run one yt-dlp download to the end and record how it finished."""
    config, job_id = store.config, job["id"]
    folder = config.files / job_id
    shutil.rmtree(folder, ignore_errors=True)
    folder.mkdir(parents=True, exist_ok=True)
    try:
        process = subprocess.Popen(
            download_command(config, job, folder), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True, errors="replace", start_new_session=True,
            env=downloader_environment(config),
        )
    except OSError as error:
        store.finish(job_id, status="failed", error="The downloader could not start ({}).".format(error.strerror or "error"))
        return
    with store.lock:
        registered = job_id in store.jobs and not store.stopping
        if registered:
            store.processes[job_id] = process
    if not registered:
        # Removed, or the service began stopping while the downloader was starting. The job keeps its "running"
        # status in the stopping case, so the next start queues it again.
        JobStore._kill(process)
        process.wait()
        for pipe in (process.stdout, process.stderr):
            pipe.close()
        return
    stderr_lines: List[str] = []
    reader = threading.Thread(target=lambda: stderr_lines.extend(process.stderr), daemon=True)
    reader.start()
    timer = threading.Timer(config.job_timeout, lambda: JobStore._kill(process))
    timer.daemon = True
    timer.start()
    printed = ""
    plan: Optional[List[Optional[int]]] = None  # the size of each stream, in download order (parse_plan)
    part, last, before = 1, 0.0, 0.0  # before: the bytes of the streams already fetched
    stream: Optional[str] = None  # the format id of the stream in hand, when yt-dlp names one
    try:
        for line in process.stdout:
            line = line.strip()
            if line.startswith(PLAN_PREFIX):
                if plan is None:
                    plan = parse_plan(line)
            elif line.startswith(PROGRESS_PREFIX):
                fields = line[len(PROGRESS_PREFIX):].split("|", 5)
                if len(fields) != 6:
                    continue
                downloaded = _number(fields[0]) or 0.0
                total = _number(fields[1]) or _number(fields[2])
                named = fields[5] if fields[5] not in ("", "NA") else None
                # The next stream has begun when yt-dlp names another format. A count that starts over inside one
                # stream is a retry, not a new stream; it is the only sign there is when no format is named.
                if (named != stream) if (named and stream) else (not named and downloaded < last):
                    before += last
                    part += 1
                stream = named or stream
                last = downloaded
                speed, eta = _number(fields[3]), _number(fields[4])
                sizes = plan or []
                if len(sizes) >= part and all(sizes):
                    # Every stream's size is known: measure against the whole file, not the stream in hand.
                    total = before + (total or sizes[part - 1]) + sum(sizes[part:])
                    downloaded = min(before + downloaded, total)
                    eta = (total - downloaded) / speed if speed else None
                    shown_part = shown_parts = None
                else:
                    # Sizes are not all known, so the measure is the stream in hand; say which one it is.
                    shown_parts = len(sizes) if len(sizes) > 1 else None
                    shown_part = part if shown_parts or part > 1 else None
                percent = min(99, int(downloaded * 100 / total)) if total else 0
                if total and total > config.max_bytes:
                    JobStore._kill(process)
                    stderr_lines.append("ERROR: too-large")
                    break
                alive = store.progress(job_id, status="running", percent=percent, downloaded=int(downloaded),
                                       total=int(total) if total else None, speed=speed, eta=eta,
                                       part=shown_part, parts=shown_parts)
                if not alive:
                    JobStore._kill(process)
                    break
            elif line.startswith(("[Merger]", "[ExtractAudio]", "[VideoRemuxer]", "[VideoConvertor]", "[FixupM")):
                store.progress(job_id, status="processing", percent=99, speed=None, eta=None)
            elif line and not line.startswith("["):
                printed = line
        process.wait()
    finally:
        timer.cancel()
        if process.poll() is None:
            # The loop was left early (cancelled, or over the size limit) with the process already told to stop.
            try:
                process.wait(timeout=15)
            except subprocess.TimeoutExpired:
                JobStore._kill(process)
        reader.join(timeout=5)
        process.stdout.close()
        process.stderr.close()
    if store.stopping:
        # The service is shutting down: leave the job marked running so the next start queues it again.
        return
    produced = pathlib.Path(printed) if printed else None
    if produced is None or not produced.is_file() or produced.parent != folder:
        candidates = [p for p in folder.iterdir() if p.is_file() and p.suffix not in (".part", ".ytdl", ".json")]
        produced = max(candidates, key=lambda p: p.stat().st_size) if process.returncode == 0 and candidates else None
    stderr = "".join(stderr_lines)
    if process.returncode == 0 and produced is not None:
        size = produced.stat().st_size
        if size > config.max_bytes:
            shutil.rmtree(folder, ignore_errors=True)
            store.finish(job_id, status="failed", error="That video is larger than {}. Pick a smaller size.".format(format_bytes(config.max_bytes)))
            return
        wanted = bool(job.get("optimize")) and produced.suffix.lower() in OPTIMIZABLE
        too_large = wanted and config.too_large_to_optimize(size)
        smaller = wanted and not too_large and config.optimizer_ready
        store.finish(job_id, status="optimizing" if smaller else "done", percent=100, stored=produced.name,
                     filename=safe_filename(produced.name), size=size, downloaded=size, total=size, part=None, parts=None,
                     optimize_started_at=None, **({"optimized": "too_large"} if too_large else {}))
        if too_large:
            store.log.write("job.optimize_skipped", {"job": job_id, "size": size, "limit": config.optimize_max_bytes})
        if smaller:
            start_optimize(store, job_id)
        return
    shutil.rmtree(folder, ignore_errors=True)
    if "too-large" in stderr or (process.returncode == 0 and produced is None):
        message = "That video is larger than {}. Pick a smaller size.".format(format_bytes(config.max_bytes))
    elif process.returncode in (-signal.SIGTERM, -signal.SIGKILL):
        message = "The download was stopped before it finished. Add it again to retry."
    else:
        message = clean_error(stderr)
    store.finish(job_id, status="failed", error=message)


def worker(store: JobStore) -> None:
    while True:
        job = store.next_job()
        if job is None:
            return
        store.log.write("job.started", {"job": job["id"], "owner": job["owner"], "host": url_host(job["url"])})
        try:
            run_job(store, job)
        except Exception as error:  # a worker must outlive one bad job
            store.log.write("job.crashed", {"job": job["id"], "error": repr(error)[:300]}, level="error")
            store.finish(job["id"], status="failed", error="The download failed unexpectedly. Add it again to retry.")


def janitor(store: JobStore, interval: int = 300) -> None:
    while not store.stopping:
        try:
            store.purge_expired()
        except OSError as error:
            store.log.write("queue.purge_failed", {"error": str(error)[:200]}, level="warning")
        time.sleep(interval)


PROBES = threading.BoundedSemaphore(MAX_PROBES)


def probe(config: ServerConfig, url: str) -> dict:
    if not PROBES.acquire(timeout=20):
        raise BadRequest("The studio is busy looking up other videos. Try again in a moment.", 503)
    try:
        result = subprocess.run(
            [config.ytdlp, "--ignore-config", "-J", "--no-playlist", "--no-warnings", "--socket-timeout", "20",
             *proxy_arguments(config), "--", url],
            stdin=subprocess.DEVNULL, capture_output=True, text=True, errors="replace", timeout=PROBE_TIMEOUT,
            env=downloader_environment(config),
        )
    except subprocess.TimeoutExpired:
        raise BadRequest("That site took too long to answer. Try again.", 504)
    except OSError:
        raise BadRequest("The downloader is not available on the studio right now.", 503)
    finally:
        PROBES.release()
    if result.returncode != 0:
        raise BadRequest(clean_error(result.stderr), 422)
    try:
        info = json.loads(result.stdout)
    except json.JSONDecodeError:
        raise BadRequest("That page did not give back a video.", 422)
    if not isinstance(info, dict) or info.get("_type") == "playlist":
        raise BadRequest("That link is a list of videos. Paste the link to one video.", 422)
    duration = info.get("duration")
    return {
        "title": str(info.get("title") or url_host(url))[:200],
        "uploader": str(info.get("uploader") or info.get("channel") or "")[:120] or None,
        "duration": int(duration) if isinstance(duration, (int, float)) else None,
        "site": site_name(info.get("extractor_key"), url),
        "thumbnail": info.get("thumbnail") if isinstance(info.get("thumbnail"), str) and info["thumbnail"].startswith("https://") else None,
        "choices": build_choices(info),
    }


def ytdlp_version(config: ServerConfig) -> Optional[str]:
    try:
        result = subprocess.run([config.ytdlp, "--version"], capture_output=True, text=True, timeout=10)
    except (OSError, subprocess.SubprocessError):
        return None
    return result.stdout.strip() or None if result.returncode == 0 else None


def parse_range(header: Optional[str], size: int) -> Optional[Tuple[int, int]]:
    """A single `bytes=a-b` range as inclusive offsets, None for the whole file; raises on an unusable one."""
    if not header:
        return None
    match = re.match(r"^bytes=(\d*)-(\d*)$", header.strip())
    if not match or (not match.group(1) and not match.group(2)):
        return None
    if not match.group(1):
        length = int(match.group(2))
        if length == 0:
            raise BadRequest("Range not satisfiable", 416)
        return max(0, size - length), size - 1
    start = int(match.group(1))
    end = min(int(match.group(2)), size - 1) if match.group(2) else size - 1
    if start >= size or start > end:
        raise BadRequest("Range not satisfiable", 416)
    return start, end


def make_handler(config: ServerConfig, store: JobStore, log: EventLog, version: Optional[str]):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        server_version = "MothershipVideo/1"

        def log_message(self, *_args) -> None:  # request lines go to the JSONL log, not stderr
            return

        def _json(self, status: int, payload: object) -> None:
            body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(body)

        def _body(self) -> dict:
            try:
                length = int(self.headers.get("Content-Length") or 0)
            except ValueError:
                raise BadRequest("Bad request", 400)
            if length <= 0 or length > MAX_BODY:
                raise BadRequest("Bad request", 400)
            try:
                payload = json.loads(self.rfile.read(length))
            except (json.JSONDecodeError, UnicodeDecodeError):
                raise BadRequest("Bad request", 400)
            if not isinstance(payload, dict):
                raise BadRequest("Bad request", 400)
            return payload

        def _owner(self, value: object) -> str:
            if not isinstance(value, (str, int)) or isinstance(value, bool) or not OWNER.match(str(value)):
                raise BadRequest("Bad request", 400)
            return str(value)

        def _route(self) -> Tuple[List[str], dict]:
            parsed = urlparse(self.path)
            return [part for part in parsed.path.split("/") if part], parse_qs(parsed.query)

        def _dispatch(self, method: str) -> None:
            try:
                parts, query = self._route()
                owner = lambda: self._owner((query.get("owner") or [""])[0])  # noqa: E731
                if method == "GET" and parts == ["health"]:
                    usable = version is not None
                    self._json(200 if usable else 503, {
                        "ok": usable, "ytdlp": version, "workers": config.workers,
                        "ffmpeg": pathlib.Path(config.ffmpeg_dir, "ffmpeg").is_file(),
                        "optimizer": config.optimizer_ready, "optimize_max": config.optimize_max_bytes or None,
                        **store.stats(),
                    })
                elif method == "POST" and parts == ["probe"]:
                    url = validate_url(self._body().get("url"), allow_private=config.allow_private)
                    self._json(200, probe(config, url))
                elif method == "GET" and parts == ["jobs"]:
                    self._json(200, {"jobs": store.list(owner()), "keep_seconds": config.keep_seconds,
                                     "optimize": config.optimizer_ready,
                                     "optimize_max": config.optimize_max_bytes or None, "now": now()})
                elif method == "POST" and parts == ["jobs"]:
                    body = self._body()
                    url = validate_url(body.get("url"), allow_private=config.allow_private)
                    choice = body.get("choice")
                    choice_args(choice if isinstance(choice, str) else "")
                    title = body.get("title") if isinstance(body.get("title"), str) else None
                    job, created = store.add(self._owner(body.get("owner")), url, choice, title,
                                             optimize=body.get("optimize") is True)
                    self._json(201 if created else 200, {"job": job})
                elif len(parts) == 2 and parts[0] == "jobs" and JOB_ID.match(parts[1]) and method == "GET":
                    self._json(200, {"job": store.get(parts[1], owner())})
                elif len(parts) == 2 and parts[0] == "jobs" and JOB_ID.match(parts[1]) and method == "DELETE":
                    store.remove(parts[1], owner())
                    self._json(200, {"ok": True})
                elif len(parts) == 3 and parts[0] == "jobs" and JOB_ID.match(parts[1]) and parts[2] == "optimize" and method == "POST":
                    self.rfile.read(min(MAX_BODY, int(self.headers.get("Content-Length") or 0)))
                    job, claimed = store.begin_optimize(parts[1], owner())
                    if claimed:
                        start_optimize(store, parts[1])
                    self._json(202, {"job": job})
                elif len(parts) == 2 and parts[0] == "files" and JOB_ID.match(parts[1]) and method == "GET":
                    self._file(*store.file(parts[1], owner()))
                else:
                    self._json(404, {"message": "Not found"})
            except BadRequest as error:
                self._json(error.status, {"message": str(error)})
            except (BrokenPipeError, ConnectionResetError):
                pass
            except Exception as error:
                log.write("request.failed", {"path": urlparse(self.path).path, "error": repr(error)[:300]}, level="error")
                try:
                    self._json(500, {"message": "The video service hit an error."})
                except OSError:
                    pass

        def _file(self, path: pathlib.Path, name: str) -> None:
            size = path.stat().st_size
            span = parse_range(self.headers.get("Range"), size)
            start, end = span if span else (0, size - 1)
            kind = {".mp4": "video/mp4", ".mp3": "audio/mpeg", ".webm": "video/webm", ".mkv": "video/x-matroska",
                    ".m4a": "audio/mp4", ".mov": "video/quicktime"}.get(path.suffix.lower(), "application/octet-stream")
            self.send_response(206 if span else 200)
            self.send_header("Content-Type", kind)
            self.send_header("Content-Length", str(end - start + 1 if size else 0))
            self.send_header("Accept-Ranges", "bytes")
            if span:
                self.send_header("Content-Range", "bytes {}-{}/{}".format(start, end, size))
            ascii_name = name.encode("ascii", "ignore").decode().replace('"', "").strip() or "video" + path.suffix
            self.send_header("Content-Disposition", "attachment; filename=\"{}\"; filename*=UTF-8''{}".format(ascii_name, quote(name)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            remaining = end - start + 1 if size else 0
            with path.open("rb") as source:
                source.seek(start)
                while remaining > 0:
                    chunk = source.read(min(1024 * 1024, remaining))
                    if not chunk:
                        break
                    self.wfile.write(chunk)
                    remaining -= len(chunk)

        def do_GET(self) -> None:  # noqa: N802
            self._dispatch("GET")

        def do_POST(self) -> None:  # noqa: N802
            self._dispatch("POST")

        def do_DELETE(self) -> None:  # noqa: N802
            self._dispatch("DELETE")

    return Handler


def serve(config: ServerConfig) -> Tuple[ThreadingHTTPServer, JobStore]:
    """Build the server and start its workers; the caller runs serve_forever()."""
    config.data.mkdir(parents=True, exist_ok=True)
    log = EventLog(config)
    store = JobStore(config, log)
    version = ytdlp_version(config)
    proxy = EgressProxy(config, log)
    threading.Thread(target=proxy.serve_forever, name="video-egress", daemon=True).start()
    config.proxy = proxy.url
    config.ffmpeg_tools = install_ffmpeg_wrappers(config)
    for index in range(config.workers):
        thread = threading.Thread(target=worker, args=(store,), name="video-worker-{}".format(index), daemon=True)
        store.workers.append(thread)
        thread.start()
    threading.Thread(target=janitor, args=(store,), name="video-janitor", daemon=True).start()
    config.optimizer_ready = optimizer_health(config)
    threading.Thread(target=optimizer_watch, args=(config, store), name="video-optimizer-watch", daemon=True).start()
    server = ThreadingHTTPServer((config.host, config.port), make_handler(config, store, log, version))
    server.daemon_threads = True
    log.write("service.listening", {"host": config.host, "port": config.port, "ytdlp": version, "workers": config.workers, "egress": config.proxy, "optimizer": config.optimizer_ready})
    return server, store


def main() -> int:
    config = ServerConfig()
    server, store = serve(config)

    def shutdown(*_args) -> None:
        store.stop()
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)
    try:
        server.serve_forever()
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
