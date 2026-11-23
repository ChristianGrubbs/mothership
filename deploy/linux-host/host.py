#!/usr/bin/env python3
"""Linux host helper for the compose deployment in this folder (docs/ops/linux-host.md).

`render` writes the generated runtime files the compose services mount, reusing the
renderers in tools/studio/runtime.py so the Linux host serves the same nginx, PHP-FPM and
worker configuration as the macOS launcher. `verify` runs the launcher's verify checks
against the compose stack. Secrets in app.env are never printed.

Set STUDIO_LOG=1 to append JSONL events to <state>/events.jsonl.
"""
from __future__ import annotations

import argparse
import json
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Dict, List, Mapping, Sequence, Tuple

SOURCE = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(SOURCE / "tools" / "studio"))

import runtime  # noqa: E402  (tools/studio/runtime.py; stdlib only, imports cleanly on Linux)

# Pinned in compose.yaml (services.fpm.networks.mothership-private.ipv4_address): nginx resolves its
# FastCGI upstream once at start, so FPM keeps one address across recreations.
FPM_IP = "192.168.164.10"
# Docker's embedded DNS; nginx resolves /__dam_remote/ hosts through it at request time.
DOCKER_DNS = "127.0.0.11"
WEB = "http://127.0.0.1:18090"
TUNNEL = "mothership-tunnel"
TUNNEL_METRICS = "http://127.0.0.1:20241/ready"

CONTAINERS = dict(runtime.CONTAINER_NAMES)
WORKERS = (
    ("queue", "queue:work"),
    ("panorama_queue", "queue:work"),
    ("stylepress_queue", "queue:work"),
    ("vectorization_queue", "queue:work"),
    ("ai_index_queue", "queue:work"),
    ("scheduler", "schedule:work"),
)
BRIDGES = (("ai", "MOTHERSHIP_AI_API"), ("video", "MOTHERSHIP_HOME_VIDEO_API"))


class Failure(Exception):
    pass


def render_fpm_entrypoint() -> str:
    """The launcher's FPM entrypoint, plus a storage chown before php-fpm starts.

    Setup runs artisan as root; on a Linux bind mount the files it creates would stay root-owned
    and the workers (the host user) could not write them. Apple virtiofs never needed this.
    """
    entrypoint = runtime.render_fpm_entrypoint()
    anchor = "\nexec php-fpm\n"
    if entrypoint.count(anchor) != 1:
        raise Failure("FPM entrypoint anchor must occur exactly once")
    return entrypoint.replace(anchor, '\nchown -R www-data:www-data "${ROOT}/storage"\nexec php-fpm\n', 1)


def postgres_env(environment: Mapping[str, str]) -> str:
    """Only the three values the postgres image reads, derived the way the launcher derives them."""
    values = {
        "POSTGRES_DB": environment.get("POSTGRES_DB", environment.get("DB_DATABASE", "")),
        "POSTGRES_USER": environment.get("POSTGRES_USER", environment.get("DB_USERNAME", "")),
        "POSTGRES_PASSWORD": environment.get("POSTGRES_PASSWORD", environment.get("DB_PASSWORD", "")),
    }
    missing = [key for key, value in values.items() if not value]
    if missing:
        raise Failure("app.env lacks database settings for: {}".format(", ".join(missing)))
    return "".join("{}={}\n".format(key, value) for key, value in values.items())


def render(state: pathlib.Path, events: runtime.EventLog) -> None:
    env_file = state / "app.env"
    if not env_file.is_file():
        raise Failure("{} is missing".format(env_file))
    environment = runtime.read_env(path=env_file)
    generated = state / "generated"
    runtime._atomic_write(path=generated / "nginx" / "default.conf",
                          content=runtime.render_nginx(fpm_ip=FPM_IP, resolver_ip=DOCKER_DNS))
    runtime._atomic_write(path=generated / "fpm" / "zz-studio.conf", content=runtime.render_fpm_pool())
    runtime._atomic_write(path=generated / "fpm" / "zz-studio-opcache.ini", content=runtime.render_opcache())
    runtime._atomic_write(path=generated / "fpm" / "entrypoint.sh", content=render_fpm_entrypoint(), mode=0o755)
    runtime._atomic_write(path=generated / "workers" / "worker-supervisor.sh",
                          content=runtime.render_worker_supervisor(), mode=0o755)
    runtime._atomic_write(path=generated / "postgres.env", content=postgres_env(environment), mode=0o600)
    events.write("info", "render.done", "linux_host.render", {"generated": str(generated)})
    print("rendered {}".format(generated))


def docker(args: Sequence[str], timeout: int = 30) -> str:
    result = subprocess.run(["docker", *args], capture_output=True, text=True, timeout=timeout)
    if result.returncode != 0:
        raise Failure("docker {} failed: {}".format(args[0], (result.stderr or result.stdout).strip()[-300:]))
    return result.stdout


def running_states() -> Dict[str, str]:
    output = docker(["ps", "--all", "--format", "{{.Names}}\t{{.State}}"])
    return dict(line.split("\t", 1) for line in output.splitlines() if "\t" in line)


def artisan_probe(container: str, process: str) -> bool:
    code = (
        '$path="/tmp/studio-worker.pid";'
        'if(!is_readable($path)){exit(1);}'
        '$pid=(int)trim(file_get_contents($path));'
        'if($pid<=1||!is_dir("/proc/".$pid)){exit(1);}'
        '$command=file_get_contents("/proc/".$pid."/cmdline");'
        'exit(strpos($command,"artisan")===false||strpos($command,"' + process + '")===false?1:0);'
    )
    try:
        docker(["exec", container, "php", "-r", code], timeout=15)
        return True
    except (Failure, subprocess.TimeoutExpired):
        return False


def fetch(url: str) -> Tuple[str, str]:
    request = urllib.request.Request(url, headers={"User-Agent": "mothership-verify/1"})
    label = urllib.parse.urlparse(url).path or "/"
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            if response.status != 200:
                raise Failure("{} returned HTTP {}".format(label, response.status))
            return response.read().decode("utf-8", errors="replace"), response.headers.get("Content-Type", "")
    except urllib.error.HTTPError as error:
        raise Failure("{} returned HTTP {}".format(label, error.code)) from error
    except (OSError, urllib.error.URLError) as error:
        raise Failure("{} is unavailable: {}".format(label, getattr(error, "reason", None) or error)) from error


def verify(state: pathlib.Path, fpm_image: str, events: runtime.EventLog) -> None:
    failures: List[str] = []
    states = running_states()
    for role, name in CONTAINERS.items():
        if states.get(name) != "running":
            failures.append("{} is {}".format(role, states.get(name, "missing")))
    if failures:
        raise Failure("; ".join(failures))
    environment = runtime.read_env(path=state / "app.env")
    fpm = CONTAINERS["fpm"]
    docker(["exec", fpm, "curl", "--fail", "--silent", "--show-error", "--max-time", "10",
            "http://looper:3457/health"], timeout=20)
    print("looper: healthy")
    for label, key in BRIDGES:
        url = environment.get(key, "").strip().rstrip("/")
        if not url:
            failures.append("{} bridge is not configured ({})".format(label, key))
            continue
        try:
            docker(["exec", fpm, "curl", "--fail", "--silent", "--show-error", "--max-time", "10",
                    url + "/health"], timeout=20)
            print("{} bridge: healthy".format(label))
        except (Failure, subprocess.TimeoutExpired):
            failures.append("{} bridge /health failed from FPM".format(label))
    if environment.get("MOTHERSHIP_OPTIMIZER_API", "").strip():
        print("optimizer: configured but not served on this host", file=sys.stderr)
    for role, process in WORKERS:
        deadline = time.monotonic() + 30
        while not artisan_probe(CONTAINERS[role], process):
            if time.monotonic() > deadline:
                failures.append("{} has no live {} process".format(role, process))
                break
            time.sleep(2)
    if failures:
        raise Failure("; ".join(failures))
    print("workers: running")

    fetch(WEB + "/up")
    body, _ = fetch(WEB + "/admin/login")
    status, commit = runtime.check_build_commit(body=body, fpm_image=fpm_image)
    events.write("warning" if status == "unknown" else "info", "verify.build_commit", "linux_host.verify",
                 {"status": status, "commit": commit, "fpm_image": fpm_image})
    if status == "mismatch":
        raise Failure("/admin/login serves commit {} but the FPM image is {}".format(commit, fpm_image))
    configured = urllib.parse.urlparse(environment.get("APP_URL", "").strip()).netloc
    static_urls = runtime.select_static_urls(body=body, page_url=WEB + "/admin/login",
                                             loopback_netloc="127.0.0.1:18090", extra_netlocs=(configured,))
    if not static_urls:
        raise Failure("/admin/login did not reference any local static assets")
    for url in static_urls[:30]:
        fetch(url)
    print("web: /up, /admin/login (commit {}), {} static references".format(status, min(len(static_urls), 30)))

    if TUNNEL in states:
        if states[TUNNEL] != "running":
            raise Failure("tunnel is {}".format(states[TUNNEL]))
        fetch(TUNNEL_METRICS)
        print("tunnel: connected")
    events.write("info", "verify.passed", "linux_host.verify", {"fpm_image": fpm_image, "static": len(static_urls)})
    print("verified {}".format(WEB))


def main(argv: Sequence[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("command", choices=("render", "verify"))
    parser.add_argument("--state", required=True, type=pathlib.Path)
    parser.add_argument("--fpm-image", default="")
    args = parser.parse_args(argv)
    events = runtime.EventLog(args.state)
    try:
        if args.command == "render":
            render(args.state, events)
        else:
            verify(args.state, args.fpm_image, events)
    except (Failure, runtime.RuntimeFailure, subprocess.TimeoutExpired) as error:
        events.write("error", "{}.failed".format(args.command), "linux_host.main", {"error": str(error)})
        print("{} failed: {}".format(args.command, error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
