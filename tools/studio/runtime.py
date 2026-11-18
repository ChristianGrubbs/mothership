#!/usr/bin/env python3
"""Start and verify the private The Mothership stack with Apple container."""

from __future__ import annotations

import argparse
import base64
import hashlib
import html.parser
import importlib.util
import ipaddress
import json
import os
import pathlib
import re
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import asdict, dataclass, replace
from types import MappingProxyType
from datetime import datetime, timezone
from typing import Dict, Iterable, List, Mapping, Optional, Sequence, Set, Tuple

_METRICS_MODULE_NAME = "studio_system_metrics_process"
if _METRICS_MODULE_NAME in sys.modules:
    system_metrics_process = sys.modules[_METRICS_MODULE_NAME]
else:
    _metrics_path = pathlib.Path(__file__).parent / "system_metrics_process.py"
    _spec = importlib.util.spec_from_file_location(_METRICS_MODULE_NAME, _metrics_path)
    system_metrics_process = importlib.util.module_from_spec(_spec)
    sys.modules[_spec.name] = system_metrics_process
    _spec.loader.exec_module(system_metrics_process)


OWNER_LABEL = "org.mothership.runtime"
OWNER_VALUE = "studio-private-v1"
CONFIG_LABEL = "org.mothership.config"
NETWORK_NAME = "mothership-private"
# Pinned, not left to Apple container's allocator: `default` takes the first free 192.168.6x.0/24
# at every service start, and a private network that recorded the same subnet then fails to load
# at all (listed as missing, yet `network create` reports it already exists). 2026-10-08.
NETWORK_SUBNET = "192.168.164.0/24"
ELASTICSEARCH_VOLUME_INIT_NAME = "mothership-private-elasticsearch-volume-init"
RELAY_MAX_CONNECTIONS = 64

CONTAINER_NAMES = {
    "postgres": "mothership-private-postgres",
    "redis": "mothership-private-redis",
    "elasticsearch": "mothership-private-elasticsearch",
    "mailpit": "mothership-private-mailpit",
    "looper": "mothership-private-looper",
    "fpm": "mothership-private-fpm",
    "queue": "mothership-private-queue",
    "panorama_queue": "mothership-private-panorama-queue",
    "stylepress_queue": "mothership-private-stylepress-queue",
    "vectorization_queue": "mothership-private-vectorization-queue",
    "ai_index_queue": "mothership-private-ai-index-queue",
    "scheduler": "mothership-private-scheduler",
    "nginx": "mothership-private-nginx",
}

# Containers an older launcher created and this one no longer plans. `up` and `down` still
# remove an owned one, so an upgrade cannot leave a retired worker running unseen.
RETIRED_CONTAINER_NAMES = (
    "mothership-private-image-editor-queue",  # AI image edit removed 2026-09-21
)

VOLUME_NAMES = {
    "postgres": "mothership-private-postgres-data",
    "redis": "mothership-private-redis-data",
    "elasticsearch": "mothership-private-elasticsearch-data",
}

IMAGES = {
    "postgres": "docker.io/library/postgres:16-alpine",
    "redis": "docker.io/library/redis:7.2-alpine",
    "elasticsearch": "docker.io/library/elasticsearch:8.17.0",
    "mailpit": "docker.io/axllent/mailpit:v1.21",
    "nginx": "docker.io/library/nginx:1.27-alpine",
}

# Every deploy builds one tag per repository (<sha12>); `prune-images` deletes old ones. Only these two
# repositories are candidates: the Looper, infra and craft-* images are never selected.
PRUNABLE_IMAGE_REPOSITORIES = ("mothership-fpm", "mothership-q")

STATIC_SUFFIXES = {
    ".css",
    ".eot",
    ".gif",
    ".ico",
    ".jpeg",
    ".jpg",
    ".js",
    ".mjs",
    ".png",
    ".svg",
    ".ttf",
    ".wasm",
    ".webp",
    ".woff",
    ".woff2",
}


class RuntimeFailure(RuntimeError):
    pass


@dataclass(frozen=True)
class RuntimeNamespace:
    instance: str = "production"
    network: str = NETWORK_NAME
    subnet: str = NETWORK_SUBNET
    owner: str = OWNER_VALUE

    def __post_init__(self) -> None:
        expected = {
            "production": (NETWORK_NAME, NETWORK_SUBNET, OWNER_VALUE),
            "stage": ("mothership-stage", "192.168.165.0/24", "studio-stage-v1"),
        }
        if expected.get(self.instance) != (self.network, self.subnet, self.owner):
            raise RuntimeFailure("invalid instance namespace (network, subnet or owner)")

    def name(self, production_name: str) -> str:
        return production_name.replace(NETWORK_NAME, self.network, 1)

    @property
    def containers(self) -> Mapping[str, str]:
        return MappingProxyType({role: self.name(name) for role, name in CONTAINER_NAMES.items()})

    @property
    def volumes(self) -> Mapping[str, str]:
        return MappingProxyType({role: self.name(name) for role, name in VOLUME_NAMES.items()})

    @property
    def retired(self) -> Tuple[str, ...]:
        return tuple(self.name(name) for name in RETIRED_CONTAINER_NAMES)

    @property
    def initializer(self) -> str:
        return self.name(ELASTICSEARCH_VOLUME_INIT_NAME)


PRODUCTION_NAMESPACE = RuntimeNamespace()
STAGE_NAMESPACE = RuntimeNamespace(
    instance="stage", network="mothership-stage", subnet="192.168.165.0/24", owner="studio-stage-v1",
)
WEB_ROLES = ("postgres", "redis", "elasticsearch", "mailpit", "looper", "fpm", "nginx")
# No queue consumers are permitted in stage, including for a cloned jobs table/Redis dump.
# Explicit URLs also replace production-facing defaults in Laravel's config files.
STAGE_DISABLED_ENV = (
    "APP_ENV=staging", "STYLEPRESS_ENABLED=false", "MICROSOFT_SSO_ENABLED=false",
    "MOTHERSHIP_VECTORIZATION_ENABLED=false", "MOTHERSHIP_AI_INDEX_ENABLED=false",
    "MOTHERSHIP_SMART_SEARCH_ENABLED=false", "MOTHERSHIP_AI_HEALTH_CHECK=false",
    "MOTHERSHIP_AI_API=http://127.0.0.1:9",
    "MOTHERSHIP_OPTIMIZER_API=http://127.0.0.1:9",
    "MOTHERSHIP_HOME_VIDEO_API=http://127.0.0.1:9",
    "MOTHERSHIP_HOME_RSS_API=http://127.0.0.1:9", "MOTHERSHIP_HOME_TRENDING_API=http://127.0.0.1:9",
    "STYLEPRESS_HIGGSFIELD_API_BASE_URL=http://127.0.0.1:9", "STYLEPRESS_GROQ_BASE_URL=http://127.0.0.1:9",
)


@dataclass(frozen=True)
class Mount:
    source: pathlib.Path
    target: str
    readonly: bool = False
    track_source: bool = True

    def argument(self) -> str:
        parts = ["type=bind", "source={}".format(self.source), "target={}".format(self.target)]
        if self.readonly:
            parts.append("readonly")
        return ",".join(parts)


@dataclass(frozen=True)
class ContainerSpec:
    role: str
    name: str
    image: str
    cpus: int
    memory: str
    env: Tuple[str, ...] = ()
    inherit_env: Tuple[str, ...] = ()
    env_file: Optional[pathlib.Path] = None
    mounts: Tuple[Mount, ...] = ()
    named_volumes: Tuple[Tuple[str, str], ...] = ()
    publishes: Tuple[str, ...] = ()
    user: Optional[str] = None
    workdir: Optional[str] = None
    entrypoint: Optional[str] = None
    command: Tuple[str, ...] = ()
    network: str = NETWORK_NAME
    owner: str = OWNER_VALUE

    def digest(self) -> str:
        payload = asdict(self)
        # Keep production digests stable: merely upgrading the launcher must not restart it.
        if self.network == NETWORK_NAME and self.owner == OWNER_VALUE:
            payload.pop("network")
            payload.pop("owner")
        payload["mounts"] = [
            {
                "source": str(mount.source),
                "target": mount.target,
                "readonly": mount.readonly,
                "source_state": _path_state(mount.source) if mount.track_source else None,
            }
            for mount in self.mounts
        ]
        payload["env_file"] = str(self.env_file) if self.env_file else None
        if self.env_file and self.env_file.exists():
            stat = self.env_file.stat()
            payload["env_file_state"] = {"mtime_ns": stat.st_mtime_ns, "size": stat.st_size}
        encoded = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
        return hashlib.sha256(encoded).hexdigest()

    def run_args(self) -> List[str]:
        args = [
            "run",
            "--detach",
            "--name",
            self.name,
            "--network",
            self.network,
            "--cpus",
            str(self.cpus),
            "--memory",
            self.memory,
            "--label",
            "{}={}".format(OWNER_LABEL, self.owner),
            "--label",
            "{}={}".format(CONFIG_LABEL, self.digest()),
        ]
        if self.env_file:
            args.extend(["--env-file", str(self.env_file)])
        for item in self.env:
            args.extend(["--env", item])
        for key in self.inherit_env:
            args.extend(["--env", key])
        for mount in self.mounts:
            args.extend(["--mount", mount.argument()])
        for volume, target in self.named_volumes:
            args.extend(["--volume", "{}:{}".format(volume, target)])
        for publish in self.publishes:
            args.extend(["--publish", publish])
        if self.user:
            args.extend(["--user", self.user])
        if self.workdir:
            args.extend(["--workdir", self.workdir])
        if self.entrypoint:
            args.extend(["--entrypoint", self.entrypoint])
        args.append(self.image)
        args.extend(self.command)
        return args


@dataclass(frozen=True)
class NativeBridge:
    """A host-native sidecar the app reaches on the private gateway.

    The optimizer and AI bridges are started by ``runtime.py up`` through their
    own managers.
    """

    label: str
    env_key: str
    manager: str
    state_name: str
    # An optional bridge backs a feature the app works without. Its failure is logged and printed, and
    # never fails `up` or `verify`: the site must not stay down for it.
    optional: bool = False


# Started by `up` (idempotent) and health-checked by `verify` when configured.
MANAGED_BRIDGES = (
    NativeBridge(label="optimizer", env_key="MOTHERSHIP_OPTIMIZER_API", manager="clop_service.py", state_name="clop"),
    NativeBridge(label="ai", env_key="MOTHERSHIP_AI_API", manager="ai_service.py", state_name="ai"),
    NativeBridge(label="video", env_key="MOTHERSHIP_HOME_VIDEO_API", manager="video_service.py", state_name="video"),
)


@dataclass(frozen=True)
class RuntimeConfig:
    source: pathlib.Path
    state: pathlib.Path
    web_port: int = 18090
    mailpit_port: Optional[int] = None
    container_bin: str = "/opt/homebrew/bin/container"
    fpm_image: str = ""
    queue_image: str = ""
    looper_image: str = "mothership-looper:bf4b288"
    host_uid: int = 502
    host_gid: int = 20
    web_transport: str = "native-publish"
    python_executable: Optional[str] = None
    native_state: Optional[pathlib.Path] = None
    instance: str = "production"
    web_only: bool = False
    production_state: Optional[pathlib.Path] = None
    production_native_state: Optional[pathlib.Path] = None

    @property
    def namespace(self) -> RuntimeNamespace:
        if self.instance == "production":
            return PRODUCTION_NAMESPACE
        if self.instance == "stage":
            return STAGE_NAMESPACE
        raise RuntimeFailure("unknown runtime instance")

    @property
    def staging(self) -> bool:
        return self.namespace.instance == "stage"

    def validate(self, *, scan_aliases: bool = True) -> None:
        if not self.staging:
            if self.web_only:
                raise RuntimeFailure("--web-only requires --instance stage")
            return
        if not self.web_only or self.web_transport != "native-publish":
            raise RuntimeFailure("stage requires --web-only and native-publish")
        if self.web_port != 28090 or self.mailpit_port is not None:
            raise RuntimeFailure("stage requires loopback web port 28090 and no Mailpit publish")
        if self.production_state is None or self.native_state is None:
            raise RuntimeFailure("stage requires --production-state and --native-state")
        production = self.production_state.resolve()
        production_native = (self.production_native_state or production.parent / "Mothership Native Services").resolve()
        stage_paths = (self.state.resolve(), self.native_state.resolve())
        protected = (production, production_native, self.source.resolve())
        paths = stage_paths + protected
        for index, path in enumerate(stage_paths):
            for other in paths[index + 1:]:
                if path == other or path in other.parents or other in path.parents:
                    raise RuntimeFailure("stage state/native-state paths must be distinct and disjoint")
                if path.exists() and other.exists():
                    try:
                        if os.path.samefile(path, other):
                            raise RuntimeFailure("stage state/native-state paths must be distinct and disjoint")
                    except OSError:
                        pass
        # Reject aliases inside cloned state too, before chmod, writes or host process selection.
        if not scan_aliases:
            return
        for root in (self.state.absolute(), self.native_state.absolute()):
            if root.is_symlink():
                raise RuntimeFailure("stage paths must not contain symlinks")
            if root.exists():
                for child in root.rglob("*"):
                    if child.is_symlink() or (child.is_file() and child.stat().st_nlink != 1):
                        raise RuntimeFailure("stage state must not contain symlinks or hardlinks")

    def validate_stage_env(self) -> Dict[str, str]:
        self.validate(scan_aliases=False)
        if not self.env_file.is_file() or self.env_file.stat().st_mode & 0o077:
            raise RuntimeFailure("stage app.env must exist with private permissions")
        seen = set()
        for line in self.env_file.read_text(encoding="utf-8").splitlines():
            if line and not line.startswith("#") and not re.fullmatch(r"[A-Z_]+=[A-Za-z0-9_:/+.=\-]+", line):
                raise RuntimeFailure("stage app.env requires literal unquoted KEY=value lines")
            if line and not line.startswith("#"):
                key = line.split("=", 1)[0]
                if key in seen:
                    raise RuntimeFailure("stage app.env must not contain duplicate keys")
                seen.add(key)
        environment = read_env(path=self.env_file)
        allowed = {"APP_KEY", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "APP_URL"}
        if set(environment) - allowed:
            raise RuntimeFailure("stage app.env must contain only APP_KEY, DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL")
        if any(not environment.get(key) for key in allowed):
            raise RuntimeFailure("stage app.env is missing a required sanitized setting")
        if environment["APP_URL"] != "http://127.0.0.1:28090":
            raise RuntimeFailure("stage APP_URL must use its loopback web port")
        try:
            key = environment["APP_KEY"]
            if not key.startswith("base64:") or len(base64.b64decode(key[7:], validate=True)) != 32:
                raise ValueError("invalid key")
        except ValueError as error:
            raise RuntimeFailure("stage APP_KEY must be a base64-encoded 32-byte key") from error
        if any("$" in value or "\n" in value or "\r" in value for value in environment.values()):
            raise RuntimeFailure("stage app.env must not contain interpolation")
        if not environment["DB_DATABASE"].startswith("mothership_stage") or not environment["DB_USERNAME"].startswith("mothership_stage"):
            raise RuntimeFailure("stage database and username must use the mothership_stage prefix")
        production_env = self.production_state / "app.env"
        if not production_env.is_file():
            raise RuntimeFailure("production app.env is required to validate distinct stage credentials")
        original = read_env(path=production_env)
        for key in ("APP_KEY", "DB_PASSWORD"):
            if not original.get(key) or environment[key] == original[key]:
                raise RuntimeFailure("stage credentials must be distinct from production")
        return environment

    @property
    def native_services(self) -> pathlib.Path:
        # Sibling of the private state root: "Studio Data/Mothership Native Services".
        return self.native_state or self.state.parent / "Mothership Native Services"

    @property
    def env_file(self) -> pathlib.Path:
        return self.state / "app.env"

    @property
    def storage(self) -> pathlib.Path:
        return self.state / "storage"

    @property
    def generated(self) -> pathlib.Path:
        return self.state / "runtime"

    @property
    def higgsfield_session(self) -> pathlib.Path:
        return self.state / "higgsfield-session"

    @property
    def looper_uploads(self) -> pathlib.Path:
        return self.state / "looper" / "uploads"

    @property
    def relay_record(self) -> pathlib.Path:
        return self.generated / "web-relay.json"

    @property
    def relay_log(self) -> pathlib.Path:
        return self.generated / "web-relay.log"

    @property
    def system_metrics_snapshot(self) -> pathlib.Path:
        # The PHP reader resolves storage_path("app/private/system-quickview.json"); keep both in step.
        return self.storage / "app" / "private" / "system-quickview.json"

    @property
    def system_metrics_receipt(self) -> pathlib.Path:
        return self.generated / "metrics" / "receipt.json"

    @property
    def system_metrics_interval_seconds(self) -> float:
        return 5.0

    @property
    def system_metrics_python_executable(self) -> str:
        return self.python_executable or sys.executable


def _snapshot_fresh(status: object) -> bool:
    age = getattr(status, "snapshot_age_seconds", None)
    return age is not None and age <= 15.0


class EventLog:
    def __init__(self, state: pathlib.Path):
        self.enabled = os.environ.get("STUDIO_LOG", "").lower() in {"1", "true", "yes", "on"}
        self.path = state / "events.jsonl"
        self.run_id = str(uuid.uuid4())

    def write(self, level: str, event: str, loc: str, ctx: Optional[Mapping[str, object]] = None) -> None:
        if not self.enabled:
            return
        self.path.parent.mkdir(parents=True, exist_ok=True)
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "run_id": self.run_id,
            "level": level,
            "event": event,
            "loc": loc,
            "ctx": dict(ctx or {}),
        }
        with self.path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n")


class Runner:
    def __init__(self, binary: str):
        self.binary = binary
        self._redactions: Set[str] = set()

    def add_redactions(self, values: Iterable[str]) -> None:
        self._redactions.update(value for value in values if value)

    def _sanitize(self, value: str) -> str:
        for secret in sorted(self._redactions, key=len, reverse=True):
            value = value.replace(secret, "[redacted]")
        return value

    def run(
        self,
        args: Sequence[str],
        *,
        child_env: Optional[Mapping[str, str]] = None,
        check: bool = True,
        timeout: int = 300,
    ) -> str:
        environment = os.environ.copy()
        if child_env:
            environment.update(child_env)
        try:
            result = subprocess.run(
                [self.binary, *args],
                check=False,
                capture_output=True,
                text=True,
                env=environment,
                timeout=timeout,
            )
        except (OSError, subprocess.TimeoutExpired) as error:
            raise RuntimeFailure("container command failed: {}".format(self._sanitize(str(error)))) from error
        if check and result.returncode != 0:
            detail = result.stderr.strip() or result.stdout.strip() or "exit {}".format(result.returncode)
            raise RuntimeFailure(self._sanitize(detail))
        return result.stdout

    def json(self, args: Sequence[str]) -> object:
        output = self.run(args)
        try:
            return json.loads(output)
        except json.JSONDecodeError as error:
            raise RuntimeFailure("container returned invalid JSON") from error

    def list_container_names(self) -> Set[str]:
        return _names_from_listing(self.json(["list", "--all", "--format", "json"]))

    def network_names(self) -> Set[str]:
        return _names_from_listing(self.json(["network", "list", "--format", "json"]))

    def volume_names(self) -> Set[str]:
        return _names_from_listing(self.json(["volume", "list", "--format", "json"]))

    def inspect(self, name: str) -> object:
        return self.json(["inspect", name])

    def inspect_network(self, name: str) -> object:
        return self.json(["network", "inspect", name])

    def inspect_volume(self, name: str) -> object:
        return self.json(["volume", "inspect", name])

    def builder_status(self) -> object:
        return self.json(["builder", "status", "--format", "json"])

    def list_containers(self) -> object:
        return self.json(["list", "--all", "--format", "json"])

    def list_images(self) -> object:
        return self.json(["image", "list", "--format", "json"])


def _walk(payload: object) -> Iterable[Tuple[str, object]]:
    if isinstance(payload, dict):
        for key, value in payload.items():
            yield key, value
            yield from _walk(value)
    elif isinstance(payload, list):
        for value in payload:
            yield from _walk(value)


def _path_state(path: pathlib.Path) -> object:
    if path.is_file():
        stat = path.stat()
        return {"kind": "file", "mtime_ns": stat.st_mtime_ns, "size": stat.st_size}
    if path.is_dir():
        files = []
        for child in sorted(item for item in path.rglob("*") if item.is_file()):
            stat = child.stat()
            files.append(
                {
                    "path": str(child.relative_to(path)),
                    "mtime_ns": stat.st_mtime_ns,
                    "size": stat.st_size,
                }
            )
        return {"kind": "directory", "files": files}
    return None


def _names_from_listing(payload: object) -> Set[str]:
    rows = payload if isinstance(payload, list) else [payload]
    names: Set[str] = set()
    for row in rows:
        if not isinstance(row, dict):
            continue
        configuration = row.get("configuration")
        if isinstance(configuration, dict):
            candidate = configuration.get("name") or configuration.get("id")
            if isinstance(candidate, str):
                names.add(candidate)
                continue
        candidate = row.get("name") or row.get("id")
        if isinstance(candidate, str):
            names.add(candidate)
    return names


def extract_labels(payload: object) -> Mapping[str, str]:
    for key, value in _walk(payload):
        if key == "labels" and isinstance(value, dict):
            return {str(item_key): str(item_value) for item_key, item_value in value.items()}
    return {}


def extract_state(payload: object) -> str:
    for key, value in _walk(payload):
        if key == "state" and isinstance(value, str):
            return value.lower()
    return "unknown"


def extract_ipv4(payload: object) -> str:
    for key, value in _walk(payload):
        if key != "ipv4Address" or not isinstance(value, str):
            continue
        candidate = value.split("/", 1)[0]
        try:
            address = ipaddress.ip_address(candidate)
        except ValueError:
            continue
        if address.version == 4:
            return str(address)
    raise RuntimeFailure("container inspect did not include an IPv4 address")


def extract_ipv4_gateway(payload: object) -> str:
    for key, value in _walk(payload):
        if key != "ipv4Gateway" or not isinstance(value, str):
            continue
        try:
            address = ipaddress.ip_address(value)
        except ValueError:
            continue
        if address.version == 4:
            return str(address)
    raise RuntimeFailure("private network inspect did not include an IPv4 gateway")


def namespace_resolver_ip(namespace: "RuntimeNamespace") -> str:
    """A container's DNS is its own network's gateway, the subnet's first host (checked 2026-11-18)."""
    return str(next(ipaddress.ip_network(namespace.subnet).hosts()))


def render_nginx(fpm_ip: str, resolver_ip: str | None = None) -> str:
    ipaddress.ip_address(fpm_ip)
    # Production's gateway when no namespace is given; Stack passes its own namespace's gateway.
    resolver_ip = resolver_ip or str(next(ipaddress.ip_network(NETWORK_SUBNET).hosts()))
    ipaddress.ip_address(resolver_ip)
    template = r'''map $http_x_forwarded_proto $mothership_forwarded_proto {
    default $scheme;
    http http;
    https https;
}

# A web page bundle URL carries its capability (admin grant, share token) as a path segment, so
# the two page locations below clear this flag and those requests are never access-logged.
map $host $mothership_log {
    default 1;
}

server {
    listen 80;
    listen [::]:80;
    server_name _;
    root /var/www/html/public;
    index index.php;
    charset utf-8;
    client_max_body_size 200M;
    client_body_timeout 600s;

    gzip on;
    gzip_types application/json application/javascript application/xml application/wasm font/ttf image/svg+xml model/gltf-binary text/css text/plain;
    gzip_min_length 512;
    gzip_comp_level 6;
    gzip_vary on;

    # Only the registered OAuth discovery documents bypass dotfile denial; Laravel
    # still owns enablement and response policy, never a public filesystem file.
    location = /.well-known/oauth-protected-resource/mcp { rewrite ^ /index.php last; }
    location = /.well-known/oauth-authorization-server { rewrite ^ /index.php last; }

    location ^~ /cache/ { try_files $uri /index.php?$query_string; }

    # Web page bundles (ADR 0007): every sibling (.css, .js, images, fonts) must reach the
    # grant/share-checking Laravel route, and the capability in the URL must never be logged.
    # nginx takes the first matching regex, so these come before every other regex location
    # (a bundle path may itself contain home/tools/ or end in .css). The share family also
    # matches the slashless /page, which Laravel redirects to /page/. The share alternation mirrors
    # the four session-free routes in web-pages.php (page, pg, pf, pgf) with their segment shapes
    # (pin = asset.attachment.file.64hex, grant = id.64hex); a malformed segment gains no bypass.
    location ~ ^/(?:[^/]+/)*dam/pages/[0-9]+/g/ {
        set $mothership_log 0;
        rewrite ^ /index.php last;
    }
    # Quoted: an unquoted `{` in the regex quantifier would open the location block.
    location ~ "^/share/[A-Za-z0-9]{20,64}/(?:page|pg/[0-9]{1,12}\.[0-9a-f]{64}|pf/[0-9]{1,18}\.[0-9]{1,18}\.[0-9]{1,18}\.[0-9a-f]{64}|pgf/[0-9]{1,12}\.[0-9a-f]{64}/[0-9]{1,18}\.[0-9]{1,18}\.[0-9]{1,18}\.[0-9a-f]{64})(?:/|$)" {
        set $mothership_log 0;
        rewrite ^ /index.php last;
    }

    location ~ ^/(?:[^/]+/)*home/(tools|service)/ { rewrite ^ /index.php last; }

    location ^~ /storage/ {
        alias /var/www/html/storage/app/public/;
        location ~* \.(php[0-9]?|phtml|pht|phar|html?|shtml|xhtml)$ { return 404; }
    }
    location ~* \.mjs$ {
        default_type application/javascript;
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }
    location ~* \.(jpg|jpeg|gif|png|svg|ico|webp|avif|woff|woff2|ttf|eot|css|js|map|wasm)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass __FPM_IP__:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/public$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT /var/www/html/public;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $mothership_forwarded_proto;
        fastcgi_param HTTP_X_REAL_IP $remote_addr;
        fastcgi_buffers 32 32k;
        fastcgi_buffer_size 128k;
        fastcgi_busy_buffers_size 256k;
        fastcgi_read_timeout 600;
        fastcgi_send_timeout 600;
        internal;
    }

    # ^~ keeps the static-extension regex below from claiming internal media redirects.
    location ^~ /__dam_media/ {
        internal;
        alias /var/www/html/storage/app/private/;
        # Gzip would drop Content-Length and byte ranges (a GLB streamed chunked, Range -> 200).
        gzip off;
        add_header X-Content-Type-Options nosniff always;
    }
    # Remote media (ADR 0017): PHP hands nginx a blob's signed object-store URL; nginx streams it same-origin.
    location ^~ /__dam_remote/ {
        internal;
        location ~ ^/__dam_remote/(https?)/(\[[0-9A-Fa-f:.]+\]|[^/:\[\]]+)(:[0-9]+)?/(.+)$ {
            internal;
            set $dam_remote_scheme $1;
            set $dam_remote_host $2;
            set $dam_remote_port $3;
            set $dam_remote_path $4;
            # Variables in proxy_pass resolve at request time, through the private network's gateway DNS.
            resolver __RESOLVER_IP__ valid=30s ipv6=off;
            proxy_pass $dam_remote_scheme://$dam_remote_host$dam_remote_port/$dam_remote_path$is_args$args;
            proxy_ssl_server_name on;
            proxy_ssl_name $dam_remote_host;
            proxy_set_header Host $dam_remote_host$dam_remote_port;
            proxy_set_header Authorization "";
            proxy_set_header Cookie "";
            proxy_http_version 1.1;
            proxy_set_header Connection "";
            proxy_hide_header Content-Disposition;
            proxy_hide_header Set-Cookie;
            proxy_buffering off;
            gzip off;
            add_header X-Content-Type-Options nosniff always;
        }
        return 404;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; access_log off; }
    access_log /dev/stdout combined if=$mothership_log;
    error_log /dev/stderr notice;
}
'''
    return template.replace("__FPM_IP__", fpm_ip).replace("__RESOLVER_IP__", resolver_ip)


# Sizing, for the fpm container's 4G / 2 vCPU grant (ContainerSpec role="fpm"):
#   - an idle ondemand pool holds no workers, so the shared cost is opcache (256M, zz-studio-opcache.ini) plus the
#     master and nginx-facing socket, about 350M;
#   - a booted worker serving a page measured ~80M RSS (CLI proxy, 2026-10-16); an image decode is bounded by
#     php.ini memory_limit = 512M, which is what the old value of 6 was sized for (6 x 512M = 3G);
#   - 10 workers = ~1.2G typical, and a simultaneous 512M peak in seven of them still fits (7 x 512M + 3 x 120M + 350M < 4G).
# Six saturated: 32 "reached max_children" warnings in ~4h, each one a request queued behind 80 s AI renames and
# first-view thumbnail generation. Ten removes the queueing without paying the 512M worst case for every slot.
FPM_MAX_CHILDREN = 10


def render_fpm_pool() -> str:
    return f"""[www]\npm = ondemand\npm.max_children = {FPM_MAX_CHILDREN}\npm.process_idle_timeout = 10s\npm.max_requests = 500\n"""


def render_opcache() -> str:
    return """opcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=30000\nopcache.validate_timestamps=0\n"""


def render_fpm_entrypoint() -> str:
    return r'''#!/bin/bash
set -e

ROOT=/var/www/html

cp /studio-runtime/zz-studio.conf /usr/local/etc/php-fpm.d/zz-studio.conf
cp /studio-runtime/zz-studio-opcache.ini /usr/local/etc/php/conf.d/zz-studio-opcache.ini

source "${ROOT}/dockerfiles/lib/ensure-vendor.sh"
source "${ROOT}/dockerfiles/lib/ensure-app-key.sh"
source "${ROOT}/dockerfiles/lib/wait-for-db.sh"
source "${ROOT}/dockerfiles/lib/setup-app.sh"

ensure_vendor "$ROOT"
ensure_app_key
wait_for_db
exec 2>&1
setup_app "$ROOT"

# Apple virtiofs rejects chown on the host storage bind. The image's www-data
# user is built as the Studio owner (502:20), so preserve host ownership.
chown -R www-data:www-data "${ROOT}/bootstrap/cache"

php artisan view:clear --no-interaction >/dev/null 2>&1 || true

# Container env is final here (the launcher injects dependency IPs before start),
# so cache config, routes and events for FPM. A failed cache must never block boot.
if ! { php artisan config:cache --no-interaction \
    && php artisan route:cache --no-interaction \
    && php artisan event:cache --no-interaction; } >/dev/null 2>&1; then
    echo "framework cache failed; serving uncached"
    php artisan optimize:clear --no-interaction >/dev/null 2>&1 || true
fi
chown -R www-data:www-data "${ROOT}/bootstrap/cache"

echo "→ Mothership ready on ${APP_URL:-http://localhost:8000}/${APP_ADMIN_URL:-admin}"

exec php-fpm
'''


def render_worker_supervisor() -> str:
    return r'''#!/bin/bash
set -u

worker_pid_file="${STUDIO_WORKER_PID_FILE:-/tmp/studio-worker.pid}"
# A failure (any exit but 0 or 12, a PHP fatal's 255 included) restarts the worker after a pause. Only
# max_failures of them in a row stop the container; a clean recycle, or a run of at least stable_seconds,
# starts the count again. One job that kills the worker must not hold every queue behind it.
max_failures="${STUDIO_WORKER_MAX_FAILURES:-5}"
failure_backoff="${STUDIO_WORKER_FAILURE_BACKOFF:-5}"
stable_seconds="${STUDIO_WORKER_STABLE_SECONDS:-60}"
failures=0
child=0
shutdown() {
    if (( child > 0 )); then
        kill -TERM "$child" 2>/dev/null || true
        wait "$child" 2>/dev/null || true
    fi
    rm -f "$worker_pid_file"
    exit 143
}
trap shutdown TERM INT

while true; do
    started=$SECONDS
    "$@" &
    child=$!
    echo "$child" > "$worker_pid_file"
    wait "$child"
    status=$?
    child=0
    rm -f "$worker_pid_file"

    if (( status == 0 || status == 12 )); then
        failures=0
        echo "worker recycled with status ${status}; restarting"
        sleep 2
        continue
    fi

    if (( SECONDS - started >= stable_seconds )); then
        failures=0
    fi
    failures=$(( failures + 1 ))

    if (( failures >= max_failures )); then
        echo "worker stopped with status ${status} after ${failures} consecutive failures; leaving container stopped"
        exit "$status"
    fi

    echo "worker failed with status ${status} (${failures}/${max_failures}); restarting in ${failure_backoff}s"
    # Waited on, not run in the foreground, so TERM ends the pause at once.
    sleep "$failure_backoff" &
    wait $!
done
'''


def elasticsearch_volume_init_args(namespace: RuntimeNamespace = PRODUCTION_NAMESPACE) -> List[str]:
    return [
        "run",
        "--rm",
        "--name",
        namespace.initializer,
        *(("--network", namespace.network) if namespace.instance == "stage" else ()),
        "--cpus",
        "1",
        "--memory",
        "256M",
        "--label",
        "{}={}".format(OWNER_LABEL, namespace.owner),
        "--volume",
        "{}:/data".format(namespace.volumes["elasticsearch"]),
        "docker.io/library/alpine:latest",
        "chown",
        "1000:0",
        "/data",
    ]


def network_create_args(namespace: RuntimeNamespace = PRODUCTION_NAMESPACE) -> List[str]:
    return [
        "network",
        "create",
        "--label",
        "{}={}".format(OWNER_LABEL, namespace.owner),
        "--subnet",
        namespace.subnet,
        namespace.network,
    ]


def _atomic_write(path: pathlib.Path, content: str, mode: int = 0o644) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists() and path.read_text(encoding="utf-8") == content:
        if stat.S_IMODE(path.stat().st_mode) != mode:
            path.chmod(mode)
        return
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=path.parent, delete=False) as handle:
        handle.write(content)
        temporary = pathlib.Path(handle.name)
    temporary.chmod(mode)
    temporary.replace(path)


class TcpRelayServer:
    """A loopback-only byte relay with full-duplex streaming and half-close support."""

    def __init__(
        self,
        listen_host: str,
        listen_port: int,
        target_host: str,
        target_port: int,
        on_notice=None,
    ):
        if listen_host != "127.0.0.1":
            raise RuntimeFailure("web relay must listen on 127.0.0.1")
        target = ipaddress.ip_address(target_host)
        if target.version != 4:
            raise RuntimeFailure("web relay target must be IPv4")
        self.listen_host = listen_host
        self.listen_port = listen_port
        self.target_host = str(target)
        self.target_port = target_port
        self.bound_port = 0
        self.ready = threading.Event()
        self._stopping = threading.Event()
        self._listener: Optional[socket.socket] = None
        self._active: Set[socket.socket] = set()
        self._active_lock = threading.Lock()
        self._slots = threading.BoundedSemaphore(RELAY_MAX_CONNECTIONS)
        self._on_notice = on_notice or (lambda _event: None)

    @staticmethod
    def _close(sock: socket.socket) -> None:
        try:
            sock.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        try:
            sock.close()
        except OSError:
            pass

    def _pump(self, source: socket.socket, destination: socket.socket) -> None:
        try:
            while not self._stopping.is_set():
                chunk = source.recv(256 * 1024)
                if not chunk:
                    try:
                        destination.shutdown(socket.SHUT_WR)
                    except OSError:
                        pass
                    return
                destination.sendall(chunk)
        except OSError:
            self._close(source)
            self._close(destination)

    def _serve_connection(self, client: socket.socket) -> None:
        upstream: Optional[socket.socket] = None
        try:
            try:
                upstream = socket.create_connection((self.target_host, self.target_port), timeout=10)
            except OSError:
                self._on_notice("upstream_unavailable")
                return
            upstream.settimeout(None)
            client.settimeout(None)
            with self._active_lock:
                self._active.update((client, upstream))
            request_thread = threading.Thread(
                target=self._pump,
                args=(client, upstream),
                daemon=True,
            )
            request_thread.start()
            self._pump(upstream, client)
            request_thread.join(timeout=2)
        finally:
            self._close(client)
            if upstream is not None:
                self._close(upstream)
            with self._active_lock:
                self._active.discard(client)
                if upstream is not None:
                    self._active.discard(upstream)
            self._slots.release()

    def _dispatch(self, client: socket.socket) -> None:
        if not self._slots.acquire(blocking=False):
            self._close(client)
            self._on_notice("capacity_refused")
            return
        worker = threading.Thread(target=self._serve_connection, args=(client,), daemon=True)
        try:
            worker.start()
        except RuntimeError:
            self._close(client)
            self._slots.release()
            self._on_notice("thread_start_failed")

    def serve_forever(self, on_ready=None) -> None:
        listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            listener.bind((self.listen_host, self.listen_port))
            listener.listen(128)
            listener.settimeout(0.5)
            self._listener = listener
            self.bound_port = int(listener.getsockname()[1])
            if on_ready is not None:
                on_ready()
            self.ready.set()
            while not self._stopping.is_set():
                try:
                    client, _ = listener.accept()
                except socket.timeout:
                    continue
                except OSError:
                    if self._stopping.is_set():
                        break
                    raise
                self._dispatch(client)
        finally:
            self._stopping.set()
            self._close(listener)
            with self._active_lock:
                active = tuple(self._active)
            for connection in active:
                self._close(connection)

    def shutdown(self) -> None:
        self._stopping.set()
        if self._listener is not None:
            self._close(self._listener)


class RelayManager:
    def __init__(self, config: RuntimeConfig, events: EventLog):
        self.config = config
        self.events = events
        self.script = pathlib.Path(__file__).resolve()

    def _read_record(self) -> Optional[Mapping[str, object]]:
        if not self.config.relay_record.exists():
            return None
        try:
            record = json.loads(self.config.relay_record.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as error:
            raise RuntimeFailure("web relay ownership record is invalid") from error
        required = {
            "pid": int,
            "token": str,
            "listen_host": str,
            "listen_port": int,
            "target_host": str,
            "target_port": int,
            "script": str,
        }
        if not isinstance(record, dict) or any(not isinstance(record.get(key), kind) for key, kind in required.items()):
            raise RuntimeFailure("web relay ownership record is invalid")
        return record

    def _discard_record(self, reason: str) -> None:
        self.config.relay_record.unlink(missing_ok=True)
        self.events.write(
            "warning",
            "relay.record_discarded",
            "RelayManager._discard_record",
            {"reason": reason},
        )

    def _load_record(self) -> Tuple[Optional[Mapping[str, object]], Optional[str]]:
        try:
            return self._read_record(), None
        except RuntimeFailure:
            self._discard_record("invalid")
            return None, "invalid record discarded"

    @staticmethod
    def _alive(pid: int) -> bool:
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return False
        except PermissionError:
            return True
        return True

    @staticmethod
    def _command(pid: int) -> str:
        result = subprocess.run(
            ["/bin/ps", "-ww", "-p", str(pid), "-o", "command="],
            check=False,
            capture_output=True,
            text=True,
            timeout=5,
        )
        return result.stdout.strip() if result.returncode == 0 else ""

    def _owned_process(self, record: Mapping[str, object]) -> bool:
        command = self._command(int(record["pid"]))
        markers = (str(self.script), "relay-serve", str(record["token"]))
        return (
            str(record["script"]) == str(self.script)
            and bool(command)
            and all(marker in command for marker in markers)
        )

    def _remove_record(self, token: str) -> None:
        record, _ = self._load_record()
        if record is not None and record["token"] == token:
            self.config.relay_record.unlink(missing_ok=True)

    def stop(self) -> None:
        record, _ = self._load_record()
        if record is None:
            return
        pid = int(record["pid"])
        token = str(record["token"])
        if not self._alive(pid):
            self._discard_record("dead_pid")
            return
        if not self._owned_process(record):
            self._discard_record("reused_or_foreign_pid")
            return
        os.kill(pid, signal.SIGTERM)
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline and self._alive(pid):
            time.sleep(0.1)
        if self._alive(pid):
            os.kill(pid, signal.SIGKILL)
        self._remove_record(token)
        self.events.write("info", "relay.stopped", "RelayManager.stop", {"pid": pid})

    def start(self, target_host: str) -> None:
        target = ipaddress.ip_address(target_host)
        if target.version != 4 or target.is_loopback or not target.is_private:
            raise RuntimeFailure("web relay target must be a private non-loopback IPv4 address")
        record, _ = self._load_record()
        if record is not None and self._alive(int(record["pid"])):
            if not self._owned_process(record):
                self._discard_record("reused_or_foreign_pid")
                record = None
        elif record is not None:
            self._discard_record("dead_pid")
            record = None
        if record is not None:
            if (
                record["listen_host"] == "127.0.0.1"
                and record["listen_port"] == self.config.web_port
                and record["target_host"] == target_host
                and record["target_port"] == 80
            ):
                return
            self.stop()

        token = uuid.uuid4().hex
        command = [
            sys.executable,
            str(self.script),
            "--source", str(self.config.source),
            "--state", str(self.config.state),
            "--web-port", str(self.config.web_port),
            "--host-uid", str(self.config.host_uid),
            "--host-gid", str(self.config.host_gid),
            "relay-serve",
            "--target-host", target_host,
            "--target-port", "80",
            "--relay-token", token,
        ]
        self.config.generated.mkdir(parents=True, exist_ok=True)
        descriptor = os.open(self.config.relay_log, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        os.chmod(self.config.relay_log, 0o600)
        with os.fdopen(descriptor, "a", encoding="utf-8") as log:
            process = subprocess.Popen(
                command,
                stdin=subprocess.DEVNULL,
                stdout=log,
                stderr=subprocess.STDOUT,
                start_new_session=True,
                close_fds=True,
            )
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if process.poll() is not None:
                raise RuntimeFailure("web relay stopped during startup; inspect state/runtime/web-relay.log")
            record, _ = self._load_record()
            if record is not None and record["token"] == token:
                self.require(target_host)
                self.events.write(
                    "info",
                    "relay.started",
                    "RelayManager.start",
                    {"pid": process.pid, "target_host": target_host, "listen_port": self.config.web_port},
                )
                return
            time.sleep(0.1)
        process.terminate()
        raise RuntimeFailure("web relay did not become ready in 10s")

    def require(self, target_host: str) -> None:
        record, detail = self._load_record()
        if record is None or not self._alive(int(record["pid"])):
            if record is not None:
                self._discard_record("dead_pid")
            raise RuntimeFailure("web relay is not running{}".format(" ({})".format(detail) if detail else ""))
        if not self._owned_process(record):
            self._discard_record("reused_or_foreign_pid")
            raise RuntimeFailure("web relay is not running (stale record discarded)")
        if (
            record["listen_host"] != "127.0.0.1"
            or record["listen_port"] != self.config.web_port
            or record["target_host"] != target_host
            or record["target_port"] != 80
        ):
            raise RuntimeFailure("web relay target does not match the current Nginx container")
        try:
            with socket.create_connection(("127.0.0.1", self.config.web_port), timeout=3):
                pass
        except OSError as error:
            raise RuntimeFailure("web relay listener is unavailable: {}".format(error)) from error

    def describe(self) -> str:
        record, detail = self._load_record()
        if record is None:
            return "stopped{}".format(" ({})".format(detail) if detail else "")
        if not self._alive(int(record["pid"])):
            self._discard_record("dead_pid")
            return "stopped (stale record discarded)"
        if not self._owned_process(record):
            self._discard_record("reused_or_foreign_pid")
            return "stopped (stale record discarded)"
        return "running 127.0.0.1:{} -> {}:80".format(self.config.web_port, record["target_host"])


def serve_relay(config: RuntimeConfig, target_host: str, target_port: int, token: str) -> None:
    target = ipaddress.ip_address(target_host)
    if target.version != 4 or target.is_loopback or not target.is_private:
        raise RuntimeFailure("web relay target must be a private non-loopback IPv4 address")
    events = EventLog(state=config.state)

    def relay_notice(event: str) -> None:
        print("web relay notice: {}".format(event), flush=True)
        events.write(
            "warning",
            "relay.{}".format(event),
            "TcpRelayServer._dispatch",
            {"max_connections": RELAY_MAX_CONNECTIONS},
        )

    server = TcpRelayServer(
        listen_host="127.0.0.1",
        listen_port=config.web_port,
        target_host=target_host,
        target_port=target_port,
        on_notice=relay_notice,
    )

    def record_ready() -> None:
        record = {
            "pid": os.getpid(),
            "token": token,
            "listen_host": "127.0.0.1",
            "listen_port": server.bound_port,
            "target_host": target_host,
            "target_port": target_port,
            "script": str(pathlib.Path(__file__).resolve()),
        }
        _atomic_write(
            path=config.relay_record,
            content=json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n",
            mode=0o600,
        )
        events.write("info", "relay.listening", "serve_relay", {"target_host": target_host})

    def stop_relay(_signum, _frame) -> None:
        server.shutdown()

    signal.signal(signal.SIGTERM, stop_relay)
    signal.signal(signal.SIGINT, stop_relay)
    try:
        server.serve_forever(on_ready=record_ready)
    finally:
        if config.relay_record.exists():
            try:
                record = json.loads(config.relay_record.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                record = {}
            if record.get("token") == token:
                config.relay_record.unlink(missing_ok=True)
        events.write("info", "relay.exited", "serve_relay")


def read_env(path: pathlib.Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].lstrip()
        if "=" not in line:
            raise RuntimeFailure("invalid app.env line {}".format(line_number))
        key, value = line.split("=", 1)
        key = key.strip()
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key):
            raise RuntimeFailure("invalid app.env key on line {}".format(line_number))
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]
        values[key] = value
    return values


def _secret_values(environment: Mapping[str, str]) -> Iterable[str]:
    for key, value in environment.items():
        if any(fragment in key.upper() for fragment in ("PASSWORD", "SECRET", "TOKEN", "APP_KEY")):
            yield value


class StaticReferences(html.parser.HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.references: List[str] = []

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, Optional[str]]]) -> None:
        for key, value in attrs:
            if key in {"href", "src"} and value:
                self.references.append(value)


def select_static_urls(
    body: str,
    page_url: str,
    loopback_netloc: str,
    extra_netlocs: Iterable[str] = (),
) -> List[str]:
    """Pick the login page's own static assets and address them through the loopback relay.

    Relative references and references on the loopback authority always qualify. Once
    APP_URL names a public origin the rendered markup carries that authority instead, so it
    is accepted as well and rewritten back to the loopback relay for fetching -- the check
    stays a local one. Any other authority is a third-party asset and is ignored.
    """
    allowed = {loopback_netloc}
    allowed.update(netloc for netloc in extra_netlocs if netloc)
    parser = StaticReferences()
    parser.feed(body)
    selected: List[str] = []
    for reference in parser.references:
        parsed = urllib.parse.urlparse(urllib.parse.urljoin(page_url, reference))
        if parsed.netloc not in allowed:
            continue
        if pathlib.PurePosixPath(parsed.path).suffix.lower() not in STATIC_SUFFIXES:
            continue
        local = urllib.parse.urlunparse(parsed._replace(scheme="http", netloc=loopback_netloc))
        if local not in selected:
            selected.append(local)
    return selected


class BuildCommitMeta(html.parser.HTMLParser):
    """Collects the content of <meta name="mothership-build-commit">."""

    def __init__(self) -> None:
        super().__init__()
        self.commit: Optional[str] = None

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, Optional[str]]]) -> None:
        if tag != "meta" or self.commit is not None:
            return
        values = dict(attrs)
        if values.get("name") == "mothership-build-commit":
            self.commit = (values.get("content") or "").strip()


def check_build_commit(body: str, fpm_image: str) -> Tuple[str, str]:
    """Compare the commit a page publishes with the FPM image tag it should be serving.

    Returns (status, commit): "match" when the published full commit starts with the image
    tag's <sha12>, "unknown" when the page names no commit (an image built before the
    MOTHERSHIP_BUILD_COMMIT build argument, or a tag that is not a hex SHA), and "mismatch"
    otherwise.
    """
    parser = BuildCommitMeta()
    parser.feed(body)
    commit = (parser.commit or "").lower()
    tag = fpm_image.rsplit(":", 1)[-1].lower() if ":" in fpm_image else ""
    if not re.fullmatch(r"[0-9a-f]{40}", commit) or not re.fullmatch(r"[0-9a-f]{7,40}", tag):
        return "unknown", commit or "unknown"
    return ("match" if commit.startswith(tag) else "mismatch"), commit


def _image_reference(name: str) -> str:
    return name[len("docker.io/library/"):] if name.startswith("docker.io/library/") else name


def _image_repository_tag(name: str) -> Optional[Tuple[str, str]]:
    reference = _image_reference(name)
    if "@" in reference or ":" not in reference:
        return None
    repository, tag = reference.rsplit(":", 1)
    return repository, tag


@dataclass(frozen=True)
class ImagePrunePlan:
    keep: Tuple[Tuple[str, str], ...]
    remove: Tuple[str, ...]


def select_image_prunes(images: object, containers: object, live_images: Sequence[str]) -> ImagePrunePlan:
    """Choose which FPM/queue image tags a post-deploy prune deletes.

    Kept: the live tag, the newest tag older than it (rollback), any tag newer than it (a build not
    yet deployed), and any image a container references by name or digest, whatever its state.
    Images outside PRUNABLE_IMAGE_REPOSITORIES are never considered.
    """
    referenced_names: Dict[str, str] = {}
    referenced_digests: Dict[str, str] = {}
    for container in containers if isinstance(containers, list) else []:
        configuration = container.get("configuration", {}) if isinstance(container, dict) else {}
        image = configuration.get("image", {}) or {}
        owner = str(configuration.get("id", "?"))
        if image.get("reference"):
            referenced_names.setdefault(_image_reference(str(image["reference"])), owner)
        digest = (image.get("descriptor") or {}).get("digest")
        if digest:
            referenced_digests.setdefault(str(digest), owner)

    candidates: List[Tuple[str, str, str, str]] = []  # (name, tag, created, digest)
    for image in images if isinstance(images, list) else []:
        configuration = image.get("configuration", {}) if isinstance(image, dict) else {}
        name = str(configuration.get("name", ""))
        parts = _image_repository_tag(name)
        if parts is None or parts[0] not in PRUNABLE_IMAGE_REPOSITORIES:
            continue
        digest = str((configuration.get("descriptor") or {}).get("digest", ""))
        candidates.append((name, parts[1], str(configuration.get("creationDate", "")), digest))

    live_tags = set()
    for live in live_images:
        parts = _image_repository_tag(live)
        if parts is None or parts[0] not in PRUNABLE_IMAGE_REPOSITORIES:
            raise RuntimeFailure("live image {} is not a prunable FPM/queue tag".format(live))
        live_tags.add(parts[1])
    tag_created: Dict[str, str] = {}
    for _, tag, created, _ in candidates:
        tag_created[tag] = max(created, tag_created.get(tag, ""))
    missing = sorted(tag for tag in live_tags if tag not in tag_created)
    if missing:
        raise RuntimeFailure("live tag {} is not in the local image list".format(", ".join(missing)))
    live_created = max(tag_created[tag] for tag in live_tags)
    # ISO-8601 UTC timestamps in one format order correctly as strings.
    older = sorted((tag for tag in tag_created if tag not in live_tags and tag_created[tag] < live_created),
                   key=lambda tag: tag_created[tag], reverse=True)
    previous = older[0] if older else None

    keep: List[Tuple[str, str]] = []
    remove: List[str] = []
    for name, tag, created, digest in sorted(candidates):
        reference = _image_reference(name)
        if tag in live_tags:
            keep.append((name, "live"))
        elif tag == previous:
            keep.append((name, "previous (rollback)"))
        elif created >= live_created:
            keep.append((name, "newer than live"))
        elif reference in referenced_names:
            keep.append((name, "used by container {}".format(referenced_names[reference])))
        elif digest and digest in referenced_digests:
            keep.append((name, "used by container {}".format(referenced_digests[digest])))
        else:
            remove.append(name)
    return ImagePrunePlan(keep=tuple(keep), remove=tuple(remove))


class Stack:
    def __init__(
        self,
        config: RuntimeConfig,
        runner: Optional[Runner] = None,
        relay: Optional[RelayManager] = None,
    ):
        config.validate(scan_aliases=False)
        self.namespace = config.namespace
        self.config = config
        self.runner = runner or Runner(binary=config.container_bin)
        self.events = EventLog(state=config.state)
        self.relay = relay or RelayManager(config=config, events=self.events)
        self.collector_snapshot_wait_seconds = 20.0
        self.collector_manager = system_metrics_process.CollectorManager(
            snapshot_path=config.system_metrics_snapshot,
            receipt_path=config.system_metrics_receipt,
            script_path=pathlib.Path(__file__).parent / "system_metrics.py",
            interval_seconds=config.system_metrics_interval_seconds,
            events=self.events,
            python_executable=config.system_metrics_python_executable,
        )

    def _omitted_names(self) -> Tuple[str, ...]:
        return tuple(name for role, name in self.namespace.containers.items()
                     if self.config.staging and role not in WEB_ROLES)

    def _stage_preflight(
        self, *, validate_env: bool = True, inspect_networks: bool = True, scan_aliases: bool = True,
    ) -> None:
        if not self.config.staging:
            return
        self.config.validate(scan_aliases=scan_aliases)
        if validate_env:
            self.config.validate_stage_env()
        # Finish every ownership/collision check before the first filesystem/process/container mutation.
        existing = self.runner.list_container_names()
        selected = tuple(self.namespace.containers.values()) + self.namespace.retired + (self.namespace.initializer,)
        for name in selected:
            if name in existing:
                self._require_owned("container", name, self.runner.inspect(name))
        volumes = self.runner.volume_names()
        for name in self.namespace.volumes.values():
            if name in volumes:
                self._require_owned("volume", name, self.runner.inspect_volume(name))
        if not inspect_networks:
            return
        subnet = ipaddress.ip_network(self.namespace.subnet)
        for name in self.runner.network_names():
            try:
                inspection = self.runner.inspect_network(name)
            except RuntimeFailure:
                raise RuntimeFailure("cannot inspect existing network {}".format(name))
            if name == self.namespace.network:
                self._require_owned("network", name, inspection)
            found = set()
            for _, value in _walk(inspection):
                if isinstance(value, str) and "/" in value:
                    try:
                        found.add(ipaddress.ip_network(value, strict=False))
                    except ValueError:
                        pass
            ipv4_found = {net for net in found if net.version == 4}
            if not ipv4_found:
                raise RuntimeFailure("cannot prove existing network subnet isolation")
            if name == self.namespace.network and subnet not in ipv4_found:
                raise RuntimeFailure("stage network does not prove the configured subnet")
            if name != self.namespace.network and any(subnet.overlaps(net) for net in ipv4_found):
                raise RuntimeFailure("stage subnet collides with an existing network")

    def _stage_port_preflight(self) -> None:
        if not self.config.staging:
            return
        nginx = next(spec for spec in self.plan({role: "127.0.0.1" for role in WEB_ROLES}) if spec.role == "nginx")
        if nginx.name in self.runner.list_container_names():
            inspection = self.runner.inspect(nginx.name)
            if extract_state(inspection) == "running":
                if extract_labels(inspection).get(CONFIG_LABEL) != nginx.digest():
                    raise RuntimeFailure("stop the stale stage nginx before replacing its listener")
                return
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
                listener.bind(("127.0.0.1", self.config.web_port))
        except OSError as error:
            raise RuntimeFailure("stage web port is already occupied") from error

    def describe_plan(self) -> None:
        if self.config.staging:
            self.config.validate_stage_env()
        specs = self.plan({role: "127.0.0.1" for role in WEB_ROLES})
        # Do not emit env values or argv: APP_KEY/DB credentials remain private.
        print(json.dumps({
            "instance": self.config.instance, "web_only": self.config.web_only,
            "network": self.namespace.network, "subnet": self.namespace.subnet,
            "owner": self.namespace.owner, "web_port": self.config.web_port,
            "state": str(self.config.state), "native_state": str(self.config.native_services),
            "containers": {spec.role: spec.name for spec in specs},
            "volumes": dict(self.namespace.volumes), "initializer": self.namespace.initializer,
            "cleanup": list(self.namespace.retired + (self.namespace.initializer,) + self._omitted_names()),
            "limits": {spec.role: {"cpus": spec.cpus, "memory": spec.memory} for spec in specs},
            "external_bridges": [] if self.config.staging else [bridge.label for bridge in MANAGED_BRIDGES],
            "live_collision_check": "performed by up before mutation",
        }, indent=2, sort_keys=True))

    def plan(self, ips: Mapping[str, str]) -> Tuple[ContainerSpec, ...]:
        state = self.config.state
        source = self.config.source
        generated = self.config.generated
        storage = self.config.storage
        try:
            looper_ip = ips["looper"]
        except KeyError as error:
            raise RuntimeFailure("runtime plan is missing the discovered looper IP") from error
        try:
            ipaddress.ip_address(looper_ip)
        except ValueError as error:
            raise RuntimeFailure("runtime plan has an invalid looper IP") from error
        runtime_user = "{}:{}".format(self.config.host_uid, self.config.host_gid)
        mailpit_publishes: Tuple[str, ...] = ()
        if self.config.mailpit_port is not None:
            mailpit_publishes = ("127.0.0.1:{}:8025".format(self.config.mailpit_port),)
        nginx_publishes: Tuple[str, ...] = ()
        if self.config.web_transport == "native-publish":
            nginx_publishes = ("127.0.0.1:{}:80".format(self.config.web_port),)
        # state/app.env may pin a public origin for this deployment; otherwise keep loopback.
        # The value still comes from a trusted 0600 file, never from a request, so the
        # Host-poisoning protection in CoreServiceProvider is preserved.
        operator_env = read_env(path=self.config.env_file) if self.config.env_file.is_file() else {}
        app_url = operator_env.get("APP_URL", "").strip() or "http://127.0.0.1:{}".format(self.config.web_port)
        trusted_proxies = operator_env.get("TRUSTED_PROXIES", "").strip() or "127.0.0.1"
        application_env = (
            "APP_ENV=production",
            "APP_DEBUG=false",
            "APP_URL={}".format(app_url),
            "APP_ADMIN_URL=admin",
            "MOTHERSHIP_PIM_ENABLED=false",
            # Matches the internal /__dam_media/ location in render_nginx().
            "MOTHERSHIP_MEDIA_ACCEL_PREFIX=/__dam_media",
            # Matches the internal /__dam_remote/ location: blobs on a remote asset disk stream same-origin.
            "MOTHERSHIP_REMOTE_ACCEL_PREFIX=/__dam_remote",
            "MOTHERSHIP_HOME_LOOPER_API=http://{}:3457".format(looper_ip),
            "DB_CONNECTION=pgsql",
            "DB_HOST={}".format(ips["postgres"]),
            "DB_PORT=5432",
            "CACHE_STORE=redis",
            "SESSION_DRIVER=redis",
            "QUEUE_CONNECTION=redis",
            "REDIS_HOST={}".format(ips["redis"]),
            "REDIS_PORT=6379",
            "ELASTICSEARCH_ENABLED=true",
            "ELASTICSEARCH_HOST=http://{}:9200".format(ips["elasticsearch"]),
            "MAIL_MAILER=smtp",
            "MAIL_HOST={}".format(ips["mailpit"]),
            "MAIL_PORT=1025",
            "MAIL_ENCRYPTION=null",
            "TRUSTED_PROXIES={}".format(trusted_proxies),
        )
        storage_mount = Mount(
            source=storage,
            target="/var/www/html/storage",
            track_source=False,
        )
        higgsfield_mount = Mount(
            source=self.config.higgsfield_session,
            target="/var/lib/mothership/.config/higgsfield",
            track_source=False,
        )
        worker_runtime_mount = Mount(
            source=generated / "workers",
            target="/studio-runtime",
            readonly=True,
        )
        worker_prefix = ("/studio-runtime/worker-supervisor.sh", "php")

        specs = (
            ContainerSpec(
                role="postgres",
                name=self.namespace.containers["postgres"],
                image=IMAGES["postgres"],
                cpus=1,
                memory="1G",
                env=(
                    "PGDATA=/var/lib/postgresql/data/pgdata",
                    "POSTGRES_INITDB_ARGS=--encoding=UTF8 --locale=C",
                ),
                inherit_env=("POSTGRES_DB", "POSTGRES_USER", "POSTGRES_PASSWORD"),
                named_volumes=((self.namespace.volumes["postgres"], "/var/lib/postgresql/data"),),
            ),
            ContainerSpec(
                role="redis",
                name=self.namespace.containers["redis"],
                image=IMAGES["redis"],
                cpus=1,
                memory="256M",
                named_volumes=((self.namespace.volumes["redis"], "/data"),),
                command=(
                    "redis-server",
                    "--appendonly",
                    "yes",
                    "--maxmemory",
                    "200mb",
                    "--maxmemory-policy",
                    "volatile-lru",
                ),
            ),
            ContainerSpec(
                role="elasticsearch",
                name=self.namespace.containers["elasticsearch"],
                image=IMAGES["elasticsearch"],
                cpus=1,
                memory="1G",
                env=(
                    "discovery.type=single-node",
                    "xpack.security.enabled=false",
                    "node.name={}".format(self.namespace.network),
                    "cluster.name={}".format(self.namespace.network),
                    "ES_JAVA_OPTS=-Xms512m -Xmx512m",
                ),
                named_volumes=((self.namespace.volumes["elasticsearch"], "/usr/share/elasticsearch/data"),),
            ),
            ContainerSpec(
                role="mailpit",
                name=self.namespace.containers["mailpit"],
                image=IMAGES["mailpit"],
                cpus=1,
                memory="256M",
                publishes=mailpit_publishes,
            ),
            ContainerSpec(
                role="looper",
                name=self.namespace.containers["looper"],
                image=self.config.looper_image,
                cpus=2,
                memory="4G",
                mounts=(
                    Mount(
                        source=self.config.looper_uploads,
                        target="/app/uploads",
                        track_source=False,
                    ),
                ),
            ),
            ContainerSpec(
                role="fpm",
                name=self.namespace.containers["fpm"],
                image=self.config.fpm_image,
                cpus=2,
                memory="4G",
                env_file=self.config.env_file,
                env=application_env + ("MOTHERSHIP_SKIP_MIGRATIONS=false",),
                mounts=(
                    storage_mount,
                    higgsfield_mount,
                    Mount(
                        source=generated / "fpm",
                        target="/studio-runtime",
                        readonly=True,
                    ),
                ),
                entrypoint="/bin/bash",
                command=("/studio-runtime/entrypoint.sh",),
            ),
            ContainerSpec(
                role="queue",
                name=self.namespace.containers["queue"],
                image=self.config.queue_image,
                cpus=2,
                memory="2G",
                env_file=self.config.env_file,
                env=application_env + ("QUEUE_NAMES=webhooks,system,completeness,default,broadcastable",),
                mounts=(storage_mount, worker_runtime_mount),
                user=runtime_user,
                workdir="/var/www/html",
                entrypoint="/bin/bash",
                command=worker_prefix + (
                    "artisan", "queue:work",
                    "--queue=webhooks,system,completeness,default,broadcastable",
                    "--timeout=90", "--tries=3", "--max-jobs=1000",
                    "--max-time=3600", "--sleep=3",
                ),
            ),
            ContainerSpec(
                role="panorama_queue",
                name=self.namespace.containers["panorama_queue"],
                image=self.config.queue_image,
                cpus=4,
                memory="6G",
                env_file=self.config.env_file,
                env=application_env + ("QUEUE_WORKER_CONNECTION=panorama",),
                mounts=(storage_mount, worker_runtime_mount),
                user=runtime_user,
                workdir="/var/www/html",
                entrypoint="/bin/bash",
                command=worker_prefix + (
                    "artisan", "queue:work", "panorama",
                    "--queue=panorama,video-preview", "--tries=1",
                ),
            ),
            ContainerSpec(
                role="stylepress_queue",
                name=self.namespace.containers["stylepress_queue"],
                image=self.config.fpm_image,
                cpus=1,
                memory="1G",
                env_file=self.config.env_file,
                env=application_env,
                mounts=(storage_mount, higgsfield_mount, worker_runtime_mount),
                user=runtime_user,
                workdir="/var/www/html",
                entrypoint="/bin/bash",
                command=worker_prefix + (
                    "artisan", "queue:work", "database", "--queue=stylepress",
                    "--timeout=80", "--tries=0", "--sleep=3", "--max-time=3600",
                ),
            ),
            ContainerSpec(
                role="vectorization_queue",
                name=self.namespace.containers["vectorization_queue"],
                image=self.config.queue_image,
                cpus=1,
                memory="1G",
                env_file=self.config.env_file,
                env=application_env + ("QUEUE_WORKER_CONNECTION=vectorization",),
                mounts=(storage_mount, worker_runtime_mount),
                user=runtime_user,
                workdir="/var/www/html",
                entrypoint="/bin/bash",
                command=worker_prefix + (
                    "artisan", "queue:work", "vectorization",
                    "--queue=vectorization", "--timeout=480", "--tries=1",
                    "--memory=512", "--max-jobs=50",
                ),
            ),
            ContainerSpec(
                role="ai_index_queue",
                name=self.namespace.containers["ai_index_queue"],
                image=self.config.queue_image,
                cpus=1,
                memory="1G",
                env_file=self.config.env_file,
                env=application_env + ("QUEUE_WORKER_CONNECTION=ai-index",),
                mounts=(storage_mount, worker_runtime_mount),
                user=runtime_user,
                workdir="/var/www/html",
                entrypoint="/bin/bash",
                command=worker_prefix + (
                    "artisan", "queue:work", "ai-index",
                    "--queue=ai-index", "--timeout=300", "--tries=4",
                    "--memory=512", "--max-jobs=200",
                ),
            ),
            ContainerSpec(
                role="scheduler",
                name=self.namespace.containers["scheduler"],
                image=self.config.queue_image,
                cpus=1,
                # The 03:00 background fan-out starts nine PHP commands alongside schedule:work.
                # 512M exhausted the cgroup at the 03:00 boundary; keep headroom for that overlap.
                memory="1G",
                env_file=self.config.env_file,
                env=application_env,
                mounts=(storage_mount, worker_runtime_mount),
                user=runtime_user,
                workdir="/var/www/html",
                entrypoint="/bin/bash",
                command=worker_prefix + ("artisan", "schedule:work", "--no-interaction"),
            ),
            ContainerSpec(
                role="nginx",
                name=self.namespace.containers["nginx"],
                image=IMAGES["nginx"],
                cpus=1,
                memory="256M",
                mounts=(
                    Mount(source=source / "public", target="/var/www/html/public", readonly=True),
                    Mount(source=storage, target="/var/www/html/storage", readonly=True, track_source=False),
                    Mount(
                        source=generated / "nginx",
                        target="/etc/nginx/conf.d",
                        readonly=True,
                    ),
                ),
                publishes=nginx_publishes,
            ),
        )
        if self.config.staging:
            self.config.validate_stage_env()
            specs = tuple(
                replace(
                    spec,
                    mounts=tuple(mount for mount in spec.mounts if mount != higgsfield_mount),
                    env=tuple(item for item in spec.env if item.split("=", 1)[0] not in {
                        disabled.split("=", 1)[0] for disabled in STAGE_DISABLED_ENV
                    }) + (STAGE_DISABLED_ENV if spec.role == "fpm" else ()),
                )
                for spec in specs if spec.role in WEB_ROLES
            )
        return tuple(replace(spec, network=self.namespace.network, owner=self.namespace.owner) for spec in specs)

    def _validate_up(self) -> Dict[str, str]:
        if not pathlib.Path(self.config.container_bin).is_file():
            raise RuntimeFailure("Apple container binary is unavailable: {}".format(self.config.container_bin))
        if not (self.config.source / "public" / "index.php").is_file():
            raise RuntimeFailure("--source must contain public/index.php")
        if not self.config.env_file.is_file():
            raise RuntimeFailure("missing required state/app.env")
        state_stat = self.config.state.stat()
        if (state_stat.st_uid, state_stat.st_gid) != (self.config.host_uid, self.config.host_gid):
            raise RuntimeFailure("--state ownership does not match the configured host uid/gid")
        if self.config.env_file.stat().st_mode & 0o077:
            raise RuntimeFailure("state/app.env must not be readable by group or others")
        environment = read_env(path=self.config.env_file)
        for key in ("APP_KEY", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"):
            if not environment.get(key):
                raise RuntimeFailure("state/app.env is missing {}".format(key))
        self.runner.add_redactions(_secret_values(environment))
        return environment

    def _gateway_endpoint(self, environment: Mapping[str, str], key: str) -> Optional[Tuple[str, int]]:
        configured = environment.get(key, "").strip()
        if not configured:
            return None
        try:
            parsed = urllib.parse.urlparse(configured)
            if parsed.scheme != "http" or not parsed.hostname:
                raise RuntimeFailure("{} must be an http URL on the private gateway".format(key))
            host = str(ipaddress.ip_address(parsed.hostname))
            port = parsed.port or 80
        except RuntimeFailure:
            raise
        except (ValueError, TypeError) as error:
            raise RuntimeFailure("{} must use a valid private-gateway IPv4 address and port".format(key)) from error
        gateway = extract_ipv4_gateway(self.runner.inspect_network(self.namespace.network))
        if host != gateway:
            raise RuntimeFailure(
                "{} host {} does not match the current private gateway {}".format(key, host, gateway)
            )
        return host, port

    def _start_native_bridges(self, environment: Mapping[str, str]) -> None:
        """Bring up each configured optimizer/AI bridge on the gateway; idempotent."""
        failures: List[str] = []
        python = self.config.python_executable or sys.executable
        for bridge in MANAGED_BRIDGES:
            try:
                endpoint = self._gateway_endpoint(environment, bridge.env_key)
            except RuntimeFailure as error:
                self.events.write("error", "bridge.config_invalid", "Stack._start_native_bridges", {"bridge": bridge.label})
                print("{}: {}".format(bridge.label, error), file=sys.stderr)
                if not bridge.optional:
                    failures.append("{} ({})".format(bridge.label, error))
                continue
            if endpoint is None:
                self.events.write("warning", "bridge.unconfigured", "Stack._start_native_bridges", {"bridge": bridge.label})
                print("{}: not started ({} is not configured)".format(bridge.label, bridge.env_key))
                continue
            host, port = endpoint
            command = [
                python,
                str(self.config.source / "tools" / "studio" / bridge.manager),
                "up",
                "--source",
                str(self.config.source),
                "--state",
                str(self.config.native_services / bridge.state_name),
                "--host",
                host,
                "--port",
                str(port),
            ]
            self.events.write("info", "bridge.starting", "Stack._start_native_bridges", {"bridge": bridge.label, "host": host, "port": port})
            try:
                result = subprocess.run(command, check=False, capture_output=True, text=True, timeout=180)
                returncode, detail = result.returncode, (result.stderr or result.stdout).strip()
            except (OSError, subprocess.SubprocessError) as error:
                returncode, detail = 1, str(error)
            if returncode != 0:
                self.events.write(
                    "error", "bridge.start_failed", "Stack._start_native_bridges",
                    {"bridge": bridge.label, "host": host, "port": port, "returncode": returncode},
                )
                print("{}: start failed: {}".format(bridge.label, detail.splitlines()[-1] if detail else "no output"), file=sys.stderr)
                if bridge.optional:
                    print("{}: optional, the site starts without it".format(bridge.label), file=sys.stderr)
                else:
                    failures.append(bridge.label)
                continue
            self.events.write("info", "bridge.ready", "Stack._start_native_bridges", {"bridge": bridge.label, "host": host, "port": port})
            print("{}: running on {}:{}".format(bridge.label, host, port))
        if failures:
            raise RuntimeFailure("native bridge(s) failed to start: {}".format(", ".join(failures)))

    def _verify_higgsfield_session(self, environment: Dict[str, str]) -> None:
        # The CLI keeps its login in credentials.json; config.json alone is an unauthenticated session.
        if environment.get("STYLEPRESS_HIGGSFIELD_DRIVER", "").strip() != "subscription":
            print("higgsfield: subscription driver is not configured")
            return
        credentials = self.config.higgsfield_session / "credentials.json"
        try:
            session = json.loads(credentials.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            session = None
        # Content stays opaque to the launcher: only an empty or unreadable session is rejected here.
        if not isinstance(session, dict) or not session:
            self.events.write("error", "higgsfield.session_missing", "Stack._verify_higgsfield_session")
            raise RuntimeFailure(
                "state/higgsfield-session has no credentials.json; run higgsfield auth login for this installation"
            )

    def _verify_native_bridges(self, environment: Mapping[str, str]) -> None:
        """Health-check every configured optimizer/AI bridge from FPM; name each failure."""
        invalid: List[str] = []
        unreachable: List[str] = []
        for bridge in MANAGED_BRIDGES:
            try:
                endpoint = self._gateway_endpoint(environment, bridge.env_key)
            except RuntimeFailure as error:
                self.events.write("error", "bridge.config_invalid", "Stack._verify_native_bridges", {"bridge": bridge.label})
                if bridge.optional:
                    print("{}: unavailable ({}); optional, not a failure".format(bridge.label, error), file=sys.stderr)
                else:
                    invalid.append("{} ({})".format(bridge.label, error))
                continue
            if endpoint is None:
                self.events.write("warning", "bridge.unconfigured", "Stack._verify_native_bridges", {"bridge": bridge.label})
                print("{}: disabled ({} is not configured)".format(bridge.label, bridge.env_key))
                continue
            host, port = endpoint
            try:
                self.runner.run(
                    ["exec", self.namespace.containers["fpm"], "curl", "--fail", "--silent", "--show-error",
                     "http://{}:{}/health".format(host, port)],
                    timeout=15,
                )
            except RuntimeFailure:
                self.events.write(
                    "error", "bridge.unreachable", "Stack._verify_native_bridges",
                    {"bridge": bridge.label, "host": host, "port": port},
                )
                if bridge.optional:
                    print("{}: unreachable at {}:{}; optional, not a failure".format(bridge.label, host, port), file=sys.stderr)
                else:
                    unreachable.append("{} ({}:{})".format(bridge.label, host, port))
        problems = []
        if invalid:
            problems.append("bridge config invalid: {}".format(", ".join(invalid)))
        if unreachable:
            problems.append(
                "bridge unreachable at the current private gateway: {}; run runtime up to restart it".format(
                    ", ".join(unreachable)
                )
            )
        if problems:
            raise RuntimeFailure("; ".join(problems))

    def _prepare_state(self) -> None:
        directories = (
            self.config.storage / "app" / "private",
            self.config.storage / "app" / "public",
            self.config.storage / "framework" / "cache" / "data",
            self.config.storage / "framework" / "sessions",
            self.config.storage / "framework" / "testing",
            self.config.storage / "framework" / "views",
            self.config.storage / "logs",
            self.config.looper_uploads,
            self.config.storage / "metrics",
        )
        for directory in directories:
            if not directory.exists():
                directory.mkdir(parents=True, mode=0o770)
        if not self.config.staging:
            self._prepare_higgsfield_session()
        self.config.generated.mkdir(parents=True, exist_ok=True)
        (self.config.generated / "fpm").mkdir(parents=True, exist_ok=True)
        (self.config.generated / "nginx").mkdir(parents=True, exist_ok=True)
        (self.config.generated / "workers").mkdir(parents=True, exist_ok=True)
        (self.config.generated / "metrics").mkdir(parents=True, exist_ok=True)
        _atomic_write(path=self.config.generated / "fpm" / "zz-studio.conf", content=render_fpm_pool())
        _atomic_write(path=self.config.generated / "fpm" / "zz-studio-opcache.ini", content=render_opcache())
        entrypoint = render_fpm_entrypoint()
        if self.config.staging:
            # Strip image ENV and baked config before any artisan bootstrap. The only .env
            # visible to Laravel is the validated, read-only staging bind above.
            specs = self.plan({role: "127.0.0.1" for role in WEB_ROLES})
            fpm = next(spec for spec in specs if spec.role == "fpm")
            keys = sorted({item.split("=", 1)[0] for item in fpm.env} | set(self.config.validate_stage_env()))
            anchor = "ROOT=/var/www/html"
            if entrypoint.count(anchor) != 1:
                raise RuntimeFailure("FPM entrypoint insertion anchor must occur exactly once")
            prefix = ('for key in $(compgen -e); do\n'
                      '    case "$key" in PATH|HOME|MOTHERSHIP_BUILD_COMMIT|' + '|'.join(keys) + ') ;; *) unset "$key" ;; esac\n'
                      'done\nrm -f /var/www/html/bootstrap/cache/*.php /var/www/html/.env /var/www/html/.env.*\n'
                      'install -o www-data -g www-data -m 600 /studio-runtime/app.env /var/www/html/.env\n')
            entrypoint = entrypoint.replace(anchor, prefix + anchor, 1)
            _atomic_write(
                path=self.config.generated / "fpm" / "app.env",
                content=self.config.env_file.read_text(encoding="utf-8"),
                mode=0o600,
            )
        _atomic_write(path=self.config.generated / "fpm" / "entrypoint.sh", content=entrypoint, mode=0o755)
        _atomic_write(
            path=self.config.generated / "workers" / "worker-supervisor.sh",
            content=render_worker_supervisor(), mode=0o755,
        )
        self.events.write("info", "state.prepared", "Stack._prepare_state", {"directory_count": len(directories)})

    def _prepare_higgsfield_session(self) -> None:
        if not self.config.higgsfield_session.exists():
            self.config.higgsfield_session.mkdir(parents=True, mode=0o700)
        if self.config.higgsfield_session.stat().st_mode & 0o077:
            raise RuntimeFailure("state/higgsfield-session must not be accessible by group or others")
        for child in self.config.higgsfield_session.rglob("*"):
            metadata = child.lstat()
            if not stat.S_ISREG(metadata.st_mode):
                continue
            if metadata.st_uid != self.config.host_uid:
                raise RuntimeFailure("files in state/higgsfield-session must be owned by the configured host user")
            child.chmod(0o600)

    def _ensure_network_and_volumes(self) -> None:
        networks = self.runner.network_names()
        if self.namespace.network in networks:
            self._require_owned("network", self.namespace.network, self.runner.inspect_network(self.namespace.network))
        else:
            self.runner.run(network_create_args(namespace=self.namespace))
            self.events.write("info", "network.created", "Stack._ensure_network_and_volumes", {"name": self.namespace.network})

        volumes = self.runner.volume_names()
        for name in self.namespace.volumes.values():
            if name in volumes:
                self._require_owned("volume", name, self.runner.inspect_volume(name))
                continue
            self.runner.run(["volume", "create", "--label", "{}={}".format(OWNER_LABEL, self.namespace.owner), name])
            self.events.write("info", "volume.created", "Stack._ensure_network_and_volumes", {"name": name})

    def _require_builder_stopped(self) -> None:
        state = extract_state(self.runner.builder_status())
        if state == "running":
            raise RuntimeFailure(
                "Apple container builder is running; finish builds and run container builder stop before up"
            )
        if state != "stopped":
            raise RuntimeFailure("Apple container builder status did not prove it is stopped (state={})".format(state))
        self.events.write("info", "builder.preflight", "Stack._require_builder_stopped", {"state": state})

    def _initialize_elasticsearch_volume(self) -> None:
        existing = self.runner.list_container_names()
        elasticsearch_name = self.namespace.containers["elasticsearch"]
        if elasticsearch_name in existing:
            self._require_owned("container", elasticsearch_name, self.runner.inspect(elasticsearch_name))
            self.events.write(
                "info",
                "volume.initialization_skipped",
                "Stack._initialize_elasticsearch_volume",
                {"role": "elasticsearch", "reason": "container_exists"},
            )
            return
        if self.namespace.initializer in existing:
            initializer = self.runner.inspect(self.namespace.initializer)
            self._require_owned("container", self.namespace.initializer, initializer)
            if extract_state(initializer) == "running":
                raise RuntimeFailure("owned Elasticsearch volume initializer is already running")
            self.runner.run(["delete", self.namespace.initializer])
        inspection = self.runner.inspect_volume(self.namespace.volumes["elasticsearch"])
        self._require_owned("volume", self.namespace.volumes["elasticsearch"], inspection)
        self.runner.run(elasticsearch_volume_init_args(namespace=self.namespace), timeout=300)
        self.events.write(
            "info",
            "volume.initialized",
            "Stack._initialize_elasticsearch_volume",
            {"role": "elasticsearch", "uid": 1000, "gid": 0},
        )

    def _require_owned(self, kind: str, name: str, inspection: object) -> Mapping[str, str]:
        labels = extract_labels(inspection)
        if labels.get(OWNER_LABEL) != self.namespace.owner:
            raise RuntimeFailure("foreign {} uses owned name {}".format(kind, name))
        return labels

    def _ensure_container(self, spec: ContainerSpec, child_env: Optional[Mapping[str, str]] = None) -> None:
        if self.config.staging and (
            spec.name != self.namespace.containers.get(spec.role) or spec.role not in WEB_ROLES
            or spec.network != self.namespace.network or spec.owner != self.namespace.owner
        ):
            raise RuntimeFailure("container spec is outside the stage web-only namespace")
        existing = self.runner.list_container_names()
        if spec.name in existing:
            inspection = self.runner.inspect(spec.name)
            labels = self._require_owned("container", spec.name, inspection)
            if labels.get(CONFIG_LABEL) == spec.digest():
                if extract_state(inspection) != "running":
                    self.runner.run(["start", spec.name])
                    self.events.write("info", "container.started", "Stack._ensure_container", {"role": spec.role})
                return
            if extract_state(inspection) == "running":
                self.runner.run(["stop", "--time", "10", spec.name])
            self.runner.run(["delete", spec.name])
            self.events.write("info", "container.refreshed", "Stack._ensure_container", {"role": spec.role})
        self.runner.run(spec.run_args(), child_env=child_env, timeout=900)
        self.events.write("info", "container.created", "Stack._ensure_container", {"role": spec.role})

    def _container_ip(self, name: str) -> str:
        return extract_ipv4(self.runner.inspect(name))

    def _wait(
        self,
        description: str,
        probe,
        *,
        timeout: int,
        container_name: str,
    ) -> None:
        deadline = time.monotonic() + timeout
        last_error = "not ready"
        while time.monotonic() < deadline:
            inspection = self.runner.inspect(container_name)
            state = extract_state(inspection)
            if state not in {"running", "unknown"}:
                raise RuntimeFailure("{} stopped before readiness (state={})".format(description, state))
            try:
                if probe():
                    self.events.write("info", "readiness.passed", "Stack._wait", {"service": description})
                    return
            except (OSError, RuntimeFailure, urllib.error.URLError) as error:
                last_error = str(error)
            time.sleep(2)
        raise RuntimeFailure("{} did not become ready in {}s: {}".format(description, timeout, last_error))

    def _wait_exec(self, role: str, args: Sequence[str], timeout: int) -> None:
        name = self.namespace.containers[role]

        def probe() -> bool:
            self.runner.run(["exec", name, *args], timeout=15)
            return True

        self._wait(role, probe, timeout=timeout, container_name=name)

    def _artisan_probe(self, role: str, process: str) -> bool:
        code = (
            '$path="/tmp/studio-worker.pid";'
            'if(!is_readable($path)){exit(1);}'
            '$pid=(int)trim(file_get_contents($path));'
            'if($pid<=1||!is_dir("/proc/".$pid)){exit(1);}'
            '$command=file_get_contents("/proc/".$pid."/cmdline");'
            'exit(strpos($command,"artisan")===false||strpos($command,"'
            + process
            + '")===false?1:0);'
        )
        self.runner.run(["exec", self.namespace.containers[role], "php", "-r", code], timeout=15)

        return True

    def _wait_artisan(self, role: str, process: str, timeout: int = 120) -> None:
        self._wait(
            role,
            lambda: self._artisan_probe(role=role, process=process),
            timeout=timeout,
            container_name=self.namespace.containers[role],
        )

    @staticmethod
    def _tcp_probe(ip: str, port: int) -> bool:
        with socket.create_connection((ip, port), timeout=3):
            return True

    @staticmethod
    def _http_probe(url: str) -> bool:
        with urllib.request.urlopen(url, timeout=5) as response:
            return response.status == 200

    def _require_http(self, description: str, url: str) -> None:
        try:
            if self._http_probe(url):
                return
        except urllib.error.HTTPError as error:
            raise RuntimeFailure("{} health check returned HTTP {}".format(description, error.code)) from error
        except (OSError, urllib.error.URLError) as error:
            detail = getattr(error, "reason", None) or str(error)
            raise RuntimeFailure("{} health check is unavailable: {}".format(description, detail)) from error
        raise RuntimeFailure("{} health check failed".format(description))

    def _maintenance_hold(self) -> bool:
        """True while `artisan down` has paused the application (the file lives on the shared storage state).

        Maintenance survives a restart because the marker is in `storage/framework`. A held start is healthy
        only when the application answers 503 to ordinary pages while the framework health route `/up` (which
        Laravel exempts from maintenance) still answers 200; it is a deliberate pause, not a reopening.
        """
        return (self.config.storage / "framework" / "down").is_file()

    def _require_hold_response(self, url: str) -> None:
        label = urllib.parse.urlparse(url).path or "/"
        request = urllib.request.Request(url, headers={"User-Agent": "mothership-verify/1"})
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                raise RuntimeFailure(
                    "{} returned HTTP {} while the maintenance hold is set; the application is not paused".format(
                        label, response.status
                    )
                )
        except urllib.error.HTTPError as error:
            if error.code != 503:
                raise RuntimeFailure(
                    "{} returned HTTP {} while the maintenance hold is set; expected 503".format(label, error.code)
                ) from error
        except (OSError, urllib.error.URLError) as error:
            detail = getattr(error, "reason", None) or str(error)
            raise RuntimeFailure("{} is unavailable: {}".format(label, detail)) from error

    def up(self) -> None:
        self._stage_preflight()
        self._stage_port_preflight()
        environment = self._validate_up()
        if self.config.staging:
            self.events.write("info", "stage.preflight", "Stack.up", {"web_only": True})
        if self.config.web_transport == "native-publish" and not self.config.staging:
            self.relay.stop()
        self._require_builder_stopped()
        self._prepare_state()
        if not self.config.staging:
            self.collector_manager.start()
        self._ensure_network_and_volumes()
        self._initialize_elasticsearch_volume()
        postgres_environment = {
            "POSTGRES_DB": environment.get("POSTGRES_DB", environment["DB_DATABASE"]),
            "POSTGRES_USER": environment.get("POSTGRES_USER", environment["DB_USERNAME"]),
            "POSTGRES_PASSWORD": environment.get("POSTGRES_PASSWORD", environment["DB_PASSWORD"]),
        }

        placeholder_ips = {
            "postgres": "127.0.0.1",
            "redis": "127.0.0.1",
            "elasticsearch": "127.0.0.1",
            "mailpit": "127.0.0.1",
            "looper": "127.0.0.1",
            "fpm": "127.0.0.1",
        }
        initial = {spec.role: spec for spec in self.plan(placeholder_ips)}

        self._delete_containers(self.namespace.retired + self._omitted_names(), loc="Stack.up")

        self._ensure_container(initial["postgres"], child_env=postgres_environment)
        self._wait_exec(
            role="postgres",
            args=("pg_isready", "-U", postgres_environment["POSTGRES_USER"], "-d", postgres_environment["POSTGRES_DB"]),
            timeout=90,
        )
        postgres_ip = self._container_ip(self.namespace.containers["postgres"])

        self._ensure_container(initial["redis"])
        self._wait_exec(role="redis", args=("redis-cli", "ping"), timeout=60)
        redis_ip = self._container_ip(self.namespace.containers["redis"])

        self._ensure_container(initial["elasticsearch"])
        elasticsearch_ip = self._container_ip(self.namespace.containers["elasticsearch"])
        self._wait(
            "elasticsearch",
            lambda: self._http_probe("http://{}:9200/_cluster/health?wait_for_status=yellow&timeout=5s".format(elasticsearch_ip)),
            timeout=150,
            container_name=self.namespace.containers["elasticsearch"],
        )

        self._ensure_container(initial["mailpit"])
        mailpit_ip = self._container_ip(self.namespace.containers["mailpit"])
        self._wait(
            "mailpit",
            lambda: self._tcp_probe(mailpit_ip, 1025),
            timeout=45,
            container_name=self.namespace.containers["mailpit"],
        )

        self._ensure_container(initial["looper"])
        looper_ip = self._container_ip(self.namespace.containers["looper"])
        self._wait(
            "looper",
            lambda: self._http_probe("http://{}:3457/health".format(looper_ip)),
            timeout=120,
            container_name=self.namespace.containers["looper"],
        )
        for description, host, port in (
            ("postgres peer route", postgres_ip, 5432),
            ("elasticsearch peer route", elasticsearch_ip, 9200),
            ("mailpit peer route", mailpit_ip, 1025),
            ("looper peer route", looper_ip, 3457),
        ):
            self._wait(
                description,
                lambda host=host, port=port: self.runner.run(
                    ["exec", self.namespace.containers["redis"], "nc", "-z", "-w3", host, str(port)],
                    timeout=15,
                )
                == "",
                timeout=30,
                container_name=self.namespace.containers["redis"],
            )

        runtime_ips = {
            "postgres": postgres_ip,
            "redis": redis_ip,
            "elasticsearch": elasticsearch_ip,
            "mailpit": mailpit_ip,
            "looper": looper_ip,
            "fpm": "127.0.0.1",
        }
        application = {spec.role: spec for spec in self.plan(runtime_ips)}
        self._ensure_container(application["fpm"])
        fpm_ip = self._container_ip(self.namespace.containers["fpm"])
        self._wait(
            "fpm",
            lambda: self._tcp_probe(fpm_ip, 9000),
            timeout=300,
            container_name=self.namespace.containers["fpm"],
        )
        for role in (
            "queue",
            "panorama_queue",
            "stylepress_queue",
            "vectorization_queue",
            "ai_index_queue",
        ):
            if role not in application:
                continue
            self._ensure_container(application[role])
            self._wait_artisan(role=role, process="queue:work")
        if "scheduler" in application:
            self._ensure_container(application["scheduler"])
            self._wait_artisan(role="scheduler", process="schedule:work")

        _atomic_write(path=self.config.generated / "nginx" / "default.conf", content=render_nginx(
            fpm_ip=fpm_ip, resolver_ip=namespace_resolver_ip(self.namespace),
        ))
        runtime_ips["fpm"] = fpm_ip
        nginx = {spec.role: spec for spec in self.plan(runtime_ips)}["nginx"]
        self._ensure_container(nginx)
        nginx_ip = self._container_ip(self.namespace.containers["nginx"])
        if self.config.web_transport == "relay":
            self.relay.start(nginx_ip)
        self._wait(
            "nginx",
            lambda: self._http_probe("http://127.0.0.1:{}/up".format(self.config.web_port)),
            timeout=60,
            container_name=self.namespace.containers["nginx"],
        )
        if not self.config.staging:
            self._start_native_bridges(environment)
        self.verify(scan_aliases=False)

    def _delete_containers(self, names: Sequence[str], loc: str) -> None:
        allowed = set(self.namespace.containers.values()) | set(self.namespace.retired) | {self.namespace.initializer}
        if self.config.staging and not set(names) <= allowed:
            raise RuntimeFailure("cleanup is outside the stage namespace")
        existing = self.runner.list_container_names()
        for name in names:
            if name not in existing:
                continue
            inspection = self.runner.inspect(name)
            self._require_owned("container", name, inspection)
            if extract_state(inspection) == "running":
                self.runner.run(["stop", "--time", "10", name])
            self.runner.run(["delete", name])
            self.events.write("info", "container.deleted", loc, {"name": name})

    def down(self) -> None:
        self._stage_preflight(validate_env=False, inspect_networks=False, scan_aliases=False)
        if not self.config.staging:
            self.relay.stop()
            self.collector_manager.stop()
        self._delete_containers(self.namespace.retired + ((self.namespace.initializer,) if self.config.staging else ()) + tuple(reversed(tuple(self.namespace.containers.values()))), loc="Stack.down")
        print("private containers stopped; state, network, and volumes preserved")

    def status(self) -> None:
        self._stage_preflight(validate_env=False, inspect_networks=False, scan_aliases=False)
        print("web_transport: {}".format(self.config.web_transport))
        if not self.config.staging:
            print("web_relay: {}".format(self.relay.describe()))
        existing = self.runner.list_container_names()
        for role, name in self.namespace.containers.items():
            if name not in existing:
                print("{}: missing".format(role))
                continue
            inspection = self.runner.inspect(name)
            self._require_owned("container", name, inspection)
            state = extract_state(inspection)
            try:
                ip = extract_ipv4(inspection)
            except RuntimeFailure:
                ip = "unassigned"
            print("{}: {} {}".format(role, state, ip))
        if self.config.staging:
            print("stage web-only: queues, scheduler, native bridges and collector excluded")
            return
        collector_status = self.collector_manager.status(now=datetime.now(timezone.utc))
        age_str = ""
        if collector_status.snapshot_age_seconds is not None:
            age_str = " age={:.1f}s".format(collector_status.snapshot_age_seconds)
        cpu_str = " cpu={}".format(collector_status.signals.get("cpu", False))
        memory_str = " memory={}".format(collector_status.signals.get("memory", False))
        gpu_str = " gpu={}".format(collector_status.signals.get("gpu", False))
        print("collector: pid={} running={} owned={}{}{}{}{}".format(
            collector_status.pid, collector_status.running, collector_status.owned,
            age_str, cpu_str, memory_str, gpu_str
        ))

    def _fetch(self, url: str) -> Tuple[str, str]:
        request = urllib.request.Request(url, headers={"User-Agent": "mothership-verify/1"})
        label = urllib.parse.urlparse(url).path or "/"
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                if response.status != 200:
                    raise RuntimeFailure("{} returned HTTP {}".format(label, response.status))
                content_type = response.headers.get("Content-Type", "")
                body = response.read().decode("utf-8", errors="replace")
                return body, content_type
        except RuntimeFailure:
            raise
        except urllib.error.HTTPError as error:
            raise RuntimeFailure("{} returned HTTP {}".format(label, error.code)) from error
        except (OSError, urllib.error.URLError) as error:
            detail = getattr(error, "reason", None) or str(error)
            raise RuntimeFailure("{} is unavailable: {}".format(label, detail)) from error

    def prune_images(self, *, apply: bool) -> None:
        """Delete FPM/queue image tags older than the live tag and its rollback; dry run unless apply."""
        if self.config.staging:
            raise RuntimeFailure("stage does not prune images; run prune-images against production")
        self.verify()
        if self._maintenance_hold():
            raise RuntimeFailure("maintenance hold is on; prune only after the application reopens")
        containers = self.runner.list_containers()
        running = {}
        for container in containers if isinstance(containers, list) else []:
            configuration = container.get("configuration", {})
            running[configuration.get("id")] = _image_reference(str((configuration.get("image") or {}).get("reference", "")))
        live_images = (self.config.fpm_image, self.config.queue_image)
        for role, image in zip(("fpm", "queue"), live_images):
            actual = running.get(self.namespace.containers[role])
            if actual != _image_reference(image):
                raise RuntimeFailure("{} container runs {}, not --{}-image {}".format(
                    role, actual or "nothing", role, image))
        plan = select_image_prunes(images=self.runner.list_images(), containers=containers, live_images=live_images)
        for name, reason in plan.keep:
            print("keep {} ({})".format(name, reason))
        for name in plan.remove:
            print("{} {}".format("delete" if apply else "would delete", name))
        self.events.write(
            "info",
            "prune.planned",
            "Stack.prune_images",
            {"apply": apply, "keep": [name for name, _ in plan.keep], "remove": list(plan.remove)},
        )
        if not apply:
            print("dry run: pass --apply to delete {} image tag(s)".format(len(plan.remove)))
            return
        for name in plan.remove:
            self.runner.run(["image", "delete", name])
            self.events.write("info", "prune.deleted", "Stack.prune_images", {"image": name})
        print("deleted {} image tag(s)".format(len(plan.remove)))

    def verify(self, *, scan_aliases: bool = True) -> None:
        self._stage_preflight(scan_aliases=scan_aliases)
        existing = self.runner.list_container_names()
        for role, name in self.namespace.containers.items():
            if self.config.staging and role not in WEB_ROLES:
                if name in existing and extract_state(self.runner.inspect(name)) == "running":
                    raise RuntimeFailure("stage web-only has an active excluded worker")
                continue
            if name not in existing:
                raise RuntimeFailure("{} container is missing".format(role))
            inspection = self.runner.inspect(name)
            self._require_owned("container", name, inspection)
            if extract_state(inspection) != "running":
                raise RuntimeFailure("{} container is not running".format(role))

        if not self.config.staging:
            collector_status = self.collector_manager.status(now=datetime.now(timezone.utc))
            if not collector_status.running or not collector_status.owned:
                raise RuntimeFailure("collector is not running or not owned")
            deadline = time.monotonic() + self.collector_snapshot_wait_seconds
            while not _snapshot_fresh(collector_status) and time.monotonic() < deadline:
                time.sleep(1.0)
                collector_status = self.collector_manager.status(now=datetime.now(timezone.utc))
            # The wait re-polls, so ownership is re-established on the status that is finally trusted.
            if not collector_status.running or not collector_status.owned:
                raise RuntimeFailure("collector is not running or not owned")
            if not _snapshot_fresh(collector_status):
                raise RuntimeFailure("collector snapshot is missing or older than 15 seconds")
            cpu_avail = collector_status.signals.get("cpu", False)
            memory_avail = collector_status.signals.get("memory", False)
            gpu_avail = collector_status.signals.get("gpu", False)
            self.events.write(
                "info",
                "collector.signals",
                "Stack.verify",
                {"cpu": cpu_avail, "memory": memory_avail, "gpu": gpu_avail},
            )
        looper_ip = self._container_ip(self.namespace.containers["looper"])
        self._require_http("looper", "http://{}:3457/health".format(looper_ip))
        nginx_ip = self._container_ip(self.namespace.containers["nginx"])
        if self.config.web_transport == "relay":
            self.relay.require(nginx_ip)
        environment = read_env(path=self.config.env_file)
        self.runner.add_redactions(_secret_values(environment))
        if not self.config.staging:
            self._verify_native_bridges(environment)
            self._verify_higgsfield_session(environment)
            for role in (
                "queue",
                "panorama_queue",
                "stylepress_queue",
                "vectorization_queue",
                "ai_index_queue",
            ):
                self._wait_artisan(role=role, process="queue:work", timeout=30)
            self._wait_artisan(role="scheduler", process="schedule:work", timeout=30)

        base = "http://127.0.0.1:{}".format(self.config.web_port)
        if self._maintenance_hold():
            self._fetch(base + "/up")
            self._require_hold_response(base + "/admin/login")
            self.events.write("info", "verify.held", "Stack.verify", {"web_port": self.config.web_port})
            print("held {} (maintenance: /up 200, /admin/login HTTP 503; services healthy, application NOT reopened)".format(base))
            return
        self._fetch(base + "/up")
        login_body, _ = self._fetch(base + "/admin/login")
        commit_status, commit = check_build_commit(body=login_body, fpm_image=self.config.fpm_image)
        self.events.write(
            "warning" if commit_status == "unknown" else "info",
            "verify.build_commit",
            "Stack.verify",
            {"status": commit_status, "commit": commit, "fpm_image": self.config.fpm_image},
        )
        if commit_status == "mismatch":
            raise RuntimeFailure(
                "/admin/login serves commit {} but the FPM image is {}".format(commit, self.config.fpm_image)
            )
        # A configured public APP_URL makes the markup emit absolute URLs on that origin.
        configured = urllib.parse.urlparse(environment.get("APP_URL", "").strip()).netloc
        static_urls = select_static_urls(
            body=login_body,
            page_url=base + "/admin/login",
            loopback_netloc="127.0.0.1:{}".format(self.config.web_port),
            extra_netlocs=(configured,),
        )
        if not static_urls:
            raise RuntimeFailure("/admin/login did not reference any local static assets")
        for url in static_urls[:30]:
            self._fetch(url)
        self.events.write(
            "info",
            "verify.passed",
            "Stack.verify",
            {"static_reference_count": min(len(static_urls), 30), "web_port": self.config.web_port},
        )
        if self.config.staging:
            print("stage web-only: queues, scheduler, external bridges and collector NOT verified or started")
        print(
            "ready {} ({} login assets verified, build commit {} [{}])".format(
                base, min(len(static_urls), 30), commit, commit_status
            )
        )


def _port(value: str) -> int:
    port = int(value)
    if port < 1 or port > 65535:
        raise argparse.ArgumentTypeError("port must be between 1 and 65535")
    return port


def _identity_id(value: str) -> int:
    identifier = int(value)
    if identifier < 0:
        raise argparse.ArgumentTypeError("uid/gid must be zero or greater")
    return identifier


def parse_args(argv: Optional[Sequence[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=pathlib.Path, help="matching The Mothership source checkout")
    parser.add_argument("--state", required=True, type=pathlib.Path, help="private persistent state root")
    parser.add_argument("--instance", choices=("production", "stage"), default="production")
    parser.add_argument("--web-only", action="store_true", help="required for stage; excludes workers and external bridges")
    parser.add_argument("--production-state", type=pathlib.Path, help="production state root to exclude and compare credentials")
    parser.add_argument("--production-native-state", type=pathlib.Path, help="production native state root if customized")
    parser.add_argument("--web-port", type=_port)
    parser.add_argument(
        "--web-transport",
        choices=("native-publish", "relay"),
        default="native-publish",
        help="localhost transport; relay requires Local Network access for the detached Python process",
    )
    parser.add_argument("--mailpit-port", type=_port, help="optional loopback Mailpit UI port")
    parser.add_argument("--container-bin", default="/opt/homebrew/bin/container")
    # No built-in FPM/queue tags: they change with every deploy, so a default names a stale image.
    parser.add_argument("--fpm-image", help="deployed FPM image, e.g. mothership-fpm:<sha12>")
    parser.add_argument("--queue-image", help="deployed queue image, e.g. mothership-q:<sha12>")
    parser.add_argument("--looper-image", default="mothership-looper:bf4b288")
    parser.add_argument("--host-uid", type=_identity_id, default=502)
    parser.add_argument("--host-gid", type=_identity_id, default=20)
    parser.add_argument("--python-executable", help="Python executable for system metrics collector")
    parser.add_argument(
        "--native-state",
        type=pathlib.Path,
        help="state root of the native bridges (default: <state parent>/Mothership Native Services)",
    )
    subcommands = parser.add_subparsers(dest="command", required=True)
    subcommands.add_parser("plan", help="print selected resources without starting containers or querying the runtime")
    subcommands.add_parser("up", help="start and verify the private stack")
    subcommands.add_parser("down", help="stop owned containers and preserve all data")
    subcommands.add_parser("status", help="show owned container states and runtime IPs")
    subcommands.add_parser("verify", help="verify containers, /up, login, and static assets")
    prune = subcommands.add_parser(
        "prune-images",
        help="after verify, delete FPM/queue tags older than the live tag and its rollback (dry run unless --apply)",
    )
    prune.add_argument("--apply", action="store_true", help="delete the selected tags")
    relay = subcommands.add_parser("relay-serve", help=argparse.SUPPRESS)
    relay.add_argument("--target-host", required=True)
    relay.add_argument("--target-port", required=True, type=_port)
    relay.add_argument("--relay-token", required=True)
    args = parser.parse_args(argv)
    if args.web_port is None:
        args.web_port = 28090 if args.instance == "stage" else 18090
    return args


def main(argv: Optional[Sequence[str]] = None) -> int:
    args = parse_args(argv=argv)
    if args.command != "relay-serve" and not (args.fpm_image and (args.queue_image or args.instance == "stage")):
        print("error: pass --fpm-image (and --queue-image for production) with the deployed tags", file=sys.stderr)
        return 2
    config = RuntimeConfig(
        source=args.source.expanduser().resolve(),
        state=args.state.expanduser().absolute() if args.instance == "stage" else args.state.expanduser().resolve(),
        web_port=args.web_port,
        mailpit_port=args.mailpit_port,
        container_bin=args.container_bin,
        fpm_image=args.fpm_image or "",
        queue_image=args.queue_image or "",
        looper_image=args.looper_image,
        host_uid=args.host_uid,
        host_gid=args.host_gid,
        web_transport=args.web_transport,
        python_executable=args.python_executable,
        native_state=(args.native_state.expanduser().absolute() if args.instance == "stage"
                      else args.native_state.expanduser().resolve()) if args.native_state else None,
        instance=args.instance,
        web_only=args.web_only,
        production_state=args.production_state.expanduser().resolve() if args.production_state else None,
        production_native_state=args.production_native_state.expanduser().resolve() if args.production_native_state else None,
    )
    stack = None
    try:
        config.validate(scan_aliases=False)
        if args.command == "relay-serve":
            if config.staging:
                raise RuntimeFailure("stage does not run a host relay")
            serve_relay(
                config=config,
                target_host=args.target_host,
                target_port=args.target_port,
                token=args.relay_token,
            )
            return 0
        stack = Stack(config=config)
        if args.command == "plan":
            stack.describe_plan()
        elif args.command == "up":
            stack.up()
        elif args.command == "down":
            stack.down()
        elif args.command == "status":
            stack.status()
        elif args.command == "verify":
            stack.verify()
        elif args.command == "prune-images":
            stack.prune_images(apply=args.apply)
        else:
            raise RuntimeFailure("unsupported command {}".format(args.command))
    except (RuntimeFailure, system_metrics_process.CollectorOwnershipError) as error:
        # A rejected stage path may be production: never create an error log there.
        if config.instance != "stage":
            events = stack.events if stack is not None else EventLog(state=config.state)
            events.write("error", "runtime.failed", "main", {"command": args.command, "message": str(error)})
        print("error: {}".format(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
